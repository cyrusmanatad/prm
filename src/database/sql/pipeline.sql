-- Creator-private CRM records. Runtime ownership/context remains the M1 boundary.
CREATE TABLE pipeline_entries (
    id uuid PRIMARY KEY,
    org_id uuid NOT NULL REFERENCES organizations(id),
    brand_name varchar(160) NOT NULL CHECK (length(trim(brand_name)) > 0),
    contact_name varchar(120),
    contact_email varchar(255),
    notes text NOT NULL DEFAULT '' CHECK (length(notes) <= 5000),
    stage varchar(24) NOT NULL DEFAULT 'lead'
        CHECK (stage IN ('lead','pitched','negotiating','onboarded','active','repeat-client','lost')),
    value_minor bigint NOT NULL DEFAULT 0 CHECK (value_minor >= 0 AND value_minor <= 999999999999999999),
    currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    next_action_due timestamptz,
    version integer NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (org_id, id)
);
CREATE INDEX pipeline_org_stage_due ON pipeline_entries(org_id, stage, next_action_due);
CREATE INDEX pipeline_org_created ON pipeline_entries(org_id, created_at DESC, id DESC);
CREATE TABLE pipeline_activities (
    id uuid PRIMARY KEY,
    org_id uuid NOT NULL REFERENCES organizations(id),
    pipeline_entry_id uuid NOT NULL,
    actor_id bigint NOT NULL REFERENCES users(id),
    kind varchar(24) NOT NULL CHECK (kind IN ('created','updated','stage','snoozed','note','email','chat','call')),
    body text NOT NULL CHECK (length(body) <= 5000),
    occurred_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (org_id, pipeline_entry_id) REFERENCES pipeline_entries(org_id, id) ON DELETE CASCADE,
    UNIQUE (org_id, id)
);
CREATE INDEX pipeline_activity_entry_time ON pipeline_activities(org_id, pipeline_entry_id, occurred_at DESC, id DESC);
ALTER TABLE pipeline_entries ENABLE ROW LEVEL SECURITY;
ALTER TABLE pipeline_entries FORCE ROW LEVEL SECURITY;
CREATE POLICY pipeline_read ON pipeline_entries FOR SELECT
    USING (org_id = app_org() AND app_has_membership(org_id));
CREATE POLICY pipeline_insert ON pipeline_entries FOR INSERT
    WITH CHECK (org_id = app_org() AND app_has_membership(org_id, ARRAY['owner','admin','manager','member']));
CREATE POLICY pipeline_update ON pipeline_entries FOR UPDATE
    USING (org_id = app_org() AND app_has_membership(org_id, ARRAY['owner','admin','manager','member']))
    WITH CHECK (org_id = app_org() AND app_has_membership(org_id, ARRAY['owner','admin','manager','member']));
CREATE POLICY pipeline_delete ON pipeline_entries FOR DELETE
    USING (org_id = app_org() AND app_has_membership(org_id, ARRAY['owner','admin','manager','member']));
ALTER TABLE pipeline_activities ENABLE ROW LEVEL SECURITY;
ALTER TABLE pipeline_activities FORCE ROW LEVEL SECURITY;
CREATE POLICY pipeline_activity_read ON pipeline_activities FOR SELECT
    USING (org_id = app_org() AND app_has_membership(org_id));
CREATE POLICY pipeline_activity_append ON pipeline_activities FOR INSERT
    WITH CHECK (org_id = app_org() AND actor_id = app_actor()
        AND app_has_membership(org_id, ARRAY['owner','admin','manager','member']));
-- Activity is append-only; deleting its entry removes the private child rows.
