ALTER TABLE notification_outbox
    DROP CONSTRAINT notification_outbox_kind_check,
    ALTER COLUMN subject_id DROP NOT NULL,
    ADD COLUMN onboarding_request_id uuid,
    ADD COLUMN invitation_hash char(64),
    ADD CONSTRAINT notification_onboarding_target FOREIGN KEY (org_id,onboarding_request_id) REFERENCES onboarding_requests(org_id,id),
    ADD CONSTRAINT notification_onboarding_generation UNIQUE (kind,onboarding_request_id,invitation_hash),
    ADD CONSTRAINT notification_subject_kind CHECK (
        (kind='partner.invitation' AND subject_id IS NOT NULL AND onboarding_request_id IS NULL AND invitation_hash IS NULL)
        OR (kind='onboarding.invitation' AND subject_id IS NULL AND onboarding_request_id IS NOT NULL AND invitation_hash IS NOT NULL)
    );

-- Widen only onboarding intent to the existing onboarding writer roles.
DROP POLICY notification_read ON notification_outbox;
DROP POLICY notification_insert ON notification_outbox;
DROP POLICY notification_update ON notification_outbox;
CREATE POLICY notification_read ON notification_outbox FOR SELECT
    USING (org_id=app_org() AND app_has_membership(org_id,
        CASE WHEN kind='onboarding.invitation' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END));
CREATE POLICY notification_insert ON notification_outbox FOR INSERT
    WITH CHECK (org_id=app_org() AND actor_id=app_actor() AND app_has_membership(org_id,
        CASE WHEN kind='onboarding.invitation' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END));
CREATE POLICY notification_update ON notification_outbox FOR UPDATE
    USING (org_id=app_org() AND app_has_membership(org_id,
        CASE WHEN kind='onboarding.invitation' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END))
    WITH CHECK (org_id=app_org() AND app_has_membership(org_id,
        CASE WHEN kind='onboarding.invitation' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END));

CREATE OR REPLACE FUNCTION app_reconcile_notifications() RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
    UPDATE public.notification_outbox n SET state='cancelled',encrypted_payload=NULL,completed_at=clock_timestamp()
      WHERE n.state='pending' AND (
        NOT EXISTS (
            SELECT 1 FROM public.memberships m JOIN public.users u ON u.id=m.user_id
            WHERE m.org_id=n.org_id AND m.user_id=n.actor_id AND m.revoked_at IS NULL
              AND m.role=ANY(CASE WHEN n.kind='onboarding.invitation' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END)
              AND u.email_verified_at IS NOT NULL)
        OR (n.kind='onboarding.invitation' AND NOT EXISTS (
            SELECT 1 FROM public.onboarding_requests r WHERE r.id=n.onboarding_request_id AND r.org_id=n.org_id
              AND r.token_hash=n.invitation_hash AND r.consumed_at IS NULL AND r.status<>'revoked' AND r.expires_at>clock_timestamp())));
    UPDATE public.notification_outbox SET state='uncertain',encrypted_payload=NULL,completed_at=clock_timestamp()
      WHERE state='sending' AND started_at < clock_timestamp()-interval '5 minutes';
END $$;
