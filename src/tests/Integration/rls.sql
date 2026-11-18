-- Run only in a disposable database. Uses the exact production foundation DDL.
-- Actor IDs 1 and 2 and the fixed UUIDs are synthetic fixtures.
SET ROLE weavdash_app;
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = current_user AND (rolsuper OR rolbypassrls)) THEN
        RAISE EXCEPTION 'runtime role bypasses RLS';
    END IF;
    IF app_lock_membership('00000000-0000-4000-8000-000000000001') THEN
        RAISE EXCEPTION 'membership lock accepted missing context';
    END IF;
    IF (SELECT count(*) FROM organizations) <> 0 OR (SELECT count(*) FROM memberships) <> 0 THEN
        RAISE EXCEPTION 'missing identity context exposed rows';
    END IF;
END $$;
BEGIN;
SELECT set_config('app.actor_id', '1', true), set_config('app.org_id', '00000000-0000-4000-8000-000000000001', true);
SELECT app_create_workspace('00000000-0000-4000-8000-000000000001', 'Creator A');
INSERT INTO audit_logs(id, org_id, actor_id, event, subject_id) VALUES ('10000000-0000-4000-8000-000000000001', app_org(), app_actor(), 'workspace.created', app_org());
COMMIT;
BEGIN;
SELECT set_config('app.actor_id', '2', true), set_config('app.org_id', '00000000-0000-4000-8000-000000000002', true);
SELECT app_create_workspace('00000000-0000-4000-8000-000000000002', 'Creator B');
INSERT INTO audit_logs(id, org_id, actor_id, event, subject_id) VALUES ('10000000-0000-4000-8000-000000000002', app_org(), app_actor(), 'workspace.created', app_org());
COMMIT;
DO $$ BEGIN
    IF app_org() IS NOT NULL OR app_actor() IS NOT NULL OR (SELECT count(*) FROM audit_logs) <> 0 THEN
        RAISE EXCEPTION 'context survived commit';
    END IF;
END $$;
BEGIN;
SELECT set_config('app.actor_id', '1', true), set_config('app.org_id', '00000000-0000-4000-8000-000000000001', true);
DO $$ BEGIN
    IF (SELECT count(*) FROM organizations) <> 1 OR (SELECT count(*) FROM memberships) <> 1 OR (SELECT count(*) FROM audit_logs) <> 1 THEN
        RAISE EXCEPTION 'SELECT isolation failed';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM organizations WHERE name = 'Creator A') THEN RAISE EXCEPTION 'own organization hidden'; END IF;
    IF NOT app_lock_membership(app_org()) OR app_lock_membership('00000000-0000-4000-8000-000000000002') THEN
        RAISE EXCEPTION 'membership lock scope failed';
    END IF;
END $$;
UPDATE organizations SET name = 'Creator A renamed' WHERE id = app_org();
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM organizations WHERE name = 'Creator A renamed') THEN RAISE EXCEPTION 'authorized update failed'; END IF;
    BEGIN
        INSERT INTO audit_logs(id, org_id, actor_id, event, subject_id) VALUES ('10000000-0000-4000-8000-000000000003', '00000000-0000-4000-8000-000000000002', app_actor(), 'forged', app_org());
        RAISE EXCEPTION 'cross-org INSERT accepted';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        INSERT INTO audit_logs(id, org_id, actor_id, event, subject_id) VALUES ('10000000-0000-4000-8000-000000000004', app_org(), 2, 'forged', app_org());
        RAISE EXCEPTION 'forged actor INSERT accepted';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        INSERT INTO memberships(org_id, user_id, role) VALUES (app_org(), 2, 'owner');
        RAISE EXCEPTION 'runtime membership escalation accepted';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
END $$;
UPDATE organizations SET name = 'hacked' WHERE id = '00000000-0000-4000-8000-000000000002';
UPDATE audit_logs SET event = 'hacked';
DELETE FROM audit_logs;
DELETE FROM organizations;
DO $$ BEGIN
    IF (SELECT count(*) FROM organizations) <> 1 OR (SELECT event FROM audit_logs LIMIT 1) <> 'workspace.created' THEN
        RAISE EXCEPTION 'unauthorized UPDATE/DELETE affected records';
    END IF;
END $$;
COMMIT;
BEGIN;
SELECT set_config('app.actor_id', '2', true), set_config('app.org_id', '00000000-0000-4000-8000-000000000002', true);
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM organizations WHERE name = 'Creator B') THEN RAISE EXCEPTION 'cross-org update changed B'; END IF;
    IF (SELECT count(*) FROM audit_logs) <> 1 THEN RAISE EXCEPTION 'connection reuse leaked audit rows'; END IF;
END $$;
ROLLBACK;
DO $$ BEGIN
    IF app_org() IS NOT NULL OR app_actor() IS NOT NULL THEN RAISE EXCEPTION 'context survived rollback'; END IF;
END $$;
RESET ROLE;
SET ROLE weavdash_migrator;
UPDATE memberships SET role = 'viewer' WHERE user_id = 1;
RESET ROLE;
SET ROLE weavdash_app;
BEGIN;
SELECT set_config('app.actor_id', '1', true), set_config('app.org_id', '00000000-0000-4000-8000-000000000001', true);
UPDATE organizations SET name = 'viewer hacked' WHERE id = app_org();
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM organizations WHERE name = 'Creator A renamed') OR (SELECT count(*) FROM audit_logs) <> 0 THEN
        RAISE EXCEPTION 'viewer modified settings or read owner audit';
    END IF;
END $$;
COMMIT;
RESET ROLE;
SET ROLE weavdash_migrator;
UPDATE memberships SET revoked_at = now() WHERE user_id = 1;
RESET ROLE;
SET ROLE weavdash_app;
BEGIN;
SELECT set_config('app.actor_id', '1', true), set_config('app.org_id', '00000000-0000-4000-8000-000000000001', true);
DO $$ BEGIN
    IF (SELECT count(*) FROM organizations) <> 0 OR app_has_membership(app_org()) OR app_lock_membership(app_org()) THEN RAISE EXCEPTION 'revoked membership still trusted'; END IF;
END $$;
COMMIT;
RESET ROLE;
-- Schema integrity: all current private tables have forced RLS and no runtime ownership.
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM pg_class c JOIN pg_roles r ON r.oid = c.relowner WHERE c.relname IN ('organizations','memberships','audit_logs') AND (NOT c.relrowsecurity OR NOT c.relforcerowsecurity OR r.rolname = 'weavdash_app')) THEN
        RAISE EXCEPTION 'table ownership/RLS configuration unsafe';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_proc p JOIN pg_roles r ON r.oid = p.proowner
        WHERE p.oid = 'app_lock_membership(uuid)'::regprocedure AND p.prosecdef
          AND r.rolname = 'weavdash_migrator'
          AND p.proconfig @> ARRAY['search_path=pg_catalog, public']
          AND NOT EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = 0 AND a.privilege_type = 'EXECUTE')
    ) THEN RAISE EXCEPTION 'membership lock function privilege boundary unsafe'; END IF;
END $$;
-- Platform administration. Actor 2 still owns Creator B; actor 1's membership is revoked.
SET ROLE weavdash_app;
BEGIN;
SELECT set_config('app.actor_id', '2', true);
DO $$ BEGIN
    IF app_platform_allowed('directory.read') OR app_platform_any() OR EXISTS (SELECT 1 FROM app_lock_platform_grant()) THEN
        RAISE EXCEPTION 'platform access without a grant';
    END IF;
    BEGIN
        PERFORM app_platform_accounts('{}'::jsonb, 1);
        RAISE EXCEPTION 'directory readable without a grant';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        INSERT INTO platform_grants(user_id, permissions) VALUES (2, ARRAY['platform.owner']);
        RAISE EXCEPTION 'runtime self-granted platform access';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        INSERT INTO platform_audit(actor_id, event, target, reason) VALUES (2, 'forged', 'x', 'forged');
        RAISE EXCEPTION 'runtime wrote platform audit directly';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
END $$;
COMMIT;
RESET ROLE;
SET ROLE weavdash_migrator;
INSERT INTO platform_grants(user_id, permissions) VALUES (2, ARRAY['directory.read']);
INSERT INTO platform_audit(operator, event, target, reason) VALUES ('rls-check', 'grant.assigned.offline', '2', 'RLS check');
RESET ROLE;
SET ROLE weavdash_app;
BEGIN;
SELECT set_config('app.actor_id', '2', true), set_config('app.admin_version', '1', true);
DO $$ BEGIN
    IF app_platform_allowed('directory.read') THEN RAISE EXCEPTION 'grant usable without a confirmed authenticator'; END IF;
END $$;
COMMIT;
RESET ROLE;
SET ROLE weavdash_migrator;
UPDATE users SET admin_totp_confirmed_at = now() WHERE id = 2;
RESET ROLE;
SET ROLE weavdash_app;
BEGIN;
SELECT set_config('app.actor_id', '2', true);
DO $$ BEGIN
    IF app_platform_allowed('directory.read') THEN RAISE EXCEPTION 'grant usable without step-up'; END IF;
    PERFORM set_config('app.admin_version', '2', true);
    IF app_platform_allowed('directory.read') THEN RAISE EXCEPTION 'grant usable with a stale step-up version'; END IF;
    PERFORM set_config('app.admin_version', '1', true);
    IF NOT app_platform_allowed('directory.read') OR NOT app_platform_any() THEN RAISE EXCEPTION 'valid grant refused'; END IF;
    IF app_platform_allowed('plans.assign') OR app_platform_allowed('platform.owner') OR app_platform_allowed('access.manage') THEN
        RAISE EXCEPTION 'capability outside the grant accepted';
    END IF;
    IF (app_platform_accounts('{}'::jsonb, 1)->>'total')::int < 2 THEN RAISE EXCEPTION 'granted directory projection failed'; END IF;
    BEGIN
        PERFORM app_platform_operators();
        RAISE EXCEPTION 'operator list readable without platform.owner';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        PERFORM app_platform_suspend('account', '1', 0, 'forged');
        RAISE EXCEPTION 'suspension accepted without access.manage';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        PERFORM app_support_records('00000000-0000-4000-8000-000000000009', 'reminders', 1);
        RAISE EXCEPTION 'support records readable without a grant';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    -- The grant row is readable only by its holder and never writable; every other platform table shows nothing.
    IF (SELECT count(*) FROM platform_grants) <> 1 OR (SELECT count(*) FROM platform_audit) <> 0 THEN RAISE EXCEPTION 'platform table visibility wrong'; END IF;
    UPDATE platform_grants SET permissions = ARRAY['platform.owner'];
    DELETE FROM platform_audit;
    IF app_platform_allowed('platform.owner') THEN RAISE EXCEPTION 'runtime changed its own grant'; END IF;
    -- Unrelated to a suspension: the skip and pause helpers refuse ordinary or unknown records.
    IF app_skip_due_schedule('00000000-0000-4000-8000-000000000009', NULL) OR app_partnership_paused('00000000-0000-4000-8000-000000000009') THEN
        RAISE EXCEPTION 'suspension helper acted on an unknown record';
    END IF;
END $$;
COMMIT;
BEGIN;
SELECT set_config('app.actor_id', '1', true), set_config('app.admin_version', '1', true);
DO $$ BEGIN
    IF (SELECT count(*) FROM platform_grants) <> 0 OR app_platform_allowed('directory.read') THEN RAISE EXCEPTION 'another actor used or read the grant'; END IF;
END $$;
COMMIT;
RESET ROLE;
-- Suspension is enforced by the same functions every tenant policy and worker uses.
SET ROLE weavdash_migrator;
INSERT INTO platform_suspensions(subject, org_id, reason, suspended_by) VALUES ('workspace', '00000000-0000-4000-8000-000000000002', 'RLS check', 2);
RESET ROLE;
SET ROLE weavdash_app;
BEGIN;
SELECT set_config('app.actor_id', '2', true), set_config('app.org_id', '00000000-0000-4000-8000-000000000002', true), set_config('app.admin_version', '1', true);
DO $$ BEGIN
    IF app_has_membership(app_org()) OR app_lock_membership(app_org()) OR (SELECT count(*) FROM organizations) <> 0 OR (SELECT count(*) FROM audit_logs) <> 0 THEN
        RAISE EXCEPTION 'suspended workspace still readable';
    END IF;
    UPDATE organizations SET name = 'suspended write' WHERE id = app_org();
    IF NOT app_platform_allowed('directory.read') THEN RAISE EXCEPTION 'workspace suspension blocked the operator account'; END IF;
    IF (SELECT count(*) FROM platform_suspensions) <> 0 THEN RAISE EXCEPTION 'suspension record visible to runtime'; END IF;
    UPDATE platform_suspensions SET lifted_at = now(), lifted_by = 2;
END $$;
COMMIT;
RESET ROLE;
SET ROLE weavdash_migrator;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM organizations WHERE id = '00000000-0000-4000-8000-000000000002' AND name = 'Creator B')
        OR NOT EXISTS (SELECT 1 FROM platform_suspensions WHERE lifted_at IS NULL) THEN RAISE EXCEPTION 'suspended workspace was modified or runtime lifted the suspension'; END IF;
END $$;
UPDATE platform_suspensions SET lifted_at = now(), lifted_by = 2, lift_reason = 'RLS check';
INSERT INTO platform_suspensions(subject, user_id, reason, suspended_by) VALUES ('account', 2, 'RLS check', 1);
RESET ROLE;
SET ROLE weavdash_app;
BEGIN;
SELECT set_config('app.actor_id', '2', true), set_config('app.org_id', '00000000-0000-4000-8000-000000000002', true), set_config('app.admin_version', '1', true);
DO $$ BEGIN
    IF app_has_membership(app_org()) OR app_lock_membership(app_org()) OR (SELECT count(*) FROM organizations) <> 0 THEN RAISE EXCEPTION 'suspended account still trusted'; END IF;
    IF app_platform_allowed('directory.read') OR app_platform_any() OR EXISTS (SELECT 1 FROM app_lock_platform_grant()) THEN
        RAISE EXCEPTION 'suspended operator kept platform access';
    END IF;
END $$;
COMMIT;
RESET ROLE;
SET ROLE weavdash_migrator;
UPDATE platform_suspensions SET lifted_at = now(), lifted_by = 1, lift_reason = 'RLS check' WHERE lifted_at IS NULL;
RESET ROLE;
SET ROLE weavdash_app;
BEGIN;
SELECT set_config('app.actor_id', '2', true), set_config('app.org_id', '00000000-0000-4000-8000-000000000002', true);
DO $$ BEGIN
    IF NOT app_has_membership(app_org()) OR (SELECT count(*) FROM organizations) <> 1 THEN RAISE EXCEPTION 'reactivation did not restore access'; END IF;
END $$;
COMMIT;
BEGIN;
SELECT set_config('app.actor_id', '1', true), set_config('app.org_id', '00000000-0000-4000-8000-000000000001', true);
DO $$ BEGIN
    IF app_has_membership(app_org()) THEN RAISE EXCEPTION 'reactivation restored a revoked membership'; END IF;
END $$;
COMMIT;
RESET ROLE;
-- Leave no grant or authenticator behind for later suites; suspension and audit history is append-only by design.
SET ROLE weavdash_migrator;
DELETE FROM platform_grants WHERE user_id = 2;
UPDATE users SET admin_totp_confirmed_at = NULL WHERE id = 2;
RESET ROLE;
-- Platform schema integrity: forced RLS, no runtime ownership, one self-read policy, gated definer functions only.
DO $$ DECLARE unsafe text; BEGIN
    SELECT string_agg(c.relname, ', ') INTO unsafe FROM pg_class c JOIN pg_roles r ON r.oid = c.relowner
        WHERE c.relname IN ('platform_grants','platform_audit','notification_resolutions','platform_observations','platform_suspensions','support_grants','fixture_batches','fixture_marks','cleanup_runs')
          AND (NOT c.relrowsecurity OR NOT c.relforcerowsecurity OR r.rolname = 'weavdash_app');
    IF unsafe IS NOT NULL THEN RAISE EXCEPTION 'platform table ownership/RLS unsafe: %', unsafe; END IF;
    IF (SELECT count(*) FROM pg_class WHERE relkind = 'r' AND relname IN ('platform_grants','platform_audit','notification_resolutions','platform_observations','platform_suspensions','support_grants','fixture_batches','fixture_marks','cleanup_runs')) <> 9 THEN
        RAISE EXCEPTION 'platform table missing';
    END IF;
    SELECT string_agg(tablename || '.' || policyname, ', ') INTO unsafe FROM pg_policies
        WHERE tablename IN ('platform_grants','platform_audit','notification_resolutions','platform_observations','platform_suspensions','support_grants','fixture_batches','fixture_marks','cleanup_runs')
          AND NOT (tablename = 'platform_grants' AND policyname = 'grant_self_read' AND cmd = 'SELECT');
    IF unsafe IS NOT NULL THEN RAISE EXCEPTION 'unexpected runtime policy on a platform table: %', unsafe; END IF;
    -- Every function the runtime may call for administration is a definer function owned by the migration role,
    -- with a pinned search path and no PUBLIC execute.
    SELECT string_agg(p.oid::regprocedure::text, ', ') INTO unsafe FROM pg_proc p JOIN pg_roles r ON r.oid = p.proowner
        WHERE p.pronamespace = 'public'::regnamespace AND (p.proname LIKE 'app\_platform\_%' OR p.proname LIKE 'app\_support\_%' OR p.proname LIKE 'app\_cleanup\_%' OR p.proname LIKE 'app\_workspace\_support\_%'
            OR p.proname IN ('app_lock_platform_grant','app_record_observation','app_skip_suspended_work','app_skip_due_schedule','app_suspended_due_schedules','app_partnership_paused','app_account_suspended','app_workspace_suspended','app_public_link_suspended'))
          AND has_function_privilege('weavdash_app', p.oid, 'EXECUTE')
          AND (NOT p.prosecdef OR r.rolname <> 'weavdash_migrator' OR p.proconfig IS NULL OR NOT EXISTS (SELECT 1 FROM unnest(p.proconfig) c WHERE c LIKE 'search_path=pg_catalog%')
            OR EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = 0 AND a.privilege_type = 'EXECUTE'));
    IF unsafe IS NOT NULL THEN RAISE EXCEPTION 'platform function privilege boundary unsafe: %', unsafe; END IF;
    -- Cross-tenant helpers stay internal.
    SELECT string_agg(f, ', ') INTO unsafe FROM unnest(ARRAY['app_platform_require(text)','app_entitlement_state(uuid)','app_platform_activity(text,uuid)','app_suspension_version(text,bigint,uuid)',
        'app_usable_owner_besides(bigint)','app_support_row(support_grants)','app_support_state(support_grants)','app_job_name(text)']) f
        WHERE has_function_privilege('weavdash_app', f, 'EXECUTE');
    IF unsafe IS NOT NULL THEN RAISE EXCEPTION 'internal platform helper executable by runtime: %', unsafe; END IF;
    -- Cleanup is a dry run: nothing named for cleanup or fixtures may delete.
    IF EXISTS (SELECT 1 FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname LIKE 'app\_cleanup\_%' AND p.prosrc ~* '\mDELETE\s+FROM\s+public\.(?!fixture_marks)') THEN
        RAISE EXCEPTION 'a cleanup function deletes records';
    END IF;
END $$;
-- Brand workspace approval (D37): applicants see only their own requests, and the runtime role can neither
-- create a Brand workspace nor write requests, history, decisions or account notices directly.
SET ROLE weavdash_migrator;
INSERT INTO users(id, name, email, email_verified_at, password, created_at, updated_at) VALUES
    (101, 'RLS Brand Applicant A', 'rls-brand-a@example.test', now(), 'not-a-login-hash', now(), now()),
    (102, 'RLS Brand Applicant B', 'rls-brand-b@example.test', now(), 'not-a-login-hash', now(), now());
SELECT setval(pg_get_serial_sequence('users', 'id'), 102);
RESET ROLE;
SET ROLE weavdash_app;
BEGIN;
SELECT set_config('app.actor_id', '101', true), set_config('app.org_id', '00000000-0000-4000-8000-0000000000b2', true);
SELECT app_brand_request_submit('00000000-0000-4000-8000-0000000000b1', 'RLS Brand', 'https://rls.example.test', 'Owner', 'RLS check');
DO $$ BEGIN
    IF (SELECT count(*) FROM brand_requests) <> 1 OR (SELECT count(*) FROM brand_request_events) <> 0 OR (SELECT count(*) FROM account_notification_outbox) <> 0 THEN
        RAISE EXCEPTION 'brand request visibility wrong for the applicant';
    END IF;
    BEGIN
        PERFORM app_brand_request_submit('00000000-0000-4000-8000-0000000000b3', 'Second', 'https://rls.example.test', 'Owner', 'Second open request');
        RAISE EXCEPTION 'second open brand request accepted';
    EXCEPTION WHEN unique_violation THEN NULL;
    END;
    BEGIN
        PERFORM app_create_typed_workspace('00000000-0000-4000-8000-0000000000b2', 'Bypass', 'brand');
        RAISE EXCEPTION 'runtime created a brand workspace';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        PERFORM app_create_experience_workspace('00000000-0000-4000-8000-0000000000b2', 'Bypass', 'brand', 'partner');
        RAISE EXCEPTION 'runtime created a brand workspace through the experience path';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        INSERT INTO organizations(id, name, kind) VALUES ('00000000-0000-4000-8000-0000000000b2', 'Bypass', 'brand');
        RAISE EXCEPTION 'runtime inserted a brand workspace';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        INSERT INTO brand_requests(id, source, status, org_id, decided_at) VALUES ('00000000-0000-4000-8000-0000000000b4', 'rollout', 'approved', '00000000-0000-4000-8000-000000000002', now());
        RAISE EXCEPTION 'runtime forged approval provenance';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        INSERT INTO brand_request_events(id, request_id, version, event, actor_id, reason) VALUES ('00000000-0000-4000-8000-0000000000b5', '00000000-0000-4000-8000-0000000000b1', 2, 'approved', 101, 'forged');
        RAISE EXCEPTION 'runtime forged a decision';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    UPDATE brand_requests SET status = 'approved';
    IF (SELECT status FROM brand_requests) <> 'pending' THEN RAISE EXCEPTION 'runtime changed a request directly'; END IF;
    BEGIN
        PERFORM app_platform_brand_decide('00000000-0000-4000-8000-0000000000b1', 1, 'approve', 'forged', '00000000-0000-4000-8000-0000000000b5', '00000000-0000-4000-8000-0000000000b2');
        RAISE EXCEPTION 'applicant decided a request';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        PERFORM app_platform_brand_requests('{}'::jsonb, 1);
        RAISE EXCEPTION 'review queue readable without the capability';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
END $$;
COMMIT;
BEGIN;
SELECT set_config('app.actor_id', '102', true), set_config('app.org_id', '', true);
DO $$ BEGIN
    IF (SELECT count(*) FROM brand_requests) <> 0 OR app_brand_requests_mine() <> '[]'::jsonb THEN RAISE EXCEPTION 'another applicant read a brand request'; END IF;
    BEGIN
        PERFORM app_brand_request_mine('00000000-0000-4000-8000-0000000000b1');
        RAISE EXCEPTION 'another applicant opened a brand request';
    EXCEPTION WHEN no_data_found THEN NULL;
    END;
    BEGIN
        PERFORM app_brand_request_revise('00000000-0000-4000-8000-0000000000b1', 1, 'Taken', 'https://rls.example.test', 'Owner', 'Taken over');
        RAISE EXCEPTION 'another applicant revised a brand request';
    EXCEPTION WHEN no_data_found THEN NULL;
    END;
END $$;
COMMIT;
RESET ROLE;
DO $$ DECLARE unsafe text; BEGIN
    IF (SELECT count(*) FROM pg_class WHERE relname IN ('brand_requests','brand_request_events','account_notification_outbox','account_notification_resolutions') AND relrowsecurity AND relforcerowsecurity) <> 4
       OR (SELECT count(*) FROM pg_policy WHERE polrelid IN ('brand_requests'::regclass,'brand_request_events'::regclass,'account_notification_outbox'::regclass,'account_notification_resolutions'::regclass)) <> 1 THEN
        RAISE EXCEPTION 'brand approval tables are not behind forced RLS with one self-read policy';
    END IF;
    SELECT string_agg(f, ', ') INTO unsafe FROM unnest(ARRAY['app_brand_request_row(brand_requests)','app_brand_fields(text,text,text,text)','app_brand_provenance()','app_brand_history_guard()','app_platform_capabilities()']) f
        WHERE has_function_privilege('weavdash_app', f, 'EXECUTE');
    IF unsafe IS NOT NULL THEN RAISE EXCEPTION 'internal brand helper executable by runtime: %', unsafe; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'organization_brand_provenance' AND tgenabled = 'O' AND tgdeferrable) THEN
        RAISE EXCEPTION 'brand provenance trigger missing or disabled';
    END IF;
END $$;
-- Brand lead visibility (D38): forced RLS, read-only policies, guarded writes and no direct sign-in access.
DO $$ DECLARE unsafe text; BEGIN
    IF (SELECT count(*) FROM pg_class WHERE relname IN ('lead_registrations','workspace_sign_ins','partner_shared_profiles') AND relrowsecurity AND relforcerowsecurity) <> 3 THEN
        RAISE EXCEPTION 'brand lead visibility tables are not behind forced RLS';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_policy WHERE polrelid IN ('lead_registrations'::regclass,'workspace_sign_ins'::regclass,'partner_shared_profiles'::regclass) AND polcmd <> 'r')
       OR (SELECT count(*) FROM pg_policy WHERE polrelid = 'lead_registrations'::regclass) <> 2
       OR (SELECT count(*) FROM pg_policy WHERE polrelid = 'partner_shared_profiles'::regclass) <> 1
       OR EXISTS (SELECT 1 FROM pg_policy WHERE polrelid = 'workspace_sign_ins'::regclass) THEN
        RAISE EXCEPTION 'brand lead visibility policies changed: only SELECT policies are allowed';
    END IF;
    SELECT string_agg(t || ':' || p, ', ') INTO unsafe FROM unnest(ARRAY['lead_registrations','partner_shared_profiles','workspace_sign_ins']) t, unnest(ARRAY['INSERT','UPDATE','DELETE','TRUNCATE']) p
        WHERE has_table_privilege('weavdash_app', t, p);
    IF unsafe IS NOT NULL THEN RAISE EXCEPTION 'runtime can write shared tables directly: %', unsafe; END IF;
    IF has_table_privilege('weavdash_app', 'workspace_sign_ins', 'SELECT') THEN RAISE EXCEPTION 'runtime can read sign-in times directly'; END IF;
    SELECT string_agg(f, ', ') INTO unsafe FROM unnest(ARRAY['app_sync_lead_registrations()','app_end_partnership_registrations()']) f WHERE has_function_privilege('weavdash_app', f, 'EXECUTE');
    IF unsafe IS NOT NULL THEN RAISE EXCEPTION 'internal lead visibility trigger executable by runtime: %', unsafe; END IF;
    SELECT string_agg(p.proname, ', ') INTO unsafe FROM pg_proc p WHERE p.proname IN ('app_partnership_sharing','app_register_lead','app_update_lead_registration','app_withdraw_lead_registration',
        'app_sync_lead_registrations','app_end_partnership_registrations','app_record_workspace_sign_in','app_partner_signed_in_at','app_save_shared_profile','app_partner_shared_profile')
        AND (NOT p.prosecdef OR NOT coalesce(p.proconfig::text,'') LIKE '%search_path=pg_catalog, public%' OR has_function_privilege('public', p.oid, 'EXECUTE'));
    IF unsafe IS NOT NULL THEN RAISE EXCEPTION 'lead visibility function without fixed search path, definer rights or with PUBLIC execute: %', unsafe; END IF;
    IF (SELECT count(*) FROM pg_trigger WHERE tgname IN ('lead_registration_source_sync','lead_registration_source_delete','partnership_registrations_end') AND tgenabled = 'O') <> 3 THEN
        RAISE EXCEPTION 'lead visibility triggers missing or disabled';
    END IF;
END $$;
SELECT 'RLS_CHECKS_PASSED';
