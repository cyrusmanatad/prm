-- Compact administration Assignments and Support access: chip counts. Definitions are otherwise those in
-- platform-console.sql (app_platform_assignments) and platform-governance.sql (app_platform_support_list).

-- ---------------------------------------------------------------------------------------------
-- Assignments: counts for each view (current, active, scheduled, expired, superseded and the full history).
-- They follow the workspace and plan filters but not the state and history filters.
CREATE OR REPLACE FUNCTION app_platform_assignments(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
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
 SELECT jsonb_build_object('total',(SELECT count(*) FROM filtered),
  'facets',(SELECT jsonb_build_object('all',count(*),'current',count(*) FILTER (WHERE selected),'active',count(*) FILTER (WHERE state='active'),
    'scheduled',count(*) FILTER (WHERE state='scheduled'),'expired',count(*) FILTER (WHERE state='expired'),'superseded',count(*) FILTER (WHERE state='superseded')) FROM matched),
  'rows',coalesce((SELECT jsonb_agg(row_to_json(x)) FROM (
  SELECT * FROM filtered ORDER BY created_at DESC,id LIMIT 25 OFFSET greatest(page_number-1,0)*25) x),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

-- ---------------------------------------------------------------------------------------------
-- Support access: a count per state that follows the workspace filter but not the state filter.
CREATE OR REPLACE FUNCTION app_platform_support_list(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; wanted text := filters->>'state'; target uuid := nullif(filters->>'workspace','')::uuid;
BEGIN
 PERFORM public.app_platform_require('support.request');
 WITH scoped AS (SELECT g.*,public.app_support_state(g) AS current_state FROM public.support_grants g WHERE target IS NULL OR g.org_id=target),
 matched AS (SELECT * FROM scoped WHERE wanted IS NULL OR current_state=wanted)
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),
  'facets',(SELECT jsonb_build_object('all',count(*),'requested',count(*) FILTER (WHERE current_state='requested'),'approved',count(*) FILTER (WHERE current_state='approved'),
    'declined',count(*) FILTER (WHERE current_state='declined'),'revoked',count(*) FILTER (WHERE current_state='revoked'),
    'cancelled',count(*) FILTER (WHERE current_state='cancelled'),'expired',count(*) FILTER (WHERE current_state='expired')) FROM scoped),
  'rows',coalesce((SELECT jsonb_agg(public.app_support_row(g) ORDER BY g.requested_at DESC,g.id) FROM public.support_grants g WHERE g.id IN (
  SELECT id FROM matched ORDER BY requested_at DESC,id LIMIT 25 OFFSET greatest(page_number-1,0)*25)),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;
