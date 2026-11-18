-- Brand workspace approval (D37). Runs after owner-mfa.sql.
-- A Brand workspace exists only through a reviewed request or a recorded rollout approval. The runtime role
-- keeps tenant RLS and reaches these tables only through the reviewed SECURITY DEFINER functions below.
ALTER TABLE platform_grants DROP CONSTRAINT platform_grants_permissions_check;
ALTER TABLE platform_grants ADD CONSTRAINT platform_grants_permissions_check
 CHECK(permissions <@ ARRAY['platform.owner','directory.read','plans.assign','notifications.read','notifications.reconcile','operations.read','audit.read','access.manage','support.request','cleanup.review','brands.review']::text[]);

-- ---------------------------------------------------------------------------------------------
-- Requests. `source='rollout'` rows record approval of a workspace that predates review: they have no
-- applicant and no business fields, because none were collected at the time.
CREATE TABLE brand_requests (
 id uuid PRIMARY KEY,
 source varchar(8) NOT NULL DEFAULT 'request' CHECK(source IN ('request','rollout')),
 applicant_id bigint REFERENCES users(id),
 brand_name varchar(120), website varchar(500), applicant_role varchar(120), intended_use varchar(2000),
 status varchar(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','needs_information','approved','declined')),
 version integer NOT NULL DEFAULT 1 CHECK(version>0),
 org_id uuid UNIQUE REFERENCES organizations(id),
 submitted_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 decided_at timestamptz,
 CHECK((status='approved')=(org_id IS NOT NULL)),
 CHECK((status IN ('approved','declined'))=(decided_at IS NOT NULL)),
 CHECK(source='rollout' OR (applicant_id IS NOT NULL AND length(trim(brand_name))>0 AND website ~* '^https?://[^[:space:]]+$'
  AND length(trim(applicant_role))>0 AND length(trim(intended_use))>0)),
 CHECK(source='request' OR (status='approved' AND applicant_id IS NULL))
);
-- One unresolved request per applicant; approved and declined requests stay as history.
CREATE UNIQUE INDEX brand_request_unresolved ON brand_requests(applicant_id) WHERE status IN ('pending','needs_information');
CREATE INDEX brand_request_queue ON brand_requests(status,updated_at);
CREATE INDEX brand_request_applicant ON brand_requests(applicant_id,updated_at);

-- Append-only submissions and decisions. A decision event's id is also its notification identity.
CREATE TABLE brand_request_events (
 id uuid PRIMARY KEY, request_id uuid NOT NULL REFERENCES brand_requests(id), version integer NOT NULL CHECK(version>0),
 event varchar(24) NOT NULL CHECK(event IN ('submitted','resubmitted','information_requested','approved','declined','rollout_approved')),
 actor_id bigint REFERENCES users(id), operator varchar(160),
 reason varchar(1000), submission jsonb,
 occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(request_id,version),
 CHECK((event IN ('submitted','resubmitted'))=(submission IS NOT NULL)),
 CHECK(event IN ('submitted','resubmitted') OR length(trim(reason))>0),
 CHECK(event='rollout_approved' OR actor_id IS NOT NULL),
 CHECK((event='rollout_approved')=(operator IS NOT NULL AND length(trim(operator))>0))
);

-- Account-scoped outbox for review outcomes. It holds no message body, recipient or link: the recipient is
-- the applicant's current address and the link is the authenticated request page, both resolved at send time.
CREATE TABLE account_notification_outbox (
 id uuid PRIMARY KEY, user_id bigint NOT NULL REFERENCES users(id),
 kind varchar(40) NOT NULL CHECK(kind IN ('brand_request.approved','brand_request.needs_information','brand_request.declined')),
 brand_request_id uuid NOT NULL REFERENCES brand_requests(id),
 event_id uuid NOT NULL UNIQUE REFERENCES brand_request_events(id),
 -- The request version this notice describes. A later version makes the notice obsolete.
 request_version integer NOT NULL CHECK(request_version>0),
 state varchar(16) NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','sending','sent','captured','cancelled','uncertain')),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), started_at timestamptz, completed_at timestamptz,
 available_at timestamptz NOT NULL DEFAULT clock_timestamp(), dispatch_after timestamptz NOT NULL DEFAULT clock_timestamp(),
 defer_count integer NOT NULL DEFAULT 0 CHECK(defer_count>=0),
 failure_code varchar(32) CHECK(failure_code IN ('transport_unavailable','account_budget','global_budget','queue_unavailable'))
);
CREATE INDEX account_notification_due ON account_notification_outbox(available_at,dispatch_after,user_id,created_at,id) WHERE state='pending';
CREATE TABLE account_notification_resolutions (
 notification_id uuid PRIMARY KEY REFERENCES account_notification_outbox(id), resolution varchar(24) NOT NULL CHECK(resolution IN ('transport_accepted','not_sent','unknown_closed')),
 actor_id bigint NOT NULL REFERENCES users(id), version integer NOT NULL DEFAULT 1 CHECK(version=1), resolved_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

ALTER TABLE brand_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE brand_requests FORCE ROW LEVEL SECURITY;
-- The only runtime policy: an applicant reads their own requests. There is no runtime write policy.
CREATE POLICY brand_request_self_read ON brand_requests FOR SELECT USING(applicant_id=app_actor());
ALTER TABLE brand_request_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE brand_request_events FORCE ROW LEVEL SECURITY;
ALTER TABLE account_notification_outbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE account_notification_outbox FORCE ROW LEVEL SECURITY;
ALTER TABLE account_notification_resolutions ENABLE ROW LEVEL SECURITY;
ALTER TABLE account_notification_resolutions FORCE ROW LEVEL SECURITY;

-- History is never rewritten, by any role. Dropping the tables (reviewed rollback) is the only removal.
CREATE FUNCTION app_brand_history_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN
 IF TG_TABLE_NAME='brand_request_events' THEN RAISE EXCEPTION 'Brand request history is append-only' USING ERRCODE='42501'; END IF;
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Brand requests are never deleted' USING ERRCODE='42501'; END IF;
 IF OLD.status IN ('approved','declined') THEN RAISE EXCEPTION 'A decided Brand request cannot change' USING ERRCODE='42501'; END IF;
 IF NEW.id IS DISTINCT FROM OLD.id OR NEW.source IS DISTINCT FROM OLD.source OR NEW.applicant_id IS DISTINCT FROM OLD.applicant_id OR NEW.submitted_at IS DISTINCT FROM OLD.submitted_at THEN
  RAISE EXCEPTION 'Brand request identity is immutable' USING ERRCODE='42501'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER brand_request_guard BEFORE UPDATE OR DELETE ON brand_requests FOR EACH ROW EXECUTE FUNCTION app_brand_history_guard();
CREATE TRIGGER brand_request_event_guard BEFORE UPDATE OR DELETE ON brand_request_events FOR EACH ROW EXECUTE FUNCTION app_brand_history_guard();

-- Approval provenance, checked at commit for every role including the schema owner: a new Brand workspace
-- must be the result of an approved request or rollout record written in the same transaction.
CREATE FUNCTION app_brand_provenance() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM public.brand_requests r WHERE r.org_id=NEW.id AND r.status='approved') THEN
  RAISE EXCEPTION 'Brand workspaces require an approved request' USING ERRCODE='42501'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER organization_brand_provenance AFTER INSERT ON organizations DEFERRABLE INITIALLY DEFERRED
 FOR EACH ROW WHEN (NEW.kind='brand') EXECUTE FUNCTION app_brand_provenance();

-- The ordinary creation path refuses a new Brand workspace. app_create_experience_workspace delegates here.
-- An existing workspace the caller already owns is still accepted, so earlier provisioning stays repeat-safe.
CREATE OR REPLACE FUNCTION app_create_typed_workspace(target uuid, label text, workspace_kind text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
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

-- ---------------------------------------------------------------------------------------------
-- Applicant functions. Each acts only on the caller's own requests.
CREATE FUNCTION app_brand_fields(label text,site text,role_title text,purpose text) RETURNS jsonb LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog AS $$
BEGIN
 IF label IS NULL OR length(trim(label)) NOT BETWEEN 1 AND 120 OR site IS NULL OR length(trim(site))>500 OR trim(site) !~* '^https?://[^[:space:]]+$'
  OR role_title IS NULL OR length(trim(role_title)) NOT BETWEEN 1 AND 120 OR purpose IS NULL OR length(trim(purpose)) NOT BETWEEN 1 AND 2000 THEN
  RAISE EXCEPTION 'Brand name, web address, role and intended use are required' USING ERRCODE='22000'; END IF;
 RETURN jsonb_build_object('brand_name',trim(label),'website',trim(site),'applicant_role',trim(role_title),'intended_use',trim(purpose));
END $$;

CREATE FUNCTION app_brand_request_submit(target uuid,label text,site text,role_title text,purpose text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE actor bigint := public.app_actor(); fields jsonb;
BEGIN
 IF actor IS NULL OR target IS NULL OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=actor AND email_verified_at IS NOT NULL) OR public.app_account_suspended(actor) THEN
  RAISE EXCEPTION 'Verified account required' USING ERRCODE='42501'; END IF;
 fields := public.app_brand_fields(label,site,role_title,purpose);
 PERFORM pg_advisory_xact_lock(hashtextextended('brand-request:'||actor::text,0));
 IF EXISTS(SELECT 1 FROM public.brand_requests WHERE applicant_id=actor AND status IN ('pending','needs_information')) THEN
  RAISE EXCEPTION 'A Brand request is already open' USING ERRCODE='23505'; END IF;
 INSERT INTO public.brand_requests(id,applicant_id,brand_name,website,applicant_role,intended_use)
  VALUES(target,actor,fields->>'brand_name',fields->>'website',fields->>'applicant_role',fields->>'intended_use');
 INSERT INTO public.brand_request_events(id,request_id,version,event,actor_id,submission) VALUES(gen_random_uuid(),target,1,'submitted',actor,fields);
END $$;

-- Only a request awaiting information can be revised. Resubmission returns it to review and keeps every
-- earlier submission and the reviewer's feedback in the history.
CREATE FUNCTION app_brand_request_revise(target uuid,expected integer,label text,site text,role_title text,purpose text) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE actor bigint := public.app_actor(); r public.brand_requests; fields jsonb;
BEGIN
 IF actor IS NULL OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=actor AND email_verified_at IS NOT NULL) OR public.app_account_suspended(actor) THEN
  RAISE EXCEPTION 'Verified account required' USING ERRCODE='42501'; END IF;
 fields := public.app_brand_fields(label,site,role_title,purpose);
 SELECT * INTO r FROM public.brand_requests WHERE id=target AND applicant_id=actor FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Request unavailable' USING ERRCODE='P0002'; END IF;
 IF r.status<>'needs_information' OR expected IS NULL OR r.version<>expected THEN RAISE EXCEPTION 'Request changed. Reload before saving.' USING ERRCODE='40001'; END IF;
 UPDATE public.brand_requests SET brand_name=fields->>'brand_name',website=fields->>'website',applicant_role=fields->>'applicant_role',intended_use=fields->>'intended_use',
  status='pending',version=r.version+1,updated_at=clock_timestamp() WHERE id=target;
 INSERT INTO public.brand_request_events(id,request_id,version,event,actor_id,submission) VALUES(gen_random_uuid(),target,r.version+1,'resubmitted',actor,fields);
 -- A notice asking for information would now send the applicant to a state that no longer exists.
 UPDATE public.account_notification_outbox SET state='cancelled',completed_at=clock_timestamp() WHERE brand_request_id=target AND state='pending';
 RETURN r.version+1;
END $$;

-- Reviewer identities are not part of the applicant's view; feedback is.
CREATE FUNCTION app_brand_request_row(r public.brand_requests) RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT jsonb_build_object('id',r.id,'status',r.status,'version',r.version,'brand_name',r.brand_name,'website',r.website,'applicant_role',r.applicant_role,
  'intended_use',r.intended_use,'submitted_at',r.submitted_at,'updated_at',r.updated_at,'decided_at',r.decided_at,
  'workspace',CASE WHEN r.org_id IS NULL THEN NULL ELSE (SELECT jsonb_build_object('id',o.id,'name',o.name,
    'member',EXISTS(SELECT 1 FROM public.memberships m WHERE m.org_id=o.id AND m.user_id=r.applicant_id AND m.revoked_at IS NULL)) FROM public.organizations o WHERE o.id=r.org_id) END)
$$;
CREATE FUNCTION app_brand_requests_mine() RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT coalesce((SELECT jsonb_agg(public.app_brand_request_row(r) ORDER BY r.updated_at DESC,r.id) FROM public.brand_requests r
  WHERE r.applicant_id=public.app_actor() AND r.source='request'),'[]'::jsonb)
$$;
CREATE FUNCTION app_brand_request_mine(target uuid) RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE r public.brand_requests;
BEGIN
 SELECT * INTO r FROM public.brand_requests WHERE id=target AND applicant_id=public.app_actor() AND source='request';
 IF NOT FOUND THEN RAISE EXCEPTION 'Request unavailable' USING ERRCODE='P0002'; END IF;
 RETURN public.app_brand_request_row(r) || jsonb_build_object('history',coalesce((SELECT jsonb_agg(jsonb_build_object('version',e.version,'event',e.event,'occurred_at',e.occurred_at,
  'feedback',CASE WHEN e.event IN ('information_requested','approved','declined') THEN e.reason END,'submission',e.submission) ORDER BY e.version DESC)
  FROM public.brand_request_events e WHERE e.request_id=r.id),'[]'::jsonb));
END $$;

-- ---------------------------------------------------------------------------------------------
-- Review. Request metadata only: the capability gives no access to any workspace's private records.
CREATE FUNCTION app_platform_brand_requests(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; term text := nullif(trim(filters->>'q'),''); wanted_status text := filters->>'status'; wanted_source text := filters->>'source';
BEGIN
 PERFORM public.app_platform_require('brands.review');
 WITH matched AS (
  SELECT r.id,r.source,r.status,r.version,coalesce(r.brand_name,o.name) AS brand_name,r.submitted_at,r.updated_at,r.decided_at,r.org_id,
   r.applicant_id,u.name AS applicant,u.email AS applicant_email
  FROM public.brand_requests r LEFT JOIN public.users u ON u.id=r.applicant_id LEFT JOIN public.organizations o ON o.id=r.org_id
  WHERE (wanted_status IS NULL OR r.status=wanted_status) AND (wanted_source IS NULL OR r.source=wanted_source)
   AND (term IS NULL OR coalesce(r.brand_name,o.name) ILIKE public.app_like(term) OR u.name ILIKE public.app_like(term) OR u.email ILIKE public.app_like(term) OR r.id::text=lower(term))
 )
 -- Requests awaiting a decision come first, longest wait first; everything else follows, newest first.
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),'pending',(SELECT count(*) FROM public.brand_requests WHERE status='pending'),
  'rows',coalesce((SELECT jsonb_agg(row_to_json(p)) FROM (
   SELECT * FROM matched ORDER BY (status<>'pending'),CASE WHEN status='pending' THEN extract(epoch FROM updated_at) ELSE -extract(epoch FROM updated_at) END,id
   LIMIT 25 OFFSET greatest(page_number-1,0)*25) p),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

CREATE FUNCTION app_platform_brand_request(target uuid) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb;
BEGIN
 PERFORM public.app_platform_require('brands.review');
 SELECT jsonb_build_object(
  'request',jsonb_build_object('id',r.id,'source',r.source,'status',r.status,'version',r.version,'brand_name',r.brand_name,'website',r.website,'applicant_role',r.applicant_role,
   'intended_use',r.intended_use,'submitted_at',r.submitted_at,'updated_at',r.updated_at,'decided_at',r.decided_at),
  'applicant',(SELECT jsonb_build_object('id',u.id,'name',u.name,'email',u.email,'verified',u.email_verified_at IS NOT NULL,'suspended',public.app_account_suspended(u.id),
    'self',u.id=public.app_actor()) FROM public.users u WHERE u.id=r.applicant_id),
  'workspace',(SELECT jsonb_build_object('id',o.id,'name',o.name,'created_at',o.created_at,'suspended',public.app_workspace_suspended(o.id)) FROM public.organizations o WHERE o.id=r.org_id),
  'history',coalesce((SELECT jsonb_agg(jsonb_build_object('id',e.id,'version',e.version,'event',e.event,'occurred_at',e.occurred_at,'actor',a.name,'operator',e.operator,
    'reason',e.reason,'submission',e.submission) ORDER BY e.version DESC) FROM public.brand_request_events e LEFT JOIN public.users a ON a.id=e.actor_id WHERE e.request_id=r.id),'[]'::jsonb))
  INTO result FROM public.brand_requests r WHERE r.id=target;
 IF result IS NULL THEN RAISE EXCEPTION 'Request unavailable' USING ERRCODE='P0002'; END IF;
 RETURN result;
END $$;

-- One transaction: decision, history, audit, notification intent and, on approval, the workspace and its
-- owner. Only a pending request at the reviewed version can be decided, so a repeated or concurrent approval
-- finds the request already decided and changes nothing. The reviewer never becomes a member.
CREATE FUNCTION app_platform_brand_decide(target uuid,expected integer,decision text,feedback text,event_uuid uuid,workspace_uuid uuid) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE r public.brand_requests; applicant public.users; note text := trim(feedback); next_version integer; outcome text; event_name text;
BEGIN
 -- Lock the grant to serialize revocation and recovery with this decision.
 PERFORM 1 FROM public.platform_grants WHERE user_id=public.app_actor() AND revoked_at IS NULL FOR SHARE;
 PERFORM public.app_platform_require('brands.review');
 IF decision IS NULL OR decision NOT IN ('approve','request_information','decline') OR note IS NULL OR length(note) NOT BETWEEN 1 AND 1000 OR expected IS NULL OR event_uuid IS NULL THEN
  RAISE EXCEPTION 'A decision, the reviewed version and feedback for the applicant are required' USING ERRCODE='22000'; END IF;
 SELECT * INTO r FROM public.brand_requests WHERE id=target AND source='request' FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Request unavailable' USING ERRCODE='P0002'; END IF;
 IF r.status<>'pending' OR r.version<>expected THEN RAISE EXCEPTION 'Request changed. Reload before deciding.' USING ERRCODE='40001'; END IF;
 IF r.applicant_id=public.app_actor() THEN RAISE EXCEPTION 'You cannot review your own Brand request' USING ERRCODE='22000'; END IF;
 next_version := r.version+1;
 outcome := CASE decision WHEN 'approve' THEN 'approved' WHEN 'decline' THEN 'declined' ELSE 'needs_information' END;
 event_name := CASE decision WHEN 'request_information' THEN 'information_requested' ELSE outcome END;
 IF decision='approve' THEN
  IF workspace_uuid IS NULL THEN RAISE EXCEPTION 'Workspace reference required' USING ERRCODE='22000'; END IF;
  -- Suspension locks the account row first, so this share lock orders the two.
  SELECT * INTO applicant FROM public.users WHERE id=r.applicant_id FOR SHARE;
  IF applicant.email_verified_at IS NULL OR public.app_account_suspended(r.applicant_id) THEN
   RAISE EXCEPTION 'The applicant needs a verified account with active access before approval' USING ERRCODE='22000'; END IF;
  INSERT INTO public.organizations(id,name,kind) VALUES(workspace_uuid,r.brand_name,'brand');
  INSERT INTO public.memberships(org_id,user_id,role) VALUES(workspace_uuid,r.applicant_id,'owner');
  INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),workspace_uuid,public.app_actor(),'workspace.approved',workspace_uuid);
  UPDATE public.brand_requests SET status='approved',org_id=workspace_uuid,version=next_version,updated_at=clock_timestamp(),decided_at=clock_timestamp() WHERE id=target;
 ELSIF decision='decline' THEN
  UPDATE public.brand_requests SET status='declined',version=next_version,updated_at=clock_timestamp(),decided_at=clock_timestamp() WHERE id=target;
 ELSE
  UPDATE public.brand_requests SET status='needs_information',version=next_version,updated_at=clock_timestamp() WHERE id=target;
 END IF;
 INSERT INTO public.brand_request_events(id,request_id,version,event,actor_id,reason) VALUES(event_uuid,target,next_version,event_name,public.app_actor(),note);
 -- The audit trail keeps the first 240 characters; the event above holds the full feedback.
 INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),'brand_request.'||event_name,target::text,left(note,240));
 UPDATE public.account_notification_outbox SET state='cancelled',completed_at=clock_timestamp() WHERE brand_request_id=target AND state='pending';
 INSERT INTO public.account_notification_outbox(id,user_id,kind,brand_request_id,event_id,request_version)
  VALUES(gen_random_uuid(),r.applicant_id,'brand_request.'||outcome,target,event_uuid,next_version);
 RETURN jsonb_build_object('status',outcome,'version',next_version,'workspace',CASE WHEN decision='approve' THEN workspace_uuid END);
END $$;

-- Approval metadata for a workspace's administration detail. Null for creator workspaces.
CREATE FUNCTION app_platform_workspace_approval(target uuid) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb;
BEGIN
 PERFORM public.app_platform_require('directory.read');
 IF NOT EXISTS(SELECT 1 FROM public.organizations WHERE id=target AND kind='brand') THEN RETURN NULL; END IF;
 SELECT jsonb_build_object('status','approved','source',r.source,'request',r.id,'approved_at',r.decided_at,'reviewer',u.name,'operator',e.operator,'reason',e.reason) INTO result
 FROM public.brand_requests r JOIN public.brand_request_events e ON e.request_id=r.id AND e.event IN ('approved','rollout_approved')
  LEFT JOIN public.users u ON u.id=e.actor_id WHERE r.org_id=target AND r.status='approved';
 RETURN coalesce(result,jsonb_build_object('status','unrecorded'));
END $$;

-- ---------------------------------------------------------------------------------------------
-- Account notification delivery. Called by the scheduler and worker, which have no acting account.
-- Returns identifiers only.
CREATE FUNCTION app_account_notifications_claim() RETURNS TABLE(notification_id uuid)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('account-notifications:dispatch',0));
 -- A send that began and never reported back may have been delivered. It is never retried automatically.
 UPDATE public.account_notification_outbox n SET state='uncertain',completed_at=clock_timestamp()
  WHERE n.state='sending' AND n.started_at<clock_timestamp()-interval '5 minutes';
 -- Cancelled rather than held: the account lost verification or access, or the request has moved on.
 UPDATE public.account_notification_outbox n SET state='cancelled',completed_at=clock_timestamp()
  WHERE n.state='pending' AND (public.app_account_suspended(n.user_id)
   OR NOT EXISTS(SELECT 1 FROM public.users u WHERE u.id=n.user_id AND u.email_verified_at IS NOT NULL)
   OR NOT EXISTS(SELECT 1 FROM public.brand_requests r WHERE r.id=n.brand_request_id AND r.version=n.request_version AND r.applicant_id=n.user_id));
 RETURN QUERY WITH due AS (
  SELECT x.id FROM (SELECT n.id,n.created_at,row_number() OVER(PARTITION BY n.user_id ORDER BY n.created_at,n.id) AS turn FROM public.account_notification_outbox n
   WHERE n.state='pending' AND n.available_at<=clock_timestamp() AND n.dispatch_after<=clock_timestamp()) x ORDER BY x.turn,x.created_at,x.id LIMIT 100
 ) UPDATE public.account_notification_outbox n SET dispatch_after=clock_timestamp()+interval '60 seconds' FROM due WHERE n.id=due.id AND n.state='pending' RETURNING n.id;
END $$;

CREATE FUNCTION app_account_notification_defer(target uuid,code text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF code IS NULL OR code NOT IN ('transport_unavailable','account_budget','global_budget','queue_unavailable') THEN RAISE EXCEPTION 'Unknown deferral' USING ERRCODE='22000'; END IF;
 UPDATE public.account_notification_outbox n SET failure_code=code,defer_count=n.defer_count+1,
  available_at=clock_timestamp()+make_interval(secs=>CASE WHEN code IN ('account_budget','global_budget') THEN 60 ELSE least(3600,60*(2^least(n.defer_count,6))::integer) END)
  WHERE n.id=target AND n.state='pending';
END $$;

-- Commits the send reservation before SMTP is contacted, so a queue retry or Redis replay finds the notice
-- already reserved and does nothing. Verification, suspension, the current recipient and supersession are
-- rechecked here. The global budget window is the one tenant notifications use.
CREATE FUNCTION app_account_notification_reserve(target uuid,transport_ready boolean,account_limit integer,global_limit integer) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE n public.account_notification_outbox; u public.users; r public.brand_requests; request_id uuid;
 minute_start timestamptz := date_trunc('minute',clock_timestamp()); local_used integer; total_used integer;
BEGIN
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

-- Records the transport outcome of a reserved send. Nothing returns a notice to pending.
CREATE FUNCTION app_account_notification_finish(target uuid,outcome text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF outcome IS NULL OR outcome NOT IN ('sent','captured','uncertain') THEN RAISE EXCEPTION 'Unknown outcome' USING ERRCODE='22000'; END IF;
 UPDATE public.account_notification_outbox SET state=outcome,completed_at=clock_timestamp() WHERE id=target AND state='sending';
END $$;

-- ---------------------------------------------------------------------------------------------
-- Administration: capability list, diagnostics and the overview. The definitions are otherwise those in
-- platform-console.sql, delivery-controls.sql and platform-followup.sql.
CREATE FUNCTION app_platform_capabilities() RETURNS text[] LANGUAGE sql IMMUTABLE SET search_path=pg_catalog AS $$
 SELECT ARRAY['platform.owner','directory.read','plans.assign','notifications.read','notifications.reconcile','operations.read','audit.read','access.manage','support.request','cleanup.review','brands.review']::text[]
$$;

CREATE OR REPLACE FUNCTION app_platform_grant_save(target bigint,expected integer,capabilities text[],revoke boolean,explanation text) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE old public.platform_grants; next_version integer;
BEGIN
 LOCK TABLE public.platform_grants IN SHARE ROW EXCLUSIVE MODE;
 PERFORM public.app_platform_require('platform.owner');
 IF explanation IS NULL OR length(trim(explanation)) NOT BETWEEN 1 AND 240 OR target IS NULL OR expected IS NULL OR expected<0 THEN
  RAISE EXCEPTION 'Operator, version and reason required' USING ERRCODE='22000'; END IF;
 IF target=public.app_actor() THEN RAISE EXCEPTION 'An owner cannot change their own grant' USING ERRCODE='22000'; END IF;
 IF NOT EXISTS(SELECT 1 FROM public.users WHERE id=target AND email_verified_at IS NOT NULL) THEN RAISE EXCEPTION 'Existing verified identity required' USING ERRCODE='22000'; END IF;
 IF NOT revoke AND (capabilities IS NULL OR cardinality(capabilities)=0 OR NOT capabilities <@ public.app_platform_capabilities()) THEN
  RAISE EXCEPTION 'Explicit capabilities required' USING ERRCODE='22000'; END IF;
 SELECT * INTO old FROM public.platform_grants WHERE user_id=target FOR UPDATE;
 IF coalesce(old.version,0)<>expected THEN RAISE EXCEPTION 'Grant changed. Reload before saving.' USING ERRCODE='40001'; END IF;
 IF revoke AND (old.user_id IS NULL OR old.revoked_at IS NOT NULL) THEN RAISE EXCEPTION 'Grant changed. Reload before saving.' USING ERRCODE='40001'; END IF;
 -- Last-owner protection: the caller passed the platform.owner gate, so is a usable owner (verified, authenticator
 -- confirmed, active grant), and cannot be the target. Any change here therefore leaves at least that owner usable.
 next_version := coalesce(old.version,0)+1;
 INSERT INTO public.platform_grants(user_id,permissions,version,revoked_at)
  VALUES(target,CASE WHEN revoke THEN old.permissions ELSE (SELECT array_agg(DISTINCT c ORDER BY c) FROM unnest(capabilities) c) END,next_version,CASE WHEN revoke THEN clock_timestamp() END)
 ON CONFLICT(user_id) DO UPDATE SET permissions=excluded.permissions,version=excluded.version,revoked_at=excluded.revoked_at;
 -- A changed grant invalidates every existing session for that operator, as the offline procedure does.
 DELETE FROM public.sessions WHERE user_id=target;
 INSERT INTO public.platform_audit(actor_id,event,target,reason)
  VALUES(public.app_actor(),CASE WHEN revoke THEN 'grant.revoked' WHEN old.user_id IS NULL OR old.revoked_at IS NOT NULL THEN 'grant.assigned' ELSE 'grant.changed' END,target::text,trim(explanation));
 RETURN next_version;
END $$;

-- Delivery metadata for workspace and account notices together. Account rows have no workspace; they name
-- the account instead. Neither kind exposes a recipient address, link or message body.
CREATE OR REPLACE FUNCTION app_platform_notification_list(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; target_org uuid := nullif(filters->>'workspace','')::uuid; wanted_state text := filters->>'state'; term text := nullif(trim(filters->>'q'),'');
BEGIN
 PERFORM public.app_platform_require('notifications.read');
 IF wanted_state IS NOT NULL AND wanted_state NOT IN ('pending','sending','sent','captured','cancelled','uncertain') THEN RAISE EXCEPTION 'Invalid state' USING ERRCODE='22000'; END IF;
 WITH unified AS (
  SELECT n.id,n.org_id,o.name AS workspace,NULL::bigint AS account_id,NULL::text AS account,n.kind::text AS kind,n.state::text AS state,n.created_at,n.started_at,n.completed_at,n.available_at,n.defer_count,
   n.failure_code::text AS failure_code,r.resolution::text AS resolution,r.resolved_at,coalesce(r.version,0) AS resolution_version
  FROM public.notification_outbox n JOIN public.organizations o ON o.id=n.org_id LEFT JOIN public.notification_resolutions r ON r.notification_id=n.id
  UNION ALL
  SELECT a.id,NULL::uuid,NULL::text,a.user_id,u.name::text,a.kind::text,a.state::text,a.created_at,a.started_at,a.completed_at,a.available_at,a.defer_count,
   a.failure_code::text,r.resolution::text,r.resolved_at,coalesce(r.version,0)
  FROM public.account_notification_outbox a JOIN public.users u ON u.id=a.user_id LEFT JOIN public.account_notification_resolutions r ON r.notification_id=a.id
 ), matched AS (
  SELECT * FROM unified x
  WHERE (target_org IS NULL OR x.org_id=target_org) AND (wanted_state IS NULL OR x.state=wanted_state)
   AND (term IS NULL OR x.workspace ILIKE public.app_like(term) OR x.account ILIKE public.app_like(term) OR x.id::text=lower(term) OR x.org_id::text=lower(term))
 )
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),'rows',coalesce((SELECT jsonb_agg(row_to_json(x)) FROM (
  SELECT * FROM matched ORDER BY created_at DESC,id DESC LIMIT 25 OFFSET greatest(page_number-1,0)*25) x),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

CREATE OR REPLACE FUNCTION app_platform_notification(target uuid) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb;
BEGIN
 PERFORM public.app_platform_require('notifications.read');
 SELECT jsonb_build_object('id',n.id,'org_id',n.org_id,'workspace',o.name,'account',NULL,'kind',n.kind,'state',n.state,'created_at',n.created_at,'started_at',n.started_at,
  'completed_at',n.completed_at,'available_at',n.available_at,'defer_count',n.defer_count,'failure_code',n.failure_code,
  'actor',(SELECT jsonb_build_object('id',u.id,'name',u.name) FROM public.users u WHERE u.id=n.actor_id),
  'resolution',r.resolution,'resolved_at',r.resolved_at,'resolution_version',coalesce(r.version,0),
  'resolver',(SELECT u.name FROM public.users u WHERE u.id=r.actor_id)) INTO result
 FROM public.notification_outbox n JOIN public.organizations o ON o.id=n.org_id LEFT JOIN public.notification_resolutions r ON r.notification_id=n.id WHERE n.id=target;
 IF result IS NULL THEN
  -- Account notices are sent by the system on a reviewer's decision; `actor` is the reviewer.
  SELECT jsonb_build_object('id',a.id,'org_id',NULL,'workspace',NULL,'account',jsonb_build_object('id',u.id,'name',u.name),'kind',a.kind,'state',a.state,'created_at',a.created_at,
   'started_at',a.started_at,'completed_at',a.completed_at,'available_at',a.available_at,'defer_count',a.defer_count,'failure_code',a.failure_code,
   'actor',(SELECT jsonb_build_object('id',v.id,'name',v.name) FROM public.brand_request_events e JOIN public.users v ON v.id=e.actor_id WHERE e.id=a.event_id),
   'resolution',r.resolution,'resolved_at',r.resolved_at,'resolution_version',coalesce(r.version,0),
   'resolver',(SELECT v.name FROM public.users v WHERE v.id=r.actor_id)) INTO result
  FROM public.account_notification_outbox a JOIN public.users u ON u.id=a.user_id LEFT JOIN public.account_notification_resolutions r ON r.notification_id=a.id WHERE a.id=target;
 END IF;
 IF result IS NULL THEN RAISE EXCEPTION 'Notification unavailable' USING ERRCODE='P0002'; END IF;
 RETURN result;
END $$;

CREATE OR REPLACE FUNCTION app_platform_resolve_notification(target uuid,expected integer,outcome text,explanation text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE n public.notification_outbox; a public.account_notification_outbox;
BEGIN
 PERFORM 1 FROM public.platform_grants WHERE user_id=public.app_actor() AND revoked_at IS NULL FOR SHARE;
 IF NOT public.app_platform_allowed('notifications.reconcile') THEN RAISE EXCEPTION 'Platform permission required' USING ERRCODE='42501'; END IF;
 IF explanation IS NULL OR length(trim(explanation)) NOT BETWEEN 1 AND 240 OR outcome IS NULL OR outcome NOT IN ('transport_accepted','not_sent','unknown_closed') THEN
  RAISE EXCEPTION 'Resolution and reason required' USING ERRCODE='22000'; END IF;
 SELECT * INTO n FROM public.notification_outbox WHERE id=target FOR UPDATE;
 IF FOUND THEN
  IF n.state<>'uncertain' OR expected IS DISTINCT FROM 0 OR EXISTS(SELECT 1 FROM public.notification_resolutions WHERE notification_id=target) THEN
   RAISE EXCEPTION 'Resolution changed. Reload diagnostics.' USING ERRCODE='40001'; END IF;
  INSERT INTO public.notification_resolutions(notification_id,resolution,actor_id) VALUES(target,outcome,public.app_actor());
 ELSE
  SELECT * INTO a FROM public.account_notification_outbox WHERE id=target FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Notification unavailable' USING ERRCODE='P0002'; END IF;
  IF a.state<>'uncertain' OR expected IS DISTINCT FROM 0 OR EXISTS(SELECT 1 FROM public.account_notification_resolutions WHERE notification_id=target) THEN
   RAISE EXCEPTION 'Resolution changed. Reload diagnostics.' USING ERRCODE='40001'; END IF;
  INSERT INTO public.account_notification_resolutions(notification_id,resolution,actor_id) VALUES(target,outcome,public.app_actor());
 END IF;
 PERFORM public.app_platform_audit('notification.resolved',target::text,trim(explanation));
END $$;

CREATE OR REPLACE FUNCTION app_platform_operations() RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM public.app_platform_require('operations.read');
 RETURN jsonb_build_object(
  'observed_at',clock_timestamp(),
  'scheduler',(SELECT jsonb_build_object('observed_at',s.observed_at,'age_seconds',floor(extract(epoch FROM clock_timestamp()-s.observed_at))::bigint) FROM public.platform_observations s WHERE s.source='scheduler'),
  'outbox',jsonb_build_object('pending',(SELECT count(*) FROM public.notification_outbox WHERE state='pending')+(SELECT count(*) FROM public.account_notification_outbox WHERE state='pending'),
   'oldest_pending_at',least((SELECT min(created_at) FROM public.notification_outbox WHERE state='pending'),(SELECT min(created_at) FROM public.account_notification_outbox WHERE state='pending')),
   'uncertain_unresolved',(SELECT count(*) FROM public.notification_outbox n WHERE n.state='uncertain' AND NOT EXISTS(SELECT 1 FROM public.notification_resolutions r WHERE r.notification_id=n.id))
    +(SELECT count(*) FROM public.account_notification_outbox n WHERE n.state='uncertain' AND NOT EXISTS(SELECT 1 FROM public.account_notification_resolutions r WHERE r.notification_id=n.id))),
  'failed',jsonb_build_object('total',(SELECT count(*) FROM public.failed_jobs),
   'last_24_hours',(SELECT count(*) FROM public.failed_jobs WHERE failed_at>(clock_timestamp()-interval '24 hours')),
   'rows',coalesce((SELECT jsonb_agg(row_to_json(f)) FROM (
    SELECT j.uuid AS reference,j.queue,j.connection,j.failed_at,public.app_job_name(j.payload) AS job,
     CASE WHEN split_part(j.exception,':',1) ~ '^[A-Za-z0-9_\\]{1,160}$' THEN split_part(j.exception,':',1) ELSE NULL END AS category
    FROM public.failed_jobs j ORDER BY j.failed_at DESC,j.id DESC LIMIT 25) f),'[]'::jsonb)));
END $$;

CREATE OR REPLACE FUNCTION app_platform_overview() RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE directory boolean; plans boolean; notices boolean; operations boolean; owner boolean;
BEGIN
 IF NOT public.app_platform_any() THEN RAISE EXCEPTION 'Platform permission required' USING ERRCODE='42501'; END IF;
 directory := public.app_platform_allowed('directory.read'); plans := public.app_platform_allowed('plans.assign');
 notices := public.app_platform_allowed('notifications.read'); operations := public.app_platform_allowed('operations.read'); owner := public.app_platform_allowed('platform.owner');
 RETURN jsonb_build_object('observed_at',clock_timestamp(),
  'accounts',CASE WHEN directory THEN (SELECT count(*) FROM public.users) END,
  'creator_workspaces',CASE WHEN directory THEN (SELECT count(*) FROM public.organizations WHERE kind='creator') END,
  'brand_workspaces',CASE WHEN directory THEN (SELECT count(*) FROM public.organizations WHERE kind='brand') END,
  'active_assignments',CASE WHEN plans THEN (SELECT count(*) FROM public.organizations o WHERE public.app_entitlement_state(o.id)='active') END,
  'expired_assignments',CASE WHEN plans THEN (SELECT count(*) FROM public.organizations o WHERE public.app_entitlement_state(o.id)='expired') END,
  'pending_notices',CASE WHEN notices THEN (SELECT count(*) FROM public.notification_outbox WHERE state='pending')+(SELECT count(*) FROM public.account_notification_outbox WHERE state='pending') END,
  'uncertain_sends',CASE WHEN notices THEN (SELECT count(*) FROM public.notification_outbox n WHERE n.state='uncertain' AND NOT EXISTS(SELECT 1 FROM public.notification_resolutions r WHERE r.notification_id=n.id))
   +(SELECT count(*) FROM public.account_notification_outbox n WHERE n.state='uncertain' AND NOT EXISTS(SELECT 1 FROM public.account_notification_resolutions r WHERE r.notification_id=n.id)) END,
  'failed_jobs',CASE WHEN operations THEN (SELECT count(*) FROM public.failed_jobs WHERE failed_at>(clock_timestamp()-interval '24 hours')) END,
  'scheduler_age_seconds',CASE WHEN operations THEN (SELECT floor(extract(epoch FROM clock_timestamp()-s.observed_at))::bigint FROM public.platform_observations s WHERE s.source='scheduler') END,
  'scheduler_visible',operations,
  'long_suspensions',CASE WHEN public.app_platform_allowed('access.manage') THEN (SELECT count(*) FROM public.platform_suspensions WHERE lifted_at IS NULL AND suspended_at<(clock_timestamp()-interval '30 days')) END,
  'support_awaiting',CASE WHEN public.app_platform_allowed('support.request') THEN (SELECT count(*) FROM public.support_grants WHERE state='requested') END,
  -- Approved access that ends within two hours, and batches whose current dry run found blocked records.
  'support_expiring',CASE WHEN public.app_platform_allowed('support.request') THEN (SELECT count(*) FROM public.support_grants WHERE state='approved' AND expires_at>clock_timestamp() AND expires_at<=(clock_timestamp()+interval '2 hours')) END,
  'cleanup_blocked',CASE WHEN public.app_platform_allowed('cleanup.review') THEN (SELECT count(*) FROM public.fixture_batches b WHERE EXISTS(SELECT 1 FROM public.cleanup_runs r WHERE r.batch_id=b.id AND r.batch_version=b.version AND r.blocked>0
    AND r.created_at=(SELECT max(x.created_at) FROM public.cleanup_runs x WHERE x.batch_id=b.id))) END,
  -- Brand requests waiting for a decision, and Brand workspaces with no recorded approval (rollout not yet run).
  'brand_requests_pending',CASE WHEN public.app_platform_allowed('brands.review') THEN (SELECT count(*) FROM public.brand_requests WHERE status='pending') END,
  'brands_unrecorded',CASE WHEN public.app_platform_allowed('brands.review') THEN (SELECT count(*) FROM public.organizations o WHERE o.kind='brand'
    AND NOT EXISTS(SELECT 1 FROM public.brand_requests r WHERE r.org_id=o.id AND r.status='approved')) END,
  'operators_without_mfa',CASE WHEN owner THEN (SELECT count(*) FROM public.platform_grants g JOIN public.users u ON u.id=g.user_id WHERE g.revoked_at IS NULL AND u.admin_totp_confirmed_at IS NULL) END,
  'activity',CASE WHEN public.app_platform_allowed('audit.read') THEN coalesce((SELECT jsonb_agg(row_to_json(e)) FROM (
    SELECT a.id::text AS id,a.occurred_at,a.event,a.target,coalesce(u.name,a.operator) AS actor FROM public.platform_audit a LEFT JOIN public.users u ON u.id=a.actor_id
    WHERE a.event<>'request.denied' ORDER BY a.occurred_at DESC,a.id DESC LIMIT 8) e),'[]'::jsonb) END);
END $$;

REVOKE ALL ON FUNCTION app_brand_history_guard(),app_brand_provenance(),app_brand_fields(text,text,text,text),app_brand_request_submit(uuid,text,text,text,text),
 app_brand_request_revise(uuid,integer,text,text,text,text),app_brand_request_row(brand_requests),app_brand_requests_mine(),app_brand_request_mine(uuid),
 app_platform_brand_requests(jsonb,integer),app_platform_brand_request(uuid),app_platform_brand_decide(uuid,integer,text,text,uuid,uuid),app_platform_workspace_approval(uuid),
 app_account_notifications_claim(),app_account_notification_defer(uuid,text),app_account_notification_reserve(uuid,boolean,integer,integer),app_account_notification_finish(uuid,text),
 app_platform_capabilities() FROM PUBLIC;
-- The row helper and the guards stay internal; everything else is a gated entry point.
GRANT EXECUTE ON FUNCTION app_brand_request_submit(uuid,text,text,text,text),app_brand_request_revise(uuid,integer,text,text,text,text),app_brand_requests_mine(),app_brand_request_mine(uuid),
 app_platform_brand_requests(jsonb,integer),app_platform_brand_request(uuid),app_platform_brand_decide(uuid,integer,text,text,uuid,uuid),app_platform_workspace_approval(uuid),
 app_account_notifications_claim(),app_account_notification_defer(uuid,text),app_account_notification_reserve(uuid,boolean,integer,integer),app_account_notification_finish(uuid,text) TO weavdash_app;
GRANT SELECT ON brand_requests TO weavdash_app;
