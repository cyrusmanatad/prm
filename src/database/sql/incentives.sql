-- Brand incentives (D44). Runs after campaign-lead-links.sql. A Brand offers rewards to selected Partners and
-- approves each award a Partner claims with its lead registrations. Rewards are recorded and fulfilled outside
-- WeavDash; nothing here moves money. Every write goes through the functions below.

CREATE TABLE incentives (
 id uuid PRIMARY KEY,
 org_id uuid NOT NULL REFERENCES organizations(id),
 title varchar(160) NOT NULL CHECK (btrim(title) <> ''),
 terms varchar(4000) NOT NULL DEFAULT '',
 reward_type varchar(8) NOT NULL DEFAULT 'cash' CHECK (reward_type IN ('cash','non_cash','percent')),
 amount_minor bigint CHECK (amount_minor IS NULL OR amount_minor BETWEEN 1 AND 99999999999),
 currency varchar(3) CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),
 reward_text varchar(200) NOT NULL DEFAULT '',
 rule_stage varchar(24) NOT NULL DEFAULT 'onboarded' CHECK (rule_stage IN ('pitched','negotiating','onboarded','active','repeat-client')),
 leads_per_award smallint NOT NULL DEFAULT 1 CHECK (leads_per_award BETWEEN 1 AND 20),
 campaign_id uuid REFERENCES campaigns(id),
 starts_on date,
 ends_on date,
 cap_per_partner integer CHECK (cap_per_partner IS NULL OR cap_per_partner BETWEEN 1 AND 10000),
 cap_total integer CHECK (cap_total IS NULL OR cap_total BETWEEN 1 AND 100000),
 -- Draft audience only. Sharing moves it to incentive_partners, so Partners never read other audience members.
 audience uuid[] NOT NULL DEFAULT '{}',
 state varchar(8) NOT NULL DEFAULT 'draft' CHECK (state IN ('draft','shared','ended')),
 shared_at timestamptz,
 ended_at timestamptz,
 created_by bigint NOT NULL REFERENCES users(id),
 version integer NOT NULL DEFAULT 1 CHECK (version > 0),
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 CHECK (starts_on IS NULL OR ends_on IS NULL OR ends_on >= starts_on),
 CHECK ((state = 'draft') = (shared_at IS NULL)),
 CHECK ((state = 'ended') = (ended_at IS NOT NULL)),
 CHECK (cap_total IS NULL OR cap_per_partner IS NULL OR cap_per_partner <= cap_total),
 CHECK (state = 'draft' OR (btrim(terms) <> '' AND starts_on IS NOT NULL AND ends_on IS NOT NULL AND cardinality(audience) = 0
  AND CASE reward_type WHEN 'cash' THEN amount_minor IS NOT NULL AND currency IS NOT NULL ELSE btrim(reward_text) <> '' END))
);
CREATE INDEX incentives_owner ON incentives(org_id, created_at DESC, id);
CREATE INDEX incentives_campaign ON incentives(campaign_id) WHERE campaign_id IS NOT NULL;

CREATE TABLE incentive_partners (
 incentive_id uuid NOT NULL REFERENCES incentives(id),
 partnership_id uuid NOT NULL REFERENCES partnerships(id),
 brand_org_id uuid NOT NULL REFERENCES organizations(id),
 creator_org_id uuid NOT NULL REFERENCES organizations(id),
 PRIMARY KEY (incentive_id, partnership_id)
);
CREATE INDEX incentive_partners_partner ON incentive_partners(creator_org_id, partnership_id);

CREATE TABLE incentive_awards (
 id uuid PRIMARY KEY,
 incentive_id uuid NOT NULL REFERENCES incentives(id),
 partnership_id uuid NOT NULL REFERENCES partnerships(id),
 brand_org_id uuid NOT NULL REFERENCES organizations(id),
 creator_org_id uuid NOT NULL REFERENCES organizations(id),
 state varchar(10) NOT NULL DEFAULT 'pending' CHECK (state IN ('pending','approved','rejected','fulfilled','cancelled')),
 amount_minor bigint CHECK (amount_minor IS NULL OR amount_minor BETWEEN 1 AND 99999999999),
 amount_reason varchar(500),
 note varchar(1000) NOT NULL DEFAULT '',
 decision_reason varchar(500),
 cancel_reason varchar(20) CHECK (cancel_reason IN ('withdrawn','source_deleted','partnership_ended')),
 claimed_by bigint NOT NULL REFERENCES users(id),
 claimed_at timestamptz NOT NULL DEFAULT now(),
 decided_by bigint REFERENCES users(id),
 decided_at timestamptz,
 fulfilled_on date,
 fulfilment_reference varchar(200),
 fulfilled_at timestamptz,
 version integer NOT NULL DEFAULT 1 CHECK (version > 0),
 updated_at timestamptz NOT NULL DEFAULT now(),
 CHECK ((state = 'cancelled') = (cancel_reason IS NOT NULL)),
 CHECK (state <> 'rejected' OR btrim(coalesce(decision_reason, '')) <> ''),
 CHECK ((state = 'fulfilled') = (fulfilled_on IS NOT NULL)),
 CHECK (state NOT IN ('approved','fulfilled') OR decided_at IS NOT NULL)
);
CREATE INDEX incentive_awards_incentive ON incentive_awards(incentive_id, state, claimed_at DESC);
CREATE INDEX incentive_awards_brand ON incentive_awards(brand_org_id, state, claimed_at DESC);
CREATE INDEX incentive_awards_partner ON incentive_awards(creator_org_id, claimed_at DESC);

CREATE TABLE incentive_award_registrations (
 award_id uuid NOT NULL REFERENCES incentive_awards(id),
 incentive_id uuid NOT NULL REFERENCES incentives(id),
 registration_id uuid NOT NULL REFERENCES lead_registrations(id),
 partnership_id uuid NOT NULL REFERENCES partnerships(id),
 brand_org_id uuid NOT NULL REFERENCES organizations(id),
 creator_org_id uuid NOT NULL REFERENCES organizations(id),
 -- Snapshot at claim time: the Brand keeps what it approved even after the registration is withdrawn.
 lead_name varchar(160) NOT NULL,
 stage_at_claim varchar(24) NOT NULL,
 -- A rejected or cancelled award releases its registrations for another claim on the same incentive.
 released boolean NOT NULL DEFAULT false,
 PRIMARY KEY (award_id, registration_id)
);
CREATE UNIQUE INDEX incentive_registration_once ON incentive_award_registrations(incentive_id, registration_id) WHERE NOT released;
CREATE INDEX incentive_award_registration_lead ON incentive_award_registrations(registration_id) WHERE NOT released;

ALTER TABLE incentives ENABLE ROW LEVEL SECURITY;
ALTER TABLE incentives FORCE ROW LEVEL SECURITY;
ALTER TABLE incentive_partners ENABLE ROW LEVEL SECURITY;
ALTER TABLE incentive_partners FORCE ROW LEVEL SECURITY;
ALTER TABLE incentive_awards ENABLE ROW LEVEL SECURITY;
ALTER TABLE incentive_awards FORCE ROW LEVEL SECURITY;
ALTER TABLE incentive_award_registrations ENABLE ROW LEVEL SECURITY;
ALTER TABLE incentive_award_registrations FORCE ROW LEVEL SECURITY;

-- Brand members read their own records in every state, including after a relationship ends.
CREATE POLICY incentive_brand_read ON incentives FOR SELECT USING (org_id = app_org() AND app_has_membership(org_id));
CREATE POLICY incentive_partner_brand_read ON incentive_partners FOR SELECT USING (brand_org_id = app_org() AND app_has_membership(brand_org_id));
CREATE POLICY incentive_award_brand_read ON incentive_awards FOR SELECT USING (brand_org_id = app_org() AND app_has_membership(brand_org_id));
CREATE POLICY incentive_award_registration_brand_read ON incentive_award_registrations FOR SELECT USING (brand_org_id = app_org() AND app_has_membership(brand_org_id));
-- A Partner reads only its own audience rows and awards, and only while the relationship is active and available.
CREATE POLICY incentive_partner_own_read ON incentive_partners FOR SELECT
 USING (creator_org_id = app_org() AND app_has_membership(creator_org_id) AND app_partnership_sharing(partnership_id));
CREATE POLICY incentive_partner_read ON incentives FOR SELECT USING (
 state <> 'draft' AND EXISTS (SELECT 1 FROM incentive_partners ip WHERE ip.incentive_id = incentives.id AND ip.creator_org_id = app_org())
);
CREATE POLICY incentive_award_partner_read ON incentive_awards FOR SELECT
 USING (creator_org_id = app_org() AND app_has_membership(creator_org_id) AND app_partnership_sharing(partnership_id));
CREATE POLICY incentive_award_registration_partner_read ON incentive_award_registrations FOR SELECT
 USING (creator_org_id = app_org() AND app_has_membership(creator_org_id) AND app_partnership_sharing(partnership_id));

-- Stage order for the qualifying rule. Lost never qualifies.
CREATE FUNCTION app_stage_rank(stage text) RETURNS integer LANGUAGE sql IMMUTABLE AS $$
 SELECT array_position(ARRAY['lead','pitched','negotiating','onboarded','active','repeat-client'], stage)
$$;

-- Approved and fulfilled awards count against caps. Pending claims do not, but a Partner cannot hold more open
-- and granted claims than its own cap.
CREATE FUNCTION app_incentive_counts(target uuid, relationship uuid) RETURNS TABLE(granted_total integer, granted_partner integer, open_partner integer)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT (count(*) FILTER (WHERE a.state IN ('approved','fulfilled')))::int,
  (count(*) FILTER (WHERE a.state IN ('approved','fulfilled') AND a.partnership_id = relationship))::int,
  (count(*) FILTER (WHERE a.state IN ('pending','approved','fulfilled') AND a.partnership_id = relationship))::int
 FROM public.incentive_awards a WHERE a.incentive_id = target
$$;

-- What a Partner may know about availability: whether the total cap still has room. Never the numbers.
CREATE FUNCTION app_incentive_has_room(target uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT i.cap_total IS NULL OR (SELECT granted_total FROM public.app_incentive_counts(i.id, NULL)) < i.cap_total
 FROM public.incentives i
 WHERE i.id = target AND i.state <> 'draft' AND EXISTS (SELECT 1 FROM public.incentive_partners ip
  WHERE ip.incentive_id = i.id AND public.app_org() IN (ip.brand_org_id, ip.creator_org_id))
$$;

-- Create (expected = 0) or save a draft, optionally sharing it. `data` carries the form fields.
CREATE FUNCTION app_save_incentive(target uuid, expected integer, data jsonb, publish boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE i public.incentives; c public.campaigns; chosen uuid[]; found_count integer; created boolean := expected = 0; moment timestamptz := clock_timestamp();
BEGIN
 IF NOT public.app_has_membership(public.app_org(), ARRAY['owner','admin'])
  OR NOT EXISTS (SELECT 1 FROM public.organizations WHERE id = public.app_org() AND kind = 'brand') THEN RETURN 'denied'; END IF;
 IF nullif(data->>'campaign_id', '') IS NOT NULL THEN
  SELECT * INTO c FROM public.campaigns WHERE id = (data->>'campaign_id')::uuid AND org_id = public.app_org() AND partnership_id IS NOT NULL;
  IF NOT FOUND THEN RETURN 'campaign'; END IF;
 END IF;
 chosen := CASE WHEN c.id IS NOT NULL THEN ARRAY[c.partnership_id]
  ELSE ARRAY(SELECT DISTINCT value::uuid FROM jsonb_array_elements_text(coalesce(data->'audience', '[]'::jsonb)) ORDER BY 1) END;
 IF expected = 0 THEN
  INSERT INTO public.incentives(id, org_id, title, created_by) VALUES (target, public.app_org(), btrim(data->>'title'), public.app_actor())
   ON CONFLICT (id) DO NOTHING;
  IF NOT FOUND THEN RETURN 'stale'; END IF;
  INSERT INTO public.audit_logs(id, org_id, actor_id, event, subject_id) VALUES (gen_random_uuid(), public.app_org(), public.app_actor(), 'incentive.created', target);
  expected := 1;
 END IF;
 SELECT * INTO i FROM public.incentives WHERE id = target AND org_id = public.app_org() FOR UPDATE;
 IF NOT FOUND THEN RETURN 'missing'; END IF;
 IF i.version <> expected OR i.state <> 'draft' THEN RETURN 'stale'; END IF;
 IF publish THEN
  IF cardinality(chosen) = 0 THEN RETURN 'audience'; END IF;
  -- Every audience member must be an active, available Partner relationship of this Brand.
  SELECT count(*) INTO found_count FROM (SELECT p.id FROM public.partnerships p JOIN public.organizations o ON o.id = p.creator_org_id
   WHERE p.id = ANY(chosen) AND p.brand_org_id = public.app_org() AND p.status = 'active' AND o.experience = 'partner'
    AND public.app_partnership_sharing(p.id) ORDER BY p.id FOR SHARE OF p) eligible;
  IF found_count <> cardinality(chosen) THEN RETURN 'unavailable'; END IF;
 END IF;
 UPDATE public.incentives SET title = btrim(data->>'title'), terms = coalesce(data->>'terms', ''),
  reward_type = coalesce(nullif(data->>'reward_type', ''), 'cash'),
  amount_minor = CASE WHEN coalesce(data->>'reward_type', 'cash') = 'cash' THEN nullif(data->>'amount_minor', '')::bigint END,
  currency = CASE WHEN coalesce(data->>'reward_type', 'cash') = 'cash' THEN nullif(data->>'currency', '') END,
  reward_text = CASE WHEN coalesce(data->>'reward_type', 'cash') = 'cash' THEN '' ELSE coalesce(data->>'reward_text', '') END,
  rule_stage = coalesce(nullif(data->>'rule_stage', ''), 'onboarded'),
  leads_per_award = coalesce(nullif(data->>'leads_per_award', '')::smallint, 1),
  campaign_id = c.id, starts_on = nullif(data->>'starts_on', '')::date, ends_on = nullif(data->>'ends_on', '')::date,
  cap_per_partner = nullif(data->>'cap_per_partner', '')::integer, cap_total = nullif(data->>'cap_total', '')::integer,
  audience = CASE WHEN publish THEN '{}' ELSE chosen END,
  state = CASE WHEN publish THEN 'shared' ELSE 'draft' END, shared_at = CASE WHEN publish THEN moment END,
  -- A new draft keeps version 1: its first fields arrive in the same call that creates it.
  version = CASE WHEN created THEN 1 ELSE version + 1 END, updated_at = moment
 WHERE id = target;
 IF publish THEN
  INSERT INTO public.incentive_partners(incentive_id, partnership_id, brand_org_id, creator_org_id)
   SELECT target, p.id, p.brand_org_id, p.creator_org_id FROM public.partnerships p WHERE p.id = ANY(chosen);
 END IF;
 INSERT INTO public.audit_logs(id, org_id, actor_id, event, subject_id)
  SELECT gen_random_uuid(), public.app_org(), public.app_actor(), CASE WHEN publish THEN 'incentive.shared' ELSE 'incentive.saved' END, target
  WHERE publish OR NOT created;
 RETURN 'saved';
END $$;

-- Ending stops new claims. Pending claims stay open for a decision and existing awards are kept.
CREATE FUNCTION app_end_incentive(target uuid, expected integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE i public.incentives;
BEGIN
 IF NOT public.app_has_membership(public.app_org(), ARRAY['owner','admin']) THEN RETURN 'denied'; END IF;
 SELECT * INTO i FROM public.incentives WHERE id = target AND org_id = public.app_org() FOR UPDATE;
 IF NOT FOUND THEN RETURN 'missing'; END IF;
 IF i.version <> expected OR i.state <> 'shared' THEN RETURN 'stale'; END IF;
 UPDATE public.incentives SET state = 'ended', ended_at = clock_timestamp(), version = version + 1, updated_at = clock_timestamp() WHERE id = target;
 INSERT INTO public.audit_logs(id, org_id, actor_id, event, subject_id) VALUES (gen_random_uuid(), public.app_org(), public.app_actor(), 'incentive.ended', target);
 RETURN 'ok';
END $$;

-- A Partner owner or admin claims one award with exactly `leads_per_award` of its own qualifying registrations.
-- Lock order: partnership, incentive, registrations. Brand decisions take incentive, registrations, award.
CREATE FUNCTION app_claim_incentive_award(new_id uuid, target uuid, relationship uuid, registrations uuid[], message text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE p public.partnerships; i public.incentives; counts record; eligible integer; picked uuid[];
BEGIN
 IF NOT public.app_has_membership(public.app_org(), ARRAY['owner','admin']) THEN RETURN 'denied'; END IF;
 SELECT * INTO p FROM public.partnerships WHERE id = relationship AND creator_org_id = public.app_org() AND status = 'active' FOR SHARE;
 IF NOT FOUND OR NOT public.app_partnership_sharing(p.id) THEN RETURN 'unavailable'; END IF;
 SELECT * INTO i FROM public.incentives WHERE id = target AND org_id = p.brand_org_id FOR SHARE;
 IF NOT FOUND OR NOT EXISTS (SELECT 1 FROM public.incentive_partners WHERE incentive_id = target AND partnership_id = p.id) THEN RETURN 'missing'; END IF;
 IF i.state <> 'shared' OR current_date < i.starts_on OR current_date > i.ends_on THEN RETURN 'closed'; END IF;
 SELECT * INTO counts FROM public.app_incentive_counts(i.id, p.id);
 IF (i.cap_total IS NOT NULL AND counts.granted_total >= i.cap_total) OR (i.cap_per_partner IS NOT NULL AND counts.open_partner >= i.cap_per_partner) THEN
  RETURN 'cap';
 END IF;
 picked := ARRAY(SELECT DISTINCT unnest(registrations) ORDER BY 1);
 IF cardinality(picked) <> i.leads_per_award THEN RETURN 'ineligible'; END IF;
 SELECT count(*) INTO eligible FROM (SELECT r.id FROM public.lead_registrations r
  WHERE r.id = ANY(picked) AND r.partnership_id = p.id AND r.creator_org_id = public.app_org() AND r.state = 'active'
   AND public.app_stage_rank(r.stage) >= public.app_stage_rank(i.rule_stage) AND r.stage <> 'lost'
   AND (i.campaign_id IS NULL OR r.campaign_id = i.campaign_id) ORDER BY r.id FOR SHARE) ok;
 IF eligible <> i.leads_per_award THEN RETURN 'ineligible'; END IF;
 INSERT INTO public.incentive_awards(id, incentive_id, partnership_id, brand_org_id, creator_org_id, amount_minor, note, claimed_by)
  VALUES (new_id, i.id, p.id, p.brand_org_id, p.creator_org_id, i.amount_minor, coalesce(btrim(message), ''), public.app_actor());
 BEGIN
  INSERT INTO public.incentive_award_registrations(award_id, incentive_id, registration_id, partnership_id, brand_org_id, creator_org_id, lead_name, stage_at_claim)
   SELECT new_id, i.id, r.id, p.id, p.brand_org_id, p.creator_org_id, r.display_name, r.stage FROM public.lead_registrations r WHERE r.id = ANY(picked);
 EXCEPTION WHEN unique_violation THEN
  RAISE EXCEPTION 'A lead already supports an award for this incentive.' USING ERRCODE = 'P0006';
 END;
 INSERT INTO public.audit_logs(id, org_id, actor_id, event, subject_id) VALUES (gen_random_uuid(), public.app_org(), public.app_actor(), 'incentive_award.claimed', new_id);
 RETURN 'ok';
END $$;

-- Locks the incentive (caps), then the cited registrations, then the award. Returns the locked award or nothing.
CREATE FUNCTION app_lock_incentive_award(target uuid) RETURNS public.incentive_awards
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE a public.incentive_awards;
BEGIN
 SELECT * INTO a FROM public.incentive_awards WHERE id = target AND brand_org_id = public.app_org();
 IF NOT FOUND THEN RETURN NULL; END IF;
 PERFORM 1 FROM public.incentives WHERE id = a.incentive_id FOR UPDATE;
 PERFORM 1 FROM public.lead_registrations WHERE id IN (SELECT registration_id FROM public.incentive_award_registrations WHERE award_id = target) ORDER BY id FOR UPDATE;
 SELECT * INTO a FROM public.incentive_awards WHERE id = target FOR UPDATE;
 RETURN a;
END $$;

-- The Brand approves or rejects a pending award. Approval confirms the D43 source campaign of the cited leads.
CREATE FUNCTION app_decide_incentive_award(target uuid, expected integer, approve boolean, amount bigint, reason text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE a public.incentive_awards; i public.incentives; counts record; final bigint; why text := nullif(btrim(coalesce(reason, '')), '');
BEGIN
 IF NOT public.app_has_membership(public.app_org(), ARRAY['owner','admin']) THEN RETURN 'denied'; END IF;
 a := public.app_lock_incentive_award(target);
 IF a.id IS NULL THEN RETURN 'missing'; END IF;
 IF a.version <> expected OR a.state <> 'pending' THEN RETURN 'stale'; END IF;
 SELECT * INTO i FROM public.incentives WHERE id = a.incentive_id;
 IF NOT approve THEN
  IF why IS NULL THEN RETURN 'reason'; END IF;
  UPDATE public.incentive_awards SET state = 'rejected', decision_reason = why, decided_by = public.app_actor(), decided_at = clock_timestamp(),
   version = version + 1, updated_at = clock_timestamp() WHERE id = target;
  UPDATE public.incentive_award_registrations SET released = true WHERE award_id = target;
  INSERT INTO public.audit_logs(id, org_id, actor_id, event, subject_id) VALUES (gen_random_uuid(), public.app_org(), public.app_actor(), 'incentive_award.rejected', target);
  RETURN 'ok';
 END IF;
 SELECT * INTO counts FROM public.app_incentive_counts(i.id, a.partnership_id);
 IF (i.cap_total IS NOT NULL AND counts.granted_total >= i.cap_total) OR (i.cap_per_partner IS NOT NULL AND counts.granted_partner >= i.cap_per_partner) THEN
  RETURN 'cap';
 END IF;
 final := CASE WHEN i.reward_type = 'cash' THEN coalesce(amount, i.amount_minor) END;
 IF final IS DISTINCT FROM i.amount_minor AND why IS NULL THEN RETURN 'reason'; END IF;
 UPDATE public.incentive_awards SET state = 'approved', amount_minor = final,
  amount_reason = CASE WHEN final IS DISTINCT FROM i.amount_minor THEN why END, decision_reason = NULL,
  decided_by = public.app_actor(), decided_at = clock_timestamp(), version = version + 1, updated_at = clock_timestamp() WHERE id = target;
 IF i.campaign_id IS NOT NULL THEN
  UPDATE public.lead_registrations SET campaign_confirmed_at = clock_timestamp()
   WHERE id IN (SELECT registration_id FROM public.incentive_award_registrations WHERE award_id = target) AND campaign_id = i.campaign_id
    AND campaign_confirmed_at IS NULL;
 END IF;
 INSERT INTO public.audit_logs(id, org_id, actor_id, event, subject_id) VALUES (gen_random_uuid(), public.app_org(), public.app_actor(), 'incentive_award.approved', target);
 RETURN 'ok';
END $$;

-- Recorded after the Brand delivers the reward outside WeavDash.
CREATE FUNCTION app_fulfil_incentive_award(target uuid, expected integer, delivered date, reference text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE a public.incentive_awards;
BEGIN
 IF NOT public.app_has_membership(public.app_org(), ARRAY['owner','admin']) THEN RETURN 'denied'; END IF;
 a := public.app_lock_incentive_award(target);
 IF a.id IS NULL THEN RETURN 'missing'; END IF;
 IF a.version <> expected OR a.state <> 'approved' THEN RETURN 'stale'; END IF;
 UPDATE public.incentive_awards SET state = 'fulfilled', fulfilled_on = delivered, fulfilment_reference = nullif(btrim(coalesce(reference, '')), ''),
  fulfilled_at = clock_timestamp(), version = version + 1, updated_at = clock_timestamp() WHERE id = target;
 INSERT INTO public.audit_logs(id, org_id, actor_id, event, subject_id) VALUES (gen_random_uuid(), public.app_org(), public.app_actor(), 'incentive_award.fulfilled', target);
 RETURN 'ok';
END $$;

-- Reversing an approval returns the award to review, with the reason shown to both sides.
CREATE FUNCTION app_reverse_incentive_award(target uuid, expected integer, reason text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE a public.incentive_awards; why text := nullif(btrim(coalesce(reason, '')), '');
BEGIN
 IF NOT public.app_has_membership(public.app_org(), ARRAY['owner','admin']) THEN RETURN 'denied'; END IF;
 a := public.app_lock_incentive_award(target);
 IF a.id IS NULL THEN RETURN 'missing'; END IF;
 IF a.version <> expected OR a.state <> 'approved' THEN RETURN 'stale'; END IF;
 IF why IS NULL THEN RETURN 'reason'; END IF;
 UPDATE public.incentive_awards SET state = 'pending', decision_reason = why, decided_by = NULL, decided_at = NULL,
  version = version + 1, updated_at = clock_timestamp() WHERE id = target;
 INSERT INTO public.audit_logs(id, org_id, actor_id, event, subject_id) VALUES (gen_random_uuid(), public.app_org(), public.app_actor(), 'incentive_award.reversed', target);
 RETURN 'ok';
END $$;

-- Withdrawing a registration, deleting its source lead or ending the relationship cancels pending claims that
-- cite it and releases their leads. Approved and fulfilled awards stay.
CREATE FUNCTION app_cancel_incentive_claims() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF OLD.state = 'active' AND NEW.state = 'withdrawn' THEN
  WITH gone AS (
   UPDATE public.incentive_awards a SET state = 'cancelled', version = version + 1, updated_at = clock_timestamp(),
    cancel_reason = CASE NEW.withdrawn_reason WHEN 'partner' THEN 'withdrawn' ELSE NEW.withdrawn_reason END
   WHERE a.state = 'pending' AND a.id IN (SELECT award_id FROM public.incentive_award_registrations WHERE registration_id = NEW.id AND NOT released)
   RETURNING a.id),
  freed AS (UPDATE public.incentive_award_registrations SET released = true WHERE award_id IN (SELECT id FROM gone) RETURNING award_id)
  INSERT INTO public.audit_logs(id, org_id, actor_id, event, subject_id)
   SELECT gen_random_uuid(), NEW.creator_org_id, public.app_actor(), 'incentive_award.cancelled', gone.id FROM gone WHERE public.app_actor() IS NOT NULL;
 END IF;
 -- Deleting the private lead also removes its name from award history, as it does from the registration.
 IF OLD.pipeline_entry_id IS NOT NULL AND NEW.pipeline_entry_id IS NULL THEN
  UPDATE public.incentive_award_registrations SET lead_name = 'Deleted lead' WHERE registration_id = NEW.id;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER lead_registration_incentive_claims AFTER UPDATE OF state ON lead_registrations FOR EACH ROW EXECUTE FUNCTION app_cancel_incentive_claims();

-- A source campaign that an incentive award relies on cannot be changed while that award is open or granted.
CREATE FUNCTION app_guard_award_campaign() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF NEW.campaign_id IS DISTINCT FROM OLD.campaign_id AND EXISTS (
   SELECT 1 FROM public.incentive_award_registrations ar JOIN public.incentives i ON i.id = ar.incentive_id
   WHERE ar.registration_id = OLD.id AND NOT ar.released AND i.campaign_id IS NOT NULL) THEN
  RAISE EXCEPTION 'This lead supports an incentive award for its source campaign.' USING ERRCODE = 'P0007';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER lead_registration_award_campaign BEFORE UPDATE OF campaign_id ON lead_registrations FOR EACH ROW EXECUTE FUNCTION app_guard_award_campaign();

REVOKE ALL ON FUNCTION app_stage_rank(text), app_incentive_counts(uuid,uuid), app_incentive_has_room(uuid), app_save_incentive(uuid,integer,jsonb,boolean),
 app_end_incentive(uuid,integer), app_claim_incentive_award(uuid,uuid,uuid,uuid[],text), app_lock_incentive_award(uuid),
 app_decide_incentive_award(uuid,integer,boolean,bigint,text), app_fulfil_incentive_award(uuid,integer,date,text),
 app_reverse_incentive_award(uuid,integer,text), app_cancel_incentive_claims(), app_guard_award_campaign() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_stage_rank(text), app_incentive_has_room(uuid), app_save_incentive(uuid,integer,jsonb,boolean), app_end_incentive(uuid,integer),
 app_claim_incentive_award(uuid,uuid,uuid,uuid[],text), app_decide_incentive_award(uuid,integer,boolean,bigint,text),
 app_fulfil_incentive_award(uuid,integer,date,text), app_reverse_incentive_award(uuid,integer,text) TO weavdash_app;
GRANT SELECT ON incentives, incentive_partners, incentive_awards, incentive_award_registrations TO weavdash_app;
