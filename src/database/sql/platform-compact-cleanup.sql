-- Compact administration Data cleanup: each batch's latest dry run. The definition is otherwise that in
-- platform-governance.sql (app_cleanup_batches). Cleanup stays dry-run only: nothing here deletes or executes.

-- ---------------------------------------------------------------------------------------------
-- Fixture batches: the latest dry run, whether marks changed after it, and its eligible, blocked and protected counts.
CREATE OR REPLACE FUNCTION app_cleanup_batches() RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM public.app_platform_require('cleanup.review');
 RETURN coalesce((SELECT jsonb_agg(row_to_json(x)) FROM (
  SELECT b.id,b.name,b.reason,b.created_at,(SELECT name FROM public.users WHERE id=b.created_by) AS created_by,
   (SELECT count(*) FROM public.fixture_marks m WHERE m.batch_id=b.id) AS marks,(SELECT count(*) FROM public.cleanup_runs r WHERE r.batch_id=b.id) AS runs,
   (SELECT jsonb_build_object('id',r.id,'created_at',r.created_at,'eligible',r.eligible,'blocked',r.blocked,'protected',r.protected,'stale',r.batch_version<>b.version)
    FROM public.cleanup_runs r WHERE r.batch_id=b.id ORDER BY r.created_at DESC,r.id LIMIT 1) AS latest_run
  FROM public.fixture_batches b ORDER BY b.created_at DESC,b.id LIMIT 100) x),'[]'::jsonb);
END $$;
