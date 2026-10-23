CREATE TABLE invoice_drafts (
 id uuid PRIMARY KEY, org_id uuid NOT NULL REFERENCES organizations(id),
 recipient_name varchar(160) NOT NULL CHECK(length(trim(recipient_name))>0),
 currency varchar(3) NOT NULL CHECK(currency ~ '^[A-Z]{3}$'), due_date date,
 status varchar(10) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','voided')),
 total_minor bigint NOT NULL CHECK(total_minor>=0),
 version integer NOT NULL DEFAULT 1 CHECK(version>0),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(org_id,id)
);
CREATE TABLE invoice_draft_lines (
 org_id uuid NOT NULL, invoice_id uuid NOT NULL, position smallint NOT NULL CHECK(position BETWEEN 1 AND 50),
 description varchar(240) NOT NULL CHECK(length(trim(description))>0),
 quantity integer NOT NULL CHECK(quantity BETWEEN 1 AND 1000000),
 unit_price_minor bigint NOT NULL CHECK(unit_price_minor>=0),
 total_minor bigint GENERATED ALWAYS AS (quantity::bigint * unit_price_minor) STORED,
 PRIMARY KEY(org_id,invoice_id,position),
 FOREIGN KEY(org_id,invoice_id) REFERENCES invoice_drafts(org_id,id)
);
CREATE TABLE financial_commands (
 org_id uuid NOT NULL REFERENCES organizations(id), actor_id bigint NOT NULL REFERENCES users(id),
 operation varchar(100) NOT NULL, command_id uuid NOT NULL,
 payload_hash varchar(64) NOT NULL CHECK(payload_hash ~ '^[a-f0-9]{64}$'),
 result_id uuid NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(org_id,actor_id,operation,command_id),
 FOREIGN KEY(org_id,result_id) REFERENCES invoice_drafts(org_id,id)
);
CREATE INDEX invoice_drafts_org_created ON invoice_drafts(org_id,created_at DESC,id DESC);
ALTER TABLE invoice_drafts ENABLE ROW LEVEL SECURITY;
ALTER TABLE invoice_drafts FORCE ROW LEVEL SECURITY;
CREATE POLICY invoice_drafts_read ON invoice_drafts FOR SELECT USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));
CREATE POLICY invoice_drafts_insert ON invoice_drafts FOR INSERT WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));
CREATE POLICY invoice_drafts_update ON invoice_drafts FOR UPDATE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner'])) WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));
ALTER TABLE invoice_draft_lines ENABLE ROW LEVEL SECURITY;
ALTER TABLE invoice_draft_lines FORCE ROW LEVEL SECURITY;
CREATE POLICY invoice_draft_lines_read ON invoice_draft_lines FOR SELECT USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));
CREATE POLICY invoice_draft_lines_insert ON invoice_draft_lines FOR INSERT WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));
CREATE POLICY invoice_draft_lines_delete ON invoice_draft_lines FOR DELETE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));
ALTER TABLE financial_commands ENABLE ROW LEVEL SECURITY;
ALTER TABLE financial_commands FORCE ROW LEVEL SECURITY;
CREATE POLICY financial_commands_read ON financial_commands FOR SELECT USING(org_id=app_org() AND actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner']));
CREATE POLICY financial_commands_insert ON financial_commands FOR INSERT WITH CHECK(org_id=app_org() AND actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner']));
