ALTER TABLE partner_invitations ADD CONSTRAINT partner_invitation_org_id UNIQUE (brand_org_id,id);
CREATE TABLE notification_outbox (
    id uuid PRIMARY KEY,
    org_id uuid NOT NULL REFERENCES organizations(id),
    actor_id bigint NOT NULL REFERENCES users(id),
    kind varchar(40) NOT NULL CHECK (kind = 'partner.invitation'),
    subject_id uuid NOT NULL,
    encrypted_payload text,
    state varchar(16) NOT NULL DEFAULT 'pending'
      CHECK (state IN ('pending','sending','sent','captured','cancelled','uncertain')),
    created_at timestamptz NOT NULL DEFAULT now(),
    started_at timestamptz,
    completed_at timestamptz,
    UNIQUE (kind, subject_id),
    FOREIGN KEY (org_id,subject_id) REFERENCES partner_invitations(brand_org_id,id)
);
CREATE INDEX notification_outbox_pending ON notification_outbox(created_at) WHERE state='pending';
ALTER TABLE notification_outbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE notification_outbox FORCE ROW LEVEL SECURITY;
CREATE POLICY notification_read ON notification_outbox FOR SELECT
    USING (org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin']));
CREATE POLICY notification_insert ON notification_outbox FOR INSERT
    WITH CHECK (org_id=app_org() AND actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner','admin']));
CREATE POLICY notification_update ON notification_outbox FOR UPDATE
    USING (org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin']))
    WITH CHECK (org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin']));

-- Runtime has no invitation UPDATE policy. Lock only an authorized current-brand
-- invitation through this narrow routine instead of weakening invitation RLS.
CREATE FUNCTION app_lock_notification_invitation(target uuid)
RETURNS TABLE(recipient_email varchar, status varchar, expires_at timestamptz)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
    IF NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin']) THEN RETURN; END IF;
    RETURN QUERY SELECT i.recipient_email,i.status,i.expires_at FROM public.partner_invitations i
      WHERE i.id=target AND i.brand_org_id=public.app_org() FOR UPDATE;
END $$;

-- Cross-workspace maintenance exposes routing identifiers only, never recipients/tokens.
-- Redis is disposable: pending intent remains eligible on every scheduler pass.
CREATE FUNCTION app_pending_notifications() RETURNS TABLE(id uuid, org_id uuid, actor_id bigint)
LANGUAGE sql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
    SELECT n.id,n.org_id,n.actor_id FROM public.notification_outbox n
    WHERE n.state='pending' ORDER BY n.created_at,n.id LIMIT 100
$$;
CREATE FUNCTION app_reconcile_notifications() RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
    UPDATE public.notification_outbox n SET state='cancelled',encrypted_payload=NULL,completed_at=clock_timestamp()
      WHERE n.state='pending' AND NOT EXISTS (
        SELECT 1 FROM public.memberships m JOIN public.users u ON u.id=m.user_id
        WHERE m.org_id=n.org_id AND m.user_id=n.actor_id AND m.revoked_at IS NULL
          AND m.role IN ('owner','admin') AND u.email_verified_at IS NOT NULL);
    -- A crash after SMTP starts has an ambiguous outcome. Never resend automatically.
    UPDATE public.notification_outbox SET state='uncertain',encrypted_payload=NULL,completed_at=clock_timestamp()
      WHERE state='sending' AND started_at < clock_timestamp()-interval '5 minutes';
END $$;
REVOKE ALL ON FUNCTION app_lock_notification_invitation(uuid),app_pending_notifications(),app_reconcile_notifications() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_lock_notification_invitation(uuid),app_pending_notifications(),app_reconcile_notifications() TO weavdash_app;
GRANT SELECT,INSERT,UPDATE ON notification_outbox TO weavdash_app;
