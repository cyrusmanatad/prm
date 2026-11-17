-- Governed access changes (ADM-03), consented support access (ADM-06) and cleanup dry runs (ADM-07).
-- Runs after platform-console.sql. Decisions: D31 (suspension proposal confirmed) and D32.
ALTER TABLE platform_grants DROP CONSTRAINT platform_grants_permissions_check;
ALTER TABLE platform_grants ADD CONSTRAINT platform_grants_permissions_check
 CHECK(permissions <@ ARRAY['platform.owner','directory.read','plans.assign','notifications.read','notifications.reconcile','operations.read','audit.read','access.manage','support.request','cleanup.review']::text[]);

-- ---------------------------------------------------------------------------------------------
-- Suspension. A row with lifted_at IS NULL is an active suspension; history is never deleted.
CREATE TABLE platform_suspensions (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, subject varchar(9) NOT NULL CHECK(subject IN ('account','workspace')),
 user_id bigint REFERENCES users(id), org_id uuid REFERENCES organizations(id),
 reason varchar(240) NOT NULL CHECK(length(trim(reason))>0), suspended_by bigint NOT NULL REFERENCES users(id), suspended_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 lifted_by bigint REFERENCES users(id), lifted_at timestamptz, lift_reason varchar(240),
 CHECK((subject='account' AND user_id IS NOT NULL AND org_id IS NULL) OR (subject='workspace' AND org_id IS NOT NULL AND user_id IS NULL)),
 CHECK((lifted_at IS NULL)=(lifted_by IS NULL))
);
CREATE UNIQUE INDEX platform_suspension_account ON platform_suspensions(user_id) WHERE lifted_at IS NULL AND subject='account';
CREATE UNIQUE INDEX platform_suspension_workspace ON platform_suspensions(org_id) WHERE lifted_at IS NULL AND subject='workspace';
ALTER TABLE platform_suspensions ENABLE ROW LEVEL SECURITY;
ALTER TABLE platform_suspensions FORCE ROW LEVEL SECURITY;

CREATE FUNCTION app_account_suspended(target bigint) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.platform_suspensions s WHERE s.user_id=target AND s.lifted_at IS NULL)
$$;
CREATE FUNCTION app_workspace_suspended(target uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.platform_suspensions s WHERE s.org_id=target AND s.lifted_at IS NULL)
$$;
-- Public links carry a capability, not a membership. Resolve the owning workspace so the application can answer
-- "unavailable" for a suspended workspace without learning anything else about the link.
CREATE FUNCTION app_public_link_suspended(kind text,reference text) RETURNS boolean LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE owner uuid;
BEGIN
 IF kind='kit' THEN SELECT k.org_id INTO owner FROM public.media_kits k WHERE k.id::text=reference;
 ELSIF kind='lead' THEN SELECT l.org_id INTO owner FROM public.lead_links l WHERE l.token_hash=reference;
 ELSIF kind='onboarding' THEN
  SELECT r.org_id INTO owner FROM public.onboarding_requests r WHERE r.token_hash=reference;
  IF owner IS NULL THEN SELECT r.org_id INTO owner FROM public.onboarding_return_links l JOIN public.onboarding_requests r ON r.id=l.request_id WHERE l.token_hash=reference; END IF;
 ELSIF kind='join' THEN SELECT l.brand_org_id INTO owner FROM public.partner_join_links l WHERE l.code_hash=reference AND l.revoked_at IS NULL;
 END IF;
 RETURN owner IS NOT NULL AND public.app_workspace_suspended(owner);
END $$;

-- Every tenant policy, request, worker job and scheduled task authorizes through these two functions, so
-- suspension is rechecked whenever work executes, not only when it was queued.
CREATE OR REPLACE FUNCTION app_has_membership(target uuid, allowed_roles text[] DEFAULT NULL)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
    SELECT EXISTS (
        SELECT 1 FROM public.memberships m
        WHERE m.org_id = target AND m.user_id = public.app_actor()
          AND m.revoked_at IS NULL
          AND (allowed_roles IS NULL OR m.role = ANY(allowed_roles))
    ) AND NOT public.app_workspace_suspended(target) AND NOT public.app_account_suspended(public.app_actor())
$$;
CREATE OR REPLACE FUNCTION app_lock_membership(target uuid)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
BEGIN
    IF target IS DISTINCT FROM public.app_org() OR public.app_actor() IS NULL THEN
        RETURN false;
    END IF;
    PERFORM 1 FROM public.memberships
        WHERE org_id = target AND user_id = public.app_actor() AND revoked_at IS NULL
        FOR SHARE;
    RETURN FOUND AND NOT public.app_workspace_suspended(target) AND NOT public.app_account_suspended(public.app_actor());
END
$$;
CREATE OR REPLACE FUNCTION app_platform_allowed(permission text) RETURNS boolean LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.platform_grants g JOIN public.users u ON u.id=g.user_id
 WHERE g.user_id=public.app_actor() AND g.revoked_at IS NULL AND u.email_verified_at IS NOT NULL
 AND u.admin_totp_confirmed_at IS NOT NULL
 AND g.version::text=nullif(current_setting('app.admin_version',true),'')
 AND (permission=ANY(g.permissions) OR 'platform.owner'=ANY(g.permissions)))
 AND NOT public.app_account_suspended(public.app_actor())
$$;
CREATE OR REPLACE FUNCTION app_platform_any() RETURNS boolean LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.platform_grants g JOIN public.users u ON u.id=g.user_id
 WHERE g.user_id=public.app_actor() AND g.revoked_at IS NULL AND u.email_verified_at IS NOT NULL
 AND u.admin_totp_confirmed_at IS NOT NULL AND cardinality(g.permissions)>0
 AND g.version::text=nullif(current_setting('app.admin_version',true),''))
 AND NOT public.app_account_suspended(public.app_actor())
$$;
CREATE OR REPLACE FUNCTION app_lock_platform_grant() RETURNS TABLE(user_id bigint,permissions text[],version integer)
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT g.user_id,g.permissions,g.version FROM public.platform_grants g JOIN public.users u ON u.id=g.user_id
 WHERE g.user_id=public.app_actor() AND g.revoked_at IS NULL AND u.email_verified_at IS NOT NULL
  AND NOT public.app_account_suspended(g.user_id) FOR SHARE OF g
$$;

CREATE FUNCTION app_suspension_version(kind text,account bigint,workspace uuid) RETURNS integer LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT (count(*)+count(s.lifted_at))::integer FROM public.platform_suspensions s
 WHERE (kind='account' AND s.user_id=account) OR (kind='workspace' AND s.org_id=workspace)
$$;
CREATE FUNCTION app_usable_owner_besides(excluded bigint) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.platform_grants g JOIN public.users u ON u.id=g.user_id WHERE g.user_id<>excluded AND g.revoked_at IS NULL
  AND u.email_verified_at IS NOT NULL AND u.admin_totp_confirmed_at IS NOT NULL AND 'platform.owner'=ANY(g.permissions) AND NOT public.app_account_suspended(g.user_id))
$$;

-- Server-computed consequences shown before an operator confirms; counts only.
CREATE FUNCTION app_platform_access_impact(kind text,reference text) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE account bigint; workspace uuid; current public.platform_suspensions; result jsonb;
BEGIN
 PERFORM public.app_platform_require('access.manage');
 IF kind='account' AND reference ~ '^[0-9]{1,18}$' THEN
  account := reference::bigint;
  IF NOT EXISTS(SELECT 1 FROM public.users WHERE id=account) THEN RAISE EXCEPTION 'Account unavailable' USING ERRCODE='P0002'; END IF;
  SELECT * INTO current FROM public.platform_suspensions WHERE user_id=account AND lifted_at IS NULL;
  result := jsonb_build_object('sessions',(SELECT count(*) FROM public.sessions WHERE user_id=account),
   'memberships',(SELECT count(*) FROM public.memberships WHERE user_id=account AND revoked_at IS NULL),
   'sole_owner_of',coalesce((SELECT jsonb_agg(jsonb_build_object('id',o.id,'name',o.name) ORDER BY o.name) FROM public.memberships m JOIN public.organizations o ON o.id=m.org_id
     WHERE m.user_id=account AND m.role='owner' AND m.revoked_at IS NULL
      AND NOT EXISTS(SELECT 1 FROM public.memberships x WHERE x.org_id=m.org_id AND x.user_id<>account AND x.role='owner' AND x.revoked_at IS NULL)),'[]'::jsonb),
   'platform_grant',EXISTS(SELECT 1 FROM public.platform_grants WHERE user_id=account AND revoked_at IS NULL),
   'self',account=public.app_actor(),
   'last_owner',EXISTS(SELECT 1 FROM public.platform_grants WHERE user_id=account AND revoked_at IS NULL AND 'platform.owner'=ANY(permissions)) AND NOT public.app_usable_owner_besides(account));
 ELSIF kind='workspace' AND reference ~ '^[0-9a-f-]{36}$' THEN
  workspace := reference::uuid;
  IF NOT EXISTS(SELECT 1 FROM public.organizations WHERE id=workspace) THEN RAISE EXCEPTION 'Workspace unavailable' USING ERRCODE='P0002'; END IF;
  SELECT * INTO current FROM public.platform_suspensions WHERE org_id=workspace AND lifted_at IS NULL;
  result := jsonb_build_object('members',(SELECT count(*) FROM public.memberships WHERE org_id=workspace AND revoked_at IS NULL),
   'partnerships',(SELECT count(*) FROM public.partnerships WHERE (brand_org_id=workspace OR creator_org_id=workspace) AND status='active'),
   'pending_notifications',(SELECT count(*) FROM public.notification_outbox WHERE org_id=workspace AND state='pending'),
   'ready_runs',(SELECT count(*) FROM public.schedule_runs WHERE org_id=workspace AND state='ready'),
   'approved_drafts',(SELECT count(*) FROM public.follow_up_drafts WHERE org_id=workspace AND state='approved'),
   'public_links',(SELECT count(*) FROM public.lead_links WHERE org_id=workspace AND revoked_at IS NULL AND expires_at>clock_timestamp())
     +(SELECT count(*) FROM public.media_kits WHERE org_id=workspace AND published)
     +(SELECT count(*) FROM public.partner_join_links WHERE brand_org_id=workspace AND revoked_at IS NULL)
     +(SELECT count(*) FROM public.onboarding_requests WHERE org_id=workspace AND status IN ('draft','correction') AND expires_at>clock_timestamp()));
 ELSE RAISE EXCEPTION 'Unknown subject' USING ERRCODE='22000';
 END IF;
 RETURN result || jsonb_build_object('suspended',current.id IS NOT NULL,'version',public.app_suspension_version(kind,account,workspace),
  'suspension',CASE WHEN current.id IS NULL THEN NULL ELSE jsonb_build_object('reason',current.reason,'since',current.suspended_at,'by',(SELECT name FROM public.users WHERE id=current.suspended_by)) END);
END $$;

CREATE FUNCTION app_platform_suspend(kind text,reference text,expected integer,explanation text) RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE account bigint; workspace uuid;
BEGIN
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

CREATE FUNCTION app_platform_reactivate(kind text,reference text,expected integer,explanation text) RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE account bigint; workspace uuid; lifted bigint;
BEGIN
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

CREATE FUNCTION app_platform_sign_out(target bigint,explanation text) RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE ended integer;
BEGIN
 PERFORM public.app_platform_require('access.manage');
 IF explanation IS NULL OR length(trim(explanation)) NOT BETWEEN 1 AND 240 THEN RAISE EXCEPTION 'Reason required' USING ERRCODE='22000'; END IF;
 IF NOT EXISTS(SELECT 1 FROM public.users WHERE id=target) THEN RAISE EXCEPTION 'Account unavailable' USING ERRCODE='P0002'; END IF;
 IF target=public.app_actor() THEN RAISE EXCEPTION 'Use your own account security to sign out your sessions' USING ERRCODE='22000'; END IF;
 DELETE FROM public.sessions WHERE user_id=target;
 GET DIAGNOSTICS ended = ROW_COUNT;
 INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),'account.signed_out',target::text,trim(explanation));
 RETURN ended;
END $$;

-- ---------------------------------------------------------------------------------------------
-- Support access: requested by an operator, approved by a workspace owner, read-only, at most 24 hours.
CREATE TABLE support_grants (
 id uuid PRIMARY KEY, org_id uuid NOT NULL REFERENCES organizations(id), operator_id bigint NOT NULL REFERENCES users(id),
 purpose varchar(240) NOT NULL CHECK(length(trim(purpose))>0),
 resources text[] NOT NULL CHECK(cardinality(resources)>0 AND resources <@ ARRAY['pipeline_entries','brand_contacts','reminders','onboarding_requests','outreach_templates','finance']::text[]),
 hours integer NOT NULL CHECK(hours BETWEEN 1 AND 24),
 state varchar(10) NOT NULL DEFAULT 'requested' CHECK(state IN ('requested','approved','declined','revoked','cancelled')),
 requested_at timestamptz NOT NULL DEFAULT clock_timestamp(), decided_by bigint REFERENCES users(id), decided_at timestamptz, expires_at timestamptz,
 ended_by bigint REFERENCES users(id), ended_at timestamptz, end_reason varchar(240), version integer NOT NULL DEFAULT 1 CHECK(version>0),
 CHECK(state<>'approved' OR (expires_at IS NOT NULL AND expires_at<=decided_at+interval '24 hours'))
);
CREATE UNIQUE INDEX support_grant_open ON support_grants(org_id,operator_id) WHERE state IN ('requested','approved');
ALTER TABLE support_grants ENABLE ROW LEVEL SECURITY;
ALTER TABLE support_grants FORCE ROW LEVEL SECURITY;

-- 'expired' is derived at read time so access ends on time without a job having to run.
CREATE FUNCTION app_support_state(g support_grants) RETURNS text LANGUAGE sql STABLE SET search_path=pg_catalog,public AS $$
 SELECT CASE WHEN g.state='approved' AND g.expires_at<=clock_timestamp() THEN 'expired' ELSE g.state END
$$;
CREATE FUNCTION app_support_row(g support_grants) RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT jsonb_build_object('id',g.id,'org_id',g.org_id,'workspace',(SELECT name FROM public.organizations WHERE id=g.org_id),'operator_id',g.operator_id,
  'operator',(SELECT name FROM public.users WHERE id=g.operator_id),'purpose',g.purpose,'resources',g.resources,'hours',g.hours,'state',public.app_support_state(g),
  'requested_at',g.requested_at,'decided_at',g.decided_at,'decided_by',(SELECT name FROM public.users WHERE id=g.decided_by),'expires_at',g.expires_at,
  'ended_at',g.ended_at,'ended_by',(SELECT name FROM public.users WHERE id=g.ended_by),'end_reason',g.end_reason,'version',g.version)
$$;

CREATE FUNCTION app_support_request(new_id uuid,target uuid,why text,areas text[],duration integer) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
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

CREATE FUNCTION app_platform_support_list(filters jsonb,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; wanted text := filters->>'state'; target uuid := nullif(filters->>'workspace','')::uuid;
BEGIN
 PERFORM public.app_platform_require('support.request');
 WITH matched AS (SELECT g.* FROM public.support_grants g WHERE (wanted IS NULL OR public.app_support_state(g)=wanted) AND (target IS NULL OR g.org_id=target))
 SELECT jsonb_build_object('total',(SELECT count(*) FROM matched),'rows',coalesce((SELECT jsonb_agg(public.app_support_row(m) ORDER BY m.requested_at DESC,m.id) FROM (
  SELECT * FROM matched ORDER BY requested_at DESC,id LIMIT 25 OFFSET greatest(page_number-1,0)*25) m),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

CREATE FUNCTION app_platform_support_grant(target uuid) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE g public.support_grants;
BEGIN
 PERFORM public.app_platform_require('support.request');
 SELECT * INTO g FROM public.support_grants WHERE id=target;
 IF NOT FOUND THEN RAISE EXCEPTION 'Support grant unavailable' USING ERRCODE='P0002'; END IF;
 RETURN public.app_support_row(g) || jsonb_build_object('mine',g.operator_id=public.app_actor());
END $$;

-- Operator side: withdraw an undecided request or end an active grant (own grants; any grant for a platform owner).
CREATE FUNCTION app_platform_support_end(target uuid,expected integer,why text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE g public.support_grants;
BEGIN
 PERFORM public.app_platform_require('support.request');
 IF why IS NULL OR length(trim(why)) NOT BETWEEN 1 AND 240 THEN RAISE EXCEPTION 'Reason required' USING ERRCODE='22000'; END IF;
 SELECT * INTO g FROM public.support_grants WHERE id=target FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Support grant unavailable' USING ERRCODE='P0002'; END IF;
 IF g.operator_id<>public.app_actor() AND NOT public.app_platform_allowed('platform.owner') THEN RAISE EXCEPTION 'Platform permission required' USING ERRCODE='42501'; END IF;
 IF g.version<>expected OR public.app_support_state(g) NOT IN ('requested','approved') THEN RAISE EXCEPTION 'Support grant changed. Reload before saving.' USING ERRCODE='40001'; END IF;
 UPDATE public.support_grants SET state=CASE WHEN g.state='requested' THEN 'cancelled' ELSE 'revoked' END,ended_at=clock_timestamp(),ended_by=public.app_actor(),end_reason=trim(why),version=version+1 WHERE id=target;
 INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),CASE WHEN g.state='requested' THEN 'support.cancelled' ELSE 'support.ended' END,target::text,trim(why));
 INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),g.org_id,public.app_actor(),'support.ended_by_operator',target);
END $$;

-- Workspace side. Only an active owner of the selected workspace can see or decide its requests.
-- Approval uses one instant for decided_at and expires_at, so a 24-hour grant is exactly 24 hours.
CREATE FUNCTION app_workspace_support_requests() RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF NOT public.app_has_membership(public.app_org(),ARRAY['owner']) THEN RAISE EXCEPTION 'Workspace owner required' USING ERRCODE='42501'; END IF;
 RETURN coalesce((SELECT jsonb_agg(public.app_support_row(g)-'org_id'-'operator_id' ORDER BY g.requested_at DESC,g.id) FROM (
  SELECT * FROM public.support_grants WHERE org_id=public.app_org() ORDER BY requested_at DESC,id LIMIT 50) g),'[]'::jsonb);
END $$;
CREATE FUNCTION app_workspace_support_decide(target uuid,expected integer,decision text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE g public.support_grants; moment timestamptz := clock_timestamp();
BEGIN
 IF NOT public.app_lock_membership(public.app_org()) OR NOT public.app_has_membership(public.app_org(),ARRAY['owner']) THEN RAISE EXCEPTION 'Workspace owner required' USING ERRCODE='42501'; END IF;
 IF decision IS NULL OR decision NOT IN ('approve','decline','end') THEN RAISE EXCEPTION 'Unknown decision' USING ERRCODE='22000'; END IF;
 SELECT * INTO g FROM public.support_grants WHERE id=target AND org_id=public.app_org() FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Support request unavailable' USING ERRCODE='P0002'; END IF;
 IF g.version<>expected OR (decision IN ('approve','decline') AND g.state<>'requested') OR (decision='end' AND public.app_support_state(g)<>'approved') THEN
  RAISE EXCEPTION 'Support request changed. Reload before deciding.' USING ERRCODE='40001'; END IF;
 IF decision='approve' THEN
  UPDATE public.support_grants SET state='approved',decided_by=public.app_actor(),decided_at=moment,expires_at=moment+make_interval(hours => g.hours),version=version+1 WHERE id=target;
 ELSIF decision='decline' THEN
  UPDATE public.support_grants SET state='declined',decided_by=public.app_actor(),decided_at=clock_timestamp(),version=version+1 WHERE id=target;
 ELSE
  UPDATE public.support_grants SET state='revoked',ended_by=public.app_actor(),ended_at=clock_timestamp(),end_reason='Ended by a workspace owner',version=version+1 WHERE id=target;
 END IF;
 INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),g.org_id,public.app_actor(),'support.'||CASE decision WHEN 'approve' THEN 'approved' WHEN 'decline' THEN 'declined' ELSE 'ended' END,target);
 INSERT INTO public.platform_audit(actor_id,operator,event,target,reason) VALUES(public.app_actor(),'workspace-owner','support.'||CASE decision WHEN 'approve' THEN 'approved' WHEN 'decline' THEN 'declined' ELSE 'ended_by_owner' END,target::text,'Workspace owner decision');
END $$;

-- The only path from administration to private workspace records. It requires the operator's own approved,
-- unexpired grant covering the requested area, returns a fixed read-only projection and records every view.
-- Free-text notes, template bodies, onboarding answers, documents and payment evidence are never returned.
CREATE FUNCTION app_support_records(target uuid,area text,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE g public.support_grants; selection text; ordering text; total bigint; listing jsonb;
BEGIN
 PERFORM public.app_platform_require('support.request');
 SELECT * INTO g FROM public.support_grants WHERE id=target FOR SHARE;
 IF NOT FOUND OR g.operator_id<>public.app_actor() OR public.app_support_state(g)<>'approved'
  OR NOT (CASE WHEN area IN ('invoice_drafts','manual_payments') THEN 'finance' ELSE area END)=ANY(g.resources) THEN
  RAISE EXCEPTION 'Support access is not active for this area' USING ERRCODE='42501'; END IF;
 selection := CASE area
  WHEN 'pipeline_entries' THEN 'id,brand_name,contact_name,contact_email,stage,value_minor,currency,next_action_due,created_at'
  WHEN 'brand_contacts' THEN 'id,name,email,company,created_at'
  WHEN 'reminders' THEN 'id,title,due_at,completed_at,created_at'
  WHEN 'onboarding_requests' THEN 'id,label,status,business_name,expires_at,created_at'
  WHEN 'outreach_templates' THEN 'id,name,subject,created_at'
  WHEN 'invoice_drafts' THEN 'id,recipient_name,status,total_minor,currency,due_date,created_at'
  WHEN 'manual_payments' THEN 'id,status,amount_minor,currency,received_on,created_at' END;
 IF selection IS NULL THEN RAISE EXCEPTION 'Unknown area' USING ERRCODE='22000'; END IF;
 EXECUTE format('SELECT count(*) FROM public.%I WHERE org_id=$1',area) INTO total USING g.org_id;
 EXECUTE format('SELECT coalesce(jsonb_agg(to_jsonb(x)),''[]''::jsonb) FROM (SELECT %s FROM public.%I WHERE org_id=$1 ORDER BY created_at DESC,id LIMIT 25 OFFSET $2) x',selection,area)
  INTO listing USING g.org_id,greatest(page_number-1,0)*25;
 INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),'support.viewed',target::text,'Viewed '||area);
 INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),g.org_id,public.app_actor(),'support.viewed.'||area,target);
 RETURN jsonb_build_object('rows',listing,'total',total);
END $$;

-- ---------------------------------------------------------------------------------------------
-- Cleanup dry runs. Nothing here deletes or changes a business record; there is no execution function.
CREATE TABLE fixture_batches (
 id uuid PRIMARY KEY, name varchar(120) NOT NULL UNIQUE CHECK(length(trim(name))>0), reason varchar(240) NOT NULL CHECK(length(trim(reason))>0),
 created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(), version integer NOT NULL DEFAULT 1 CHECK(version>0)
);
CREATE TABLE fixture_marks (
 batch_id uuid NOT NULL REFERENCES fixture_batches(id), subject varchar(9) NOT NULL CHECK(subject IN ('account','workspace')),
 user_id bigint REFERENCES users(id), org_id uuid REFERENCES organizations(id), evidence varchar(240) NOT NULL CHECK(length(trim(evidence))>0),
 marked_by bigint NOT NULL REFERENCES users(id), marked_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CHECK((subject='account' AND user_id IS NOT NULL AND org_id IS NULL) OR (subject='workspace' AND org_id IS NOT NULL AND user_id IS NULL)),
 UNIQUE(batch_id,user_id), UNIQUE(batch_id,org_id)
);
CREATE TABLE cleanup_runs (
 id uuid PRIMARY KEY, batch_id uuid NOT NULL REFERENCES fixture_batches(id), batch_version integer NOT NULL, manifest jsonb NOT NULL,
 eligible integer NOT NULL, blocked integer NOT NULL, protected integer NOT NULL, created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE fixture_batches ENABLE ROW LEVEL SECURITY; ALTER TABLE fixture_batches FORCE ROW LEVEL SECURITY;
ALTER TABLE fixture_marks ENABLE ROW LEVEL SECURITY; ALTER TABLE fixture_marks FORCE ROW LEVEL SECURITY;
ALTER TABLE cleanup_runs ENABLE ROW LEVEL SECURITY; ALTER TABLE cleanup_runs FORCE ROW LEVEL SECURITY;

CREATE FUNCTION app_cleanup_batch_create(new_id uuid,label text,why text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM public.app_platform_require('cleanup.review');
 IF label IS NULL OR length(trim(label)) NOT BETWEEN 1 AND 120 OR why IS NULL OR length(trim(why)) NOT BETWEEN 1 AND 240 THEN RAISE EXCEPTION 'Name and reason required' USING ERRCODE='22000'; END IF;
 IF EXISTS(SELECT 1 FROM public.fixture_batches WHERE name=trim(label)) THEN RAISE EXCEPTION 'Batch name already used' USING ERRCODE='40001'; END IF;
 INSERT INTO public.fixture_batches(id,name,reason,created_by) VALUES(new_id,trim(label),trim(why),public.app_actor());
 INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),'cleanup.batch_created',new_id::text,trim(why));
END $$;

-- Marking is explicit, one record at a time, with stated evidence. Nothing is selected by pattern.
CREATE FUNCTION app_cleanup_mark(batch uuid,expected integer,kind text,reference text,proof text,remove boolean) RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE b public.fixture_batches; account bigint; workspace uuid; changed integer;
BEGIN
 PERFORM public.app_platform_require('cleanup.review');
 SELECT * INTO b FROM public.fixture_batches WHERE id=batch FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Batch unavailable' USING ERRCODE='P0002'; END IF;
 IF b.version<>expected THEN RAISE EXCEPTION 'Batch changed. Reload before saving.' USING ERRCODE='40001'; END IF;
 IF kind='account' AND reference ~ '^[0-9]{1,18}$' AND EXISTS(SELECT 1 FROM public.users WHERE id=reference::bigint) THEN account := reference::bigint;
 ELSIF kind='workspace' AND reference ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' AND EXISTS(SELECT 1 FROM public.organizations WHERE id=reference::uuid) THEN workspace := reference::uuid;
 ELSE RAISE EXCEPTION 'Enter an existing account ID or workspace ID' USING ERRCODE='22000';
 END IF;
 IF remove THEN
  DELETE FROM public.fixture_marks WHERE batch_id=batch AND ((kind='account' AND user_id=account) OR (kind='workspace' AND org_id=workspace));
 ELSE
  IF proof IS NULL OR length(trim(proof)) NOT BETWEEN 1 AND 240 THEN RAISE EXCEPTION 'Evidence required' USING ERRCODE='22000'; END IF;
  INSERT INTO public.fixture_marks(batch_id,subject,user_id,org_id,evidence,marked_by) VALUES(batch,kind,account,workspace,trim(proof),public.app_actor()) ON CONFLICT DO NOTHING;
 END IF;
 GET DIAGNOSTICS changed = ROW_COUNT;
 IF changed=0 THEN RAISE EXCEPTION 'Batch changed. Reload before saving.' USING ERRCODE='40001'; END IF;
 UPDATE public.fixture_batches SET version=version+1 WHERE id=batch;
 INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),CASE WHEN remove THEN 'cleanup.unmarked' ELSE 'cleanup.marked' END,batch::text,kind||' '||reference||coalesce(': '||trim(proof),''));
 RETURN b.version+1;
END $$;

-- Exact, immutable inventory of what a cleanup of this batch would touch and why items are blocked or protected.
CREATE FUNCTION app_cleanup_dry_run(run_id uuid,batch uuid,expected integer) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE b public.fixture_batches; mark record; items jsonb := '[]'::jsonb; reasons text[]; records jsonb; source record; tally bigint; outcome text; held uuid[] := ARRAY[]::uuid[];
BEGIN
 PERFORM public.app_platform_require('cleanup.review');
 SELECT * INTO b FROM public.fixture_batches WHERE id=batch FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Batch unavailable' USING ERRCODE='P0002'; END IF;
 IF b.version<>expected THEN RAISE EXCEPTION 'Batch changed. Reload before saving.' USING ERRCODE='40001'; END IF;
 IF NOT EXISTS(SELECT 1 FROM public.fixture_marks WHERE batch_id=batch) THEN RAISE EXCEPTION 'Mark at least one record first' USING ERRCODE='22000'; END IF;
 FOR mark IN SELECT m.*,o.name AS workspace_name,u.name AS account_name FROM public.fixture_marks m LEFT JOIN public.organizations o ON o.id=m.org_id LEFT JOIN public.users u ON u.id=m.user_id
  WHERE m.batch_id=batch ORDER BY m.subject DESC,o.name,u.name,m.marked_at LOOP
  reasons := ARRAY[]::text[]; records := '{}'::jsonb; outcome := 'eligible';
  IF mark.subject='workspace' THEN
   FOR source IN SELECT c.table_name::text AS name,c.column_name::text AS key FROM information_schema.columns c JOIN information_schema.tables t ON t.table_schema=c.table_schema AND t.table_name=c.table_name
     WHERE c.table_schema='public' AND t.table_type='BASE TABLE' AND c.column_name IN ('org_id','brand_org_id','creator_org_id')
      AND c.table_name NOT IN ('audit_logs','pilot_admin_events','platform_suspensions','support_grants','fixture_marks') ORDER BY 1,2 LOOP
    EXECUTE format('SELECT count(*) FROM public.%I WHERE %I=$1',source.name,source.key) INTO tally USING mark.org_id;
    IF tally>0 THEN records := records || jsonb_build_object(source.name,coalesce((records->>source.name)::bigint,0)+tally); END IF;
   END LOOP;
   IF coalesce((records->>'invoice_drafts')::bigint,0)+coalesce((records->>'manual_payments')::bigint,0)>0 THEN reasons := array_append(reasons,'Holds finance records, which cleanup never removes'); END IF;
   IF EXISTS(SELECT 1 FROM public.memberships m WHERE m.org_id=mark.org_id AND NOT EXISTS(SELECT 1 FROM public.fixture_marks x WHERE x.batch_id=batch AND x.user_id=m.user_id)) THEN
    reasons := array_append(reasons,'Has a member account that is not marked in this batch'); END IF;
   IF EXISTS(SELECT 1 FROM public.partnerships p WHERE (p.brand_org_id=mark.org_id OR p.creator_org_id=mark.org_id)
     AND NOT EXISTS(SELECT 1 FROM public.fixture_marks x WHERE x.batch_id=batch AND x.org_id=CASE WHEN p.brand_org_id=mark.org_id THEN p.creator_org_id ELSE p.brand_org_id END)) THEN
    reasons := array_append(reasons,'Has a partnership with a workspace outside this batch'); END IF;
   IF EXISTS(SELECT 1 FROM public.notification_outbox n WHERE n.org_id=mark.org_id AND n.state IN ('pending','sending','uncertain')) THEN
    reasons := array_append(reasons,'Has notifications that are queued, sending or uncertain'); END IF;
   IF cardinality(reasons)>0 THEN outcome := 'blocked'; held := array_append(held,mark.org_id); END IF;
  ELSE
   records := jsonb_build_object('memberships',(SELECT count(*) FROM public.memberships WHERE user_id=mark.user_id),'sessions',(SELECT count(*) FROM public.sessions WHERE user_id=mark.user_id));
   IF EXISTS(SELECT 1 FROM public.platform_grants WHERE user_id=mark.user_id) THEN outcome := 'protected'; reasons := array_append(reasons,'Holds a platform grant; operators are always protected'); END IF;
   IF EXISTS(SELECT 1 FROM public.memberships m WHERE m.user_id=mark.user_id AND NOT EXISTS(SELECT 1 FROM public.fixture_marks x WHERE x.batch_id=batch AND x.org_id=m.org_id)) THEN
    reasons := array_append(reasons,'Is a member of a workspace that is not marked in this batch'); IF outcome='eligible' THEN outcome := 'blocked'; END IF; END IF;
   -- Workspaces are inventoried first, so an account is never eligible while a workspace it belongs to must stay.
   IF EXISTS(SELECT 1 FROM public.memberships m WHERE m.user_id=mark.user_id AND m.org_id=ANY(held)) THEN
    reasons := array_append(reasons,'Is a member of a workspace that is blocked in this batch'); IF outcome='eligible' THEN outcome := 'blocked'; END IF; END IF;
   IF EXISTS(SELECT 1 FROM public.platform_audit a WHERE a.actor_id=mark.user_id) THEN
    reasons := array_append(reasons,'Is named as the actor in administration audit history, which is always kept'); IF outcome='eligible' THEN outcome := 'blocked'; END IF; END IF;
  END IF;
  items := items || jsonb_build_object('type',mark.subject,'reference',coalesce(mark.org_id::text,mark.user_id::text),'name',coalesce(mark.workspace_name,mark.account_name),
   'evidence',mark.evidence,'disposition',outcome,'reasons',to_jsonb(reasons),'records',records);
 END LOOP;
 INSERT INTO public.cleanup_runs(id,batch_id,batch_version,manifest,eligible,blocked,protected,created_by)
  VALUES(run_id,batch,b.version,items,(SELECT count(*) FROM jsonb_array_elements(items) i WHERE i->>'disposition'='eligible'),
   (SELECT count(*) FROM jsonb_array_elements(items) i WHERE i->>'disposition'='blocked'),(SELECT count(*) FROM jsonb_array_elements(items) i WHERE i->>'disposition'='protected'),public.app_actor());
 INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),'cleanup.dry_run',run_id::text,'Dry run of batch '||b.name||'; no data deleted');
END $$;

CREATE FUNCTION app_cleanup_batches() RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM public.app_platform_require('cleanup.review');
 RETURN coalesce((SELECT jsonb_agg(row_to_json(x)) FROM (
  SELECT b.id,b.name,b.reason,b.created_at,(SELECT name FROM public.users WHERE id=b.created_by) AS created_by,
   (SELECT count(*) FROM public.fixture_marks m WHERE m.batch_id=b.id) AS marks,(SELECT count(*) FROM public.cleanup_runs r WHERE r.batch_id=b.id) AS runs
  FROM public.fixture_batches b ORDER BY b.created_at DESC,b.id LIMIT 100) x),'[]'::jsonb);
END $$;
CREATE FUNCTION app_cleanup_batch(target uuid) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb;
BEGIN
 PERFORM public.app_platform_require('cleanup.review');
 SELECT jsonb_build_object('batch',jsonb_build_object('id',b.id,'name',b.name,'reason',b.reason,'version',b.version,'created_at',b.created_at,'created_by',(SELECT name FROM public.users WHERE id=b.created_by)),
  'marks',coalesce((SELECT jsonb_agg(row_to_json(m)) FROM (SELECT f.subject AS type,coalesce(f.org_id::text,f.user_id::text) AS reference,coalesce(o.name,u.name) AS name,u.email,f.evidence,f.marked_at,
    (SELECT name FROM public.users WHERE id=f.marked_by) AS marked_by FROM public.fixture_marks f LEFT JOIN public.organizations o ON o.id=f.org_id LEFT JOIN public.users u ON u.id=f.user_id
    WHERE f.batch_id=b.id ORDER BY f.subject DESC,3,2) m),'[]'::jsonb),
  'runs',coalesce((SELECT jsonb_agg(row_to_json(r)) FROM (SELECT c.id,c.created_at,c.eligible,c.blocked,c.protected,c.batch_version<>b.version AS stale,(SELECT name FROM public.users WHERE id=c.created_by) AS created_by
    FROM public.cleanup_runs c WHERE c.batch_id=b.id ORDER BY c.created_at DESC,c.id LIMIT 25) r),'[]'::jsonb)) INTO result FROM public.fixture_batches b WHERE b.id=target;
 IF result IS NULL THEN RAISE EXCEPTION 'Batch unavailable' USING ERRCODE='P0002'; END IF;
 RETURN result;
END $$;
CREATE FUNCTION app_cleanup_run(target uuid) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb;
BEGIN
 PERFORM public.app_platform_require('cleanup.review');
 SELECT jsonb_build_object('id',c.id,'batch_id',b.id,'batch',b.name,'created_at',c.created_at,'created_by',(SELECT name FROM public.users WHERE id=c.created_by),
  'eligible',c.eligible,'blocked',c.blocked,'protected',c.protected,'stale',c.batch_version<>b.version,'items',c.manifest) INTO result
 FROM public.cleanup_runs c JOIN public.fixture_batches b ON b.id=c.batch_id WHERE c.id=target;
 IF result IS NULL THEN RAISE EXCEPTION 'Dry run unavailable' USING ERRCODE='P0002'; END IF;
 RETURN result;
END $$;

REVOKE ALL ON FUNCTION app_account_suspended(bigint),app_workspace_suspended(uuid),app_public_link_suspended(text,text),app_suspension_version(text,bigint,uuid),app_usable_owner_besides(bigint),
 app_platform_access_impact(text,text),app_platform_suspend(text,text,integer,text),app_platform_reactivate(text,text,integer,text),app_platform_sign_out(bigint,text),
 app_support_state(support_grants),app_support_row(support_grants),app_support_request(uuid,uuid,text,text[],integer),app_platform_support_list(jsonb,integer),app_platform_support_grant(uuid),
 app_platform_support_end(uuid,integer,text),app_workspace_support_requests(),app_workspace_support_decide(uuid,integer,text),app_support_records(uuid,text,integer),
 app_cleanup_batch_create(uuid,text,text),app_cleanup_mark(uuid,integer,text,text,text,boolean),app_cleanup_dry_run(uuid,uuid,integer),app_cleanup_batches(),app_cleanup_batch(uuid),app_cleanup_run(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_account_suspended(bigint),app_workspace_suspended(uuid),app_public_link_suspended(text,text),
 app_platform_access_impact(text,text),app_platform_suspend(text,text,integer,text),app_platform_reactivate(text,text,integer,text),app_platform_sign_out(bigint,text),
 app_support_request(uuid,uuid,text,text[],integer),app_platform_support_list(jsonb,integer),app_platform_support_grant(uuid),app_platform_support_end(uuid,integer,text),
 app_workspace_support_requests(),app_workspace_support_decide(uuid,integer,text),app_support_records(uuid,text,integer),
 app_cleanup_batch_create(uuid,text,text),app_cleanup_mark(uuid,integer,text,text,text,boolean),app_cleanup_dry_run(uuid,uuid,integer),app_cleanup_batches(),app_cleanup_batch(uuid),app_cleanup_run(uuid) TO weavdash_app;
