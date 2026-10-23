-- Private plain-text templates; no delivery or executable template language.
CREATE TABLE outreach_templates (
    id uuid PRIMARY KEY,
    org_id uuid NOT NULL REFERENCES organizations(id),
    name varchar(160) NOT NULL CHECK (length(trim(name)) > 0),
    subject varchar(240) NOT NULL DEFAULT '',
    body text NOT NULL CHECK (length(trim(body)) > 0 AND length(body) <= 5000),
    version integer NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX outreach_templates_org_name ON outreach_templates(org_id, name, id);
ALTER TABLE outreach_templates ENABLE ROW LEVEL SECURITY;
ALTER TABLE outreach_templates FORCE ROW LEVEL SECURITY;
CREATE POLICY template_read ON outreach_templates FOR SELECT
    USING (org_id = app_org() AND app_has_membership(org_id));
CREATE POLICY template_insert ON outreach_templates FOR INSERT
    WITH CHECK (org_id = app_org() AND app_has_membership(org_id, ARRAY['owner','admin','manager','member']));
CREATE POLICY template_update ON outreach_templates FOR UPDATE
    USING (org_id = app_org() AND app_has_membership(org_id, ARRAY['owner','admin','manager','member']))
    WITH CHECK (org_id = app_org() AND app_has_membership(org_id, ARRAY['owner','admin','manager','member']));
CREATE POLICY template_delete ON outreach_templates FOR DELETE
    USING (org_id = app_org() AND app_has_membership(org_id, ARRAY['owner','admin','manager','member']));
