-- Compact administration Overview and Accounts. Definitions are otherwise those in platform-followup.sql
-- (app_platform_accounts) and brand-approval.sql (app_platform_overview).

-- ---------------------------------------------------------------------------------------------
-- Accounts: chip counts by access, verification and platform grant. Counts follow the search and joined
-- dates but not the access, verification and grant filters, so each chip says what selecting it would show.
CREATE OR REPLACE FUNCTION app_platform_accounts(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; term text := nullif(trim(filters->>'q'),''); verified text := filters->>'verified'; granted text := filters->>'grant'; access text := filters->>'access';
 since timestamptz := nullif(filters->>'created_from','')::timestamptz; until timestamptz := nullif(filters->>'created_to','')::timestamptz;
BEGIN
 PERFORM public.app_platform_require('directory.read');
 WITH scoped AS (
  SELECT u.id,u.name,u.email,u.email_verified_at,u.created_at,
   (SELECT count(*) FROM public.memberships m WHERE m.user_id=u.id AND m.revoked_at IS NULL) AS workspaces,
   EXISTS(SELECT 1 FROM public.platform_grants g WHERE g.user_id=u.id AND g.revoked_at IS NULL) AS platform_access,
   public.app_account_suspended(u.id) AS suspended
  FROM public.users u
  WHERE (term IS NULL OR u.name ILIKE public.app_like(term) OR u.email ILIKE public.app_like(term) OR u.id::text=term)
   AND (since IS NULL OR u.created_at>=since) AND (until IS NULL OR u.created_at<until)
 ), matched AS (
  SELECT * FROM scoped
  WHERE (verified IS NULL OR (verified='yes')=(email_verified_at IS NOT NULL))
   AND (granted IS NULL OR (granted='yes')=platform_access)
   AND (access IS NULL OR (access='suspended')=suspended)
 )
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),
  'facets',(SELECT jsonb_build_object('all',count(*),'active',count(*) FILTER (WHERE NOT suspended),'suspended',count(*) FILTER (WHERE suspended),
    'unverified',count(*) FILTER (WHERE email_verified_at IS NULL),'granted',count(*) FILTER (WHERE platform_access)) FROM scoped),
  'rows',coalesce((SELECT jsonb_agg(row_to_json(p)) FROM (
  SELECT * FROM matched ORDER BY name,id LIMIT 25 OFFSET greatest(page_number-1,0)*25) p),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

-- ---------------------------------------------------------------------------------------------
-- Overview: each recent action states what kind of record it touched, and the account or workspace name
-- when the operator may read the directory. Other targets keep only their kind and reference.
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
    SELECT a.id::text AS id,a.occurred_at,a.event,a.target,coalesce(u.name,a.operator) AS actor,t.kind AS target_kind,
     CASE WHEN directory THEN CASE t.kind
      WHEN 'account' THEN (SELECT x.name FROM public.users x WHERE a.target ~ '^[0-9]{1,18}$' AND x.id=a.target::bigint)
      WHEN 'workspace' THEN (SELECT o.name FROM public.organizations o WHERE o.id::text=a.target)
      WHEN 'support' THEN (SELECT o.name FROM public.support_grants s JOIN public.organizations o ON o.id=s.org_id WHERE s.id::text=a.target) END END AS target_name
    FROM public.platform_audit a LEFT JOIN public.users u ON u.id=a.actor_id
    CROSS JOIN LATERAL (SELECT CASE
     WHEN a.event LIKE 'account.%' OR a.event LIKE 'grant.%' OR a.event LIKE 'mfa.%' THEN 'account'
     WHEN a.event LIKE 'workspace.%' OR a.event='plan.assigned' THEN 'workspace'
     WHEN a.event LIKE 'support.%' THEN 'support'
     WHEN a.event LIKE 'brand_request.%' THEN 'brand_request'
     WHEN a.event LIKE 'cleanup.%' THEN 'cleanup'
     WHEN a.event LIKE 'notification.%' THEN 'notification' END AS kind) t
    WHERE a.event<>'request.denied' ORDER BY a.occurred_at DESC,a.id DESC LIMIT 8) e),'[]'::jsonb) END);
END $$;
