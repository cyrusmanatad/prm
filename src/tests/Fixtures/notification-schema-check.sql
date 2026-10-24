DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='notification_outbox'::regclass
        AND conname='notification_onboarding_target' AND contype='f' AND cardinality(conkey)=2)
        OR NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='notification_outbox'::regclass
        AND conname='notification_onboarding_generation' AND contype='u') THEN
        RAISE EXCEPTION 'missing onboarding notification target/generation constraints';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_class WHERE oid='notification_outbox'::regclass
        AND relrowsecurity AND relforcerowsecurity AND relowner='weavdash_migrator'::regrole) THEN
        RAISE EXCEPTION 'missing migration-owned forced notification RLS';
    END IF;
    IF (SELECT count(*) FROM pg_policy WHERE polrelid='notification_outbox'::regclass) <> 3
        OR EXISTS (SELECT 1 FROM pg_policy WHERE polrelid='notification_outbox'::regclass AND polcmd NOT IN ('r','a','w')) THEN
        RAISE EXCEPTION 'unexpected notification policies';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='notification_outbox'::regclass
        AND confrelid='partner_invitations'::regclass AND contype='f' AND cardinality(conkey)=2) THEN
        RAISE EXCEPTION 'missing composite notification target';
    END IF;
    IF (SELECT count(*) FROM pg_proc WHERE proname IN ('app_lock_notification_invitation','app_pending_notifications','app_reconcile_notifications')
        AND prosecdef AND proowner='weavdash_migrator'::regrole AND proconfig @> ARRAY['search_path=pg_catalog, public']) <> 3 THEN
        RAISE EXCEPTION 'unsafe notification maintenance routines';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_proc p, LATERAL aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a
        WHERE p.proname IN ('app_lock_notification_invitation','app_pending_notifications','app_reconcile_notifications')
          AND a.grantee=0 AND a.privilege_type='EXECUTE') THEN
        RAISE EXCEPTION 'PUBLIC can execute notification maintenance routines';
    END IF;
    IF NOT has_function_privilege('weavdash_app','app_lock_notification_invitation(uuid)','EXECUTE')
        OR NOT has_function_privilege('weavdash_app','app_pending_notifications()','EXECUTE')
        OR NOT has_function_privilege('weavdash_app','app_reconcile_notifications()','EXECUTE') THEN
        RAISE EXCEPTION 'missing notification runtime grants';
    END IF;
END $$;
SELECT 'M5_SCHEMA_CHECKS_PASSED';
