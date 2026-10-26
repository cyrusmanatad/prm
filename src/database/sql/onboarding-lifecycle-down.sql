DROP FUNCTION app_queue_onboarding_submitted(uuid,uuid),app_exchange_onboarding_return(text,text,uuid);
DROP TABLE onboarding_return_links;
DELETE FROM notification_outbox WHERE onboarding_version IS NOT NULL;
DROP INDEX notification_onboarding_invitation_generation;
DROP INDEX notification_onboarding_event;
ALTER TABLE notification_outbox
    DROP CONSTRAINT notification_subject_kind,
    DROP CONSTRAINT notification_lifecycle_target,
    DROP COLUMN onboarding_version,
    ADD CONSTRAINT notification_onboarding_generation UNIQUE (kind,onboarding_request_id,invitation_hash),
    ADD CONSTRAINT notification_subject_kind CHECK (
        (kind='partner.invitation' AND subject_id IS NOT NULL AND onboarding_request_id IS NULL AND invitation_hash IS NULL)
        OR (kind='onboarding.invitation' AND subject_id IS NULL AND onboarding_request_id IS NOT NULL AND invitation_hash IS NOT NULL)
    );
ALTER TABLE onboarding_requests DROP COLUMN submission_notify_user_id;
