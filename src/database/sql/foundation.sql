-- Executed by migrations using the schema owner, never by the web runtime.
CREATE TABLE organizations (
    id uuid PRIMARY KEY,
    name varchar(120) NOT NULL CHECK (length(trim(name)) > 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE memberships (
    org_id uuid NOT NULL REFERENCES organizations(id),
    user_id bigint NOT NULL REFERENCES users(id),
    role varchar(16) NOT NULL CHECK (role IN ('owner','admin','manager','member','viewer')),
    revoked_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (org_id, user_id)
);
CREATE INDEX memberships_user_active ON memberships(user_id, org_id) WHERE revoked_at IS NULL;
CREATE TABLE audit_logs (
    id uuid PRIMARY KEY,
    org_id uuid NOT NULL REFERENCES organizations(id),
    actor_id bigint NOT NULL REFERENCES users(id),
    event varchar(80) NOT NULL,
    subject_id uuid NOT NULL,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (org_id, id)
);
CREATE INDEX audit_logs_org_time ON audit_logs(org_id, occurred_at);

CREATE FUNCTION app_actor() RETURNS bigint LANGUAGE sql STABLE AS $$
    SELECT nullif(current_setting('app.actor_id', true), '')::bigint
$$;
CREATE FUNCTION app_org() RETURNS uuid LANGUAGE sql STABLE AS $$
    SELECT nullif(current_setting('app.org_id', true), '')::uuid
$$;

-- This narrowly scoped lookup avoids recursive membership RLS evaluation.
-- The migration role owns these functions and the table; runtime cannot replace them.
CREATE FUNCTION app_has_membership(target uuid, allowed_roles text[] DEFAULT NULL)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
    SELECT EXISTS (
        SELECT 1 FROM public.memberships m
        WHERE m.org_id = target AND m.user_id = public.app_actor()
          AND m.revoked_at IS NULL
          AND (allowed_roles IS NULL OR m.role = ANY(allowed_roles))
    )
$$;

ALTER TABLE organizations ENABLE ROW LEVEL SECURITY;
ALTER TABLE organizations FORCE ROW LEVEL SECURITY;
CREATE POLICY organization_discovery ON organizations FOR SELECT
    USING (app_has_membership(id));
CREATE POLICY organization_settings ON organizations FOR UPDATE
    USING (id = app_org() AND app_has_membership(id, ARRAY['owner','admin']))
    WITH CHECK (id = app_org() AND app_has_membership(id, ARRAY['owner','admin']));

-- Discovery exposes only the current actor's active memberships; mutation has no policy.
ALTER TABLE memberships ENABLE ROW LEVEL SECURITY;
ALTER TABLE memberships FORCE ROW LEVEL SECURITY;
CREATE POLICY membership_discovery ON memberships FOR SELECT
    USING (user_id = app_actor() AND revoked_at IS NULL);

ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs FORCE ROW LEVEL SECURITY;
CREATE POLICY audit_read ON audit_logs FOR SELECT
    USING (org_id = app_org() AND app_has_membership(org_id, ARRAY['owner','admin']));
CREATE POLICY audit_append ON audit_logs FOR INSERT
    WITH CHECK (org_id = app_org() AND actor_id = app_actor() AND app_has_membership(org_id));
-- No UPDATE/DELETE policy: runtime audit events are append-only.

-- Bootstrap is the only organization/membership creation path. It needs to
-- insert the owner membership before normal organization visibility is granted.
-- The dedicated function owner must be the migration role with BYPASSRLS;
-- only these reviewed routines may use its privileges.
CREATE FUNCTION app_create_workspace(workspace_id uuid, workspace_name text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM public.users WHERE id = public.app_actor() AND email_verified_at IS NOT NULL) THEN
        RAISE EXCEPTION 'Verified identity required' USING ERRCODE = '42501';
    END IF;
    IF workspace_id IS DISTINCT FROM public.app_org() THEN
        RAISE EXCEPTION 'Workspace context required' USING ERRCODE = '42501';
    END IF;
    INSERT INTO public.organizations(id, name) VALUES (workspace_id, workspace_name);
    INSERT INTO public.memberships(org_id, user_id, role) VALUES (workspace_id, public.app_actor(), 'owner');
END
$$;
REVOKE ALL ON FUNCTION app_create_workspace(uuid, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION app_has_membership(uuid, text[]) FROM PUBLIC;
