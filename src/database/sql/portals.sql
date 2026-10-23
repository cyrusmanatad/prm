-- Additive backfill: every existing workspace is a creator. No CRM data changes.
ALTER TABLE organizations ADD COLUMN kind varchar(8) NOT NULL DEFAULT 'creator' CHECK(kind IN ('creator','brand'));
CREATE FUNCTION app_preserve_workspace_kind() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.kind IS DISTINCT FROM OLD.kind THEN RAISE EXCEPTION 'Workspace type is immutable' USING ERRCODE='42501'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER organization_kind_immutable BEFORE UPDATE ON organizations FOR EACH ROW EXECUTE FUNCTION app_preserve_workspace_kind();
CREATE FUNCTION app_create_typed_workspace(target uuid, label text, workspace_kind text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
    IF workspace_kind NOT IN ('creator','brand') OR target IS DISTINCT FROM public.app_org()
       OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=public.app_actor() AND email_verified_at IS NOT NULL) THEN
        RAISE EXCEPTION 'Verified workspace context required' USING ERRCODE='42501';
    END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended(target::text, 0));
    IF EXISTS(SELECT 1 FROM public.organizations WHERE id=target) THEN
        IF NOT public.app_has_membership(target, ARRAY['owner']) OR NOT EXISTS(SELECT 1 FROM public.organizations WHERE id=target AND kind=workspace_kind) THEN
            RAISE EXCEPTION 'Workspace unavailable' USING ERRCODE='42501';
        END IF;
        RETURN;
    END IF;
    INSERT INTO public.organizations(id,name,kind) VALUES(target,label,workspace_kind);
    INSERT INTO public.memberships(org_id,user_id,role) VALUES(target,public.app_actor(),'owner');
END $$;
REVOKE ALL ON FUNCTION app_create_typed_workspace(uuid,text,text) FROM PUBLIC;

CREATE TABLE partner_invitations (
 id uuid PRIMARY KEY,
 brand_org_id uuid NOT NULL REFERENCES organizations(id),
 recipient_email varchar(255) NOT NULL,
 token_hash char(64) NOT NULL UNIQUE,
 status varchar(12) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','accepted','revoked','expired')),
 expires_at timestamptz NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX partner_invitation_pending ON partner_invitations(brand_org_id,recipient_email) WHERE status='pending';
CREATE TABLE partnerships (
 id uuid PRIMARY KEY,
 brand_org_id uuid NOT NULL REFERENCES organizations(id),
 creator_org_id uuid NOT NULL REFERENCES organizations(id),
 brand_name varchar(120) NOT NULL,
 creator_name varchar(120) NOT NULL,
 invitation_id uuid NOT NULL UNIQUE REFERENCES partner_invitations(id),
 status varchar(8) NOT NULL DEFAULT 'active' CHECK(status IN ('active','revoked')),
 accepted_at timestamptz NOT NULL DEFAULT now(),
 revoked_at timestamptz,
 CHECK(brand_org_id <> creator_org_id),
 CHECK((status='active' AND revoked_at IS NULL) OR (status='revoked' AND revoked_at IS NOT NULL))
);
CREATE UNIQUE INDEX partnership_active_pair ON partnerships(brand_org_id,creator_org_id) WHERE status='active';
ALTER TABLE partner_invitations ENABLE ROW LEVEL SECURITY;
ALTER TABLE partner_invitations FORCE ROW LEVEL SECURITY;
CREATE POLICY invitation_brand_read ON partner_invitations FOR SELECT USING(brand_org_id=app_org() AND app_has_membership(brand_org_id,ARRAY['owner','admin']));
ALTER TABLE partnerships ENABLE ROW LEVEL SECURITY;
ALTER TABLE partnerships FORCE ROW LEVEL SECURITY;
CREATE POLICY partnership_participant_read ON partnerships FOR SELECT USING(status='active' AND
 ((brand_org_id=app_org() AND app_has_membership(brand_org_id)) OR (creator_org_id=app_org() AND app_has_membership(creator_org_id))));

CREATE FUNCTION app_invite_partner(record uuid, email text, digest text) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
    IF NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin']) OR
       NOT EXISTS(SELECT 1 FROM public.organizations WHERE id=public.app_org() AND kind='brand') THEN RETURN false; END IF;
    -- Serialize duplicate issuance and expire old invitations without exposing identities.
    PERFORM pg_advisory_xact_lock(hashtextextended(public.app_org()::text || lower(email),0));
    UPDATE public.partner_invitations SET status='expired' WHERE brand_org_id=public.app_org() AND recipient_email=lower(email) AND status='pending' AND expires_at<=now();
    IF EXISTS(SELECT 1 FROM public.partner_invitations WHERE brand_org_id=public.app_org() AND recipient_email=lower(email) AND status='pending') THEN RETURN false; END IF;
    INSERT INTO public.partner_invitations(id,brand_org_id,recipient_email,token_hash,expires_at)
      VALUES(record,public.app_org(),lower(email),digest,now()+interval '7 days');
    INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(record,public.app_org(),public.app_actor(),'partnership.invited',record);
    RETURN true;
END $$;
CREATE FUNCTION app_accept_partner(digest text, relationship uuid) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE invite public.partner_invitations%ROWTYPE; brand_label text; creator_label text;
BEGIN
    IF NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin']) THEN RETURN false; END IF;
    SELECT name INTO creator_label FROM public.organizations WHERE id=public.app_org() AND kind='creator';
    IF creator_label IS NULL THEN RETURN false; END IF;
    SELECT * INTO invite FROM public.partner_invitations WHERE token_hash=digest FOR UPDATE;
    IF NOT FOUND OR invite.status<>'pending' OR invite.expires_at<=clock_timestamp() OR NOT EXISTS(
      SELECT 1 FROM public.users WHERE id=public.app_actor() AND email_verified_at IS NOT NULL AND lower(email)=invite.recipient_email) THEN RETURN false; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended(invite.brand_org_id::text || public.app_org()::text,0));
    IF EXISTS(SELECT 1 FROM public.partnerships WHERE brand_org_id=invite.brand_org_id AND creator_org_id=public.app_org() AND status='active') THEN RETURN false; END IF;
    SELECT name INTO brand_label FROM public.organizations WHERE id=invite.brand_org_id AND kind='brand';
    IF brand_label IS NULL THEN RETURN false; END IF;
    INSERT INTO public.partnerships(id,brand_org_id,creator_org_id,brand_name,creator_name,invitation_id)
      VALUES(relationship,invite.brand_org_id,public.app_org(),brand_label,creator_label,invite.id);
    UPDATE public.partner_invitations SET status='accepted' WHERE id=invite.id;
    INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(relationship,public.app_org(),public.app_actor(),'partnership.accepted',relationship);
    RETURN true;
END $$;
CREATE FUNCTION app_revoke_partner(target uuid, event_id uuid, invitation boolean) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE changed integer;
BEGIN
    IF NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin']) THEN RETURN false; END IF;
    IF invitation THEN
      UPDATE public.partner_invitations SET status='revoked' WHERE id=target AND brand_org_id=public.app_org() AND status='pending';
    ELSE
      UPDATE public.partnerships SET status='revoked',revoked_at=now() WHERE id=target AND status='active' AND public.app_org() IN (brand_org_id,creator_org_id);
    END IF;
    GET DIAGNOSTICS changed=ROW_COUNT;
    IF changed<>1 THEN RETURN false; END IF;
    INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(event_id,public.app_org(),public.app_actor(),CASE WHEN invitation THEN 'partnership.invitation_revoked' ELSE 'partnership.revoked' END,target);
    RETURN true;
END $$;
REVOKE ALL ON FUNCTION app_invite_partner(uuid,text,text),app_accept_partner(text,uuid),app_revoke_partner(uuid,uuid,boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_create_typed_workspace(uuid,text,text),app_invite_partner(uuid,text,text),app_accept_partner(text,uuid),app_revoke_partner(uuid,uuid,boolean) TO weavdash_app;
GRANT SELECT ON partner_invitations,partnerships TO weavdash_app;
