-- PRIV-02: private, requester-owned export receipts. No platform/support override.
CREATE TABLE privacy_exports (
 id uuid PRIMARY KEY, actor_id bigint NOT NULL REFERENCES users(id),
 scope varchar(12) NOT NULL CHECK(scope IN ('account','workspace')), org_id uuid REFERENCES organizations(id),
 state varchar(12) NOT NULL DEFAULT 'building' CHECK(state IN ('building','ready','failed','expired')),
 fingerprint char(64), checksum char(64), size bigint,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 expires_at timestamptz NOT NULL DEFAULT clock_timestamp()+interval '24 hours',
 CHECK((scope='account')=(org_id IS NULL)),
 CHECK(expires_at>created_at AND expires_at<=created_at+interval '24 hours 1 second'),
 CHECK(state<>'ready' OR (fingerprint ~ '^[a-f0-9]{64}$' AND checksum ~ '^[a-f0-9]{64}$' AND size>0))
);
CREATE INDEX privacy_exports_owner ON privacy_exports(actor_id,created_at DESC,id);
CREATE INDEX privacy_exports_expiry ON privacy_exports(expires_at,id) WHERE state<>'expired';
CREATE TABLE privacy_export_events (
 id uuid PRIMARY KEY, export_id uuid NOT NULL REFERENCES privacy_exports(id), actor_id bigint NOT NULL REFERENCES users(id),
 event varchar(16) NOT NULL CHECK(event IN ('requested','ready','failed','downloaded')),
 occurred_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE privacy_exports ENABLE ROW LEVEL SECURITY;
ALTER TABLE privacy_exports FORCE ROW LEVEL SECURITY;
ALTER TABLE privacy_export_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE privacy_export_events FORCE ROW LEVEL SECURITY;
CREATE POLICY privacy_export_read ON privacy_exports FOR SELECT USING(actor_id=app_actor() AND NOT app_account_suspended(app_actor()));
CREATE POLICY privacy_export_insert ON privacy_exports FOR INSERT WITH CHECK(actor_id=app_actor() AND state='building'
 AND fingerprint IS NULL AND checksum IS NULL AND size IS NULL
 AND created_at<=clock_timestamp() AND created_at>=clock_timestamp()-interval '1 minute'
 AND expires_at<=clock_timestamp()+interval '24 hours'
 AND NOT app_account_suspended(app_actor()) AND EXISTS(SELECT 1 FROM users WHERE id=app_actor() AND email_verified_at IS NOT NULL)
 AND (scope='account' OR (org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']))));
CREATE POLICY privacy_export_update ON privacy_exports FOR UPDATE USING(actor_id=app_actor() AND NOT app_account_suspended(app_actor()))
 WITH CHECK(actor_id=app_actor() AND state IN ('ready','failed') AND NOT app_account_suspended(app_actor()));
CREATE POLICY privacy_export_event_read ON privacy_export_events FOR SELECT USING(actor_id=app_actor());
CREATE POLICY privacy_export_event_insert ON privacy_export_events FOR INSERT WITH CHECK(actor_id=app_actor()
 AND EXISTS(SELECT 1 FROM privacy_exports e WHERE e.id=export_id AND e.actor_id=app_actor()));
CREATE FUNCTION app_privacy_export_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN
 IF (NEW.id,NEW.actor_id,NEW.scope,NEW.org_id,NEW.created_at,NEW.expires_at)
  IS DISTINCT FROM (OLD.id,OLD.actor_id,OLD.scope,OLD.org_id,OLD.created_at,OLD.expires_at) THEN
  RAISE EXCEPTION 'Export scope and lifetime are immutable' USING ERRCODE='23514'; END IF;
 IF OLD.state<>'building' AND NEW.state<>'expired' THEN RAISE EXCEPTION 'Export is terminal' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER privacy_export_guard BEFORE UPDATE ON privacy_exports FOR EACH ROW EXECUTE FUNCTION app_privacy_export_guard();

-- Only the current account's sanitized security-event projection, never recovery codes or other accounts.
CREATE FUNCTION app_privacy_account_events() RETURNS TABLE(id bigint,event varchar,occurred_at timestamptz)
 LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT e.id,e.event,e.occurred_at FROM public.account_security_events e
 WHERE e.user_id=public.app_actor() AND NOT public.app_account_suspended(public.app_actor()) ORDER BY e.id LIMIT 10001
$$;
-- Same partnership-before-registration lock order as sharing writers. A withdrawal that wins first
-- causes repeatable-read serialization failure; one that follows waits until materialization finishes.
CREATE FUNCTION app_privacy_lock_sharing() RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF NOT public.app_has_membership(public.app_org(),ARRAY['owner']) THEN RAISE EXCEPTION 'Workspace owner required' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM public.partnerships p WHERE public.app_org() IN (p.brand_org_id,p.creator_org_id) ORDER BY p.id FOR SHARE;
 PERFORM 1 FROM public.lead_registrations r WHERE public.app_org() IN (r.brand_org_id,r.creator_org_id) ORDER BY r.id FOR SHARE;
END $$;
-- Maintenance discovers only opaque IDs that are eligible for file cleanup. A build holds the same advisory lock.
CREATE FUNCTION app_privacy_export_expired() RETURNS TABLE(id uuid)
 LANGUAGE sql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT e.id FROM public.privacy_exports e WHERE e.state<>'expired'
 AND (e.expires_at<=clock_timestamp() OR e.state='failed' OR (e.state='building' AND e.created_at<clock_timestamp()-interval '15 minutes'))
 ORDER BY e.expires_at,e.id LIMIT 500
$$;
CREATE FUNCTION app_privacy_export_cleanup(target uuid,finished boolean) RETURNS boolean
 LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE e public.privacy_exports;
BEGIN
 IF NOT pg_try_advisory_xact_lock(hashtextextended('privacy-export:'||target::text,0)) THEN RETURN false; END IF;
 SELECT * INTO e FROM public.privacy_exports WHERE id=target FOR UPDATE;
 IF e.id IS NULL OR e.state='expired' OR NOT (e.expires_at<=clock_timestamp() OR e.state='failed'
   OR (e.state='building' AND e.created_at<clock_timestamp()-interval '15 minutes')) THEN RETURN false; END IF;
 IF finished THEN UPDATE public.privacy_exports SET state='expired' WHERE id=target; END IF;
 RETURN true;
END $$;
REVOKE ALL ON FUNCTION app_privacy_export_guard(),app_privacy_account_events(),app_privacy_lock_sharing(),app_privacy_export_expired(),app_privacy_export_cleanup(uuid,boolean) FROM PUBLIC;
GRANT SELECT,INSERT,UPDATE ON privacy_exports TO weavdash_app;
GRANT SELECT,INSERT ON privacy_export_events TO weavdash_app;
GRANT EXECUTE ON FUNCTION app_privacy_account_events(),app_privacy_lock_sharing(),app_privacy_export_expired(),app_privacy_export_cleanup(uuid,boolean) TO weavdash_app;
