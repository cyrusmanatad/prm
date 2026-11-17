-- Administration console projections (ADM-01/02/04/05/06 read models and operator grants).
-- Every function is a reviewed SECURITY DEFINER gate: the runtime role keeps tenant RLS and gains
-- only these metadata projections. No private CRM, document, message or financial content is returned.
ALTER TABLE platform_grants DROP CONSTRAINT platform_grants_permissions_check;
ALTER TABLE platform_grants ADD CONSTRAINT platform_grants_permissions_check
 CHECK(permissions <@ ARRAY['platform.owner','directory.read','plans.assign','notifications.read','notifications.reconcile','operations.read','audit.read']::text[]);

-- Latest scheduler heartbeat only. Absence means "unknown", never "healthy".
CREATE TABLE platform_observations (
 source varchar(40) PRIMARY KEY CHECK(source IN ('scheduler')), observed_at timestamptz NOT NULL
);
ALTER TABLE platform_observations ENABLE ROW LEVEL SECURITY;
ALTER TABLE platform_observations FORCE ROW LEVEL SECURITY;
-- No policies: the runtime role reads and writes observations only through the functions below.

CREATE FUNCTION app_platform_require(permission text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF NOT public.app_platform_allowed(permission) THEN RAISE EXCEPTION 'Platform permission required' USING ERRCODE='42501'; END IF;
END $$;

-- True for any usable grant (verified identity, confirmed authenticator, current step-up version).
CREATE FUNCTION app_platform_any() RETURNS boolean LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.platform_grants g JOIN public.users u ON u.id=g.user_id
 WHERE g.user_id=public.app_actor() AND g.revoked_at IS NULL AND u.email_verified_at IS NOT NULL
 AND u.admin_totp_confirmed_at IS NOT NULL AND cardinality(g.permissions)>0
 AND g.version::text=nullif(current_setting('app.admin_version',true),''))
$$;

CREATE FUNCTION app_entitlement_state(target uuid) RETURNS text LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT coalesce((SELECT CASE WHEN a.starts_at>clock_timestamp() THEN 'scheduled' WHEN a.ends_at<=clock_timestamp() THEN 'expired' ELSE 'active' END
  FROM public.pilot_assignments a WHERE a.org_id=target ORDER BY a.version DESC LIMIT 1),
  (SELECT CASE WHEN o.experience='professional' THEN 'pending-activation' ELSE 'unassigned-development' END FROM public.organizations o WHERE o.id=target))
$$;

-- Escape LIKE wildcards so a search term is always matched literally.
CREATE FUNCTION app_like(term text) RETURNS text LANGUAGE sql IMMUTABLE SET search_path=pg_catalog AS $$
 SELECT '%'||replace(replace(replace(left(coalesce(term,''),120),'\','\\'),'%','\%'),'_','\_')||'%'
$$;

CREATE FUNCTION app_platform_accounts(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; term text := nullif(trim(filters->>'q'),''); verified text := filters->>'verified'; granted text := filters->>'grant'; access text := filters->>'access';
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
 )
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),'rows',coalesce((SELECT jsonb_agg(row_to_json(p)) FROM (
  SELECT * FROM matched ORDER BY name,id LIMIT 25 OFFSET greatest(page_number-1,0)*25) p),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

-- Recent administration audit for one target; null unless the caller may review audit.
CREATE FUNCTION app_platform_activity(target_ref text,target_org uuid) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF NOT public.app_platform_allowed('audit.read') THEN RETURN NULL; END IF;
 RETURN coalesce((SELECT jsonb_agg(row_to_json(e)) FROM (
  SELECT 'platform' AS source,a.id::text AS id,a.occurred_at,a.event,a.reason,coalesce(u.name,a.operator) AS actor
  FROM public.platform_audit a LEFT JOIN public.users u ON u.id=a.actor_id WHERE a.target=target_ref AND a.event<>'request.denied'
  UNION ALL
  SELECT 'local-pilot',p.id::text,p.created_at,p.event,p.reason,p.operator FROM public.pilot_admin_events p
  WHERE target_org IS NOT NULL AND p.org_id=target_org AND p.event<>'plan.assigned'
  ORDER BY 3 DESC LIMIT 25) e),'[]'::jsonb);
END $$;

CREATE FUNCTION app_platform_account(target bigint) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb;
BEGIN
 PERFORM public.app_platform_require('directory.read');
 SELECT jsonb_build_object(
  'account',jsonb_build_object('id',u.id,'name',u.name,'email',u.email,'email_verified_at',u.email_verified_at,'created_at',u.created_at,'updated_at',u.updated_at,'suspended',public.app_account_suspended(u.id)),
  'security',jsonb_build_object('mfa_enrolled',u.admin_totp_confirmed_at IS NOT NULL,'mfa_enrolled_at',u.admin_totp_confirmed_at,
   'sessions',(SELECT count(*) FROM public.sessions s WHERE s.user_id=u.id),
   'grant',(SELECT jsonb_build_object('permissions',g.permissions,'revoked_at',g.revoked_at) FROM public.platform_grants g WHERE g.user_id=u.id)),
  'memberships',coalesce((SELECT jsonb_agg(row_to_json(m)) FROM (
   SELECT o.id AS org_id,o.name,o.kind,m.role,m.created_at,m.revoked_at FROM public.memberships m JOIN public.organizations o ON o.id=m.org_id
   WHERE m.user_id=u.id ORDER BY m.revoked_at IS NOT NULL,o.name,o.id) m),'[]'::jsonb),
  'activity',public.app_platform_activity(u.id::text,NULL)) INTO result FROM public.users u WHERE u.id=target;
 IF result IS NULL THEN RAISE EXCEPTION 'Account unavailable' USING ERRCODE='P0002'; END IF;
 RETURN result;
END $$;

CREATE FUNCTION app_platform_workspaces(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; term text := nullif(trim(filters->>'q'),''); wanted_kind text := filters->>'kind'; wanted_state text := filters->>'state'; access text := filters->>'access';
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
 )
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),'rows',coalesce((SELECT jsonb_agg(row_to_json(p)) FROM (
  SELECT * FROM matched ORDER BY name,id LIMIT 25 OFFSET greatest(page_number-1,0)*25) p),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

-- Counts only: record names, notes, amounts, filenames and recipients never leave their tenant.
CREATE FUNCTION app_platform_workspace(target uuid) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; a public.pilot_assignments; p public.pilot_plan_versions; usage jsonb := '[]'::jsonb; resource text; used bigint;
BEGIN
 PERFORM public.app_platform_require('directory.read');
 IF NOT EXISTS(SELECT 1 FROM public.organizations WHERE id=target) THEN RAISE EXCEPTION 'Workspace unavailable' USING ERRCODE='P0002'; END IF;
 SELECT * INTO a FROM public.pilot_assignments WHERE org_id=target ORDER BY version DESC LIMIT 1;
 IF a.id IS NOT NULL THEN SELECT * INTO p FROM public.pilot_plan_versions WHERE id=a.plan_id; END IF;
 FOREACH resource IN ARRAY ARRAY['pipeline_entries','brand_contacts','reminders','onboarding_requests','outreach_templates','invoice_drafts','manual_payments'] LOOP
  EXECUTE format('SELECT count(*) FROM public.%I WHERE org_id=$1',resource) INTO used USING target;
  usage := usage || jsonb_build_object('resource',resource,'used',used,'limit',CASE WHEN a.id IS NULL THEN NULL ELSE coalesce((p.quotas->>resource)::bigint,0) END);
 END LOOP;
 SELECT jsonb_build_object(
  'workspace',jsonb_build_object('id',o.id,'name',o.name,'kind',o.kind,'experience',o.experience,'created_at',o.created_at,'updated_at',o.updated_at,'suspended',public.app_workspace_suspended(o.id)),
  'members',coalesce((SELECT jsonb_agg(row_to_json(m)) FROM (
   SELECT u.id AS user_id,u.name,u.email,m.role,m.created_at,m.revoked_at FROM public.memberships m JOIN public.users u ON u.id=m.user_id
   WHERE m.org_id=o.id ORDER BY m.revoked_at IS NOT NULL,array_position(ARRAY['owner','admin','manager','member','viewer'],m.role::text),u.name,u.id) m),'[]'::jsonb),
  'entitlement',jsonb_build_object('state',public.app_entitlement_state(o.id),
   'assignment',CASE WHEN a.id IS NULL THEN NULL ELSE jsonb_build_object('id',a.id,'version',a.version,'starts_at',a.starts_at,'ends_at',a.ends_at,'created_at',a.created_at) END,
   'plan',CASE WHEN a.id IS NULL THEN NULL ELSE jsonb_build_object('id',p.id,'name',p.name,'version',p.version) END,
   'usage',usage),
  'operations',jsonb_build_object(
   'partner_invitations',coalesce((SELECT jsonb_object_agg(status,total) FROM (SELECT status,count(*) AS total FROM public.partner_invitations WHERE brand_org_id=o.id GROUP BY status) s),'{}'::jsonb),
   'partnerships',coalesce((SELECT jsonb_object_agg(status,total) FROM (SELECT status,count(*) AS total FROM public.partnerships WHERE brand_org_id=o.id OR creator_org_id=o.id GROUP BY status) s),'{}'::jsonb),
   'onboarding_requests',coalesce((SELECT jsonb_object_agg(status,total) FROM (SELECT status,count(*) AS total FROM public.onboarding_requests WHERE org_id=o.id GROUP BY status) s),'{}'::jsonb),
   'onboarding_documents',coalesce((SELECT jsonb_object_agg(scan_state,total) FROM (SELECT scan_state,count(*) AS total FROM public.onboarding_documents WHERE org_id=o.id GROUP BY scan_state) s),'{}'::jsonb),
   'notifications',coalesce((SELECT jsonb_object_agg(state,total) FROM (SELECT state,count(*) AS total FROM public.notification_outbox WHERE org_id=o.id GROUP BY state) s),'{}'::jsonb),
   'observed_at',clock_timestamp()),
  'activity',public.app_platform_activity(o.id::text,o.id)) INTO result FROM public.organizations o WHERE o.id=target;
 RETURN result;
END $$;

CREATE FUNCTION app_platform_plans(term text,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb;
BEGIN
 PERFORM public.app_platform_require('plans.assign');
 WITH matched AS (
  SELECT p.id,p.name,p.version,p.created_at,
   (SELECT count(*) FROM public.pilot_assignments a WHERE a.plan_id=p.id AND a.version=(SELECT max(b.version) FROM public.pilot_assignments b WHERE b.org_id=a.org_id)) AS selected_workspaces
  FROM public.pilot_plan_versions p WHERE nullif(trim(term),'') IS NULL OR p.name ILIKE public.app_like(trim(term)) OR p.id::text=lower(trim(term))
 )
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),'rows',coalesce((SELECT jsonb_agg(row_to_json(x)) FROM (
  SELECT * FROM matched ORDER BY created_at DESC,id LIMIT 25 OFFSET greatest(page_number-1,0)*25) x),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

CREATE FUNCTION app_platform_assignments(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; target_org uuid := nullif(filters->>'workspace','')::uuid; target_plan uuid := nullif(filters->>'plan','')::uuid;
 wanted_state text := filters->>'state'; history text := filters->>'history';
BEGIN
 PERFORM public.app_platform_require('plans.assign');
 WITH listed AS (
  SELECT a.id,a.org_id,o.name AS workspace,a.plan_id,p.name AS plan,p.version AS plan_version,a.version,a.starts_at,a.ends_at,a.created_at,
   a.version=(SELECT max(b.version) FROM public.pilot_assignments b WHERE b.org_id=a.org_id) AS selected
  FROM public.pilot_assignments a JOIN public.organizations o ON o.id=a.org_id JOIN public.pilot_plan_versions p ON p.id=a.plan_id
  WHERE (target_org IS NULL OR a.org_id=target_org) AND (target_plan IS NULL OR a.plan_id=target_plan)
 ), matched AS (
  SELECT l.*,CASE WHEN NOT l.selected THEN 'superseded' WHEN l.starts_at>clock_timestamp() THEN 'scheduled' WHEN l.ends_at<=clock_timestamp() THEN 'expired' ELSE 'active' END AS state FROM listed l
 ), filtered AS (
  SELECT * FROM matched m WHERE (wanted_state IS NULL OR m.state=wanted_state) AND (history='all' OR target_org IS NOT NULL OR target_plan IS NOT NULL OR wanted_state='superseded' OR m.selected)
 )
 SELECT jsonb_build_object('total',(SELECT count(*) FROM filtered),'rows',coalesce((SELECT jsonb_agg(row_to_json(x)) FROM (
  SELECT * FROM filtered ORDER BY created_at DESC,id LIMIT 25 OFFSET greatest(page_number-1,0)*25) x),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

CREATE FUNCTION app_platform_plan(target uuid) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb;
BEGIN
 PERFORM public.app_platform_require('plans.assign');
 SELECT jsonb_build_object('plan',jsonb_build_object('id',p.id,'name',p.name,'version',p.version,'quotas',p.quotas,'created_at',p.created_at),
  'assignments',public.app_platform_assignments(jsonb_build_object('plan',p.id),1)) INTO result FROM public.pilot_plan_versions p WHERE p.id=target;
 IF result IS NULL THEN RAISE EXCEPTION 'Plan unavailable' USING ERRCODE='P0002'; END IF;
 RETURN result;
END $$;

-- Paged delivery metadata with totals and workspace names. The encrypted payload is never selected.
CREATE FUNCTION app_platform_notification_list(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; target_org uuid := nullif(filters->>'workspace','')::uuid; wanted_state text := filters->>'state'; term text := nullif(trim(filters->>'q'),'');
BEGIN
 PERFORM public.app_platform_require('notifications.read');
 IF wanted_state IS NOT NULL AND wanted_state NOT IN ('pending','sending','sent','captured','cancelled','uncertain') THEN RAISE EXCEPTION 'Invalid state' USING ERRCODE='22000'; END IF;
 WITH matched AS (
  SELECT n.id,n.org_id,o.name AS workspace,n.kind,n.state,n.created_at,n.started_at,n.completed_at,n.available_at,n.defer_count,n.failure_code,
   r.resolution,r.resolved_at,coalesce(r.version,0) AS resolution_version
  FROM public.notification_outbox n JOIN public.organizations o ON o.id=n.org_id LEFT JOIN public.notification_resolutions r ON r.notification_id=n.id
  WHERE (target_org IS NULL OR n.org_id=target_org) AND (wanted_state IS NULL OR n.state=wanted_state)
   AND (term IS NULL OR o.name ILIKE public.app_like(term) OR n.id::text=lower(term) OR n.org_id::text=lower(term))
 )
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),'rows',coalesce((SELECT jsonb_agg(row_to_json(x)) FROM (
  SELECT * FROM matched ORDER BY created_at DESC,id DESC LIMIT 25 OFFSET greatest(page_number-1,0)*25) x),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

CREATE FUNCTION app_platform_notification(target uuid) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb;
BEGIN
 PERFORM public.app_platform_require('notifications.read');
 SELECT jsonb_build_object('id',n.id,'org_id',n.org_id,'workspace',o.name,'kind',n.kind,'state',n.state,'created_at',n.created_at,'started_at',n.started_at,
  'completed_at',n.completed_at,'available_at',n.available_at,'defer_count',n.defer_count,'failure_code',n.failure_code,
  'actor',(SELECT jsonb_build_object('id',u.id,'name',u.name) FROM public.users u WHERE u.id=n.actor_id),
  'resolution',r.resolution,'resolved_at',r.resolved_at,'resolution_version',coalesce(r.version,0),
  'resolver',(SELECT u.name FROM public.users u WHERE u.id=r.actor_id)) INTO result
 FROM public.notification_outbox n JOIN public.organizations o ON o.id=n.org_id LEFT JOIN public.notification_resolutions r ON r.notification_id=n.id WHERE n.id=target;
 IF result IS NULL THEN RAISE EXCEPTION 'Notification unavailable' USING ERRCODE='P0002'; END IF;
 RETURN result;
END $$;

-- Queue payloads and exception text can hold private data: expose only a job class name and an exception class name.
CREATE FUNCTION app_job_name(payload text) RETURNS text LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog AS $$
DECLARE name text;
BEGIN
 name := payload::jsonb->>'displayName';
 RETURN CASE WHEN name ~ '^App\\[A-Za-z0-9_\\]{1,120}$' THEN name ELSE NULL END;
EXCEPTION WHEN OTHERS THEN RETURN NULL;
END $$;

CREATE FUNCTION app_record_observation(origin text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF origin IS DISTINCT FROM 'scheduler' THEN RAISE EXCEPTION 'Unknown observation source' USING ERRCODE='22000'; END IF;
 INSERT INTO public.platform_observations(source,observed_at) VALUES(origin,clock_timestamp())
 ON CONFLICT(source) DO UPDATE SET observed_at=excluded.observed_at;
END $$;

CREATE FUNCTION app_platform_operations() RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM public.app_platform_require('operations.read');
 RETURN jsonb_build_object(
  'observed_at',clock_timestamp(),
  'scheduler',(SELECT jsonb_build_object('observed_at',s.observed_at,'age_seconds',floor(extract(epoch FROM clock_timestamp()-s.observed_at))::bigint) FROM public.platform_observations s WHERE s.source='scheduler'),
  'outbox',jsonb_build_object('pending',(SELECT count(*) FROM public.notification_outbox WHERE state='pending'),
   'oldest_pending_at',(SELECT min(created_at) FROM public.notification_outbox WHERE state='pending'),
   'uncertain_unresolved',(SELECT count(*) FROM public.notification_outbox n WHERE n.state='uncertain' AND NOT EXISTS(SELECT 1 FROM public.notification_resolutions r WHERE r.notification_id=n.id))),
  'failed',jsonb_build_object('total',(SELECT count(*) FROM public.failed_jobs),
   'last_24_hours',(SELECT count(*) FROM public.failed_jobs WHERE failed_at>(clock_timestamp()-interval '24 hours')),
   'rows',coalesce((SELECT jsonb_agg(row_to_json(f)) FROM (
    SELECT j.uuid AS reference,j.queue,j.connection,j.failed_at,public.app_job_name(j.payload) AS job,
     CASE WHEN split_part(j.exception,':',1) ~ '^[A-Za-z0-9_\\]{1,160}$' THEN split_part(j.exception,':',1) ELSE NULL END AS category
    FROM public.failed_jobs j ORDER BY j.failed_at DESC,j.id DESC LIMIT 25) f),'[]'::jsonb)));
END $$;

CREATE FUNCTION app_platform_audit_list(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; term text := nullif(trim(filters->>'q'),''); wanted_source text := filters->>'source'; wanted_outcome text := filters->>'outcome';
 since timestamptz := nullif(filters->>'from','')::timestamptz; until timestamptz := nullif(filters->>'to','')::timestamptz;
BEGIN
 PERFORM public.app_platform_require('audit.read');
 WITH events AS (
  SELECT 'platform' AS source,a.id::text AS id,a.occurred_at,a.actor_id,coalesce(u.name,a.operator) AS actor,u.email AS actor_email,a.event,a.target,
   CASE WHEN a.event='request.denied' THEN 'denied' ELSE 'recorded' END AS outcome,NULL::uuid AS org_id
  FROM public.platform_audit a LEFT JOIN public.users u ON u.id=a.actor_id
  UNION ALL
  SELECT 'local-pilot',p.id::text,p.created_at,NULL,p.operator,NULL,p.event,p.subject_id::text,NULL,p.org_id FROM public.pilot_admin_events p
 ), matched AS (
  SELECT e.*,o.name AS workspace FROM events e LEFT JOIN public.organizations o ON o.id=e.org_id
  WHERE (term IS NULL OR e.event ILIKE public.app_like(term) OR e.target=term OR e.actor ILIKE public.app_like(term) OR e.actor_email ILIKE public.app_like(term))
   AND (wanted_source IS NULL OR e.source=wanted_source) AND (wanted_outcome IS NULL OR e.outcome=wanted_outcome)
   AND (since IS NULL OR e.occurred_at>=since) AND (until IS NULL OR e.occurred_at<until)
 )
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),'rows',coalesce((SELECT jsonb_agg(row_to_json(x)) FROM (
  SELECT * FROM matched ORDER BY occurred_at DESC,id DESC LIMIT 25 OFFSET greatest(page_number-1,0)*25) x),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

CREATE FUNCTION app_platform_audit_event(origin text,reference text) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb;
BEGIN
 PERFORM public.app_platform_require('audit.read');
 IF origin='platform' AND reference ~ '^[0-9]{1,18}$' THEN
  SELECT jsonb_build_object('source','platform','id',a.id::text,'occurred_at',a.occurred_at,'actor_id',a.actor_id,'actor',coalesce(u.name,a.operator),'actor_email',u.email,
   'operator',a.operator,'event',a.event,'target',a.target,'reason',a.reason,'outcome',CASE WHEN a.event='request.denied' THEN 'denied' ELSE 'recorded' END,'org_id',NULL,'workspace',NULL) INTO result
  FROM public.platform_audit a LEFT JOIN public.users u ON u.id=a.actor_id WHERE a.id=reference::bigint;
 ELSIF origin='local-pilot' AND reference ~ '^[0-9a-f-]{36}$' THEN
  SELECT jsonb_build_object('source','local-pilot','id',p.id::text,'occurred_at',p.created_at,'actor_id',NULL,'actor',p.operator,'actor_email',NULL,
   'operator',p.operator,'event',p.event,'target',p.subject_id::text,'reason',p.reason,'outcome',NULL,'org_id',p.org_id,'workspace',o.name) INTO result
  FROM public.pilot_admin_events p LEFT JOIN public.organizations o ON o.id=p.org_id WHERE p.id=reference::uuid;
 END IF;
 IF result IS NULL THEN RAISE EXCEPTION 'Event unavailable' USING ERRCODE='P0002'; END IF;
 RETURN result;
END $$;

CREATE FUNCTION app_platform_operators() RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM public.app_platform_require('platform.owner');
 RETURN coalesce((SELECT jsonb_agg(row_to_json(x)) FROM (
  SELECT g.user_id,u.name,u.email,u.email_verified_at IS NOT NULL AS verified,g.permissions,g.version,g.revoked_at,
   u.admin_totp_confirmed_at IS NOT NULL AS mfa_ready,g.user_id=public.app_actor() AS self,public.app_account_suspended(g.user_id) AS suspended,
   (g.revoked_at IS NULL AND 'platform.owner'=ANY(g.permissions) AND NOT EXISTS(
     SELECT 1 FROM public.platform_grants h JOIN public.users v ON v.id=h.user_id WHERE h.user_id<>g.user_id AND h.revoked_at IS NULL
      AND v.email_verified_at IS NOT NULL AND v.admin_totp_confirmed_at IS NOT NULL AND 'platform.owner'=ANY(h.permissions))) AS last_owner
  FROM public.platform_grants g JOIN public.users u ON u.id=g.user_id ORDER BY g.revoked_at IS NOT NULL,u.name,g.user_id) x),'[]'::jsonb);
END $$;

-- Grant, change or revoke another operator's access. An owner cannot alter their own grant here, and the
-- last usable owner can never be removed; the first owner is still provisioned offline.
CREATE FUNCTION app_platform_grant_save(target bigint,expected integer,capabilities text[],revoke boolean,explanation text) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE old public.platform_grants; next_version integer;
BEGIN
 LOCK TABLE public.platform_grants IN SHARE ROW EXCLUSIVE MODE;
 PERFORM public.app_platform_require('platform.owner');
 IF explanation IS NULL OR length(trim(explanation)) NOT BETWEEN 1 AND 240 OR target IS NULL OR expected IS NULL OR expected<0 THEN
  RAISE EXCEPTION 'Operator, version and reason required' USING ERRCODE='22000'; END IF;
 IF target=public.app_actor() THEN RAISE EXCEPTION 'An owner cannot change their own grant' USING ERRCODE='22000'; END IF;
 IF NOT EXISTS(SELECT 1 FROM public.users WHERE id=target AND email_verified_at IS NOT NULL) THEN RAISE EXCEPTION 'Existing verified identity required' USING ERRCODE='22000'; END IF;
 IF NOT revoke AND (capabilities IS NULL OR cardinality(capabilities)=0 OR NOT capabilities <@ ARRAY['platform.owner','directory.read','plans.assign','notifications.read','notifications.reconcile','operations.read','audit.read','access.manage','support.request','cleanup.review']::text[]) THEN
  RAISE EXCEPTION 'Explicit capabilities required' USING ERRCODE='22000'; END IF;
 SELECT * INTO old FROM public.platform_grants WHERE user_id=target FOR UPDATE;
 IF coalesce(old.version,0)<>expected THEN RAISE EXCEPTION 'Grant changed. Reload before saving.' USING ERRCODE='40001'; END IF;
 IF revoke AND (old.user_id IS NULL OR old.revoked_at IS NOT NULL) THEN RAISE EXCEPTION 'Grant changed. Reload before saving.' USING ERRCODE='40001'; END IF;
 -- Last-owner protection: the caller passed the platform.owner gate, so is a usable owner (verified, authenticator
 -- confirmed, active grant), and cannot be the target. Any change here therefore leaves at least that owner usable.
 next_version := coalesce(old.version,0)+1;
 INSERT INTO public.platform_grants(user_id,permissions,version,revoked_at)
  VALUES(target,CASE WHEN revoke THEN old.permissions ELSE (SELECT array_agg(DISTINCT c ORDER BY c) FROM unnest(capabilities) c) END,next_version,CASE WHEN revoke THEN clock_timestamp() END)
 ON CONFLICT(user_id) DO UPDATE SET permissions=excluded.permissions,version=excluded.version,revoked_at=excluded.revoked_at;
 -- A changed grant invalidates every existing session for that operator, as the offline procedure does.
 DELETE FROM public.sessions WHERE user_id=target;
 INSERT INTO public.platform_audit(actor_id,event,target,reason)
  VALUES(public.app_actor(),CASE WHEN revoke THEN 'grant.revoked' WHEN old.user_id IS NULL OR old.revoked_at IS NOT NULL THEN 'grant.assigned' ELSE 'grant.changed' END,target::text,trim(explanation));
 RETURN next_version;
END $$;

-- Each figure is null when the caller's capabilities do not cover it; the UI shows "Unavailable", never zero.
CREATE FUNCTION app_platform_overview() RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
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
  'operators_without_mfa',CASE WHEN owner THEN (SELECT count(*) FROM public.platform_grants g JOIN public.users u ON u.id=g.user_id WHERE g.revoked_at IS NULL AND u.admin_totp_confirmed_at IS NULL) END,
  'activity',CASE WHEN public.app_platform_allowed('audit.read') THEN coalesce((SELECT jsonb_agg(row_to_json(e)) FROM (
    SELECT a.id::text AS id,a.occurred_at,a.event,a.target,coalesce(u.name,a.operator) AS actor FROM public.platform_audit a LEFT JOIN public.users u ON u.id=a.actor_id
    WHERE a.event<>'request.denied' ORDER BY a.occurred_at DESC,a.id DESC LIMIT 8) e),'[]'::jsonb) END);
END $$;

REVOKE ALL ON FUNCTION app_platform_require(text),app_platform_any(),app_entitlement_state(uuid),app_like(text),app_platform_accounts(jsonb,integer),app_platform_activity(text,uuid),
 app_platform_account(bigint),app_platform_workspaces(jsonb,integer),app_platform_workspace(uuid),app_platform_plans(text,integer),app_platform_assignments(jsonb,integer),
 app_platform_plan(uuid),app_platform_notification_list(jsonb,integer),app_platform_notification(uuid),app_job_name(text),app_record_observation(text),app_platform_operations(),
 app_platform_audit_list(jsonb,integer),app_platform_audit_event(text,text),app_platform_operators(),app_platform_grant_save(bigint,integer,text[],boolean,text),app_platform_overview() FROM PUBLIC;
-- Helpers that read across tenants (entitlement state, activity, require) are reachable only through the gated functions above.
GRANT EXECUTE ON FUNCTION app_platform_any(),app_platform_accounts(jsonb,integer),app_platform_account(bigint),app_platform_workspaces(jsonb,integer),app_platform_workspace(uuid),
 app_platform_plans(text,integer),app_platform_assignments(jsonb,integer),app_platform_plan(uuid),app_platform_notification_list(jsonb,integer),app_platform_notification(uuid),
 app_record_observation(text),app_platform_operations(),app_platform_audit_list(jsonb,integer),app_platform_audit_event(text,text),app_platform_operators(),
 app_platform_grant_save(bigint,integer,text[],boolean,text),app_platform_overview() TO weavdash_app;
