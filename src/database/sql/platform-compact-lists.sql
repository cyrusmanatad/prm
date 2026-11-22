-- Compact administration lists: Workspaces, Notifications and Audit gain chip counts, and audit targets are
-- named the same way as on the Overview. Definitions are otherwise those in platform-followup.sql
-- (app_platform_workspaces), brand-approval.sql (app_platform_notification_list), platform-console.sql
-- (app_platform_audit_list) and platform-compact-console.sql (app_platform_overview).

-- ---------------------------------------------------------------------------------------------
-- The kind of record an administration action touched, from its event, and the account or workspace name
-- when `named` (the caller holds directory.read). Internal: only the gated projections call it.
CREATE FUNCTION app_platform_audit_target(event text,target text,named boolean,OUT kind text,OUT name text) LANGUAGE plpgsql STABLE SET search_path=pg_catalog,public AS $$
BEGIN
 kind := CASE
  WHEN event LIKE 'account.%' OR event LIKE 'grant.%' OR event LIKE 'mfa.%' THEN 'account'
  WHEN event LIKE 'workspace.%' OR event='plan.assigned' THEN 'workspace'
  WHEN event LIKE 'support.%' THEN 'support'
  WHEN event LIKE 'brand_request.%' THEN 'brand_request'
  WHEN event LIKE 'cleanup.%' THEN 'cleanup'
  WHEN event LIKE 'notification.%' THEN 'notification' END;
 IF named THEN
  name := CASE kind
   WHEN 'account' THEN (SELECT x.name FROM public.users x WHERE target ~ '^[0-9]{1,18}$' AND x.id=target::bigint)
   WHEN 'workspace' THEN (SELECT o.name FROM public.organizations o WHERE o.id::text=target)
   WHEN 'support' THEN (SELECT o.name FROM public.support_grants s JOIN public.organizations o ON o.id=s.org_id WHERE s.id::text=target) END;
 END IF;
END $$;
REVOKE ALL ON FUNCTION app_platform_audit_target(text,text,boolean) FROM PUBLIC;

-- ---------------------------------------------------------------------------------------------
-- Workspaces: type counts (all, creator, Brand) that follow the search, created dates, entitlement and access
-- filters but not the type filter, so each chip says what selecting it would show.
CREATE OR REPLACE FUNCTION app_platform_workspaces(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; term text := nullif(trim(filters->>'q'),''); wanted_kind text := filters->>'kind'; wanted_state text := filters->>'state'; access text := filters->>'access';
 since timestamptz := nullif(filters->>'created_from','')::timestamptz; until timestamptz := nullif(filters->>'created_to','')::timestamptz;
BEGIN
 PERFORM public.app_platform_require('directory.read');
 WITH scoped AS (
  SELECT o.id,o.name,o.kind,o.experience,o.created_at,public.app_entitlement_state(o.id) AS entitlement_state,public.app_workspace_suspended(o.id) AS suspended
  FROM public.organizations o
  WHERE (term IS NULL OR o.name ILIKE public.app_like(term) OR o.id::text=lower(term))
   AND (since IS NULL OR o.created_at>=since) AND (until IS NULL OR o.created_at<until)
 ), filtered AS (
  SELECT * FROM scoped
  WHERE (wanted_state IS NULL OR entitlement_state=wanted_state) AND (access IS NULL OR (access='suspended')=suspended)
 ), matched AS (
  SELECT * FROM filtered WHERE wanted_kind IS NULL OR kind=wanted_kind
 )
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),
  'facets',(SELECT jsonb_build_object('all',count(*),'creator',count(*) FILTER (WHERE kind='creator'),'brand',count(*) FILTER (WHERE kind='brand')) FROM filtered),
  'rows',coalesce((SELECT jsonb_agg(row_to_json(p)) FROM (
  SELECT m.*,
   (SELECT count(*) FROM public.memberships x WHERE x.org_id=m.id AND x.revoked_at IS NULL) AS members,
   coalesce((SELECT jsonb_agg(y.name ORDER BY y.name) FROM (SELECT u.name FROM public.memberships x JOIN public.users u ON u.id=x.user_id
     WHERE x.org_id=m.id AND x.role='owner' AND x.revoked_at IS NULL ORDER BY u.name LIMIT 3) y),'[]'::jsonb) AS owners,
   (SELECT count(*) FROM public.memberships x WHERE x.org_id=m.id AND x.role='owner' AND x.revoked_at IS NULL) AS owner_count,
   (SELECT p.name||' v'||p.version FROM public.pilot_assignments a JOIN public.pilot_plan_versions p ON p.id=a.plan_id WHERE a.org_id=m.id ORDER BY a.version DESC LIMIT 1) AS plan
  FROM matched m ORDER BY m.name,m.id LIMIT 25 OFFSET greatest(page_number-1,0)*25) p),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

-- ---------------------------------------------------------------------------------------------
-- Notifications: a count per delivery state that follows the search and workspace but not the state filter.
CREATE OR REPLACE FUNCTION app_platform_notification_list(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; target_org uuid := nullif(filters->>'workspace','')::uuid; wanted_state text := filters->>'state'; term text := nullif(trim(filters->>'q'),'');
BEGIN
 PERFORM public.app_platform_require('notifications.read');
 IF wanted_state IS NOT NULL AND wanted_state NOT IN ('pending','sending','sent','captured','cancelled','uncertain') THEN RAISE EXCEPTION 'Invalid state' USING ERRCODE='22000'; END IF;
 WITH unified AS (
  SELECT n.id,n.org_id,o.name AS workspace,NULL::bigint AS account_id,NULL::text AS account,n.kind::text AS kind,n.state::text AS state,n.created_at,n.started_at,n.completed_at,n.available_at,n.defer_count,
   n.failure_code::text AS failure_code,r.resolution::text AS resolution,r.resolved_at,coalesce(r.version,0) AS resolution_version
  FROM public.notification_outbox n JOIN public.organizations o ON o.id=n.org_id LEFT JOIN public.notification_resolutions r ON r.notification_id=n.id
  UNION ALL
  SELECT a.id,NULL::uuid,NULL::text,a.user_id,u.name::text,a.kind::text,a.state::text,a.created_at,a.started_at,a.completed_at,a.available_at,a.defer_count,
   a.failure_code::text,r.resolution::text,r.resolved_at,coalesce(r.version,0)
  FROM public.account_notification_outbox a JOIN public.users u ON u.id=a.user_id LEFT JOIN public.account_notification_resolutions r ON r.notification_id=a.id
 ), scoped AS (
  SELECT * FROM unified x
  WHERE (target_org IS NULL OR x.org_id=target_org)
   AND (term IS NULL OR x.workspace ILIKE public.app_like(term) OR x.account ILIKE public.app_like(term) OR x.id::text=lower(term) OR x.org_id::text=lower(term))
 ), matched AS (
  SELECT * FROM scoped WHERE wanted_state IS NULL OR state=wanted_state
 )
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),
  'facets',(SELECT jsonb_build_object('all',count(*),'pending',count(*) FILTER (WHERE state='pending'),'sending',count(*) FILTER (WHERE state='sending'),
    'sent',count(*) FILTER (WHERE state='sent'),'captured',count(*) FILTER (WHERE state='captured'),'cancelled',count(*) FILTER (WHERE state='cancelled'),
    'uncertain',count(*) FILTER (WHERE state='uncertain')) FROM scoped),
  'rows',coalesce((SELECT jsonb_agg(row_to_json(x)) FROM (
  SELECT * FROM matched ORDER BY created_at DESC,id DESC LIMIT 25 OFFSET greatest(page_number-1,0)*25) x),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

-- ---------------------------------------------------------------------------------------------
-- Audit: outcome counts that follow the search, source and dates; platform rows name their target like the Overview.
CREATE OR REPLACE FUNCTION app_platform_audit_list(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; term text := nullif(trim(filters->>'q'),''); wanted_source text := filters->>'source'; wanted_outcome text := filters->>'outcome';
 since timestamptz := nullif(filters->>'from','')::timestamptz; until timestamptz := nullif(filters->>'to','')::timestamptz; directory boolean;
BEGIN
 PERFORM public.app_platform_require('audit.read');
 directory := public.app_platform_allowed('directory.read');
 WITH events AS (
  SELECT 'platform' AS source,a.id::text AS id,a.occurred_at,a.actor_id,coalesce(u.name,a.operator) AS actor,u.email AS actor_email,a.event,a.target,
   CASE WHEN a.event='request.denied' THEN 'denied' ELSE 'recorded' END AS outcome,NULL::uuid AS org_id
  FROM public.platform_audit a LEFT JOIN public.users u ON u.id=a.actor_id
  UNION ALL
  SELECT 'local-pilot',p.id::text,p.created_at,NULL,p.operator,NULL,p.event,p.subject_id::text,NULL,p.org_id FROM public.pilot_admin_events p
 ), scoped AS (
  SELECT e.*,o.name AS workspace FROM events e LEFT JOIN public.organizations o ON o.id=e.org_id
  WHERE (term IS NULL OR e.event ILIKE public.app_like(term) OR e.target=term OR e.actor ILIKE public.app_like(term) OR e.actor_email ILIKE public.app_like(term))
   AND (wanted_source IS NULL OR e.source=wanted_source)
   AND (since IS NULL OR e.occurred_at>=since) AND (until IS NULL OR e.occurred_at<until)
 ), matched AS (
  SELECT * FROM scoped WHERE wanted_outcome IS NULL OR outcome=wanted_outcome
 )
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),
  'facets',(SELECT jsonb_build_object('all',count(*),'recorded',count(*) FILTER (WHERE outcome='recorded'),'denied',count(*) FILTER (WHERE outcome='denied')) FROM scoped),
  'rows',coalesce((SELECT jsonb_agg(row_to_json(x)) FROM (
  SELECT m.*,CASE WHEN m.source='platform' THEN t.kind END AS target_kind,CASE WHEN m.source='platform' THEN t.name END AS target_name
  FROM (SELECT * FROM matched ORDER BY occurred_at DESC,id DESC LIMIT 25 OFFSET greatest(page_number-1,0)*25) m
  CROSS JOIN LATERAL public.app_platform_audit_target(m.event,m.target,directory) t ORDER BY m.occurred_at DESC,m.id DESC) x),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

-- ---------------------------------------------------------------------------------------------
-- Overview: recent activity uses the shared target helper. Otherwise as in platform-compact-console.sql.
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
  'pending_notices',CASE WHEN notices THEN (SELECT count(*) FROM public.notification_outbox WHERE state='pending')+(SELECT count(*) FROM public.account_notification_outbox WHERE state='pending') END,
  'uncertain_sends',CASE WHEN notices THEN (SELECT count(*) FROM public.notification_outbox n WHERE n.state='uncertain' AND NOT EXISTS(SELECT 1 FROM public.notification_resolutions r WHERE r.notification_id=n.id))
   +(SELECT count(*) FROM public.account_notification_outbox n WHERE n.state='uncertain' AND NOT EXISTS(SELECT 1 FROM public.account_notification_resolutions r WHERE r.notification_id=n.id)) END,
  'failed_jobs',CASE WHEN operations THEN (SELECT count(*) FROM public.failed_jobs WHERE failed_at>(clock_timestamp()-interval '24 hours')) END,
  'scheduler_age_seconds',CASE WHEN operations THEN (SELECT floor(extract(epoch FROM clock_timestamp()-s.observed_at))::bigint FROM public.platform_observations s WHERE s.source='scheduler') END,
  'scheduler_visible',operations,
  'long_suspensions',CASE WHEN public.app_platform_allowed('access.manage') THEN (SELECT count(*) FROM public.platform_suspensions WHERE lifted_at IS NULL AND suspended_at<(clock_timestamp()-interval '30 days')) END,
  'support_awaiting',CASE WHEN public.app_platform_allowed('support.request') THEN (SELECT count(*) FROM public.support_grants WHERE state='requested') END,
  'support_expiring',CASE WHEN public.app_platform_allowed('support.request') THEN (SELECT count(*) FROM public.support_grants WHERE state='approved' AND expires_at>clock_timestamp() AND expires_at<=(clock_timestamp()+interval '2 hours')) END,
  'cleanup_blocked',CASE WHEN public.app_platform_allowed('cleanup.review') THEN (SELECT count(*) FROM public.fixture_batches b WHERE EXISTS(SELECT 1 FROM public.cleanup_runs r WHERE r.batch_id=b.id AND r.batch_version=b.version AND r.blocked>0
    AND r.created_at=(SELECT max(x.created_at) FROM public.cleanup_runs x WHERE x.batch_id=b.id))) END,
  'brand_requests_pending',CASE WHEN public.app_platform_allowed('brands.review') THEN (SELECT count(*) FROM public.brand_requests WHERE status='pending') END,
  'brands_unrecorded',CASE WHEN public.app_platform_allowed('brands.review') THEN (SELECT count(*) FROM public.organizations o WHERE o.kind='brand'
    AND NOT EXISTS(SELECT 1 FROM public.brand_requests r WHERE r.org_id=o.id AND r.status='approved')) END,
  'operators_without_mfa',CASE WHEN owner THEN (SELECT count(*) FROM public.platform_grants g JOIN public.users u ON u.id=g.user_id WHERE g.revoked_at IS NULL AND u.admin_totp_confirmed_at IS NULL) END,
  'activity',CASE WHEN public.app_platform_allowed('audit.read') THEN coalesce((SELECT jsonb_agg(row_to_json(e)) FROM (
    SELECT a.id::text AS id,a.occurred_at,a.event,a.target,coalesce(u.name,a.operator) AS actor,t.kind AS target_kind,t.name AS target_name
    FROM public.platform_audit a LEFT JOIN public.users u ON u.id=a.actor_id
    CROSS JOIN LATERAL public.app_platform_audit_target(a.event,a.target,directory) t
    WHERE a.event<>'request.denied' ORDER BY a.occurred_at DESC,a.id DESC LIMIT 8) e),'[]'::jsonb) END);
END $$;
