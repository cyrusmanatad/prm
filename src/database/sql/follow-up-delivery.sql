ALTER TABLE automation_preferences ADD COLUMN follow_up_reviews boolean NOT NULL DEFAULT false;
ALTER TABLE follow_up_drafts ADD CONSTRAINT follow_up_owner UNIQUE(org_id,actor_id,id);
ALTER TABLE notification_outbox
 DROP CONSTRAINT notification_subject_kind,
 ADD COLUMN follow_up_draft_id uuid,
 ADD COLUMN follow_up_version integer CHECK(follow_up_version>0),
 ADD COLUMN follow_up_preference_version integer CHECK(follow_up_preference_version>0),
 ADD CONSTRAINT notification_follow_up FOREIGN KEY(org_id,actor_id,follow_up_draft_id) REFERENCES follow_up_drafts(org_id,actor_id,id),
 ADD CONSTRAINT notification_follow_up_once UNIQUE(kind,follow_up_draft_id,follow_up_version);
ALTER TABLE notification_outbox ADD CONSTRAINT notification_subject_kind CHECK (
        (follow_up_draft_id IS NULL AND follow_up_version IS NULL AND follow_up_preference_version IS NULL AND schedule_run_id IS NULL AND kind='partner.invitation' AND subject_id IS NOT NULL AND onboarding_request_id IS NULL AND invitation_hash IS NULL AND onboarding_version IS NULL)
        OR (follow_up_draft_id IS NULL AND follow_up_version IS NULL AND follow_up_preference_version IS NULL AND schedule_run_id IS NULL AND kind='onboarding.invitation' AND subject_id IS NULL AND onboarding_request_id IS NOT NULL AND invitation_hash IS NOT NULL AND onboarding_version IS NULL)
        OR (follow_up_draft_id IS NULL AND follow_up_version IS NULL AND follow_up_preference_version IS NULL AND schedule_run_id IS NULL AND kind IN ('onboarding.submitted','onboarding.corrected','onboarding.accepted') AND subject_id IS NULL AND onboarding_request_id IS NOT NULL AND invitation_hash IS NOT NULL AND onboarding_version IS NOT NULL)
        OR (follow_up_draft_id IS NULL AND follow_up_version IS NULL AND follow_up_preference_version IS NULL AND kind IN ('schedule.reminder','schedule.weekly_digest') AND subject_id IS NULL AND onboarding_request_id IS NULL AND invitation_hash IS NULL AND onboarding_version IS NULL AND schedule_run_id IS NOT NULL)
        OR (kind IN ('follow_up.outreach','follow_up.review') AND follow_up_draft_id IS NOT NULL AND follow_up_version IS NOT NULL
            AND subject_id IS NULL AND onboarding_request_id IS NULL AND invitation_hash IS NULL AND onboarding_version IS NULL AND schedule_run_id IS NULL
            AND ((kind='follow_up.outreach' AND follow_up_preference_version IS NULL) OR (kind='follow_up.review' AND follow_up_preference_version IS NOT NULL)))
    );

DROP POLICY notification_read ON notification_outbox;
DROP POLICY notification_insert ON notification_outbox;
DROP POLICY notification_update ON notification_outbox;
CREATE POLICY notification_read ON notification_outbox FOR SELECT USING (org_id=app_org() AND CASE WHEN (kind LIKE 'schedule.%' OR kind LIKE 'follow_up.%') THEN actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member'])
        ELSE app_has_membership(org_id,CASE WHEN kind LIKE 'onboarding.%' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END) END);
CREATE POLICY notification_insert ON notification_outbox FOR INSERT WITH CHECK (actor_id=app_actor() AND org_id=app_org() AND CASE WHEN (kind LIKE 'schedule.%' OR kind LIKE 'follow_up.%') THEN actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member'])
        ELSE app_has_membership(org_id,CASE WHEN kind LIKE 'onboarding.%' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END) END);
CREATE POLICY notification_update ON notification_outbox FOR UPDATE USING (org_id=app_org() AND CASE WHEN (kind LIKE 'schedule.%' OR kind LIKE 'follow_up.%') THEN actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member'])
        ELSE app_has_membership(org_id,CASE WHEN kind LIKE 'onboarding.%' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END) END) WITH CHECK (org_id=app_org() AND CASE WHEN (kind LIKE 'schedule.%' OR kind LIKE 'follow_up.%') THEN actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member'])
        ELSE app_has_membership(org_id,CASE WHEN kind LIKE 'onboarding.%' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END) END);

CREATE FUNCTION app_follow_up_notice_basis(n notification_outbox) RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.follow_up_drafts d WHERE d.id=n.follow_up_draft_id AND d.org_id=n.org_id AND d.actor_id=n.actor_id
  AND d.version=n.follow_up_version AND public.app_follow_up_basis(d)
  AND CASE n.kind WHEN 'follow_up.outreach' THEN d.state='approved' AND d.approved_version=d.version
   ELSE d.state='draft' AND EXISTS(SELECT 1 FROM public.automation_preferences p WHERE p.org_id=d.org_id AND p.actor_id=d.actor_id
    AND p.follow_up_reviews AND p.version=n.follow_up_preference_version) END)
$$;
CREATE FUNCTION app_follow_up_notice_current(target uuid) RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT coalesce((SELECT public.app_follow_up_notice_basis(n) FROM public.notification_outbox n
 WHERE n.id=target AND n.org_id=public.app_org() AND n.actor_id=public.app_actor()),false)
$$;
CREATE FUNCTION app_cancel_follow_up_notice() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 UPDATE public.notification_outbox n SET state='cancelled',encrypted_payload=NULL,completed_at=clock_timestamp()
 WHERE n.follow_up_draft_id=NEW.id AND n.org_id=NEW.org_id AND n.actor_id=NEW.actor_id AND n.state='pending'
  AND (n.follow_up_version<>NEW.version OR NEW.state IN ('cancelled','discarded'));
 RETURN NEW;
END $$;
CREATE TRIGGER follow_up_notice_cancel AFTER UPDATE ON follow_up_drafts FOR EACH ROW
 WHEN ((OLD.version,OLD.state) IS DISTINCT FROM (NEW.version,NEW.state)) EXECUTE FUNCTION app_cancel_follow_up_notice();
ALTER FUNCTION app_reconcile_notifications() RENAME TO app_reconcile_notifications_before_delivery;
CREATE OR REPLACE FUNCTION app_reconcile_notifications_before_delivery() RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
    UPDATE public.notification_outbox n SET state='cancelled',encrypted_payload=NULL,completed_at=clock_timestamp()
    WHERE n.state='pending' AND (
        NOT EXISTS (SELECT 1 FROM public.memberships m JOIN public.users u ON u.id=m.user_id
            WHERE m.org_id=n.org_id AND m.user_id=n.actor_id AND m.revoked_at IS NULL
            AND m.role=ANY(CASE WHEN n.kind LIKE 'onboarding.%' OR n.kind LIKE 'schedule.%' OR n.kind LIKE 'follow_up.%' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END)
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
CREATE FUNCTION app_reconcile_notifications() RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM public.app_reconcile_notifications_before_delivery();
 UPDATE public.notification_outbox n SET state='cancelled',encrypted_payload=NULL,completed_at=clock_timestamp()
 WHERE n.state='pending' AND ((n.kind LIKE 'follow_up.%' AND NOT public.app_follow_up_notice_basis(n)) OR NOT public.app_professional_active(n.org_id));
END $$;
REVOKE ALL ON FUNCTION app_follow_up_notice_basis(notification_outbox),app_cancel_follow_up_notice(),app_reconcile_notifications_before_delivery(),app_follow_up_notice_current(uuid),app_reconcile_notifications() FROM PUBLIC;
REVOKE ALL ON FUNCTION app_reconcile_notifications_before_delivery() FROM weavdash_app;
GRANT EXECUTE ON FUNCTION app_follow_up_notice_current(uuid),app_reconcile_notifications() TO weavdash_app;
