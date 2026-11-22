-- Platform access, one page at a time. Rows carry the same fields as app_platform_operators() (platform-console.sql),
-- which stays as it is. Counts per view follow the search but not the view.

CREATE OR REPLACE FUNCTION app_platform_operator_list(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; term text := nullif(trim(filters->>'q'),''); wanted text := filters->>'view';
 pin bigint := CASE WHEN filters->>'pin' ~ '^[0-9]{1,18}$' THEN (filters->>'pin')::bigint END;
BEGIN
 PERFORM public.app_platform_require('platform.owner');
 WITH scoped AS (
  SELECT g.user_id,u.name,u.email,u.email_verified_at IS NOT NULL AS verified,g.permissions,g.version,g.revoked_at,
   u.admin_totp_confirmed_at IS NOT NULL AS mfa_ready,g.user_id=public.app_actor() AS self,public.app_account_suspended(g.user_id) AS suspended,
   (g.revoked_at IS NULL AND 'platform.owner'=ANY(g.permissions) AND NOT EXISTS(
     SELECT 1 FROM public.platform_grants h JOIN public.users v ON v.id=h.user_id WHERE h.user_id<>g.user_id AND h.revoked_at IS NULL
      AND v.email_verified_at IS NOT NULL AND v.admin_totp_confirmed_at IS NOT NULL AND 'platform.owner'=ANY(h.permissions))) AS last_owner
  FROM public.platform_grants g JOIN public.users u ON u.id=g.user_id
  WHERE term IS NULL OR u.name ILIKE public.app_like(term) OR u.email ILIKE public.app_like(term) OR g.user_id::text=term
 ), matched AS (
  SELECT * FROM scoped WHERE wanted IS NULL OR (wanted='active' AND revoked_at IS NULL)
   OR (wanted='unenrolled' AND revoked_at IS NULL AND NOT mfa_ready) OR (wanted='revoked' AND revoked_at IS NOT NULL)
 )
 -- Your own grant first, then the operator just changed or reached from an account page, then active by name, revoked last.
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),
  'facets',(SELECT jsonb_build_object('all',count(*),'active',count(*) FILTER (WHERE revoked_at IS NULL),
    'unenrolled',count(*) FILTER (WHERE revoked_at IS NULL AND NOT mfa_ready),'revoked',count(*) FILTER (WHERE revoked_at IS NOT NULL)) FROM scoped),
  'rows',coalesce((SELECT jsonb_agg(row_to_json(p)) FROM (
   SELECT * FROM matched ORDER BY self DESC,(user_id=pin) IS TRUE DESC,revoked_at IS NOT NULL,name,user_id
   LIMIT 25 OFFSET greatest(page_number-1,0)*25) p),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

REVOKE ALL ON FUNCTION app_platform_operator_list(jsonb,integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_platform_operator_list(jsonb,integer) TO weavdash_app;
