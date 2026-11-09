ALTER TABLE notification_outbox
 ADD COLUMN available_at timestamptz NOT NULL DEFAULT now(),
 ADD COLUMN dispatch_after timestamptz NOT NULL DEFAULT now(),
 ADD COLUMN defer_count integer NOT NULL DEFAULT 0 CHECK(defer_count>=0),
 ADD COLUMN failure_code varchar(32) CHECK(failure_code IN ('transport_unavailable','workspace_budget','global_budget','queue_unavailable'));
CREATE INDEX notification_delivery_due ON notification_outbox(available_at,dispatch_after,org_id,created_at,id) WHERE state='pending';
CREATE TABLE notification_delivery_windows(scope text PRIMARY KEY, window_start timestamptz NOT NULL, used integer NOT NULL CHECK(used>=0));
ALTER TABLE notification_delivery_windows ENABLE ROW LEVEL SECURITY;
ALTER TABLE notification_delivery_windows FORCE ROW LEVEL SECURITY;

CREATE OR REPLACE FUNCTION app_pending_notifications() RETURNS TABLE(id uuid,org_id uuid,actor_id bigint)
LANGUAGE sql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT x.id,x.org_id,x.actor_id FROM (
  SELECT n.id,n.org_id,n.actor_id,n.created_at,row_number() OVER(PARTITION BY n.org_id ORDER BY n.created_at,n.id) AS turn
  FROM public.notification_outbox n WHERE n.state='pending' AND n.available_at<=clock_timestamp() AND n.dispatch_after<=clock_timestamp() AND public.app_professional_active(n.org_id)
 ) x ORDER BY x.turn,coalesce((SELECT w.window_start FROM public.notification_delivery_windows w WHERE w.scope=x.org_id::text),'-infinity'::timestamptz),x.created_at,x.id LIMIT 100
$$;
CREATE FUNCTION app_claim_notifications() RETURNS TABLE(id uuid,org_id uuid,actor_id bigint)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE route record; selected_ids uuid[]; claimed_ids uuid[]:=ARRAY[]::uuid[]; claimed uuid;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('notifications:dispatch',0));
 SELECT ARRAY(SELECT p.id FROM public.app_pending_notifications() WITH ORDINALITY p(id,org_id,actor_id,turn) ORDER BY p.turn) INTO selected_ids;
 -- Acquire workspace locks in a fixed order, but return the original fair queue order.
 FOR route IN SELECT n.id,n.org_id FROM public.notification_outbox n WHERE n.id=ANY(selected_ids) ORDER BY n.org_id,n.id LOOP
  PERFORM pg_advisory_xact_lock(hashtextextended('pilot:'||route.org_id::text,0));
  UPDATE public.notification_outbox n SET dispatch_after=clock_timestamp()+interval '60 seconds'
   WHERE n.id=route.id AND n.state='pending' AND n.available_at<=clock_timestamp() AND n.dispatch_after<=clock_timestamp()
   RETURNING n.id INTO claimed;
  IF FOUND THEN claimed_ids:=array_append(claimed_ids,claimed); END IF;
 END LOOP;
 RETURN QUERY SELECT n.id,n.org_id,n.actor_id FROM unnest(selected_ids) WITH ORDINALITY chosen(id,turn)
  JOIN public.notification_outbox n ON n.id=chosen.id WHERE n.id=ANY(claimed_ids) ORDER BY chosen.turn;
END $$;
CREATE FUNCTION app_notification_budget(workspace_limit integer,global_limit integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE minute_start timestamptz:=date_trunc('minute',clock_timestamp()); local_used integer; total_used integer; target uuid:=public.app_org();
BEGIN
 IF target IS NULL OR workspace_limit IS NULL OR global_limit IS NULL OR workspace_limit<0 OR global_limit<0
  OR NOT public.app_has_membership(target,ARRAY['owner','admin','manager','member']) THEN
  RAISE EXCEPTION 'Current editing membership and nonnegative limits required' USING ERRCODE='42501';
 END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('notifications:budget',0));
 SELECT coalesce(max(used),0) INTO local_used FROM public.notification_delivery_windows WHERE scope=target::text AND window_start=minute_start;
 SELECT coalesce(max(used),0) INTO total_used FROM public.notification_delivery_windows WHERE scope='global' AND window_start=minute_start;
 IF global_limit>0 AND total_used>=global_limit THEN RETURN 'global_budget'; END IF;
 IF workspace_limit>0 AND local_used>=workspace_limit THEN RETURN 'workspace_budget'; END IF;
 INSERT INTO public.notification_delivery_windows(scope,window_start,used) VALUES('global',minute_start,1),(target::text,minute_start,1)
 ON CONFLICT(scope) DO UPDATE SET window_start=EXCLUDED.window_start,
  used=CASE WHEN notification_delivery_windows.window_start=EXCLUDED.window_start THEN notification_delivery_windows.used+1 ELSE 1 END;
 RETURN NULL;
END $$;

ALTER TABLE platform_grants DROP CONSTRAINT platform_grants_permissions_check;
ALTER TABLE platform_grants ADD CONSTRAINT platform_grants_permissions_check
 CHECK(permissions <@ ARRAY['platform.owner','directory.read','plans.assign','notifications.read','notifications.reconcile']::text[]);
CREATE TABLE notification_resolutions(
 notification_id uuid PRIMARY KEY REFERENCES notification_outbox(id), resolution varchar(24) NOT NULL CHECK(resolution IN ('transport_accepted','not_sent','unknown_closed')),
 actor_id bigint NOT NULL REFERENCES users(id), version integer NOT NULL DEFAULT 1 CHECK(version=1), resolved_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE notification_resolutions ENABLE ROW LEVEL SECURITY;
ALTER TABLE notification_resolutions FORCE ROW LEVEL SECURITY;
CREATE FUNCTION app_platform_notifications(target_org uuid,filter_state text,page_number integer) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb;
BEGIN
 IF NOT public.app_platform_allowed('notifications.read') THEN RAISE EXCEPTION 'Platform permission required' USING ERRCODE='42501'; END IF;
 IF filter_state IS NOT NULL AND filter_state NOT IN ('pending','sending','sent','captured','cancelled','uncertain') THEN RAISE EXCEPTION 'Invalid state' USING ERRCODE='22000'; END IF;
 SELECT coalesce(jsonb_agg(row_to_json(x)),'[]'::jsonb) INTO result FROM (
  SELECT n.id,n.org_id,n.kind,n.state,n.created_at,n.started_at,n.completed_at,n.available_at,n.defer_count,n.failure_code,
   r.resolution,r.resolved_at,coalesce(r.version,0) AS resolution_version
  FROM public.notification_outbox n LEFT JOIN public.notification_resolutions r ON r.notification_id=n.id
  WHERE (target_org IS NULL OR n.org_id=target_org) AND (filter_state IS NULL OR n.state=filter_state)
  ORDER BY n.created_at DESC,n.id DESC LIMIT 25 OFFSET greatest(page_number-1,0)*25
 ) x;
 RETURN result;
END $$;
CREATE FUNCTION app_platform_resolve_notification(target uuid,expected integer,outcome text,explanation text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE n public.notification_outbox;
BEGIN
 PERFORM 1 FROM public.platform_grants WHERE user_id=public.app_actor() AND revoked_at IS NULL FOR SHARE;
 IF NOT public.app_platform_allowed('notifications.reconcile') THEN RAISE EXCEPTION 'Platform permission required' USING ERRCODE='42501'; END IF;
 IF explanation IS NULL OR length(trim(explanation)) NOT BETWEEN 1 AND 240 OR outcome IS NULL OR outcome NOT IN ('transport_accepted','not_sent','unknown_closed') THEN
  RAISE EXCEPTION 'Resolution and reason required' USING ERRCODE='22000'; END IF;
 SELECT * INTO n FROM public.notification_outbox WHERE id=target FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Notification unavailable' USING ERRCODE='P0002'; END IF;
 IF n.state<>'uncertain' OR expected IS DISTINCT FROM 0 OR EXISTS(SELECT 1 FROM public.notification_resolutions WHERE notification_id=target) THEN
  RAISE EXCEPTION 'Resolution changed. Reload diagnostics.' USING ERRCODE='40001'; END IF;
 INSERT INTO public.notification_resolutions(notification_id,resolution,actor_id) VALUES(target,outcome,public.app_actor());
 PERFORM public.app_platform_audit('notification.resolved',target::text,trim(explanation));
END $$;
REVOKE ALL ON FUNCTION app_claim_notifications(),app_notification_budget(integer,integer),app_platform_notifications(uuid,text,integer),app_platform_resolve_notification(uuid,integer,text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_claim_notifications(),app_notification_budget(integer,integer),app_platform_notifications(uuid,text,integer),app_platform_resolve_notification(uuid,integer,text,text) TO weavdash_app;
