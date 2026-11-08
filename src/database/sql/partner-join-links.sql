-- Reusable brand sign-up links: one active link per brand, hashed for lookup, app-encrypted for brand display.
CREATE TABLE partner_join_links (
 id uuid PRIMARY KEY,
 brand_org_id uuid NOT NULL REFERENCES organizations(id),
 code_hash char(64) NOT NULL UNIQUE,
 sealed_code text NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 revoked_at timestamptz
);
CREATE UNIQUE INDEX partner_join_link_active ON partner_join_links(brand_org_id) WHERE revoked_at IS NULL;
ALTER TABLE partner_join_links ENABLE ROW LEVEL SECURITY;
ALTER TABLE partner_join_links FORCE ROW LEVEL SECURITY;
CREATE POLICY join_link_brand_read ON partner_join_links FOR SELECT USING(brand_org_id=app_org() AND app_has_membership(brand_org_id,ARRAY['owner','admin']));

-- A relationship originates from exactly one invitation or join link.
ALTER TABLE partnerships ALTER COLUMN invitation_id DROP NOT NULL;
ALTER TABLE partnerships ADD COLUMN join_link_id uuid REFERENCES partner_join_links(id);
ALTER TABLE partnerships ADD CONSTRAINT partnership_single_origin CHECK((invitation_id IS NULL) <> (join_link_id IS NULL));

CREATE FUNCTION app_issue_join_link(record uuid, digest text, sealed text, event_id uuid, expected uuid) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
    IF NOT public.app_lock_membership(public.app_org()) OR NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin']) OR
       NOT EXISTS(SELECT 1 FROM public.organizations WHERE id=public.app_org() AND kind='brand') THEN RETURN false; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('join-link' || public.app_org()::text,0));
    IF (SELECT id FROM public.partner_join_links WHERE brand_org_id=public.app_org() AND revoked_at IS NULL) IS DISTINCT FROM expected THEN RETURN false; END IF;
    -- Issuing replaces the active link; the previous link and code stop working immediately.
    UPDATE public.partner_join_links SET revoked_at=now() WHERE brand_org_id=public.app_org() AND revoked_at IS NULL;
    INSERT INTO public.partner_join_links(id,brand_org_id,code_hash,sealed_code) VALUES(record,public.app_org(),digest,sealed);
    INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(event_id,public.app_org(),public.app_actor(),'partnership.join_link_issued',record);
    RETURN true;
END $$;
CREATE FUNCTION app_disable_join_link(event_id uuid, expected uuid) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE target uuid;
BEGIN
    IF NOT public.app_lock_membership(public.app_org()) OR NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin']) THEN RETURN false; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('join-link' || public.app_org()::text,0));
    UPDATE public.partner_join_links SET revoked_at=now() WHERE brand_org_id=public.app_org() AND id=expected AND revoked_at IS NULL RETURNING id INTO target;
    IF target IS NULL THEN RETURN false; END IF;
    INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(event_id,public.app_org(),public.app_actor(),'partnership.join_link_disabled',target);
    RETURN true;
END $$;
-- Public landing reveals only the brand display name for an active code.
CREATE FUNCTION app_join_link_brand(digest text) RETURNS text LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
    SELECT o.name FROM public.partner_join_links l JOIN public.organizations o ON o.id=l.brand_org_id AND o.kind='brand'
    WHERE l.code_hash=digest AND l.revoked_at IS NULL;
$$;
CREATE FUNCTION app_join_partner(digest text, relationship uuid) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE link public.partner_join_links%ROWTYPE; brand_label text; creator_label text;
BEGIN
    IF NOT public.app_lock_membership(public.app_org()) OR NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin']) THEN RETURN false; END IF;
    SELECT name INTO creator_label FROM public.organizations WHERE id=public.app_org() AND kind='creator';
    IF creator_label IS NULL OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=public.app_actor() AND email_verified_at IS NOT NULL) THEN RETURN false; END IF;
    SELECT * INTO link FROM public.partner_join_links WHERE code_hash=digest;
    IF NOT FOUND THEN RETURN false; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('join-link' || link.brand_org_id::text,0));
    SELECT * INTO link FROM public.partner_join_links WHERE code_hash=digest AND revoked_at IS NULL FOR SHARE;
    IF NOT FOUND THEN RETURN false; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended(link.brand_org_id::text || public.app_org()::text,0));
    IF EXISTS(SELECT 1 FROM public.partnerships WHERE brand_org_id=link.brand_org_id AND creator_org_id=public.app_org() AND status='active') THEN RETURN false; END IF;
    SELECT name INTO brand_label FROM public.organizations WHERE id=link.brand_org_id AND kind='brand';
    IF brand_label IS NULL THEN RETURN false; END IF;
    INSERT INTO public.partnerships(id,brand_org_id,creator_org_id,brand_name,creator_name,join_link_id)
      VALUES(relationship,link.brand_org_id,public.app_org(),brand_label,creator_label,link.id);
    INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(relationship,public.app_org(),public.app_actor(),'partnership.joined',relationship);
    RETURN true;
END $$;
REVOKE ALL ON FUNCTION app_issue_join_link(uuid,text,text,uuid,uuid),app_disable_join_link(uuid,uuid),app_join_link_brand(text),app_join_partner(text,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_issue_join_link(uuid,text,text,uuid,uuid),app_disable_join_link(uuid,uuid),app_join_link_brand(text),app_join_partner(text,uuid) TO weavdash_app;
GRANT SELECT ON partner_join_links TO weavdash_app;
