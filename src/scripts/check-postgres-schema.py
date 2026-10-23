#!/usr/bin/env python3
"""Validate PostgreSQL foundation DDL in a disposable cluster without sockets.

Single-user mode does not establish runtime authorization/isolation evidence.
Run tests/Integration/rls.sql against a real server for RLS verification.
Requires PG_BIN (default /usr/lib/postgresql/16/bin).
"""
from pathlib import Path
import os
import re
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
pg_bin = Path(os.environ.get('PG_BIN', '/usr/lib/postgresql/16/bin'))
with tempfile.TemporaryDirectory(prefix='weavdash-rls-') as temporary:
    data = Path(temporary) / 'data'
    subprocess.run([str(pg_bin / 'initdb'), '-D', str(data), '-U', 'bootstrap', '--auth=trust'],
                   check=True, stdout=subprocess.DEVNULL)
    setup = """
CREATE ROLE weavdash_migrator NOLOGIN BYPASSRLS;
CREATE ROLE weavdash_app NOLOGIN NOSUPERUSER NOBYPASSRLS;
GRANT USAGE, CREATE ON SCHEMA public TO weavdash_migrator;
SET ROLE weavdash_migrator;
CREATE TABLE users (id bigserial PRIMARY KEY, email_verified_at timestamptz, email varchar(255));
CREATE TABLE migrations (id serial PRIMARY KEY);
INSERT INTO users(email_verified_at) VALUES (now()), (now());
"""
    sql = setup + (root / 'database/sql/foundation.sql').read_text()
    sql += (root / 'database/sql/lock-membership.sql').read_text()
    sql += (root / 'database/sql/pipeline.sql').read_text()
    sql += (root / 'database/sql/outreach-templates.sql').read_text()
    sql += (root / 'database/sql/crm.sql').read_text()
    sql += (root / 'database/sql/onboarding.sql').read_text()
    sql += (root / 'database/sql/public-profile.sql').read_text()
    sql += (root / 'database/sql/portals.sql').read_text()
    sql += (root / 'database/sql/invoice-drafts.sql').read_text()
    sql += (root / 'database/sql/manual-payments.sql').read_text()
    sql += (root / 'database/sql/pilot-entitlements.sql').read_text()
    sql += (root / 'database/sql/runtime-grants.sql').read_text()
    sql += """
RESET ROLE;
DO $$ BEGIN
    IF (SELECT count(*) FROM pg_class WHERE relname IN ('organizations','memberships','audit_logs') AND relrowsecurity AND relforcerowsecurity) <> 3 THEN
        RAISE EXCEPTION 'missing forced RLS';
    END IF;
    IF (SELECT count(*) FROM pg_policy WHERE polrelid IN ('organizations'::regclass, 'memberships'::regclass, 'audit_logs'::regclass)) <> 5 THEN
        RAISE EXCEPTION 'missing policies';
    END IF;
END $$;
DO $$ BEGIN
    IF (SELECT count(*) FROM pg_class WHERE relname IN ('pipeline_entries','pipeline_activities') AND relrowsecurity AND relforcerowsecurity) <> 2 THEN
        RAISE EXCEPTION 'missing pipeline forced RLS';
    END IF;
    IF (SELECT count(*) FROM pg_policy WHERE polrelid IN ('pipeline_entries'::regclass, 'pipeline_activities'::regclass)) <> 6 THEN
        RAISE EXCEPTION 'missing pipeline policies';
    END IF;
END $$;
DO $$ BEGIN
    IF (SELECT count(*) FROM pg_class WHERE relname IN ('invoice_drafts','invoice_draft_lines','financial_commands','manual_payments','payment_allocations','payment_allocation_reversals','payment_commands','pilot_plan_versions','pilot_assignments','pilot_admin_events') AND relrowsecurity AND relforcerowsecurity) <> 10 THEN
        RAISE EXCEPTION 'missing finance/pilot forced RLS';
    END IF;
END $$;
SELECT 'SCHEMA_CHECKS_PASSED';
"""
    # Preserve one SQL command per input line, including dollar-quoted functions.
    # Separate input commands are important for transaction-local setting checks.
    sql = re.sub(r'--[^\n]*', '', sql)
    commands, buffer, quote, dollar, index = [], [], False, False, 0
    while index < len(sql):
        char = sql[index]
        if not quote and sql[index:index + 2] == '$$':
            dollar = not dollar
            buffer.extend('$$')
            index += 2
            continue
        if not dollar and char == "'":
            if quote and sql[index:index + 2] == "''":
                buffer.extend("''")
                index += 2
                continue
            quote = not quote
        buffer.append(' ' if char == '\n' else char)
        if char == ';' and not quote and not dollar:
            commands.append(''.join(buffer).strip())
            buffer = []
        index += 1
    if ''.join(buffer).strip():
        raise RuntimeError('Unterminated SQL command')
    sql = '\n'.join(commands) + '\n'
    result = subprocess.run([str(pg_bin / 'postgres'), '--single', '-D', str(data), 'postgres'],
                            input=sql, text=True, capture_output=True)
    output = result.stdout + result.stderr
    if result.returncode or re.search(r'\b(ERROR|FATAL|PANIC):', output) or 'SCHEMA_CHECKS_PASSED' not in output:
        print(output)
        raise SystemExit(1)
    print('PostgreSQL schema/DDL checks passed. Runtime RLS enforcement is NOT verified by this check.')
