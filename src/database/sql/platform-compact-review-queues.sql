-- Compact administration Brand requests and Privacy requests: status counts. Definitions are otherwise those in
-- brand-approval.sql (app_platform_brand_requests) and privacy-closures.sql (app_privacy_review_list).

-- ---------------------------------------------------------------------------------------------
-- Brand requests: a count per status that follows the search and source but not the status filter.
CREATE OR REPLACE FUNCTION app_platform_brand_requests(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; term text := nullif(trim(filters->>'q'),''); wanted_status text := filters->>'status'; wanted_source text := filters->>'source';
BEGIN
 PERFORM public.app_platform_require('brands.review');
 WITH scoped AS (
  SELECT r.id,r.source,r.status,r.version,coalesce(r.brand_name,o.name) AS brand_name,r.submitted_at,r.updated_at,r.decided_at,r.org_id,
   r.applicant_id,u.name AS applicant,u.email AS applicant_email
  FROM public.brand_requests r LEFT JOIN public.users u ON u.id=r.applicant_id LEFT JOIN public.organizations o ON o.id=r.org_id
  WHERE (wanted_source IS NULL OR r.source=wanted_source)
   AND (term IS NULL OR coalesce(r.brand_name,o.name) ILIKE public.app_like(term) OR u.name ILIKE public.app_like(term) OR u.email ILIKE public.app_like(term) OR r.id::text=lower(term))
 ), matched AS (
  SELECT * FROM scoped WHERE wanted_status IS NULL OR status=wanted_status
 )
 -- Requests awaiting a decision come first, longest wait first; everything else follows, newest first.
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),'pending',(SELECT count(*) FROM public.brand_requests WHERE status='pending'),
  'facets',(SELECT jsonb_build_object('all',count(*),'pending',count(*) FILTER (WHERE status='pending'),'needs_information',count(*) FILTER (WHERE status='needs_information'),
    'approved',count(*) FILTER (WHERE status='approved'),'declined',count(*) FILTER (WHERE status='declined')) FROM scoped),
  'rows',coalesce((SELECT jsonb_agg(row_to_json(p)) FROM (
   SELECT * FROM matched ORDER BY (status<>'pending'),CASE WHEN status='pending' THEN extract(epoch FROM updated_at) ELSE -extract(epoch FROM updated_at) END,id
   LIMIT 25 OFFSET greatest(page_number-1,0)*25) p),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

-- ---------------------------------------------------------------------------------------------
-- Privacy requests: summary counts of requests awaiting review, with a review recorded, and cancelled.
CREATE OR REPLACE FUNCTION app_privacy_review_list(page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM public.app_platform_require('platform.owner');
 IF page_number IS NULL OR page_number NOT BETWEEN 1 AND 100000 THEN RAISE EXCEPTION 'Invalid page' USING ERRCODE='22000'; END IF;
 RETURN jsonb_build_object('total',(SELECT count(*) FROM public.privacy_closure_requests),
  'summary',(SELECT jsonb_build_object('awaiting',count(*) FILTER (WHERE state='cooling_off' AND reviewed_at IS NULL),
    'reviewed',count(*) FILTER (WHERE state='cooling_off' AND reviewed_at IS NOT NULL),'cancelled',count(*) FILTER (WHERE state='cancelled'),
    'unassigned',count(*) FILTER (WHERE state='cooling_off' AND assigned_to IS NULL)) FROM public.privacy_closure_requests),
  'rows',coalesce((SELECT jsonb_agg(row_to_json(x)) FROM (
  SELECT c.id,c.scope,c.subject_label,c.state,c.version,c.reason,c.requested_at,c.not_before,c.reviewed_at,c.assigned_to,
   u.name AS requested_by,u.email AS requester_email,(SELECT name FROM public.users WHERE id=c.assigned_to) AS handler,
   public.app_privacy_counts(c.manifest) AS counts
  FROM public.privacy_closure_requests c JOIN public.users u ON u.id=c.requested_by ORDER BY (c.state='cooling_off') DESC,c.requested_at,c.id LIMIT 20 OFFSET (page_number-1)*20) x),'[]'::jsonb));
END $$;
