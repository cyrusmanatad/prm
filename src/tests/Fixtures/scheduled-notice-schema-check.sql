DO $$ BEGIN
 IF NOT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='notification_outbox'::regclass
  AND conname='notification_schedule_run' AND confrelid='schedule_runs'::regclass AND cardinality(conkey)=3)
 OR NOT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='notification_outbox'::regclass
  AND contype='u' AND pg_get_constraintdef(oid)='UNIQUE (schedule_run_id)') THEN
  RAISE EXCEPTION 'Missing run ownership/deduplication constraints';
 END IF;
 IF EXISTS(SELECT 1 FROM pg_proc p, LATERAL aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a
  WHERE p.proname IN ('app_ready_schedule_notices','app_schedule_notice_current','app_schedule_notice_basis')
  AND a.grantee=0 AND a.privilege_type='EXECUTE')
 OR has_function_privilege('weavdash_app','app_schedule_notice_basis(notification_outbox)','EXECUTE') THEN
  RAISE EXCEPTION 'Unsafe scheduled notice predicate grants';
 END IF;
 IF (SELECT count(*) FROM pg_proc WHERE proname IN ('app_ready_schedule_notices','app_schedule_notice_current','app_schedule_notice_basis')
  AND prosecdef AND proowner='weavdash_migrator'::regrole AND proconfig @> ARRAY['search_path=pg_catalog, public'])<>3 THEN
  RAISE EXCEPTION 'Unsafe scheduled notice security definer';
 END IF;
END $$;
SELECT 'SCHEDULED_NOTICE_SCHEMA_CHECKS_PASSED';
