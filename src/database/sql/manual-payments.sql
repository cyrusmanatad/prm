CREATE TABLE manual_payments (
 id uuid PRIMARY KEY, org_id uuid NOT NULL REFERENCES organizations(id),
 currency varchar(3) NOT NULL CHECK(currency ~ '^[A-Z]{3}$'),
 amount_minor bigint NOT NULL CHECK(amount_minor>0), received_on date NOT NULL,
 evidence_reference varchar(160) NOT NULL CHECK(length(trim(evidence_reference))>0),
 evidence_hash varchar(64) NOT NULL, correction_of uuid,
 status varchar(10) NOT NULL DEFAULT 'recorded' CHECK(status IN ('recorded','voided')),
 version integer NOT NULL DEFAULT 1 CHECK(version>0),
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(org_id,id), UNIQUE(org_id,evidence_hash),
 FOREIGN KEY(org_id,correction_of) REFERENCES manual_payments(org_id,id)
);
CREATE TABLE payment_allocations (
 id uuid PRIMARY KEY, org_id uuid NOT NULL, payment_id uuid NOT NULL, invoice_id uuid NOT NULL,
 amount_minor bigint NOT NULL CHECK(amount_minor>0), created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(org_id,id), FOREIGN KEY(org_id,payment_id) REFERENCES manual_payments(org_id,id),
 FOREIGN KEY(org_id,invoice_id) REFERENCES invoice_drafts(org_id,id)
);
CREATE TABLE payment_allocation_reversals (
 id uuid PRIMARY KEY, org_id uuid NOT NULL, allocation_id uuid NOT NULL UNIQUE,
 reason varchar(240) NOT NULL CHECK(length(trim(reason))>0), created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(org_id,allocation_id) REFERENCES payment_allocations(org_id,id)
);
CREATE TABLE payment_commands (
 org_id uuid NOT NULL REFERENCES organizations(id), actor_id bigint NOT NULL REFERENCES users(id),
 operation varchar(100) NOT NULL, command_id uuid NOT NULL, payload_hash varchar(64) NOT NULL,
 result_id uuid NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(org_id,actor_id,operation,command_id)
);
ALTER TABLE manual_payments ENABLE ROW LEVEL SECURITY;
ALTER TABLE manual_payments FORCE ROW LEVEL SECURITY;
CREATE POLICY manual_payments_read ON manual_payments FOR SELECT USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));
CREATE POLICY manual_payments_insert ON manual_payments FOR INSERT WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));
ALTER TABLE payment_allocations ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_allocations FORCE ROW LEVEL SECURITY;
CREATE POLICY payment_allocations_read ON payment_allocations FOR SELECT USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));
CREATE POLICY payment_allocations_insert ON payment_allocations FOR INSERT WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));
ALTER TABLE payment_allocation_reversals ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_allocation_reversals FORCE ROW LEVEL SECURITY;
CREATE POLICY payment_allocation_reversals_read ON payment_allocation_reversals FOR SELECT USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));
CREATE POLICY payment_allocation_reversals_insert ON payment_allocation_reversals FOR INSERT WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));
ALTER TABLE payment_commands ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_commands FORCE ROW LEVEL SECURITY;
CREATE POLICY payment_commands_read ON payment_commands FOR SELECT USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));
CREATE POLICY payment_commands_insert ON payment_commands FOR INSERT WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));
CREATE POLICY manual_payments_update ON manual_payments FOR UPDATE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner'])) WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']));
-- Invoker triggers retain RLS and serialize financial changes within one workspace.
CREATE FUNCTION app_finance_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE p manual_payments; i invoice_drafts; used numeric;
BEGIN
 IF session_user NOT IN ('weavdash_migrator','postgres') AND NOT coalesce(NEW.org_id=app_org() AND app_has_membership(NEW.org_id,ARRAY['owner']),false) THEN
  RAISE EXCEPTION 'Financial mutation not authorized' USING ERRCODE='42501';
 END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('finance:' || NEW.org_id::text, 0));
 IF TG_TABLE_NAME = 'payment_allocations' THEN
  SELECT * INTO p FROM manual_payments WHERE id=NEW.payment_id AND org_id=NEW.org_id FOR UPDATE;
  SELECT * INTO i FROM invoice_drafts WHERE id=NEW.invoice_id AND org_id=NEW.org_id FOR UPDATE;
  IF p.id IS NULL OR i.id IS NULL OR p.status<>'recorded' OR i.status<>'draft' OR p.currency<>i.currency THEN
   RAISE EXCEPTION 'Invalid allocation targets' USING ERRCODE='23514';
  END IF;
  SELECT coalesce(sum(a.amount_minor),0) INTO used FROM payment_allocations a
   WHERE a.payment_id=p.id AND NOT EXISTS(SELECT 1 FROM payment_allocation_reversals r WHERE r.allocation_id=a.id);
  IF used+NEW.amount_minor>p.amount_minor THEN RAISE EXCEPTION 'Payment overallocated' USING ERRCODE='23514'; END IF;
  SELECT coalesce(sum(a.amount_minor),0) INTO used FROM payment_allocations a
   WHERE a.invoice_id=i.id AND NOT EXISTS(SELECT 1 FROM payment_allocation_reversals r WHERE r.allocation_id=a.id);
  IF used+NEW.amount_minor>i.total_minor THEN RAISE EXCEPTION 'Draft overallocated' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME = 'manual_payments' THEN
  IF (NEW.id,NEW.org_id,NEW.currency,NEW.amount_minor,NEW.received_on,NEW.evidence_reference,NEW.evidence_hash,NEW.correction_of,NEW.created_at)
    IS DISTINCT FROM (OLD.id,OLD.org_id,OLD.currency,OLD.amount_minor,OLD.received_on,OLD.evidence_reference,OLD.evidence_hash,OLD.correction_of,OLD.created_at)
    OR OLD.status='voided' OR NEW.version<>OLD.version+1 THEN
   RAISE EXCEPTION 'Payment records are immutable except versioned voiding/allocation' USING ERRCODE='23514';
  END IF;
  IF NEW.status='voided' AND EXISTS(SELECT 1 FROM payment_allocations a WHERE a.payment_id=OLD.id
    AND NOT EXISTS(SELECT 1 FROM payment_allocation_reversals r WHERE r.allocation_id=a.id)) THEN
   RAISE EXCEPTION 'Reverse allocations first' USING ERRCODE='23514';
  END IF;
 ELSIF TG_TABLE_NAME = 'invoice_drafts' THEN
  IF EXISTS(SELECT 1 FROM payment_allocations a WHERE a.invoice_id=OLD.id
    AND NOT EXISTS(SELECT 1 FROM payment_allocation_reversals r WHERE r.allocation_id=a.id)) THEN
   RAISE EXCEPTION 'Reverse allocations before changing draft' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER payment_allocation_bounds BEFORE INSERT ON payment_allocations FOR EACH ROW EXECUTE FUNCTION app_finance_guard();
CREATE TRIGGER payment_immutable BEFORE UPDATE ON manual_payments FOR EACH ROW EXECUTE FUNCTION app_finance_guard();
CREATE TRIGGER invoice_allocated_guard BEFORE UPDATE ON invoice_drafts FOR EACH ROW EXECUTE FUNCTION app_finance_guard();

CREATE INDEX manual_payments_org_created ON manual_payments(org_id,created_at DESC,id DESC);
CREATE INDEX allocations_payment ON payment_allocations(org_id,payment_id);
CREATE INDEX allocations_invoice ON payment_allocations(org_id,invoice_id);
