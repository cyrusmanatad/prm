-- Rolling back this feature discards only its notification history, not onboarding records.
DELETE FROM notification_outbox WHERE kind='onboarding.invitation';
ALTER TABLE notification_outbox
    DROP CONSTRAINT notification_subject_kind,
    DROP COLUMN onboarding_request_id,
    DROP COLUMN invitation_hash,
    ALTER COLUMN subject_id SET NOT NULL,
    ADD CONSTRAINT notification_outbox_kind_check CHECK (kind='partner.invitation');
DROP POLICY notification_read ON notification_outbox;
DROP POLICY notification_insert ON notification_outbox;
DROP POLICY notification_update ON notification_outbox;
CREATE POLICY notification_read ON notification_outbox FOR SELECT
    USING (org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin']));
CREATE POLICY notification_insert ON notification_outbox FOR INSERT
    WITH CHECK (org_id=app_org() AND actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner','admin']));
CREATE POLICY notification_update ON notification_outbox FOR UPDATE
    USING (org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin']))
    WITH CHECK (org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin']));
CREATE OR REPLACE FUNCTION app_reconcile_notifications() RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
    UPDATE public.notification_outbox n SET state='cancelled',encrypted_payload=NULL,completed_at=clock_timestamp()
      WHERE n.state='pending' AND NOT EXISTS (
        SELECT 1 FROM public.memberships m JOIN public.users u ON u.id=m.user_id
        WHERE m.org_id=n.org_id AND m.user_id=n.actor_id AND m.revoked_at IS NULL
          AND m.role IN ('owner','admin') AND u.email_verified_at IS NOT NULL);
    UPDATE public.notification_outbox SET state='uncertain',encrypted_payload=NULL,completed_at=clock_timestamp()
      WHERE state='sending' AND started_at < clock_timestamp()-interval '5 minutes';
END $$;
