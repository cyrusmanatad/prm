-- Disposable CI/development database only; run as the migration owner.
INSERT INTO users(id, name, email, email_verified_at, password, created_at, updated_at) VALUES
    (1, 'RLS Creator A', 'rls-a@example.test', now(), 'not-a-login-hash', now(), now()),
    (2, 'RLS Creator B', 'rls-b@example.test', now(), 'not-a-login-hash', now(), now());
SELECT setval(pg_get_serial_sequence('users', 'id'), 2);
