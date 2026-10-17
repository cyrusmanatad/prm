#!/bin/sh
set -eu
# Only for a new disposable development volume. Passwords are passed as quoted
# psql literals, never interpolated into executable SQL.
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  --set=runtime_password="$APP_DB_PASSWORD" --set=migration_password="$MIGRATION_DB_PASSWORD" <<'SQL'
CREATE ROLE weavdash_migrator LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS PASSWORD :'migration_password';
CREATE ROLE weavdash_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD :'runtime_password';
ALTER SCHEMA public OWNER TO weavdash_migrator;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO weavdash_app;
SQL
