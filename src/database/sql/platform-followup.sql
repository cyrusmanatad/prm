-- Administration follow-up (handoff Part B): partner-side paused label (B3), work skipped because of
-- suspension (B4), directory created-date filters (B5) and two Overview attention figures (B6).
-- Runs after platform-governance.sql. Confirmed suspension rules: D32.

-- ---------------------------------------------------------------------------------------------
-- B3. A participant may learn that the other party's workspace is suspended, and nothing else about it.
-- True only for the caller's own active partnership; the reason and the suspension record are never returned.
CREATE FUNCTION app_partnership_paused(target uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT coalesce((SELECT public.app_workspace_suspended(CASE WHEN p.brand_org_id=public.app_org() THEN p.creator_org_id ELSE p.brand_org_id END)
  FROM public.partnerships p WHERE p.id=target AND p.status='active' AND public.app_org() IN (p.brand_org_id,p.creator_org_id)
   AND public.app_has_membership(public.app_org())),false)
$$;

-- ---------------------------------------------------------------------------------------------
-- B4. Rule 5: work that falls due while its workspace or actor is suspended is skipped and recorded as
-- skipped, not held. Rule 6: reactivation never replays it.
ALTER TABLE schedule_runs DROP CONSTRAINT schedule_runs_state_check;
ALTER TABLE schedule_runs ADD CONSTRAINT schedule_runs_state_check CHECK(state IN ('ready','completed','cancelled','skipped'));

-- Called by the scheduler every minute. Closes work that authorization would refuse at execution:
-- ready runs become 'skipped' with a workspace audit event; pending email and approved follow-ups are
-- cancelled exactly as workspace suspension already does. Returns the number of runs skipped.
CREATE FUNCTION app_skip_suspended_work() RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE skipped integer;
BEGIN
 WITH closed AS (
  UPDATE public.schedule_runs r SET state='skipped',completed_at=clock_timestamp()
   WHERE r.state='ready' AND (public.app_workspace_suspended(r.org_id) OR public.app_account_suspended(r.actor_id))
   RETURNING r.id,r.org_id,r.actor_id
 ), logged AS (
  INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) SELECT gen_random_uuid(),c.org_id,c.actor_id,'automation.run.skipped',c.id FROM closed c RETURNING 1
 ) SELECT count(*) INTO skipped FROM logged;
 UPDATE public.follow_up_drafts d SET state='cancelled',version=version+1,approved_at=NULL,approved_version=NULL,updated_at=clock_timestamp()
  WHERE d.state='approved' AND (public.app_workspace_suspended(d.org_id) OR public.app_account_suspended(d.actor_id));
 UPDATE public.notification_outbox n SET state='cancelled',encrypted_payload=NULL,completed_at=clock_timestamp()
  WHERE n.state='pending' AND (public.app_workspace_suspended(n.org_id) OR public.app_account_suspended(n.actor_id));
 RETURN skipped;
END $$;

-- Schedules that are due while suspended. Routing and timing fields only, so the application can compute
-- the next weekly occurrence with the same clock it uses for ordinary runs.
CREATE FUNCTION app_suspended_due_schedules() RETURNS TABLE(id uuid,kind varchar,timezone varchar,weekday smallint,local_time char(5))
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT s.id,s.kind,s.timezone,s.weekday,s.local_time FROM public.business_schedules s
 WHERE s.enabled AND s.next_due_at<=clock_timestamp() AND (public.app_workspace_suspended(s.org_id) OR public.app_account_suspended(s.actor_id))
 ORDER BY s.next_due_at,s.id LIMIT 100
$$;

-- Records the missed occurrence as a skipped run and moves the schedule past it. It acts only on a
-- schedule that is due and suspended, so it cannot be used to skip ordinary work.
CREATE FUNCTION app_skip_due_schedule(target uuid,following timestamptz) RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE s public.business_schedules; run_id uuid := gen_random_uuid();
BEGIN
 SELECT * INTO s FROM public.business_schedules WHERE id=target FOR UPDATE;
 IF NOT FOUND OR NOT s.enabled OR s.next_due_at IS NULL OR s.next_due_at>clock_timestamp()
  OR NOT (public.app_workspace_suspended(s.org_id) OR public.app_account_suspended(s.actor_id)) THEN RETURN false; END IF;
 IF (s.kind='weekly_digest' AND (following IS NULL OR following<=clock_timestamp())) OR (s.kind<>'weekly_digest' AND following IS NOT NULL) THEN
  RAISE EXCEPTION 'Invalid next occurrence' USING ERRCODE='22000'; END IF;
 INSERT INTO public.schedule_runs(id,org_id,actor_id,schedule_id,schedule_version,preference_version,rule_version,due_at,state,completed_at)
  VALUES(run_id,s.org_id,s.actor_id,s.id,s.version,s.preference_version,s.rule_version,s.next_due_at,'skipped',clock_timestamp())
  ON CONFLICT(schedule_id,schedule_version,due_at) DO NOTHING;
 IF FOUND THEN
  INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),s.org_id,s.actor_id,'automation.run.skipped',run_id);
 END IF;
 UPDATE public.business_schedules SET next_due_at=following WHERE id=target;
 RETURN true;
END $$;

REVOKE ALL ON FUNCTION app_partnership_paused(uuid),app_skip_suspended_work(),app_suspended_due_schedules(),app_skip_due_schedule(uuid,timestamptz) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_partnership_paused(uuid),app_skip_suspended_work(),app_suspended_due_schedules(),app_skip_due_schedule(uuid,timestamptz) TO weavdash_app;

-- ---------------------------------------------------------------------------------------------
-- B5. Created-date range on both directories. The definitions are otherwise those in platform-console.sql.
CREATE OR REPLACE FUNCTION app_platform_accounts(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; term text := nullif(trim(filters->>'q'),''); verified text := filters->>'verified'; granted text := filters->>'grant'; access text := filters->>'access';
 since timestamptz := nullif(filters->>'created_from','')::timestamptz; until timestamptz := nullif(filters->>'created_to','')::timestamptz;
BEGIN
 PERFORM public.app_platform_require('directory.read');
 WITH matched AS (
  SELECT u.id,u.name,u.email,u.email_verified_at,u.created_at,
   (SELECT count(*) FROM public.memberships m WHERE m.user_id=u.id AND m.revoked_at IS NULL) AS workspaces,
   EXISTS(SELECT 1 FROM public.platform_grants g WHERE g.user_id=u.id AND g.revoked_at IS NULL) AS platform_access,
   public.app_account_suspended(u.id) AS suspended
  FROM public.users u
  WHERE (term IS NULL OR u.name ILIKE public.app_like(term) OR u.email ILIKE public.app_like(term) OR u.id::text=term)
   AND (verified IS NULL OR (verified='yes')=(u.email_verified_at IS NOT NULL))
   AND (granted IS NULL OR (granted='yes')=EXISTS(SELECT 1 FROM public.platform_grants g WHERE g.user_id=u.id AND g.revoked_at IS NULL))
   AND (access IS NULL OR (access='suspended')=public.app_account_suspended(u.id))
   AND (since IS NULL OR u.created_at>=since) AND (until IS NULL OR u.created_at<until)
 )
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),'rows',coalesce((SELECT jsonb_agg(row_to_json(p)) FROM (
  SELECT * FROM matched ORDER BY name,id LIMIT 25 OFFSET greatest(page_number-1,0)*25) p),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

CREATE OR REPLACE FUNCTION app_platform_workspaces(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; term text := nullif(trim(filters->>'q'),''); wanted_kind text := filters->>'kind'; wanted_state text := filters->>'state'; access text := filters->>'access';
 since timestamptz := nullif(filters->>'created_from','')::timestamptz; until timestamptz := nullif(filters->>'created_to','')::timestamptz;
BEGIN
 PERFORM public.app_platform_require('directory.read');
 WITH matched AS (
  SELECT o.id,o.name,o.kind,o.experience,o.created_at,public.app_entitlement_state(o.id) AS entitlement_state,public.app_workspace_suspended(o.id) AS suspended,
   (SELECT count(*) FROM public.memberships m WHERE m.org_id=o.id AND m.revoked_at IS NULL) AS members,
   coalesce((SELECT jsonb_agg(x.name ORDER BY x.name) FROM (SELECT u.name FROM public.memberships m JOIN public.users u ON u.id=m.user_id
     WHERE m.org_id=o.id AND m.role='owner' AND m.revoked_at IS NULL ORDER BY u.name LIMIT 3) x),'[]'::jsonb) AS owners,
   (SELECT count(*) FROM public.memberships m WHERE m.org_id=o.id AND m.role='owner' AND m.revoked_at IS NULL) AS owner_count,
   (SELECT p.name||' v'||p.version FROM public.pilot_assignments a JOIN public.pilot_plan_versions p ON p.id=a.plan_id WHERE a.org_id=o.id ORDER BY a.version DESC LIMIT 1) AS plan
  FROM public.organizations o
  WHERE (term IS NULL OR o.name ILIKE public.app_like(term) OR o.id::text=lower(term))
   AND (wanted_kind IS NULL OR o.kind=wanted_kind)
   AND (wanted_state IS NULL OR public.app_entitlement_state(o.id)=wanted_state)
   AND (access IS NULL OR (access='suspended')=public.app_workspace_suspended(o.id))
   AND (since IS NULL OR o.created_at>=since) AND (until IS NULL OR o.created_at<until)
 )
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),'rows',coalesce((SELECT jsonb_agg(row_to_json(p)) FROM (
  SELECT * FROM matched ORDER BY name,id LIMIT 25 OFFSET greatest(page_number-1,0)*25) p),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

-- ---------------------------------------------------------------------------------------------
-- B6. Overview: support access nearing expiry and cleanup reviews with blocked records.
CREATE OR REPLACE FUNCTION app_platform_overview() RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE directory boolean; plans boolean; notices boolean; operations boolean; owner boolean;
BEGIN
 IF NOT public.app_platform_any() THEN RAISE EXCEPTION 'Platform permission required' USING ERRCODE='42501'; END IF;
 directory := public.app_platform_allowed('directory.read'); plans := public.app_platform_allowed('plans.assign');
 notices := public.app_platform_allowed('notifications.read'); operations := public.app_platform_allowed('operations.read'); owner := public.app_platform_allowed('platform.owner');
 RETURN jsonb_build_object('observed_at',clock_timestamp(),
  'accounts',CASE WHEN directory THEN (SELECT count(*) FROM public.users) END,
  'creator_workspaces',CASE WHEN directory THEN (SELECT count(*) FROM public.organizations WHERE kind='creator') END,
  'brand_workspaces',CASE WHEN directory THEN (SELECT count(*) FROM public.organizations WHERE kind='brand') END,
  'active_assignments',CASE WHEN plans THEN (SELECT count(*) FROM public.organizations o WHERE public.app_entitlement_state(o.id)='active') END,
  'expired_assignments',CASE WHEN plans THEN (SELECT count(*) FROM public.organizations o WHERE public.app_entitlement_state(o.id)='expired') END,
  'pending_notices',CASE WHEN notices THEN (SELECT count(*) FROM public.notification_outbox WHERE state='pending') END,
  'uncertain_sends',CASE WHEN notices THEN (SELECT count(*) FROM public.notification_outbox n WHERE n.state='uncertain' AND NOT EXISTS(SELECT 1 FROM public.notification_resolutions r WHERE r.notification_id=n.id)) END,
  'failed_jobs',CASE WHEN operations THEN (SELECT count(*) FROM public.failed_jobs WHERE failed_at>(clock_timestamp()-interval '24 hours')) END,
  'scheduler_age_seconds',CASE WHEN operations THEN (SELECT floor(extract(epoch FROM clock_timestamp()-s.observed_at))::bigint FROM public.platform_observations s WHERE s.source='scheduler') END,
  'scheduler_visible',operations,
  'long_suspensions',CASE WHEN public.app_platform_allowed('access.manage') THEN (SELECT count(*) FROM public.platform_suspensions WHERE lifted_at IS NULL AND suspended_at<(clock_timestamp()-interval '30 days')) END,
  'support_awaiting',CASE WHEN public.app_platform_allowed('support.request') THEN (SELECT count(*) FROM public.support_grants WHERE state='requested') END,
  -- Approved access that ends within two hours, and batches whose current dry run found blocked records.
  'support_expiring',CASE WHEN public.app_platform_allowed('support.request') THEN (SELECT count(*) FROM public.support_grants WHERE state='approved' AND expires_at>clock_timestamp() AND expires_at<=(clock_timestamp()+interval '2 hours')) END,
  'cleanup_blocked',CASE WHEN public.app_platform_allowed('cleanup.review') THEN (SELECT count(*) FROM public.fixture_batches b WHERE EXISTS(SELECT 1 FROM public.cleanup_runs r WHERE r.batch_id=b.id AND r.batch_version=b.version AND r.blocked>0
    AND r.created_at=(SELECT max(x.created_at) FROM public.cleanup_runs x WHERE x.batch_id=b.id))) END,
  'operators_without_mfa',CASE WHEN owner THEN (SELECT count(*) FROM public.platform_grants g JOIN public.users u ON u.id=g.user_id WHERE g.revoked_at IS NULL AND u.admin_totp_confirmed_at IS NULL) END,
  'activity',CASE WHEN public.app_platform_allowed('audit.read') THEN coalesce((SELECT jsonb_agg(row_to_json(e)) FROM (
    SELECT a.id::text AS id,a.occurred_at,a.event,a.target,coalesce(u.name,a.operator) AS actor FROM public.platform_audit a LEFT JOIN public.users u ON u.id=a.actor_id
    WHERE a.event<>'request.denied' ORDER BY a.occurred_at DESC,a.id DESC LIMIT 8) e),'[]'::jsonb) END);
END $$;
