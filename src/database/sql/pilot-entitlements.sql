CREATE TABLE pilot_plan_versions (
 id uuid PRIMARY KEY, name varchar(120) NOT NULL, version integer NOT NULL CHECK(version>0),
 quotas jsonb NOT NULL CHECK(jsonb_typeof(quotas)='object'), created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(name,version)
);
CREATE TABLE pilot_assignments (
 id uuid PRIMARY KEY, org_id uuid NOT NULL REFERENCES organizations(id), plan_id uuid NOT NULL REFERENCES pilot_plan_versions(id),
 version integer NOT NULL CHECK(version>0), starts_at timestamptz NOT NULL, ends_at timestamptz NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), CHECK(ends_at>starts_at), UNIQUE(org_id,version)
);
CREATE TABLE pilot_admin_events (
 id uuid PRIMARY KEY, operator varchar(160) NOT NULL, reason varchar(240) NOT NULL,
 event varchar(60) NOT NULL, subject_id uuid NOT NULL, org_id uuid REFERENCES organizations(id), created_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE pilot_assignments ENABLE ROW LEVEL SECURITY;
ALTER TABLE pilot_assignments FORCE ROW LEVEL SECURITY;
CREATE POLICY pilot_assignments_read ON pilot_assignments FOR SELECT USING(org_id=app_org() AND app_has_membership(org_id));
ALTER TABLE pilot_plan_versions ENABLE ROW LEVEL SECURITY;
ALTER TABLE pilot_plan_versions FORCE ROW LEVEL SECURITY;
CREATE POLICY pilot_plan_read ON pilot_plan_versions FOR SELECT USING(EXISTS(SELECT 1 FROM pilot_assignments a WHERE a.plan_id=pilot_plan_versions.id AND a.org_id=app_org() AND app_has_membership(a.org_id)));
ALTER TABLE pilot_admin_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE pilot_admin_events FORCE ROW LEVEL SECURITY;
CREATE POLICY pilot_admin_read ON pilot_admin_events FOR SELECT USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));

-- Only fired by resource INSERTs; no runtime EXECUTE grant. The definer counts
-- all workspace rows, even capability-based captures whose RLS cannot read them.
CREATE FUNCTION app_enforce_pilot_quota() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE a public.pilot_assignments; q jsonb; cap bigint; used bigint;
BEGIN
 IF session_user NOT IN ('weavdash_migrator','postgres') AND NOT coalesce((
   NEW.org_id=public.app_org() AND public.app_has_membership(NEW.org_id,
     CASE WHEN TG_TABLE_NAME IN ('invoice_drafts','manual_payments') THEN ARRAY['owner'] ELSE ARRAY['owner','admin','manager','member'] END)
 ), false) AND NOT (TG_TABLE_NAME='pipeline_entries' AND EXISTS (
   SELECT 1 FROM public.lead_links l WHERE l.org_id=NEW.org_id
    AND l.token_hash=nullif(current_setting('app.lead_capture',true),'') AND l.revoked_at IS NULL AND l.expires_at>clock_timestamp()
 )) THEN
  RAISE EXCEPTION 'Resource insert not authorized' USING ERRCODE='42501';
 END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('pilot:'||NEW.org_id::text,0));
 SELECT * INTO a FROM public.pilot_assignments WHERE org_id=NEW.org_id ORDER BY version DESC LIMIT 1;
 IF a.id IS NULL THEN RETURN NEW; END IF;
 SELECT quotas INTO q FROM public.pilot_plan_versions WHERE id=a.plan_id;
 IF a.starts_at>clock_timestamp() OR a.ends_at<=clock_timestamp() THEN
  RAISE EXCEPTION 'Pilot entitlement inactive. Existing records remain available.' USING ERRCODE='P0004';
 END IF;
 cap := coalesce((q->>TG_TABLE_NAME)::bigint,0);
 EXECUTE format('SELECT count(*) FROM public.%I WHERE org_id=$1', TG_TABLE_NAME) INTO used USING NEW.org_id;
 IF used>=cap THEN
  RAISE EXCEPTION 'Pilot quota reached for %. Existing records remain available.',TG_TABLE_NAME USING ERRCODE='P0004';
 END IF;
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION app_enforce_pilot_quota() FROM PUBLIC;
CREATE TRIGGER pipeline_entries_pilot_quota BEFORE INSERT ON pipeline_entries FOR EACH ROW EXECUTE FUNCTION app_enforce_pilot_quota();
CREATE TRIGGER brand_contacts_pilot_quota BEFORE INSERT ON brand_contacts FOR EACH ROW EXECUTE FUNCTION app_enforce_pilot_quota();
CREATE TRIGGER reminders_pilot_quota BEFORE INSERT ON reminders FOR EACH ROW EXECUTE FUNCTION app_enforce_pilot_quota();
CREATE TRIGGER onboarding_requests_pilot_quota BEFORE INSERT ON onboarding_requests FOR EACH ROW EXECUTE FUNCTION app_enforce_pilot_quota();
CREATE TRIGGER outreach_templates_pilot_quota BEFORE INSERT ON outreach_templates FOR EACH ROW EXECUTE FUNCTION app_enforce_pilot_quota();
CREATE TRIGGER invoice_drafts_pilot_quota BEFORE INSERT ON invoice_drafts FOR EACH ROW EXECUTE FUNCTION app_enforce_pilot_quota();
CREATE TRIGGER manual_payments_pilot_quota BEFORE INSERT ON manual_payments FOR EACH ROW EXECUTE FUNCTION app_enforce_pilot_quota();

CREATE OR REPLACE FUNCTION app_capture_lead(secret text,key uuid,digest text,brand text,contact text,email text,new_id uuid) RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE l public.lead_links%ROWTYPE; prior char(64);
BEGIN
 SELECT * INTO l FROM public.lead_links WHERE token_hash=secret AND revoked_at IS NULL AND expires_at>now() FOR UPDATE;
 IF NOT FOUND THEN RETURN false; END IF;
 SELECT payload_hash INTO prior FROM public.public_lead_receipts WHERE link_id=l.id AND idempotency_key=key;
 IF FOUND THEN
   IF prior<>digest THEN RAISE EXCEPTION 'Idempotency conflict' USING ERRCODE='22000'; END IF;
   RETURN true;
 END IF;
 IF length(brand)<1 OR length(brand)>160 OR length(contact)>120 OR length(email)>255 THEN RETURN false; END IF;
 PERFORM set_config('app.lead_capture',secret,true);
 INSERT INTO public.pipeline_entries(id,org_id,brand_name,contact_name,contact_email,currency) VALUES(new_id,l.org_id,brand,contact,email,'PHP');
 INSERT INTO public.public_lead_receipts(id,org_id,link_id,idempotency_key,payload_hash) VALUES(new_id,l.org_id,l.id,key,digest);
 RETURN true;
END $$;
