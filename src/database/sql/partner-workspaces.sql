-- Partner workspaces (D32): records linked to brands through invitations or sign-up links.
ALTER TABLE organizations DROP CONSTRAINT organizations_kind_check;
ALTER TABLE organizations ADD CONSTRAINT organizations_kind_check CHECK(kind IN ('creator','brand','partner'));
ALTER TABLE users DROP CONSTRAINT users_registration_workspace_kind_check;
ALTER TABLE users ADD CONSTRAINT users_registration_workspace_kind_check CHECK(registration_workspace_kind IN ('creator','brand','partner'));

CREATE OR REPLACE FUNCTION app_create_typed_workspace(target uuid, label text, workspace_kind text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
    IF workspace_kind NOT IN ('creator','brand','partner') OR target IS DISTINCT FROM public.app_org()
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

-- Invitations and sign-up links now connect brands only to Partner workspaces.
CREATE OR REPLACE FUNCTION app_accept_partner(digest text, relationship uuid) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE invite public.partner_invitations%ROWTYPE; brand_label text; partner_label text;
BEGIN
    IF NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin']) THEN RETURN false; END IF;
    SELECT name INTO partner_label FROM public.organizations WHERE id=public.app_org() AND kind='partner';
    IF partner_label IS NULL THEN RETURN false; END IF;
    SELECT * INTO invite FROM public.partner_invitations WHERE token_hash=digest FOR UPDATE;
    IF NOT FOUND OR invite.status<>'pending' OR invite.expires_at<=clock_timestamp() OR NOT EXISTS(
      SELECT 1 FROM public.users WHERE id=public.app_actor() AND email_verified_at IS NOT NULL AND lower(email)=invite.recipient_email) THEN RETURN false; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended(invite.brand_org_id::text || public.app_org()::text,0));
    IF EXISTS(SELECT 1 FROM public.partnerships WHERE brand_org_id=invite.brand_org_id AND creator_org_id=public.app_org() AND status='active') THEN RETURN false; END IF;
    SELECT name INTO brand_label FROM public.organizations WHERE id=invite.brand_org_id AND kind='brand';
    IF brand_label IS NULL THEN RETURN false; END IF;
    INSERT INTO public.partnerships(id,brand_org_id,creator_org_id,brand_name,creator_name,invitation_id)
      VALUES(relationship,invite.brand_org_id,public.app_org(),brand_label,partner_label,invite.id);
    UPDATE public.partner_invitations SET status='accepted' WHERE id=invite.id;
    INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(relationship,public.app_org(),public.app_actor(),'partnership.accepted',relationship);
    RETURN true;
END $$;
CREATE OR REPLACE FUNCTION app_join_partner(digest text, relationship uuid) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE link public.partner_join_links%ROWTYPE; brand_label text; partner_label text;
BEGIN
    IF NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin']) THEN RETURN false; END IF;
    SELECT name INTO partner_label FROM public.organizations WHERE id=public.app_org() AND kind='partner';
    IF partner_label IS NULL OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=public.app_actor() AND email_verified_at IS NOT NULL) THEN RETURN false; END IF;
    SELECT * INTO link FROM public.partner_join_links WHERE code_hash=digest AND revoked_at IS NULL FOR SHARE;
    IF NOT FOUND THEN RETURN false; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended(link.brand_org_id::text || public.app_org()::text,0));
    IF EXISTS(SELECT 1 FROM public.partnerships WHERE brand_org_id=link.brand_org_id AND creator_org_id=public.app_org() AND status='active') THEN RETURN false; END IF;
    SELECT name INTO brand_label FROM public.organizations WHERE id=link.brand_org_id AND kind='brand';
    IF brand_label IS NULL THEN RETURN false; END IF;
    INSERT INTO public.partnerships(id,brand_org_id,creator_org_id,brand_name,creator_name,join_link_id)
      VALUES(relationship,link.brand_org_id,public.app_org(),brand_label,partner_label,link.id);
    INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(relationship,public.app_org(),public.app_actor(),'partnership.joined',relationship);
    RETURN true;
END $$;

-- Existing partnerships move from Professional workspaces to their owner's Partner workspace.
-- The previous workspace is recorded per relationship. Consented name snapshots are unchanged.
CREATE TABLE partner_workspace_moves (
 partnership_id uuid PRIMARY KEY REFERENCES partnerships(id),
 previous_org_id uuid NOT NULL REFERENCES organizations(id),
 partner_org_id uuid NOT NULL REFERENCES organizations(id),
 ended_as_duplicate boolean NOT NULL DEFAULT false,
 moved_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE partner_workspace_moves ENABLE ROW LEVEL SECURITY;
ALTER TABLE partner_workspace_moves FORCE ROW LEVEL SECURITY;
DO $$
DECLARE rel record; owner_id bigint; target uuid; duplicate boolean;
BEGIN
    FOR rel IN SELECT p.id, p.brand_org_id, p.creator_org_id, p.status FROM partnerships p
        JOIN organizations o ON o.id=p.creator_org_id AND o.kind='creator' ORDER BY p.accepted_at, p.id LOOP
        SELECT m.user_id INTO owner_id FROM memberships m
          WHERE m.org_id=rel.creator_org_id AND m.role='owner' AND m.revoked_at IS NULL ORDER BY m.created_at, m.user_id LIMIT 1;
        CONTINUE WHEN owner_id IS NULL;
        SELECT o.id INTO target FROM organizations o
          JOIN memberships m ON m.org_id=o.id AND m.user_id=owner_id AND m.role='owner' AND m.revoked_at IS NULL
          WHERE o.kind='partner' ORDER BY o.created_at, o.id LIMIT 1;
        IF target IS NULL THEN
            target := gen_random_uuid();
            INSERT INTO organizations(id,name,kind) SELECT target, left(name,120), 'partner' FROM users WHERE id=owner_id;
            INSERT INTO memberships(org_id,user_id,role) VALUES(target,owner_id,'owner');
            INSERT INTO audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),target,owner_id,'workspace.created',target);
        END IF;
        -- One active relationship per brand and Partner workspace; a later duplicate from another workspace ends.
        duplicate := rel.status='active' AND EXISTS(SELECT 1 FROM partnerships WHERE brand_org_id=rel.brand_org_id AND creator_org_id=target AND status='active');
        IF duplicate THEN
            UPDATE partnerships SET status='revoked', revoked_at=now() WHERE id=rel.id;
        END IF;
        UPDATE partnerships SET creator_org_id=target WHERE id=rel.id;
        INSERT INTO partner_workspace_moves(partnership_id,previous_org_id,partner_org_id,ended_as_duplicate)
          VALUES(rel.id,rel.creator_org_id,target,duplicate);
        INSERT INTO audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),target,owner_id,'partnership.moved',rel.id);
    END LOOP;
END $$;
