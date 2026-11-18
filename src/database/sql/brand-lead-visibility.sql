-- Brand lead visibility (D38). Runs after brand-approval.sql. Additive: no existing lead, profile field or
-- partnership is published by this migration. A Brand sees a lead only after a Partner owner/admin explicitly
-- registers it with that Brand's partnership. Private pipeline, CRM and account tables keep their policies.

-- ---------------------------------------------------------------------------------------------
-- 1. Registration: the explicit shared projection of one private lead for one partnership.
-- It holds only the fields a Brand may read. Private notes, value, reminders and activity are never copied.
CREATE TABLE lead_registrations (
 id uuid PRIMARY KEY,
 partnership_id uuid NOT NULL REFERENCES partnerships(id),
 brand_org_id uuid NOT NULL REFERENCES organizations(id),
 creator_org_id uuid NOT NULL REFERENCES organizations(id),
 -- NULL once the private source lead is deleted; the row then stays as a withdrawn tombstone.
 pipeline_entry_id uuid,
 display_name varchar(160) NOT NULL CHECK(length(trim(display_name)) > 0),
 summary varchar(1000) NOT NULL DEFAULT '',
 share_contact boolean NOT NULL DEFAULT false,
 contact_name varchar(120),
 contact_email varchar(255),
 stage varchar(24) NOT NULL CHECK(stage IN ('lead','pitched','negotiating','onboarded','active','repeat-client','lost')),
 state varchar(12) NOT NULL DEFAULT 'active' CHECK(state IN ('active','withdrawn')),
 withdrawn_reason varchar(20) CHECK(withdrawn_reason IN ('partner','source_deleted','partnership_ended')),
 withdrawn_at timestamptz,
 registered_by bigint NOT NULL REFERENCES users(id),
 -- First registration time. Never changed by edits, stage moves or a later re-share.
 registered_at timestamptz NOT NULL,
 -- Last change to a Brand-visible field. Private-note and other private edits never move it.
 shared_updated_at timestamptz NOT NULL,
 last_event varchar(12) NOT NULL DEFAULT 'registered' CHECK(last_event IN ('registered','updated')),
 version integer NOT NULL DEFAULT 1 CHECK(version > 0),
 FOREIGN KEY (creator_org_id, pipeline_entry_id) REFERENCES pipeline_entries(org_id, id),
 CHECK(brand_org_id <> creator_org_id),
 CHECK((state='active' AND withdrawn_at IS NULL AND withdrawn_reason IS NULL AND pipeline_entry_id IS NOT NULL)
    OR (state='withdrawn' AND withdrawn_at IS NOT NULL AND withdrawn_reason IS NOT NULL)),
 CHECK(share_contact OR (contact_name IS NULL AND contact_email IS NULL))
);
-- One registration per source lead and partnership: retries and concurrent requests cannot duplicate counts.
CREATE UNIQUE INDEX lead_registration_source ON lead_registrations(partnership_id, pipeline_entry_id) WHERE pipeline_entry_id IS NOT NULL;
CREATE INDEX lead_registration_brand_list ON lead_registrations(brand_org_id, registered_at DESC, id DESC) WHERE state='active';
CREATE INDEX lead_registration_partnership_list ON lead_registrations(partnership_id, registered_at DESC, id DESC) WHERE state='active';
CREATE INDEX lead_registration_partner_list ON lead_registrations(creator_org_id, registered_at DESC, id DESC);
CREATE INDEX lead_registration_entry ON lead_registrations(creator_org_id, pipeline_entry_id);

-- True only for the caller's own active partnership while neither workspace is suspended.
CREATE FUNCTION app_partnership_sharing(target uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.partnerships p WHERE p.id=target AND p.status='active'
  AND public.app_org() IN (p.brand_org_id,p.creator_org_id)
  AND NOT public.app_workspace_suspended(p.brand_org_id) AND NOT public.app_workspace_suspended(p.creator_org_id))
$$;

ALTER TABLE lead_registrations ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead_registrations FORCE ROW LEVEL SECURITY;
-- The single Brand visibility rule. Lists, counts, details and activity all read through it.
CREATE POLICY lead_registration_brand_read ON lead_registrations FOR SELECT
 USING(state='active' AND brand_org_id=app_org() AND app_has_membership(brand_org_id) AND app_partnership_sharing(partnership_id));
-- The registering workspace sees its own registrations in every state.
CREATE POLICY lead_registration_partner_read ON lead_registrations FOR SELECT
 USING(creator_org_id=app_org() AND app_has_membership(creator_org_id));
-- No INSERT/UPDATE/DELETE policy: every change goes through the functions and triggers below.

-- Lock order for every writer: partnership row, then the workspace's pilot advisory lock, then the source
-- lead, then the registration. Partnership revocation, source edits and registration changes cannot deadlock.
CREATE FUNCTION app_register_lead(new_id uuid, relationship uuid, source uuid, label text, note text, with_contact boolean) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE p public.partnerships; e public.pipeline_entries; r public.lead_registrations; moment timestamptz := now();
BEGIN
 IF NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin']) THEN RETURN jsonb_build_object('status','denied'); END IF;
 -- A revocation that locked first is seen here; one that arrives later waits for this transaction.
 SELECT * INTO p FROM public.partnerships WHERE id=relationship AND creator_org_id=public.app_org() AND status='active' FOR SHARE;
 IF NOT FOUND OR public.app_workspace_suspended(p.brand_org_id) THEN RETURN jsonb_build_object('status','unavailable'); END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('pilot:'||public.app_org()::text,0));
 IF NOT public.app_professional_active(public.app_org()) THEN
  RAISE EXCEPTION 'Work is paused. Existing records remain readable and exportable.' USING ERRCODE='P0004';
 END IF;
 SELECT * INTO e FROM public.pipeline_entries WHERE id=source AND org_id=public.app_org() FOR UPDATE;
 IF NOT FOUND THEN RETURN jsonb_build_object('status','missing'); END IF;
 INSERT INTO public.lead_registrations(id,partnership_id,brand_org_id,creator_org_id,pipeline_entry_id,display_name,summary,share_contact,contact_name,contact_email,stage,registered_by,registered_at,shared_updated_at)
  VALUES(new_id,p.id,p.brand_org_id,p.creator_org_id,e.id,trim(label),coalesce(note,''),coalesce(with_contact,false),
   CASE WHEN with_contact THEN e.contact_name END,CASE WHEN with_contact THEN e.contact_email END,e.stage,public.app_actor(),moment,moment)
  ON CONFLICT (partnership_id,pipeline_entry_id) WHERE pipeline_entry_id IS NOT NULL DO NOTHING;
 IF FOUND THEN
  INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),public.app_org(),public.app_actor(),'lead_registration.registered',new_id);
  RETURN jsonb_build_object('status','created','id',new_id);
 END IF;
 SELECT * INTO r FROM public.lead_registrations WHERE partnership_id=p.id AND pipeline_entry_id=e.id FOR UPDATE;
 -- A repeated request is a no-op: nothing is duplicated and the first registration time is kept.
 IF r.state='active' THEN RETURN jsonb_build_object('status','existing','id',r.id); END IF;
 -- An explicit re-share of a withdrawn registration in the same partnership keeps its first registration time.
 UPDATE public.lead_registrations SET state='active',withdrawn_at=NULL,withdrawn_reason=NULL,display_name=trim(label),summary=coalesce(note,''),
  share_contact=coalesce(with_contact,false),contact_name=CASE WHEN with_contact THEN e.contact_name END,contact_email=CASE WHEN with_contact THEN e.contact_email END,
  stage=e.stage,shared_updated_at=moment,last_event='updated',version=version+1 WHERE id=r.id;
 INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),public.app_org(),public.app_actor(),'lead_registration.reshared',r.id);
 RETURN jsonb_build_object('status','reshared','id',r.id);
END $$;

CREATE FUNCTION app_update_lead_registration(target uuid, expected integer, label text, note text, with_contact boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE relationship uuid; e public.pipeline_entries; r public.lead_registrations; name_now text; email_now text; changed boolean;
BEGIN
 IF NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin']) THEN RETURN 'denied'; END IF;
 SELECT partnership_id INTO relationship FROM public.lead_registrations WHERE id=target AND creator_org_id=public.app_org();
 IF NOT FOUND THEN RETURN 'missing'; END IF;
 PERFORM 1 FROM public.partnerships WHERE id=relationship AND creator_org_id=public.app_org() AND status='active' FOR SHARE;
 IF NOT FOUND THEN RETURN 'missing'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('pilot:'||public.app_org()::text,0));
 IF NOT public.app_professional_active(public.app_org()) THEN
  RAISE EXCEPTION 'Work is paused. Existing records remain readable and exportable.' USING ERRCODE='P0004';
 END IF;
 SELECT * INTO r FROM public.lead_registrations WHERE id=target AND creator_org_id=public.app_org() FOR UPDATE;
 IF NOT FOUND OR r.state<>'active' THEN RETURN 'missing'; END IF;
 IF r.version<>expected THEN RETURN 'stale'; END IF;
 SELECT * INTO e FROM public.pipeline_entries WHERE id=r.pipeline_entry_id AND org_id=public.app_org();
 name_now := CASE WHEN with_contact THEN e.contact_name END;
 email_now := CASE WHEN with_contact THEN e.contact_email END;
 changed := r.display_name<>trim(label) OR r.summary<>coalesce(note,'') OR r.share_contact<>coalesce(with_contact,false)
  OR r.contact_name IS DISTINCT FROM name_now OR r.contact_email IS DISTINCT FROM email_now;
 IF NOT changed THEN RETURN 'ok'; END IF;
 UPDATE public.lead_registrations SET display_name=trim(label),summary=coalesce(note,''),share_contact=coalesce(with_contact,false),
  contact_name=name_now,contact_email=email_now,shared_updated_at=now(),last_event='updated',version=version+1 WHERE id=target;
 INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),public.app_org(),public.app_actor(),'lead_registration.updated',target);
 RETURN 'ok';
END $$;

-- Withdrawal revokes the Brand's access. It stays available while work is paused or the Brand is suspended.
CREATE FUNCTION app_withdraw_lead_registration(target uuid, expected integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE r public.lead_registrations;
BEGIN
 IF NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin']) THEN RETURN 'denied'; END IF;
 SELECT * INTO r FROM public.lead_registrations WHERE id=target AND creator_org_id=public.app_org() FOR UPDATE;
 IF NOT FOUND OR r.state<>'active' THEN RETURN 'missing'; END IF;
 IF r.version<>expected THEN RETURN 'stale'; END IF;
 UPDATE public.lead_registrations SET state='withdrawn',withdrawn_at=now(),withdrawn_reason='partner',version=version+1 WHERE id=target;
 INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),public.app_org(),public.app_actor(),'lead_registration.withdrawn',target);
 RETURN 'ok';
END $$;

-- Source lead changes reach an existing registration in the same transaction, so both commit or roll back
-- together. Only the stage and an opted-in contact are shared; notes and other private fields are ignored.
CREATE FUNCTION app_sync_lead_registrations() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF TG_OP='DELETE' THEN
  -- Deleting the private lead withdraws its registrations and removes the shared copy of its content.
  WITH gone AS (
   UPDATE public.lead_registrations SET state='withdrawn',withdrawn_at=coalesce(withdrawn_at,now()),withdrawn_reason='source_deleted',pipeline_entry_id=NULL,
    display_name='Deleted lead',summary='',share_contact=false,contact_name=NULL,contact_email=NULL,version=version+1
   WHERE creator_org_id=OLD.org_id AND pipeline_entry_id=OLD.id RETURNING id)
  INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id)
   SELECT gen_random_uuid(),OLD.org_id,public.app_actor(),'lead_registration.source_deleted',gone.id FROM gone WHERE public.app_actor() IS NOT NULL;
  RETURN OLD;
 END IF;
 IF NEW.stage IS DISTINCT FROM OLD.stage OR NEW.contact_name IS DISTINCT FROM OLD.contact_name OR NEW.contact_email IS DISTINCT FROM OLD.contact_email THEN
  UPDATE public.lead_registrations r SET stage=NEW.stage,
   contact_name=CASE WHEN r.share_contact THEN NEW.contact_name END,contact_email=CASE WHEN r.share_contact THEN NEW.contact_email END,
   shared_updated_at=now(),last_event='updated'
  WHERE r.creator_org_id=NEW.org_id AND r.pipeline_entry_id=NEW.id AND r.state='active'
   AND (r.stage IS DISTINCT FROM NEW.stage OR (r.share_contact AND (r.contact_name IS DISTINCT FROM NEW.contact_name OR r.contact_email IS DISTINCT FROM NEW.contact_email)));
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER lead_registration_source_sync AFTER UPDATE ON pipeline_entries FOR EACH ROW EXECUTE FUNCTION app_sync_lead_registrations();
CREATE TRIGGER lead_registration_source_delete BEFORE DELETE ON pipeline_entries FOR EACH ROW EXECUTE FUNCTION app_sync_lead_registrations();

-- Ending a partnership ends its registrations in the same transaction. A later partnership between the same
-- workspaces is a new authorization context and starts with none.
CREATE FUNCTION app_end_partnership_registrations() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF NEW.status='revoked' AND OLD.status='active' THEN
  UPDATE public.lead_registrations SET state='withdrawn',withdrawn_at=now(),withdrawn_reason='partnership_ended',version=version+1
   WHERE partnership_id=NEW.id AND state='active';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER partnership_registrations_end AFTER UPDATE ON partnerships FOR EACH ROW EXECUTE FUNCTION app_end_partnership_registrations();

-- ---------------------------------------------------------------------------------------------
-- 2. Partner sign-in: one timestamp per creator workspace, with no member, device or session detail.
CREATE TABLE workspace_sign_ins (
 org_id uuid PRIMARY KEY REFERENCES organizations(id),
 signed_in_at timestamptz NOT NULL
);
ALTER TABLE workspace_sign_ins ENABLE ROW LEVEL SECURITY;
ALTER TABLE workspace_sign_ins FORCE ROW LEVEL SECURITY;
-- No runtime policies: written and read only through the two functions below.

-- Called once per completed sign-in for the creator workspace that sign-in entered. Brand workspaces and
-- callers without an active, unsuspended membership record nothing.
CREATE FUNCTION app_record_workspace_sign_in() RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF public.app_org() IS NULL OR NOT public.app_has_membership(public.app_org())
  OR NOT EXISTS(SELECT 1 FROM public.organizations WHERE id=public.app_org() AND kind='creator') THEN RETURN false; END IF;
 INSERT INTO public.workspace_sign_ins(org_id,signed_in_at) VALUES(public.app_org(),clock_timestamp())
  ON CONFLICT (org_id) DO UPDATE SET signed_in_at=greatest(public.workspace_sign_ins.signed_in_at,excluded.signed_in_at);
 RETURN true;
END $$;

-- A Brand member learns only the latest sign-in time of an active, unpaused Partner, and only sign-ins made
-- since the partnership was accepted.
CREATE FUNCTION app_partner_signed_in_at(relationship uuid) RETURNS timestamptz LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT s.signed_in_at FROM public.partnerships p JOIN public.workspace_sign_ins s ON s.org_id=p.creator_org_id
 WHERE p.id=relationship AND p.status='active' AND p.brand_org_id=public.app_org() AND public.app_has_membership(p.brand_org_id)
  AND NOT public.app_workspace_suspended(p.creator_org_id) AND s.signed_in_at>=p.accepted_at
$$;

-- ---------------------------------------------------------------------------------------------
-- 3. Shared Partner profile: optional business details a Partner chooses to show connected Brands.
-- Nothing is copied from the account: no account email, phone, address, member list or security data.
CREATE TABLE partner_shared_profiles (
 org_id uuid PRIMARY KEY REFERENCES organizations(id),
 contact_name varchar(120),
 contact_email varchar(255),
 website_url varchar(255) CHECK(website_url IS NULL OR website_url ~ '^https?://[^[:space:]]+$'),
 description varchar(500) NOT NULL DEFAULT '',
 version integer NOT NULL DEFAULT 1 CHECK(version > 0),
 updated_by bigint NOT NULL REFERENCES users(id),
 updated_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE partner_shared_profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE partner_shared_profiles FORCE ROW LEVEL SECURITY;
CREATE POLICY shared_profile_own_read ON partner_shared_profiles FOR SELECT USING(org_id=app_org() AND app_has_membership(org_id));

-- `expected` is 0 while no profile has been saved.
CREATE FUNCTION app_save_shared_profile(expected integer, contact text, email text, website text, about text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE current_version integer;
BEGIN
 IF NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin'])
  OR NOT EXISTS(SELECT 1 FROM public.organizations WHERE id=public.app_org() AND kind='creator') THEN RETURN 'denied'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('pilot:'||public.app_org()::text,0));
 IF NOT public.app_professional_active(public.app_org()) THEN
  RAISE EXCEPTION 'Work is paused. Existing records remain readable and exportable.' USING ERRCODE='P0004';
 END IF;
 SELECT version INTO current_version FROM public.partner_shared_profiles WHERE org_id=public.app_org() FOR UPDATE;
 IF coalesce(current_version,0)<>expected THEN RETURN 'stale'; END IF;
 INSERT INTO public.partner_shared_profiles(org_id,contact_name,contact_email,website_url,description,updated_by)
  VALUES(public.app_org(),nullif(trim(contact),''),nullif(trim(email),''),nullif(trim(website),''),coalesce(trim(about),''),public.app_actor())
  ON CONFLICT (org_id) DO UPDATE SET contact_name=excluded.contact_name,contact_email=excluded.contact_email,website_url=excluded.website_url,
   description=excluded.description,updated_by=excluded.updated_by,updated_at=now(),version=public.partner_shared_profiles.version+1;
 INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),public.app_org(),public.app_actor(),'shared_profile.updated',public.app_org());
 RETURN 'ok';
END $$;

-- The Brand side of an active, unpaused partnership reads the Partner's shared profile, and nothing else.
CREATE FUNCTION app_partner_shared_profile(relationship uuid) RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT jsonb_build_object('contact_name',s.contact_name,'contact_email',s.contact_email,'website_url',s.website_url,'description',s.description)
 FROM public.partnerships p JOIN public.partner_shared_profiles s ON s.org_id=p.creator_org_id
 WHERE p.id=relationship AND p.status='active' AND p.brand_org_id=public.app_org() AND public.app_has_membership(p.brand_org_id)
  AND NOT public.app_workspace_suspended(p.creator_org_id)
$$;

CREATE INDEX partnership_brand_roster ON partnerships(brand_org_id, accepted_at, id) WHERE status='active';

REVOKE ALL ON FUNCTION app_partnership_sharing(uuid),app_register_lead(uuid,uuid,uuid,text,text,boolean),app_update_lead_registration(uuid,integer,text,text,boolean),
 app_withdraw_lead_registration(uuid,integer),app_sync_lead_registrations(),app_end_partnership_registrations(),app_record_workspace_sign_in(),
 app_partner_signed_in_at(uuid),app_save_shared_profile(integer,text,text,text,text),app_partner_shared_profile(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_partnership_sharing(uuid),app_register_lead(uuid,uuid,uuid,text,text,boolean),app_update_lead_registration(uuid,integer,text,text,boolean),
 app_withdraw_lead_registration(uuid,integer),app_record_workspace_sign_in(),app_partner_signed_in_at(uuid),
 app_save_shared_profile(integer,text,text,text,text),app_partner_shared_profile(uuid) TO weavdash_app;
GRANT SELECT ON lead_registrations,partner_shared_profiles TO weavdash_app;
