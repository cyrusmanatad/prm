-- M3 public projections and private, resource-scoped onboarding.
CREATE TABLE media_kits (
 id uuid PRIMARY KEY, org_id uuid NOT NULL UNIQUE REFERENCES organizations(id),
 title varchar(160) NOT NULL, bio text NOT NULL DEFAULT '' CHECK(length(bio)<=5000),
 website varchar(500) NOT NULL DEFAULT '', published boolean NOT NULL DEFAULT false,
 consented_at timestamptz, views bigint NOT NULL DEFAULT 0, version integer NOT NULL DEFAULT 1 CHECK(version>0),
 CHECK(NOT published OR consented_at IS NOT NULL)
);
CREATE TABLE lead_links (
 id uuid PRIMARY KEY, org_id uuid NOT NULL REFERENCES organizations(id), token_hash char(64) NOT NULL UNIQUE,
 expires_at timestamptz NOT NULL, revoked_at timestamptz, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE public_lead_receipts (
 id uuid PRIMARY KEY, org_id uuid NOT NULL REFERENCES organizations(id), link_id uuid NOT NULL REFERENCES lead_links(id),
 idempotency_key uuid NOT NULL, payload_hash char(64) NOT NULL, UNIQUE(link_id,idempotency_key)
);
CREATE TABLE onboarding_requests (
 id uuid PRIMARY KEY, org_id uuid NOT NULL REFERENCES organizations(id), pipeline_entry_id uuid NOT NULL,
 label varchar(160) NOT NULL, status varchar(20) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','submitted','correction','accepted','revoked')),
 business_name varchar(160) NOT NULL DEFAULT '', contact_name varchar(120) NOT NULL DEFAULT '', business_email varchar(255) NOT NULL DEFAULT '',
 website varchar(500) NOT NULL DEFAULT '', brief text NOT NULL DEFAULT '' CHECK(length(brief)<=5000), correction_note text NOT NULL DEFAULT '' CHECK(length(correction_note)<=2000),
 token_hash char(64) NOT NULL UNIQUE, consumed_at timestamptz, expires_at timestamptz NOT NULL,
 version integer NOT NULL DEFAULT 1 CHECK(version>0), created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(org_id,pipeline_entry_id) REFERENCES pipeline_entries(org_id,id), UNIQUE(org_id,id)
);
CREATE INDEX onboarding_requests_org_created ON onboarding_requests(org_id,created_at DESC,id DESC);
CREATE TABLE onboarding_sessions (
 id uuid PRIMARY KEY, org_id uuid NOT NULL, request_id uuid NOT NULL, token_hash char(64) NOT NULL UNIQUE, expires_at timestamptz NOT NULL,
 FOREIGN KEY(org_id,request_id) REFERENCES onboarding_requests(org_id,id) ON DELETE CASCADE
);
CREATE TABLE onboarding_documents (
 id uuid PRIMARY KEY, org_id uuid NOT NULL, request_id uuid NOT NULL,
 name varchar(120) NOT NULL, object_key varchar(300) NOT NULL UNIQUE, checksum char(64) NOT NULL, size integer NOT NULL CHECK(size>0 AND size<=2097152),
 mime varchar(80) NOT NULL CHECK(mime IN ('application/pdf','image/png','image/jpeg','text/plain')),
 scan_state varchar(16) NOT NULL DEFAULT 'pending' CHECK(scan_state IN ('pending','clean','rejected')),
 FOREIGN KEY(org_id,request_id) REFERENCES onboarding_requests(org_id,id), UNIQUE(org_id,id)
);
CREATE TABLE onboarding_submissions (
 id uuid PRIMARY KEY, org_id uuid NOT NULL, request_id uuid NOT NULL, revision integer NOT NULL,
 snapshot jsonb NOT NULL, consent_at timestamptz NOT NULL DEFAULT now(), idempotency_key uuid NOT NULL, payload_hash char(64) NOT NULL,
 FOREIGN KEY(org_id,request_id) REFERENCES onboarding_requests(org_id,id), UNIQUE(request_id,revision), UNIQUE(request_id,idempotency_key)
);
CREATE TABLE onboarding_events (
 id uuid PRIMARY KEY, org_id uuid NOT NULL, request_id uuid NOT NULL, kind varchar(40) NOT NULL, occurred_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(org_id,request_id) REFERENCES onboarding_requests(org_id,id)
);
CREATE FUNCTION app_onboarding_access(target uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.onboarding_sessions s JOIN public.onboarding_requests r ON r.id=s.request_id
 WHERE s.request_id=target AND s.token_hash=current_setting('app.capability',true)
 AND s.expires_at>now() AND r.expires_at>now() AND r.status<>'revoked')
$$;
REVOKE ALL ON FUNCTION app_onboarding_access(uuid) FROM PUBLIC;
CREATE FUNCTION app_onboarding_scope(secret text) RETURNS TABLE(org uuid,request uuid) LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 RETURN QUERY SELECT r.org_id,r.id FROM public.onboarding_requests r JOIN public.onboarding_sessions s ON s.request_id=r.id
 WHERE s.token_hash=secret AND s.expires_at>now() AND r.expires_at>now() AND r.status<>'revoked' FOR UPDATE OF r;
END $$;
REVOKE ALL ON FUNCTION app_onboarding_scope(text) FROM PUBLIC;
CREATE FUNCTION app_exchange_onboarding(invite_hash text,session_hash text,session_id uuid) RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE r public.onboarding_requests%ROWTYPE;
BEGIN
 SELECT * INTO r FROM public.onboarding_requests WHERE token_hash=invite_hash AND consumed_at IS NULL AND expires_at>now() AND status<>'revoked' FOR UPDATE;
 IF NOT FOUND THEN RETURN false; END IF;
 UPDATE public.onboarding_requests SET consumed_at=now() WHERE id=r.id;
 INSERT INTO public.onboarding_sessions(id,org_id,request_id,token_hash,expires_at) VALUES(session_id,r.org_id,r.id,session_hash,least(r.expires_at,now()+interval '24 hours'));
 RETURN true;
END $$;
REVOKE ALL ON FUNCTION app_exchange_onboarding(text,text,uuid) FROM PUBLIC;
CREATE FUNCTION app_public_kit(target uuid) RETURNS TABLE(title varchar,bio text,website varchar) LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 UPDATE public.media_kits k SET views=k.views+1 WHERE k.id=target AND k.published AND k.consented_at IS NOT NULL;
 RETURN QUERY SELECT k.title,k.bio,k.website FROM public.media_kits k WHERE k.id=target AND k.published AND k.consented_at IS NOT NULL;
END $$;
REVOKE ALL ON FUNCTION app_public_kit(uuid) FROM PUBLIC;
CREATE FUNCTION app_capture_lead(secret text,key uuid,digest text,brand text,contact text,email text,new_id uuid) RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
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
 INSERT INTO public.pipeline_entries(id,org_id,brand_name,contact_name,contact_email,currency) VALUES(new_id,l.org_id,brand,contact,email,'PHP');
 INSERT INTO public.public_lead_receipts(id,org_id,link_id,idempotency_key,payload_hash) VALUES(new_id,l.org_id,l.id,key,digest);
 RETURN true;
END $$;
REVOKE ALL ON FUNCTION app_capture_lead(text,uuid,text,text,text,text,uuid) FROM PUBLIC;
ALTER TABLE media_kits ENABLE ROW LEVEL SECURITY;
ALTER TABLE media_kits FORCE ROW LEVEL SECURITY;
CREATE POLICY media_kits_read ON media_kits FOR SELECT USING((org_id=app_org() AND app_has_membership(org_id)));
CREATE POLICY media_kits_insert ON media_kits FOR INSERT WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY media_kits_update ON media_kits FOR UPDATE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member'])) WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY media_kits_delete ON media_kits FOR DELETE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
ALTER TABLE lead_links ENABLE ROW LEVEL SECURITY;
ALTER TABLE lead_links FORCE ROW LEVEL SECURITY;
CREATE POLICY lead_links_read ON lead_links FOR SELECT USING((org_id=app_org() AND app_has_membership(org_id)));
CREATE POLICY lead_links_insert ON lead_links FOR INSERT WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY lead_links_update ON lead_links FOR UPDATE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member'])) WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY lead_links_delete ON lead_links FOR DELETE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
ALTER TABLE public_lead_receipts ENABLE ROW LEVEL SECURITY;
ALTER TABLE public_lead_receipts FORCE ROW LEVEL SECURITY;
CREATE POLICY public_lead_receipts_read ON public_lead_receipts FOR SELECT USING((org_id=app_org() AND app_has_membership(org_id)));
ALTER TABLE onboarding_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE onboarding_requests FORCE ROW LEVEL SECURITY;
CREATE POLICY onboarding_requests_read ON onboarding_requests FOR SELECT USING(org_id=app_org() AND (app_has_membership(org_id) OR app_onboarding_access(id)));
CREATE POLICY onboarding_requests_insert ON onboarding_requests FOR INSERT WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY onboarding_requests_update ON onboarding_requests FOR UPDATE USING(org_id=app_org() AND (app_has_membership(org_id,ARRAY['owner','admin','manager','member']) OR app_onboarding_access(id))) WITH CHECK(org_id=app_org() AND (app_has_membership(org_id,ARRAY['owner','admin','manager','member']) OR app_onboarding_access(id)));
CREATE POLICY onboarding_requests_delete ON onboarding_requests FOR DELETE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
ALTER TABLE onboarding_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE onboarding_sessions FORCE ROW LEVEL SECURITY;
CREATE POLICY onboarding_sessions_read ON onboarding_sessions FOR SELECT USING((org_id=app_org() AND app_has_membership(org_id)));
CREATE POLICY onboarding_sessions_insert ON onboarding_sessions FOR INSERT WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY onboarding_sessions_update ON onboarding_sessions FOR UPDATE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member'])) WITH CHECK(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY onboarding_sessions_delete ON onboarding_sessions FOR DELETE USING(org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
ALTER TABLE onboarding_documents ENABLE ROW LEVEL SECURITY;
ALTER TABLE onboarding_documents FORCE ROW LEVEL SECURITY;
CREATE POLICY onboarding_documents_read ON onboarding_documents FOR SELECT USING(org_id=app_org() AND (app_has_membership(org_id) OR app_onboarding_access(request_id)));
CREATE POLICY onboarding_documents_insert ON onboarding_documents FOR INSERT WITH CHECK(org_id=app_org() AND (app_has_membership(org_id,ARRAY['owner','admin','manager','member']) OR app_onboarding_access(request_id)));
CREATE POLICY onboarding_documents_update ON onboarding_documents FOR UPDATE USING(org_id=app_org() AND (app_has_membership(org_id,ARRAY['owner','admin','manager','member']) OR app_onboarding_access(request_id))) WITH CHECK(org_id=app_org() AND (app_has_membership(org_id,ARRAY['owner','admin','manager','member']) OR app_onboarding_access(request_id)));
CREATE POLICY onboarding_documents_delete ON onboarding_documents FOR DELETE USING(org_id=app_org() AND (app_has_membership(org_id,ARRAY['owner','admin','manager','member']) OR app_onboarding_access(request_id)));
ALTER TABLE onboarding_submissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE onboarding_submissions FORCE ROW LEVEL SECURITY;
CREATE POLICY onboarding_submissions_read ON onboarding_submissions FOR SELECT USING(org_id=app_org() AND (app_has_membership(org_id) OR app_onboarding_access(request_id)));
CREATE POLICY onboarding_submissions_append ON onboarding_submissions FOR INSERT WITH CHECK(org_id=app_org() AND (app_has_membership(org_id,ARRAY['owner','admin','manager','member']) OR app_onboarding_access(request_id)));
ALTER TABLE onboarding_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE onboarding_events FORCE ROW LEVEL SECURITY;
CREATE POLICY onboarding_events_read ON onboarding_events FOR SELECT USING(org_id=app_org() AND (app_has_membership(org_id) OR app_onboarding_access(request_id)));
CREATE POLICY onboarding_events_append ON onboarding_events FOR INSERT WITH CHECK(org_id=app_org() AND (app_has_membership(org_id,ARRAY['owner','admin','manager','member']) OR app_onboarding_access(request_id)));
