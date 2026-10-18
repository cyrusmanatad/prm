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
SELECT 'RLS_CHECKS_PASSED';
