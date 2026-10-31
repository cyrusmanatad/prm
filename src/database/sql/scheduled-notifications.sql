ALTER TABLE schedule_runs ADD CONSTRAINT schedule_run_owner UNIQUE(org_id,actor_id,id);
ALTER TABLE notification_outbox
    DROP CONSTRAINT notification_subject_kind,
    ADD COLUMN schedule_run_id uuid UNIQUE,
    ADD CONSTRAINT notification_schedule_run FOREIGN KEY(org_id,actor_id,schedule_run_id) REFERENCES schedule_runs(org_id,actor_id,id),
ADD CONSTRAINT notification_subject_kind CHECK (
        (schedule_run_id IS NULL AND kind='partner.invitation' AND subject_id IS NOT NULL AND onboarding_request_id IS NULL AND invitation_hash IS NULL AND onboarding_version IS NULL)
        OR (schedule_run_id IS NULL AND kind='onboarding.invitation' AND subject_id IS NULL AND onboarding_request_id IS NOT NULL AND invitation_hash IS NOT NULL AND onboarding_version IS NULL)
        OR (schedule_run_id IS NULL AND kind IN ('onboarding.submitted','onboarding.corrected','onboarding.accepted') AND subject_id IS NULL AND onboarding_request_id IS NOT NULL AND invitation_hash IS NOT NULL AND onboarding_version IS NOT NULL)
        OR (kind IN ('schedule.reminder','schedule.weekly_digest') AND subject_id IS NULL AND onboarding_request_id IS NULL AND invitation_hash IS NULL AND onboarding_version IS NULL AND schedule_run_id IS NOT NULL)
    );

-- Scheduled notices are private to their actor, even from workspace administrators.
DROP POLICY notification_read ON notification_outbox;
DROP POLICY notification_insert ON notification_outbox;
DROP POLICY notification_update ON notification_outbox;
CREATE POLICY notification_read ON notification_outbox FOR SELECT USING (org_id=app_org() AND CASE WHEN kind LIKE 'schedule.%' THEN actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member'])
        ELSE app_has_membership(org_id,CASE WHEN kind LIKE 'onboarding.%' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END) END);
CREATE POLICY notification_insert ON notification_outbox FOR INSERT WITH CHECK (actor_id=app_actor() AND org_id=app_org() AND CASE WHEN kind LIKE 'schedule.%' THEN actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member'])
        ELSE app_has_membership(org_id,CASE WHEN kind LIKE 'onboarding.%' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END) END);
CREATE POLICY notification_update ON notification_outbox FOR UPDATE USING (org_id=app_org() AND CASE WHEN kind LIKE 'schedule.%' THEN actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member'])
        ELSE app_has_membership(org_id,CASE WHEN kind LIKE 'onboarding.%' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END) END) WITH CHECK (org_id=app_org() AND CASE WHEN kind LIKE 'schedule.%' THEN actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member'])
        ELSE app_has_membership(org_id,CASE WHEN kind LIKE 'onboarding.%' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END) END);

CREATE FUNCTION app_ready_schedule_notices() RETURNS TABLE(id uuid,org_id uuid,actor_id bigint,schedule_id uuid)
LANGUAGE sql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT r.id,r.org_id,r.actor_id,r.schedule_id FROM public.schedule_runs r
 JOIN public.business_schedules s ON s.id=r.schedule_id
 WHERE r.state='ready' AND s.kind IN ('reminder','weekly_digest') ORDER BY r.created_at,r.id LIMIT 100
$$;
-- Internal content-free predicate; callers cannot inspect another actor's basis.
CREATE FUNCTION app_schedule_notice_basis(n notification_outbox) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.schedule_runs r JOIN public.business_schedules s ON s.id=r.schedule_id
 WHERE r.id=n.schedule_run_id AND r.org_id=n.org_id AND r.actor_id=n.actor_id AND r.state='completed'
 AND n.kind='schedule.' || s.kind AND r.schedule_version=s.version AND r.preference_version=s.preference_version
 AND r.rule_version=s.rule_version AND public.app_schedule_basis(s)
 AND (s.kind<>'weekly_digest' OR NOT EXISTS(SELECT 1 FROM public.schedule_runs newer
 WHERE newer.schedule_id=s.id AND newer.schedule_version=s.version AND newer.due_at>r.due_at AND newer.state<>'cancelled')))
$$;
CREATE FUNCTION app_schedule_notice_current(target uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT coalesce((SELECT public.app_schedule_notice_basis(n) FROM public.notification_outbox n
 WHERE n.id=target AND n.org_id=public.app_org() AND n.actor_id=public.app_actor()),false)
$$;
REVOKE ALL ON FUNCTION app_ready_schedule_notices(),app_schedule_notice_basis(notification_outbox),app_schedule_notice_current(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_ready_schedule_notices(),app_schedule_notice_current(uuid) TO weavdash_app;

CREATE OR REPLACE FUNCTION app_reconcile_notifications() RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
    UPDATE public.notification_outbox n SET state='cancelled',encrypted_payload=NULL,completed_at=clock_timestamp()
    WHERE n.state='pending' AND (
        NOT EXISTS (SELECT 1 FROM public.memberships m JOIN public.users u ON u.id=m.user_id
            WHERE m.org_id=n.org_id AND m.user_id=n.actor_id AND m.revoked_at IS NULL
            AND m.role=ANY(CASE WHEN n.kind LIKE 'onboarding.%' OR n.kind LIKE 'schedule.%' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END)
            AND u.email_verified_at IS NOT NULL)
        OR (n.kind LIKE 'schedule.%' AND NOT public.app_schedule_notice_basis(n))
        OR (n.kind LIKE 'onboarding.%' AND NOT EXISTS (
            SELECT 1 FROM public.onboarding_requests r WHERE r.id=n.onboarding_request_id AND r.org_id=n.org_id
            AND r.token_hash=n.invitation_hash AND r.status<>'revoked' AND r.expires_at>clock_timestamp()
            AND ((n.kind='onboarding.invitation' AND r.consumed_at IS NULL)
                OR (n.onboarding_version=r.version AND (
                    (n.kind='onboarding.submitted' AND r.status='submitted' AND r.submission_notify_user_id=n.actor_id)
                    OR (n.kind='onboarding.corrected' AND r.status='correction')
                    OR (n.kind='onboarding.accepted' AND r.status='accepted'))))))
        OR (n.kind IN ('onboarding.corrected','onboarding.accepted') AND NOT EXISTS (
            SELECT 1 FROM public.onboarding_return_links l WHERE l.notification_id=n.id AND l.consumed_at IS NULL AND l.expires_at>clock_timestamp())));
    UPDATE public.notification_outbox SET state='uncertain',encrypted_payload=NULL,completed_at=clock_timestamp()
        WHERE state='sending' AND started_at<clock_timestamp()-interval '5 minutes';
END $$;
