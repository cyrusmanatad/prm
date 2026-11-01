-- Reverts D32 only while no Partner workspace exists: audited workspaces cannot be deleted or retyped.
DO $$
BEGIN
    IF EXISTS(SELECT 1 FROM organizations WHERE kind='partner') THEN
        RAISE EXCEPTION 'Partner workspaces exist; rolling back D32 would orphan their relationships.';
    END IF;
END $$;
DROP TABLE partner_workspace_moves;
CREATE OR REPLACE FUNCTION app_create_typed_workspace(target uuid, label text, workspace_kind text) RETURNS void
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
CREATE OR REPLACE FUNCTION app_accept_partner(digest text, relationship uuid) RETURNS boolean
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
CREATE OR REPLACE FUNCTION app_join_partner(digest text, relationship uuid) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE link public.partner_join_links%ROWTYPE; brand_label text; creator_label text;
BEGIN
    IF NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin']) THEN RETURN false; END IF;
    SELECT name INTO creator_label FROM public.organizations WHERE id=public.app_org() AND kind='creator';
    IF creator_label IS NULL OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=public.app_actor() AND email_verified_at IS NOT NULL) THEN RETURN false; END IF;
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
ALTER TABLE users DROP CONSTRAINT users_registration_workspace_kind_check;
ALTER TABLE users ADD CONSTRAINT users_registration_workspace_kind_check CHECK(registration_workspace_kind IN ('creator','brand'));
ALTER TABLE organizations DROP CONSTRAINT organizations_kind_check;
ALTER TABLE organizations ADD CONSTRAINT organizations_kind_check CHECK(kind IN ('creator','brand'));
