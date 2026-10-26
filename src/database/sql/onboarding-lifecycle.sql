ALTER TABLE onboarding_requests ADD COLUMN submission_notify_user_id bigint REFERENCES users(id);
ALTER TABLE notification_outbox
    DROP CONSTRAINT notification_subject_kind,
    DROP CONSTRAINT notification_onboarding_generation,
    ADD COLUMN onboarding_version integer CHECK (onboarding_version>0),
    ADD CONSTRAINT notification_lifecycle_target UNIQUE (org_id,onboarding_request_id,id),
    ADD CONSTRAINT notification_subject_kind CHECK (
        (kind='partner.invitation' AND subject_id IS NOT NULL AND onboarding_request_id IS NULL AND invitation_hash IS NULL AND onboarding_version IS NULL)
        OR (kind='onboarding.invitation' AND subject_id IS NULL AND onboarding_request_id IS NOT NULL AND invitation_hash IS NOT NULL AND onboarding_version IS NULL)
        OR (kind IN ('onboarding.submitted','onboarding.corrected','onboarding.accepted') AND subject_id IS NULL AND onboarding_request_id IS NOT NULL AND invitation_hash IS NOT NULL AND onboarding_version IS NOT NULL)
    );
CREATE UNIQUE INDEX notification_onboarding_invitation_generation ON notification_outbox(kind,onboarding_request_id,invitation_hash) WHERE kind='onboarding.invitation';
CREATE UNIQUE INDEX notification_onboarding_event ON notification_outbox(kind,onboarding_request_id,onboarding_version) WHERE onboarding_version IS NOT NULL;

CREATE TABLE onboarding_return_links (
    id uuid PRIMARY KEY, org_id uuid NOT NULL, request_id uuid NOT NULL,
    notification_id uuid NOT NULL UNIQUE, token_hash char(64) NOT NULL UNIQUE,
    expires_at timestamptz NOT NULL, consumed_at timestamptz,
    FOREIGN KEY (org_id,request_id,notification_id) REFERENCES notification_outbox(org_id,onboarding_request_id,id)
);
ALTER TABLE onboarding_return_links ENABLE ROW LEVEL SECURITY;
ALTER TABLE onboarding_return_links FORCE ROW LEVEL SECURITY;
CREATE POLICY onboarding_return_read ON onboarding_return_links FOR SELECT
    USING (org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY onboarding_return_insert ON onboarding_return_links FOR INSERT
    WITH CHECK (org_id=app_org() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
-- Runtime cannot consume or alter return links directly; only the exchange routine does so.
GRANT SELECT,INSERT ON onboarding_return_links TO weavdash_app;

DROP POLICY notification_read ON notification_outbox;
DROP POLICY notification_insert ON notification_outbox;
DROP POLICY notification_update ON notification_outbox;
CREATE POLICY notification_read ON notification_outbox FOR SELECT
    USING (org_id=app_org() AND app_has_membership(org_id,
        CASE WHEN kind LIKE 'onboarding.%' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END));
CREATE POLICY notification_insert ON notification_outbox FOR INSERT
    WITH CHECK (org_id=app_org() AND actor_id=app_actor() AND app_has_membership(org_id,
        CASE WHEN kind LIKE 'onboarding.%' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END));
CREATE POLICY notification_update ON notification_outbox FOR UPDATE
    USING (org_id=app_org() AND app_has_membership(org_id,
        CASE WHEN kind LIKE 'onboarding.%' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END))
    WITH CHECK (org_id=app_org() AND app_has_membership(org_id,
        CASE WHEN kind LIKE 'onboarding.%' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END));

-- No caller-selected recipient, actor, content or foreign request. The form's
-- capability may only notify the writer who explicitly opted in for this generation.
CREATE FUNCTION app_queue_onboarding_submitted(target uuid,notice uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE r public.onboarding_requests%ROWTYPE;
BEGIN
    IF NOT public.app_onboarding_access(target) THEN RETURN; END IF;
    SELECT * INTO r FROM public.onboarding_requests WHERE id=target AND org_id=public.app_org() AND status='submitted' FOR UPDATE;
    IF NOT FOUND OR r.submission_notify_user_id IS NULL THEN RETURN; END IF;
    IF NOT EXISTS (SELECT 1 FROM public.memberships m JOIN public.users u ON u.id=m.user_id
        WHERE m.org_id=r.org_id AND m.user_id=r.submission_notify_user_id AND m.revoked_at IS NULL
        AND m.role IN ('owner','admin','manager','member') AND u.email_verified_at IS NOT NULL) THEN RETURN; END IF;
    INSERT INTO public.notification_outbox(id,org_id,actor_id,kind,onboarding_request_id,invitation_hash,onboarding_version)
    VALUES(notice,r.org_id,r.submission_notify_user_id,'onboarding.submitted',r.id,r.token_hash,r.version)
    ON CONFLICT DO NOTHING;
END $$;

CREATE FUNCTION app_exchange_onboarding_return(invite_hash text,session_hash text,session_id uuid) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE r public.onboarding_requests%ROWTYPE; l public.onboarding_return_links%ROWTYPE; n public.notification_outbox%ROWTYPE;
BEGIN
    -- Match other onboarding mutations' request-first lock order.
    SELECT r0.* INTO r FROM public.onboarding_requests r0 JOIN public.onboarding_return_links l0 ON l0.request_id=r0.id
        WHERE l0.token_hash=invite_hash FOR UPDATE OF r0;
    IF NOT FOUND OR r.status='revoked' OR r.expires_at<=now() THEN RETURN false; END IF;
    SELECT * INTO l FROM public.onboarding_return_links WHERE token_hash=invite_hash FOR UPDATE;
    IF NOT FOUND OR l.consumed_at IS NOT NULL OR l.expires_at<=now() THEN RETURN false; END IF;
    SELECT * INTO n FROM public.notification_outbox WHERE id=l.notification_id;
    IF n.state='cancelled' OR n.invitation_hash<>r.token_hash OR n.onboarding_version<>r.version
       OR NOT ((n.kind='onboarding.corrected' AND r.status='correction') OR (n.kind='onboarding.accepted' AND r.status='accepted')) THEN RETURN false; END IF;
    IF NOT EXISTS (SELECT 1 FROM public.memberships m JOIN public.users u ON u.id=m.user_id
        WHERE m.org_id=r.org_id AND m.user_id=n.actor_id AND m.revoked_at IS NULL
        AND m.role IN ('owner','admin','manager','member') AND u.email_verified_at IS NOT NULL) THEN RETURN false; END IF;
    UPDATE public.onboarding_return_links SET consumed_at=now() WHERE id=l.id;
    INSERT INTO public.onboarding_sessions(id,org_id,request_id,token_hash,expires_at)
        VALUES(session_id,r.org_id,r.id,session_hash,least(r.expires_at,l.expires_at,now()+interval '24 hours'));
    RETURN true;
END $$;

CREATE OR REPLACE FUNCTION app_reconcile_notifications() RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
    UPDATE public.notification_outbox n SET state='cancelled',encrypted_payload=NULL,completed_at=clock_timestamp()
    WHERE n.state='pending' AND (
        NOT EXISTS (SELECT 1 FROM public.memberships m JOIN public.users u ON u.id=m.user_id
            WHERE m.org_id=n.org_id AND m.user_id=n.actor_id AND m.revoked_at IS NULL
            AND m.role=ANY(CASE WHEN n.kind LIKE 'onboarding.%' THEN ARRAY['owner','admin','manager','member'] ELSE ARRAY['owner','admin'] END)
            AND u.email_verified_at IS NOT NULL)
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
REVOKE ALL ON FUNCTION app_queue_onboarding_submitted(uuid,uuid),app_exchange_onboarding_return(text,text,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_queue_onboarding_submitted(uuid,uuid),app_exchange_onboarding_return(text,text,uuid) TO weavdash_app;
