-- Disposable CI/development database only; run as the migration owner.
INSERT INTO users(id, name, email, email_verified_at, password, created_at, updated_at) VALUES
    (1, 'RLS Creator A', 'rls-a@example.test', now(), 'not-a-login-hash', now(), now()),
    (2, 'RLS Creator B', 'rls-b@example.test', now(), 'not-a-login-hash', now(), now());
SELECT setval(pg_get_serial_sequence('users', 'id'), 2);

-- Brand workspaces need recorded approval (D37), which the runtime role cannot write. The integration suite
-- runs with runtime credentials only, so this disposable-database helper creates a synthetic approved Brand
-- workspace. It is installed by this fixture file, never by a migration, and must not exist outside tests.
CREATE FUNCTION test_approved_brand(owner bigint, target uuid, label text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
DECLARE request uuid := gen_random_uuid();
BEGIN
    INSERT INTO public.organizations(id, name, kind) VALUES (target, label, 'brand');
    INSERT INTO public.memberships(org_id, user_id, role) VALUES (target, owner, 'owner');
    INSERT INTO public.brand_requests(id, source, status, org_id, decided_at) VALUES (request, 'rollout', 'approved', target, clock_timestamp());
    INSERT INTO public.brand_request_events(id, request_id, version, event, operator, reason)
        VALUES (gen_random_uuid(), request, 1, 'rollout_approved', 'synthetic-fixture', 'Synthetic fixture workspace');
END $$;
ALTER FUNCTION test_approved_brand(bigint, uuid, text) OWNER TO weavdash_migrator;
REVOKE ALL ON FUNCTION test_approved_brand(bigint, uuid, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION test_approved_brand(bigint, uuid, text) TO weavdash_app;
