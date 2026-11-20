-- PRIV-03 access guards. These replace existing signatures in place so RLS keeps the same function OIDs.
ALTER TABLE privacy_exports ADD COLUMN revoked_at timestamptz;
CREATE OR REPLACE FUNCTION app_has_membership(target uuid, allowed_roles text[] DEFAULT NULL)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
    SELECT EXISTS (
        SELECT 1 FROM public.memberships m
        WHERE m.org_id = target AND m.user_id = public.app_actor()
          AND m.revoked_at IS NULL
          AND (allowed_roles IS NULL OR m.role = ANY(allowed_roles))
    ) AND NOT public.app_workspace_suspended(target) AND NOT public.app_account_suspended(public.app_actor()) AND NOT public.app_account_closing(public.app_actor()) AND NOT public.app_workspace_closing(target)
$$;

CREATE OR REPLACE FUNCTION app_lock_membership(target uuid)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
BEGIN
    PERFORM public.app_privacy_lock_access(public.app_actor(),target);
    IF target IS DISTINCT FROM public.app_org() OR public.app_actor() IS NULL THEN
        RETURN false;
    END IF;
    PERFORM 1 FROM public.memberships
        WHERE org_id = target AND user_id = public.app_actor() AND revoked_at IS NULL
        FOR SHARE;
    RETURN FOUND AND NOT public.app_workspace_suspended(target) AND NOT public.app_account_suspended(public.app_actor()) AND NOT public.app_account_closing(public.app_actor()) AND NOT public.app_workspace_closing(target);
END
$$;

CREATE OR REPLACE FUNCTION app_partnership_sharing(target uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.partnerships p WHERE p.id=target AND p.status='active'
  AND public.app_org() IN (p.brand_org_id,p.creator_org_id)
  AND NOT public.app_workspace_suspended(p.brand_org_id) AND NOT public.app_workspace_suspended(p.creator_org_id) AND NOT public.app_workspace_closing(p.brand_org_id) AND NOT public.app_workspace_closing(p.creator_org_id))
$$;

CREATE OR REPLACE FUNCTION app_onboarding_access(target uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.onboarding_sessions s JOIN public.onboarding_requests r ON r.id=s.request_id
 WHERE s.request_id=target AND s.token_hash=current_setting('app.capability',true)
 AND s.expires_at>now() AND r.expires_at>now() AND r.status<>'revoked' AND NOT public.app_workspace_closing(r.org_id))
$$;

CREATE OR REPLACE FUNCTION app_onboarding_scope(secret text) RETURNS TABLE(org uuid,request uuid) LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM public.app_privacy_lock_access(NULL,(SELECT r.org_id FROM public.onboarding_requests r JOIN public.onboarding_sessions s ON s.request_id=r.id WHERE s.token_hash=secret));
 RETURN QUERY SELECT r.org_id,r.id FROM public.onboarding_requests r JOIN public.onboarding_sessions s ON s.request_id=r.id
 WHERE s.token_hash=secret AND s.expires_at>now() AND r.expires_at>now() AND r.status<>'revoked' AND NOT public.app_workspace_closing(r.org_id) FOR UPDATE OF r;
END $$;

CREATE OR REPLACE FUNCTION app_exchange_onboarding(invite_hash text,session_hash text,session_id uuid) RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE r public.onboarding_requests%ROWTYPE;
BEGIN
 PERFORM public.app_privacy_lock_access(NULL,(SELECT org_id FROM public.onboarding_requests WHERE token_hash=invite_hash));
 IF public.app_workspace_closing((SELECT org_id FROM public.onboarding_requests WHERE token_hash=invite_hash)) THEN RETURN false; END IF;
 SELECT * INTO r FROM public.onboarding_requests WHERE token_hash=invite_hash AND consumed_at IS NULL AND expires_at>now() AND status<>'revoked' FOR UPDATE;
 IF NOT FOUND THEN RETURN false; END IF;
 UPDATE public.onboarding_requests SET consumed_at=now() WHERE id=r.id;
 INSERT INTO public.onboarding_sessions(id,org_id,request_id,token_hash,expires_at) VALUES(session_id,r.org_id,r.id,session_hash,least(r.expires_at,now()+interval '24 hours'));
 RETURN true;
END $$;

CREATE OR REPLACE FUNCTION app_exchange_onboarding_return(invite_hash text,session_hash text,session_id uuid) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE r public.onboarding_requests%ROWTYPE; l public.onboarding_return_links%ROWTYPE; n public.notification_outbox%ROWTYPE;
BEGIN
 PERFORM public.app_privacy_lock_access(NULL,(SELECT lookup_request.org_id FROM public.onboarding_requests lookup_request JOIN public.onboarding_return_links lookup_link ON lookup_link.request_id=lookup_request.id WHERE lookup_link.token_hash=invite_hash));
 IF public.app_workspace_closing((SELECT lookup_request.org_id FROM public.onboarding_requests lookup_request JOIN public.onboarding_return_links lookup_link ON lookup_link.request_id=lookup_request.id WHERE lookup_link.token_hash=invite_hash)) THEN RETURN false; END IF;
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

CREATE OR REPLACE FUNCTION app_public_kit(target uuid)
RETURNS TABLE(title varchar,bio text,website varchar,social_links jsonb)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM public.app_privacy_lock_access(NULL,(SELECT org_id FROM public.media_kits WHERE id=target));
 IF public.app_workspace_closing((SELECT org_id FROM public.media_kits WHERE id=target)) THEN RETURN; END IF;
    UPDATE public.media_kits k SET views=k.views+1
        WHERE k.id=target AND k.published AND k.consented_at IS NOT NULL;
    RETURN QUERY SELECT k.title,k.bio,k.website,
        (SELECT coalesce(jsonb_agg(jsonb_build_object('label', item->>'label', 'url', item->>'url')), '[]'::jsonb)
            FROM jsonb_array_elements(k.social_links) item)
        FROM public.media_kits k WHERE k.id=target AND k.published AND k.consented_at IS NOT NULL;
END $$;

CREATE OR REPLACE FUNCTION app_capture_lead(secret text,key uuid,digest text,brand text,contact text,email text,new_id uuid) RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE l public.lead_links%ROWTYPE; prior char(64);
BEGIN
 PERFORM public.app_privacy_lock_access(NULL,(SELECT org_id FROM public.lead_links WHERE token_hash=secret));
 IF public.app_workspace_closing((SELECT org_id FROM public.lead_links WHERE token_hash=secret)) THEN RETURN false; END IF;
 SELECT * INTO l FROM public.lead_links WHERE token_hash=secret AND revoked_at IS NULL AND expires_at>now() FOR UPDATE;
 IF NOT FOUND THEN RETURN false; END IF;
 SELECT payload_hash INTO prior FROM public.public_lead_receipts WHERE link_id=l.id AND idempotency_key=key;
 IF FOUND THEN
   IF prior<>digest THEN RAISE EXCEPTION 'Idempotency conflict' USING ERRCODE='22000'; END IF;
   RETURN true;
 END IF;
 IF length(brand)<1 OR length(brand)>160 OR length(contact)>120 OR length(email)>255 THEN RETURN false; END IF;
 PERFORM set_config('app.lead_capture',secret,true);
 INSERT INTO public.pipeline_entries(id,org_id,brand_name,contact_name,contact_email,currency) VALUES(new_id,l.org_id,brand,contact,email,'PHP');
 INSERT INTO public.public_lead_receipts(id,org_id,link_id,idempotency_key,payload_hash) VALUES(new_id,l.org_id,l.id,key,digest);
 RETURN true;
END $$;

CREATE OR REPLACE FUNCTION app_create_typed_workspace(target uuid, label text, workspace_kind text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
    PERFORM public.app_privacy_lock_access(public.app_actor(),NULL);
    IF public.app_account_closing(public.app_actor()) THEN RAISE EXCEPTION 'Account closing' USING ERRCODE='42501'; END IF;
    IF workspace_kind NOT IN ('creator','brand') OR target IS DISTINCT FROM public.app_org()
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
    IF workspace_kind='brand' THEN
        RAISE EXCEPTION 'Brand workspaces require an approved request' USING ERRCODE='42501';
    END IF;
    INSERT INTO public.organizations(id,name,kind) VALUES(target,label,workspace_kind);
    INSERT INTO public.memberships(org_id,user_id,role) VALUES(target,public.app_actor(),'owner');
END $$;

CREATE OR REPLACE FUNCTION app_schedule_basis(s business_schedules) RETURNS boolean LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT NOT public.app_account_closing(s.actor_id) AND NOT public.app_workspace_closing(s.org_id) AND public.app_professional_active(s.org_id) AND public.app_schedule_basis_before_professional(s)
$$;

CREATE OR REPLACE FUNCTION app_follow_up_basis(d follow_up_drafts) RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT NOT public.app_account_closing(d.actor_id) AND NOT public.app_workspace_closing(d.org_id) AND EXISTS(SELECT 1 FROM public.memberships m JOIN public.users u ON u.id=m.user_id
  WHERE m.org_id=d.org_id AND m.user_id=d.actor_id AND m.revoked_at IS NULL
   AND m.role IN ('owner','admin','manager','member') AND u.email_verified_at IS NOT NULL)
 AND public.app_professional_active(d.org_id)
 AND NOT EXISTS(SELECT 1 FROM (SELECT * FROM public.pilot_assignments WHERE org_id=d.org_id ORDER BY version DESC LIMIT 1) a
  WHERE a.starts_at>clock_timestamp() OR a.ends_at<=clock_timestamp())
 AND EXISTS(SELECT 1 FROM public.pipeline_entries p WHERE p.org_id=d.org_id AND p.id=d.pipeline_entry_id
  AND p.version=d.subject_version AND p.stage<>'lost')
 AND (d.schedule_run_id IS NULL OR EXISTS(SELECT 1 FROM public.schedule_runs r JOIN public.business_schedules s ON s.id=r.schedule_id
  WHERE r.id=d.schedule_run_id AND r.org_id=d.org_id AND r.actor_id=d.actor_id AND r.state='completed'
   AND s.kind='follow_up_draft' AND s.subject_id=d.pipeline_entry_id AND s.version=r.schedule_version
   AND s.preference_version=r.preference_version AND s.rule_version=r.rule_version AND public.app_schedule_basis(s)))
$$;

CREATE OR REPLACE FUNCTION app_account_notification_reserve(target uuid,transport_ready boolean,account_limit integer,global_limit integer) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE n public.account_notification_outbox; u public.users; r public.brand_requests; request_id uuid;
 minute_start timestamptz := date_trunc('minute',clock_timestamp()); local_used integer; total_used integer;
BEGIN
 PERFORM public.app_privacy_lock_access((SELECT user_id FROM public.account_notification_outbox WHERE id=target),
  (SELECT lookup_request.org_id FROM public.brand_requests lookup_request JOIN public.account_notification_outbox lookup_notice ON lookup_notice.brand_request_id=lookup_request.id WHERE lookup_notice.id=target));
 IF public.app_account_closing((SELECT user_id FROM public.account_notification_outbox WHERE id=target))
  OR public.app_workspace_closing((SELECT lookup_request.org_id FROM public.brand_requests lookup_request JOIN public.account_notification_outbox lookup_notice ON lookup_notice.brand_request_id=lookup_request.id WHERE lookup_notice.id=target)) THEN
  UPDATE public.account_notification_outbox SET state='cancelled',completed_at=clock_timestamp() WHERE id=target AND state='pending';
  RETURN jsonb_build_object('outcome','cancelled');
 END IF;
 SELECT o.brand_request_id INTO request_id FROM public.account_notification_outbox o WHERE o.id=target;
 IF request_id IS NULL THEN RETURN jsonb_build_object('outcome','skipped'); END IF;
 -- Request before notice: the same order as a revision or decision, which cancel obsolete notices.
 SELECT * INTO r FROM public.brand_requests WHERE id=request_id FOR SHARE;
 SELECT * INTO n FROM public.account_notification_outbox WHERE id=target FOR UPDATE;
 IF n.state<>'pending' OR n.available_at>clock_timestamp() THEN RETURN jsonb_build_object('outcome','skipped'); END IF;
 SELECT * INTO u FROM public.users WHERE id=n.user_id FOR SHARE;
 IF u.email_verified_at IS NULL OR public.app_account_suspended(n.user_id) OR r.version<>n.request_version OR r.applicant_id IS DISTINCT FROM n.user_id THEN
  UPDATE public.account_notification_outbox SET state='cancelled',completed_at=clock_timestamp() WHERE id=target;
  RETURN jsonb_build_object('outcome','cancelled');
 END IF;
 IF NOT coalesce(transport_ready,false) THEN
  PERFORM public.app_account_notification_defer(target,'transport_unavailable');
  RETURN jsonb_build_object('outcome','deferred','reason','transport_unavailable');
 END IF;
 -- Invalid configuration must not silently disable a configured safeguard.
 IF account_limit IS NULL OR global_limit IS NULL OR account_limit<0 OR global_limit<0 THEN
  PERFORM public.app_account_notification_defer(target,CASE WHEN global_limit IS NULL OR global_limit<0 THEN 'global_budget' ELSE 'account_budget' END);
  RETURN jsonb_build_object('outcome','deferred','reason','budget');
 END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('notifications:budget',0));
 SELECT coalesce(max(used),0) INTO local_used FROM public.notification_delivery_windows WHERE scope='account:'||n.user_id::text AND window_start=minute_start;
 SELECT coalesce(max(used),0) INTO total_used FROM public.notification_delivery_windows WHERE scope='global' AND window_start=minute_start;
 IF global_limit>0 AND total_used>=global_limit THEN
  PERFORM public.app_account_notification_defer(target,'global_budget');
  RETURN jsonb_build_object('outcome','deferred','reason','global_budget');
 END IF;
 IF account_limit>0 AND local_used>=account_limit THEN
  PERFORM public.app_account_notification_defer(target,'account_budget');
  RETURN jsonb_build_object('outcome','deferred','reason','account_budget');
 END IF;
 INSERT INTO public.notification_delivery_windows(scope,window_start,used) VALUES('global',minute_start,1),('account:'||n.user_id::text,minute_start,1)
 ON CONFLICT(scope) DO UPDATE SET window_start=EXCLUDED.window_start,
  used=CASE WHEN notification_delivery_windows.window_start=EXCLUDED.window_start THEN notification_delivery_windows.used+1 ELSE 1 END;
 UPDATE public.account_notification_outbox SET state='sending',started_at=clock_timestamp(),failure_code=NULL WHERE id=target;
 RETURN jsonb_build_object('outcome','reserved','recipient',u.email,'kind',n.kind,'request',n.brand_request_id);
END $$;

CREATE OR REPLACE FUNCTION app_account_notifications_claim() RETURNS TABLE(notification_id uuid)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('account-notifications:dispatch',0));
 -- A send that began and never reported back may have been delivered. It is never retried automatically.
 UPDATE public.account_notification_outbox n SET state='uncertain',completed_at=clock_timestamp()
  WHERE n.state='sending' AND n.started_at<clock_timestamp()-interval '5 minutes';
 -- Cancelled rather than held: the account lost verification or access, or the request has moved on.
 UPDATE public.account_notification_outbox n SET state='cancelled',completed_at=clock_timestamp()
  WHERE n.state='pending' AND ((public.app_account_suspended(n.user_id) OR public.app_account_closing(n.user_id) OR EXISTS(SELECT 1 FROM public.brand_requests r WHERE r.id=n.brand_request_id AND public.app_workspace_closing(r.org_id)))
   OR NOT EXISTS(SELECT 1 FROM public.users u WHERE u.id=n.user_id AND u.email_verified_at IS NOT NULL)
   OR NOT EXISTS(SELECT 1 FROM public.brand_requests r WHERE r.id=n.brand_request_id AND r.version=n.request_version AND r.applicant_id=n.user_id));
 RETURN QUERY WITH due AS (
  SELECT x.id FROM (SELECT n.id,n.created_at,row_number() OVER(PARTITION BY n.user_id ORDER BY n.created_at,n.id) AS turn FROM public.account_notification_outbox n
   WHERE n.state='pending' AND n.available_at<=clock_timestamp() AND n.dispatch_after<=clock_timestamp()) x ORDER BY x.turn,x.created_at,x.id LIMIT 100
 ) UPDATE public.account_notification_outbox n SET dispatch_after=clock_timestamp()+interval '60 seconds' FROM due WHERE n.id=due.id AND n.state='pending' RETURNING n.id;
END $$;

CREATE OR REPLACE FUNCTION app_privacy_export_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN
 IF (NEW.id,NEW.actor_id,NEW.scope,NEW.org_id,NEW.created_at,NEW.expires_at)
  IS DISTINCT FROM (OLD.id,OLD.actor_id,OLD.scope,OLD.org_id,OLD.created_at,OLD.expires_at) THEN
  RAISE EXCEPTION 'Export scope and lifetime are immutable' USING ERRCODE='23514'; END IF;
 IF NEW.revoked_at IS NOT NULL AND OLD.revoked_at IS NULL AND (NEW.state,NEW.fingerprint,NEW.checksum,NEW.size) IS NOT DISTINCT FROM (OLD.state,OLD.fingerprint,OLD.checksum,OLD.size) THEN RETURN NEW; END IF;
 IF NEW.revoked_at IS DISTINCT FROM OLD.revoked_at THEN RAISE EXCEPTION 'Export revocation is permanent' USING ERRCODE='23514'; END IF;
 IF OLD.state<>'building' AND NEW.state<>'expired' THEN RAISE EXCEPTION 'Export is terminal' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION app_privacy_export_expired() RETURNS TABLE(id uuid)
 LANGUAGE sql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT e.id FROM public.privacy_exports e WHERE e.state<>'expired'
 AND (e.revoked_at IS NOT NULL OR e.expires_at<=clock_timestamp() OR e.state='failed' OR (e.state='building' AND e.created_at<clock_timestamp()-interval '15 minutes'))
 ORDER BY e.expires_at,e.id LIMIT 500
$$;

CREATE OR REPLACE FUNCTION app_privacy_export_cleanup(target uuid,finished boolean) RETURNS boolean
 LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE e public.privacy_exports;
BEGIN
 IF NOT pg_try_advisory_xact_lock(hashtextextended('privacy-export:'||target::text,0)) THEN RETURN false; END IF;
 SELECT * INTO e FROM public.privacy_exports WHERE id=target FOR UPDATE;
 IF e.id IS NULL OR e.state='expired' OR NOT (e.revoked_at IS NOT NULL OR e.expires_at<=clock_timestamp() OR e.state='failed'
   OR (e.state='building' AND e.created_at<clock_timestamp()-interval '15 minutes')) THEN RETURN false; END IF;
 IF finished THEN UPDATE public.privacy_exports SET state='expired' WHERE id=target; END IF;
 RETURN true;
END $$;

-- Privileged/offline membership and operator changes must not introduce a new active grant to a closing account.
CREATE FUNCTION app_privacy_grant_guard() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF TG_TABLE_NAME='memberships' THEN
  PERFORM public.app_privacy_lock_access(NEW.user_id,NEW.org_id);
  PERFORM pg_advisory_xact_lock(hashtextextended('privacy-owner:'||NEW.org_id::text,0));
  IF NEW.revoked_at IS NULL AND public.app_account_closing(NEW.user_id) THEN RAISE EXCEPTION 'Account closing' USING ERRCODE='42501'; END IF;
 ELSE
  PERFORM public.app_privacy_lock_access(NEW.user_id,NULL);
  IF NEW.revoked_at IS NULL AND public.app_account_closing(NEW.user_id) THEN RAISE EXCEPTION 'Account closing' USING ERRCODE='42501'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER privacy_membership_guard BEFORE INSERT OR UPDATE ON memberships FOR EACH ROW EXECUTE FUNCTION app_privacy_grant_guard();
CREATE TRIGGER privacy_platform_grant_guard BEFORE INSERT OR UPDATE ON platform_grants FOR EACH ROW EXECUTE FUNCTION app_privacy_grant_guard();
CREATE FUNCTION app_privacy_brand_guard() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF NEW.applicant_id IS NOT NULL THEN
  PERFORM public.app_privacy_lock_access(NEW.applicant_id,NULL);
  IF public.app_account_closing(NEW.applicant_id) THEN RAISE EXCEPTION 'Account closing' USING ERRCODE='42501'; END IF;
 END IF;
 IF NEW.org_id IS NOT NULL AND public.app_workspace_closing(NEW.org_id) THEN RAISE EXCEPTION 'Workspace closing' USING ERRCODE='42501'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER privacy_brand_guard BEFORE INSERT OR UPDATE ON brand_requests FOR EACH ROW EXECUTE FUNCTION app_privacy_brand_guard();
REVOKE ALL ON FUNCTION app_privacy_grant_guard(),app_privacy_brand_guard() FROM PUBLIC;
REVOKE ALL ON privacy_closure_requests,privacy_closure_events FROM weavdash_app;


CREATE OR REPLACE FUNCTION app_platform_assign(target uuid,expected integer,plan_name text,limits jsonb,starts timestamptz,ends timestamptz,explanation text,plan_uuid uuid,assignment_uuid uuid) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE current_version integer; plan_version integer; resource text;
BEGIN
 PERFORM public.app_privacy_lock_access(NULL,target);
 IF public.app_workspace_closing(target) THEN RAISE EXCEPTION 'Workspace closing' USING ERRCODE='42501'; END IF;
 -- Lock the grant to serialize revocation/recovery with privileged requests.
 PERFORM 1 FROM public.platform_grants WHERE user_id=public.app_actor() AND revoked_at IS NULL FOR SHARE;
 IF NOT public.app_platform_allowed('plans.assign') THEN RAISE EXCEPTION 'Platform permission required' USING ERRCODE='42501'; END IF;
 IF explanation IS NULL OR length(trim(explanation)) NOT BETWEEN 1 AND 240 OR plan_name IS NULL OR length(trim(plan_name)) NOT BETWEEN 1 AND 120
  OR starts IS NULL OR ends IS NULL OR ends<=starts OR expected IS NULL OR expected<0 OR jsonb_typeof(limits) IS DISTINCT FROM 'object' THEN
  RAISE EXCEPTION 'Plan, dates, reason and quotas required' USING ERRCODE='22000';
 END IF;
 IF (SELECT count(*) FROM jsonb_object_keys(limits))<>7 THEN RAISE EXCEPTION 'Seven quotas required' USING ERRCODE='22000'; END IF;
 FOREACH resource IN ARRAY ARRAY['pipeline_entries','brand_contacts','reminders','onboarding_requests','outreach_templates','invoice_drafts','manual_payments'] LOOP
  IF NOT limits ? resource OR jsonb_typeof(limits->resource)<>'number' OR (limits->>resource)!~'^(0|[1-9][0-9]{0,8})$' THEN
   RAISE EXCEPTION 'Explicit nonnegative integer quotas required' USING ERRCODE='22000';
  END IF;
 END LOOP;
 PERFORM pg_advisory_xact_lock(hashtextextended('pilot:'||target::text,0));
 IF NOT EXISTS(SELECT 1 FROM public.organizations WHERE id=target) THEN RAISE EXCEPTION 'Workspace unavailable' USING ERRCODE='22000'; END IF;
 SELECT coalesce(max(version),0) INTO current_version FROM public.pilot_assignments WHERE org_id=target;
 IF current_version<>expected THEN RAISE EXCEPTION 'Assignment changed. Reload before saving.' USING ERRCODE='40001'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('plan:'||plan_name,0));
 SELECT coalesce(max(version),0)+1 INTO plan_version FROM public.pilot_plan_versions WHERE name=plan_name;
 INSERT INTO public.pilot_plan_versions(id,name,version,quotas) VALUES(plan_uuid,plan_name,plan_version,limits);
 INSERT INTO public.pilot_assignments(id,org_id,plan_id,version,starts_at,ends_at) VALUES(assignment_uuid,target,plan_uuid,current_version+1,starts,ends);
 INSERT INTO public.pilot_admin_events(id,operator,reason,event,subject_id,org_id) VALUES(assignment_uuid,public.app_actor()::text,explanation,'plan.assigned',assignment_uuid,target);
 INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),'plan.assigned',target::text,explanation);
 RETURN current_version+1;
END $$;

CREATE OR REPLACE FUNCTION app_support_request(new_id uuid,target uuid,why text,areas text[],duration integer) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM public.app_privacy_lock_access(NULL,target);
 IF public.app_workspace_closing(target) THEN RAISE EXCEPTION 'Workspace closing' USING ERRCODE='42501'; END IF;
 PERFORM public.app_platform_require('support.request');
 IF why IS NULL OR length(trim(why)) NOT BETWEEN 1 AND 240 OR areas IS NULL OR cardinality(areas)=0 OR duration IS NULL OR duration NOT BETWEEN 1 AND 24
  OR NOT areas <@ ARRAY['pipeline_entries','brand_contacts','reminders','onboarding_requests','outreach_templates','finance']::text[] THEN
  RAISE EXCEPTION 'Purpose, areas and a duration of 1 to 24 hours required' USING ERRCODE='22000'; END IF;
 IF NOT EXISTS(SELECT 1 FROM public.organizations WHERE id=target) THEN RAISE EXCEPTION 'Workspace unavailable' USING ERRCODE='P0002'; END IF;
 IF public.app_workspace_suspended(target) THEN RAISE EXCEPTION 'Workspace is suspended' USING ERRCODE='22000'; END IF;
 IF EXISTS(SELECT 1 FROM public.support_grants g WHERE g.org_id=target AND g.operator_id=public.app_actor() AND public.app_support_state(g) IN ('requested','approved')) THEN
  RAISE EXCEPTION 'An open request already exists' USING ERRCODE='40001'; END IF;
 -- An expired grant still holds the open slot in the partial index; close it before recording the new request.
 UPDATE public.support_grants SET state='revoked',ended_at=expires_at,end_reason='Expired',version=version+1 WHERE org_id=target AND operator_id=public.app_actor() AND state='approved';
 INSERT INTO public.support_grants(id,org_id,operator_id,purpose,resources,hours) VALUES(new_id,target,public.app_actor(),trim(why),(SELECT array_agg(DISTINCT a ORDER BY a) FROM unnest(areas) a),duration);
 INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),'support.requested',new_id::text,trim(why));
END $$;

CREATE OR REPLACE FUNCTION app_platform_suspend(kind text,reference text,expected integer,explanation text) RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE account bigint; workspace uuid;
BEGIN
 IF kind='workspace' THEN PERFORM public.app_privacy_lock_access(NULL,reference::uuid);
 ELSIF kind='account' THEN PERFORM public.app_privacy_lock_access(reference::bigint,NULL); END IF;
 PERFORM 1 FROM public.platform_grants WHERE user_id=public.app_actor() AND revoked_at IS NULL FOR SHARE;
 PERFORM public.app_platform_require('access.manage');
 IF explanation IS NULL OR length(trim(explanation)) NOT BETWEEN 1 AND 240 OR expected IS NULL THEN RAISE EXCEPTION 'Reason and version required' USING ERRCODE='22000'; END IF;
 IF kind='account' AND reference ~ '^[0-9]{1,18}$' THEN
  account := reference::bigint;
  PERFORM 1 FROM public.users WHERE id=account FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Account unavailable' USING ERRCODE='P0002'; END IF;
  IF account=public.app_actor() THEN RAISE EXCEPTION 'An operator cannot suspend their own account' USING ERRCODE='22000'; END IF;
  IF EXISTS(SELECT 1 FROM public.platform_grants WHERE user_id=account AND revoked_at IS NULL AND 'platform.owner'=ANY(permissions)) AND NOT public.app_usable_owner_besides(account) THEN
   RAISE EXCEPTION 'Cannot suspend the last usable platform owner' USING ERRCODE='22000'; END IF;
 ELSIF kind='workspace' AND reference ~ '^[0-9a-f-]{36}$' THEN
  workspace := reference::uuid;
  PERFORM pg_advisory_xact_lock(hashtextextended('pilot:'||workspace::text,0));
  PERFORM 1 FROM public.organizations WHERE id=workspace FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Workspace unavailable' USING ERRCODE='P0002'; END IF;
 ELSE RAISE EXCEPTION 'Unknown subject' USING ERRCODE='22000';
 END IF;
 IF public.app_suspension_version(kind,account,workspace)<>expected OR (kind='account' AND public.app_account_suspended(account)) OR (kind='workspace' AND public.app_workspace_suspended(workspace)) THEN
  RAISE EXCEPTION 'Access state changed. Reload before saving.' USING ERRCODE='40001'; END IF;
 INSERT INTO public.platform_suspensions(subject,user_id,org_id,reason,suspended_by) VALUES(kind,account,workspace,trim(explanation),public.app_actor());
 IF kind='account' THEN
  DELETE FROM public.sessions WHERE user_id=account;
 ELSE
  -- Cancelled, not held: reactivation never replays work that was waiting when access was suspended.
  UPDATE public.schedule_runs SET state='cancelled',completed_at=clock_timestamp() WHERE org_id=workspace AND state='ready';
  UPDATE public.follow_up_drafts SET state='cancelled',version=version+1,approved_at=NULL,approved_version=NULL,updated_at=clock_timestamp() WHERE org_id=workspace AND state='approved';
  UPDATE public.notification_outbox SET state='cancelled',encrypted_payload=NULL,completed_at=clock_timestamp() WHERE org_id=workspace AND state='pending';
  UPDATE public.support_grants SET state='revoked',ended_at=clock_timestamp(),ended_by=public.app_actor(),end_reason='Workspace suspended',version=version+1
   WHERE org_id=workspace AND state IN ('requested','approved');
 END IF;
 INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),kind||'.suspended',reference,trim(explanation));
 RETURN public.app_suspension_version(kind,account,workspace);
END $$;

CREATE OR REPLACE FUNCTION app_platform_reactivate(kind text,reference text,expected integer,explanation text) RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE account bigint; workspace uuid; lifted bigint;
BEGIN
 IF kind='workspace' THEN PERFORM public.app_privacy_lock_access(NULL,reference::uuid);
 ELSIF kind='account' THEN PERFORM public.app_privacy_lock_access(reference::bigint,NULL); END IF;
 PERFORM 1 FROM public.platform_grants WHERE user_id=public.app_actor() AND revoked_at IS NULL FOR SHARE;
 PERFORM public.app_platform_require('access.manage');
 IF explanation IS NULL OR length(trim(explanation)) NOT BETWEEN 1 AND 240 OR expected IS NULL THEN RAISE EXCEPTION 'Reason and version required' USING ERRCODE='22000'; END IF;
 IF kind='account' AND reference ~ '^[0-9]{1,18}$' THEN account := reference::bigint;
 ELSIF kind='workspace' AND reference ~ '^[0-9a-f-]{36}$' THEN workspace := reference::uuid;
 ELSE RAISE EXCEPTION 'Unknown subject' USING ERRCODE='22000';
 END IF;
 -- Restores access from now on only: revoked memberships and grants, expired links and cancelled work stay as they are.
 UPDATE public.platform_suspensions SET lifted_at=clock_timestamp(),lifted_by=public.app_actor(),lift_reason=trim(explanation)
  WHERE lifted_at IS NULL AND ((kind='account' AND user_id=account) OR (kind='workspace' AND org_id=workspace))
   AND public.app_suspension_version(kind,account,workspace)=expected RETURNING id INTO lifted;
 IF lifted IS NULL THEN RAISE EXCEPTION 'Access state changed. Reload before saving.' USING ERRCODE='40001'; END IF;
 INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),kind||'.reactivated',reference,trim(explanation));
 RETURN public.app_suspension_version(kind,account,workspace);
END $$;

DROP POLICY privacy_export_insert ON privacy_exports;
CREATE POLICY privacy_export_insert ON privacy_exports FOR INSERT WITH CHECK(actor_id=app_actor() AND state='building'
 AND fingerprint IS NULL AND checksum IS NULL AND size IS NULL
 AND created_at<=clock_timestamp() AND created_at>=clock_timestamp()-interval '1 minute'
 AND expires_at<=clock_timestamp()+interval '24 hours'
 AND NOT app_account_suspended(app_actor()) AND NOT app_account_closing(app_actor()) AND EXISTS(SELECT 1 FROM users WHERE id=app_actor() AND email_verified_at IS NOT NULL)
 AND (scope='account' OR (org_id=app_org() AND app_has_membership(org_id,ARRAY['owner']))));
