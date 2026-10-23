CREATE TABLE brand_contacts (
 id uuid PRIMARY KEY, org_id uuid NOT NULL REFERENCES organizations(id),
 name varchar(120) NOT NULL CHECK(length(trim(name)) > 0),
 email varchar(255), company varchar(160) NOT NULL DEFAULT '', notes text NOT NULL DEFAULT '' CHECK(length(notes)<=5000),
 version integer NOT NULL DEFAULT 1 CHECK(version>0),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(org_id,id)
);
CREATE TABLE reminders (
 id uuid PRIMARY KEY, org_id uuid NOT NULL REFERENCES organizations(id),
 pipeline_entry_id uuid, title varchar(240) NOT NULL CHECK(length(trim(title))>0),
 due_at timestamptz NOT NULL, completed_at timestamptz,
 version integer NOT NULL DEFAULT 1 CHECK(version>0),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(org_id,pipeline_entry_id) REFERENCES pipeline_entries(org_id,id) ON DELETE CASCADE
);
CREATE TABLE pipeline_contacts (
 org_id uuid NOT NULL, pipeline_entry_id uuid NOT NULL, contact_id uuid NOT NULL,
 PRIMARY KEY(org_id,pipeline_entry_id,contact_id),
 FOREIGN KEY(org_id,pipeline_entry_id) REFERENCES pipeline_entries(org_id,id) ON DELETE CASCADE,
 FOREIGN KEY(org_id,contact_id) REFERENCES brand_contacts(org_id,id)
);
CREATE INDEX brand_contacts_org_created ON brand_contacts(org_id,created_at DESC,id DESC);
CREATE INDEX reminders_org_due ON reminders(org_id,completed_at,due_at);
ALTER TABLE brand_contacts ENABLE ROW LEVEL SECURITY;
ALTER TABLE brand_contacts FORCE ROW LEVEL SECURITY;
CREATE POLICY brand_contacts_read ON brand_contacts FOR SELECT USING(org_id=app_org() AND app_has_membership(org_id));
CREATE POLICY brand_contacts_insert ON brand_contacts FOR INSERT WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY brand_contacts_update ON brand_contacts FOR UPDATE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member'])) WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY brand_contacts_delete ON brand_contacts FOR DELETE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
ALTER TABLE reminders ENABLE ROW LEVEL SECURITY;
ALTER TABLE reminders FORCE ROW LEVEL SECURITY;
CREATE POLICY reminders_read ON reminders FOR SELECT USING(org_id=app_org() AND app_has_membership(org_id));
CREATE POLICY reminders_insert ON reminders FOR INSERT WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY reminders_update ON reminders FOR UPDATE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member'])) WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY reminders_delete ON reminders FOR DELETE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
ALTER TABLE pipeline_contacts ENABLE ROW LEVEL SECURITY;
ALTER TABLE pipeline_contacts FORCE ROW LEVEL SECURITY;
CREATE POLICY pipeline_contacts_read ON pipeline_contacts FOR SELECT USING(org_id=app_org() AND app_has_membership(org_id));
CREATE POLICY pipeline_contacts_insert ON pipeline_contacts FOR INSERT WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY pipeline_contacts_update ON pipeline_contacts FOR UPDATE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member'])) WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY pipeline_contacts_delete ON pipeline_contacts FOR DELETE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
