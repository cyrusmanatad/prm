DROP FUNCTION app_ready_schedule_notices(),app_schedule_notice_current(uuid),app_schedule_notice_basis(notification_outbox);
DELETE FROM notification_outbox WHERE schedule_run_id IS NOT NULL;
ALTER TABLE notification_outbox DROP CONSTRAINT notification_subject_kind, DROP COLUMN schedule_run_id,
ADD CONSTRAINT notification_subject_kind CHECK (
        (kind='partner.invitation' AND subject_id IS NOT NULL AND onboarding_request_id IS NULL AND invitation_hash IS NULL AND onboarding_version IS NULL)
        OR (kind='onboarding.invitation' AND subject_id IS NULL AND onboarding_request_id IS NOT NULL AND invitation_hash IS NOT NULL AND onboarding_version IS NULL)
        OR (kind IN ('onboarding.submitted','onboarding.corrected','onboarding.accepted') AND subject_id IS NULL AND onboarding_request_id IS NOT NULL AND invitation_hash IS NOT NULL AND onboarding_version IS NOT NULL)
    );
ALTER TABLE schedule_runs DROP CONSTRAINT schedule_run_owner;
