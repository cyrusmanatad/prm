DO $$ BEGIN
 IF (SELECT count(*) FROM pg_class WHERE relname IN ('automation_preferences','business_schedules','schedule_runs','automation_consent_events') AND relrowsecurity AND relforcerowsecurity)<>4 THEN
  RAISE EXCEPTION 'Scheduling tables need forced RLS';
 END IF;
 IF has_function_privilege('weavdash_app','app_schedule_basis(business_schedules)','EXECUTE') THEN
  RAISE EXCEPTION 'Internal scheduling predicate exposed';
 END IF;
 IF EXISTS (SELECT 1 FROM pg_proc p CROSS JOIN LATERAL aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a
   WHERE p.proname IN ('app_schedule_basis','app_schedule_current','app_reconcile_schedules','app_due_schedules') AND a.grantee=0 AND a.privilege_type='EXECUTE') THEN
  RAISE EXCEPTION 'Scheduling functions publicly executable';
 END IF;
 IF EXISTS (SELECT 1 FROM pg_policy WHERE polrelid='automation_consent_events'::regclass AND polcmd IN ('w','d','*')) THEN
  RAISE EXCEPTION 'Consent history is mutable';
 END IF;
 IF NOT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='schedule_runs'::regclass AND contype='f' AND cardinality(conkey)=3) THEN
  RAISE EXCEPTION 'Run routing lacks composite constraint';
 END IF;
END $$;
SELECT 'SCHEDULE_SCHEMA_CHECKS_PASSED';
