-- PRIV-03. Restriction and reviewed cancellation only: no erasure function or timed deletion job.
CREATE TABLE privacy_closure_requests (
 id uuid PRIMARY KEY, scope varchar(12) NOT NULL CHECK(scope IN ('account','workspace')),
 user_id bigint REFERENCES users(id), org_id uuid REFERENCES organizations(id),
 requested_by bigint NOT NULL REFERENCES users(id), subject_label varchar(160) NOT NULL,
 reason varchar(240) NOT NULL CHECK(length(trim(reason))>0),
 state varchar(12) NOT NULL DEFAULT 'cooling_off' CHECK(state IN ('cooling_off','cancelled')),
 version integer NOT NULL DEFAULT 1 CHECK(version>0),
 manifest jsonb NOT NULL, manifest_hash char(64) NOT NULL,
 requested_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 not_before timestamptz NOT NULL DEFAULT clock_timestamp()+interval '30 days',
 assigned_to bigint REFERENCES users(id), reviewed_by bigint REFERENCES users(id), reviewed_at timestamptz,
 cancelled_by bigint REFERENCES users(id), cancelled_at timestamptz,
 CHECK((scope='account' AND user_id IS NOT NULL AND org_id IS NULL) OR (scope='workspace' AND org_id IS NOT NULL AND user_id IS NULL)),
 CHECK((state='cancelled')=(cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL))
);
CREATE UNIQUE INDEX privacy_closure_account ON privacy_closure_requests(user_id) WHERE state='cooling_off';
CREATE UNIQUE INDEX privacy_closure_workspace ON privacy_closure_requests(org_id) WHERE state='cooling_off';
CREATE INDEX privacy_closure_review ON privacy_closure_requests(state,requested_at,id);
CREATE TABLE privacy_closure_events (
 id uuid PRIMARY KEY, request_id uuid NOT NULL REFERENCES privacy_closure_requests(id),
 actor_id bigint NOT NULL REFERENCES users(id), event varchar(16) NOT NULL CHECK(event IN ('requested','cancelled','assigned','reviewed')),
 reason varchar(240) NOT NULL, verification_reference varchar(160), occurred_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE privacy_closure_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE privacy_closure_requests FORCE ROW LEVEL SECURITY;
ALTER TABLE privacy_closure_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE privacy_closure_events FORCE ROW LEVEL SECURITY;
-- No runtime table policies. Private manifests never become directory or Inertia projections.

CREATE FUNCTION app_account_closing(target bigint) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.privacy_closure_requests WHERE user_id=target AND state='cooling_off')
$$;
CREATE FUNCTION app_workspace_closing(target uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.privacy_closure_requests WHERE org_id=target AND state='cooling_off')
$$;
-- Read barriers precede existing membership/partnership/subject locks. Anchor locks detect old repeatable-read
-- snapshots after an exclusive closer commits; ordinary non-key account/settings updates remain compatible.
CREATE FUNCTION app_privacy_lock_access(account bigint,workspace uuid) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF account IS NOT NULL AND account>0 THEN
  PERFORM pg_advisory_xact_lock_shared(hashtextextended('privacy-account:'||account::text,0));
  IF current_setting('transaction_isolation') IN ('repeatable read','serializable') THEN
   PERFORM 1 FROM public.users WHERE id=account FOR SHARE;
  ELSE PERFORM 1 FROM public.users WHERE id=account FOR KEY SHARE; END IF;
 END IF;
 IF workspace IS NOT NULL THEN
  PERFORM pg_advisory_xact_lock_shared(hashtextextended('privacy-workspace:'||workspace::text,0));
  IF current_setting('transaction_isolation') IN ('repeatable read','serializable') THEN
   PERFORM 1 FROM public.organizations WHERE id=workspace FOR SHARE;
  ELSE PERFORM 1 FROM public.organizations WHERE id=workspace FOR KEY SHARE; END IF;
 END IF;
END $$;
CREATE FUNCTION app_privacy_mail_allowed(account bigint) RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM public.app_privacy_lock_access(account,NULL);
 RETURN NOT public.app_account_closing(account) AND NOT public.app_account_suspended(account);
END $$;
CREATE FUNCTION app_privacy_raw_owner(workspace uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.memberships WHERE org_id=workspace AND user_id=public.app_actor() AND role='owner' AND revoked_at IS NULL)
$$;
CREATE FUNCTION app_privacy_usable_owner(workspace uuid,except_account bigint) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.memberships m JOIN public.users u ON u.id=m.user_id
 WHERE m.org_id=workspace AND m.role='owner' AND m.revoked_at IS NULL AND m.user_id IS DISTINCT FROM except_account
 AND u.email_verified_at IS NOT NULL AND u.admin_totp_confirmed_at IS NOT NULL AND NOT public.app_account_suspended(m.user_id) AND NOT public.app_account_closing(m.user_id))
$$;

-- Exact private record locators + digests, not field contents. Table/filter identifiers are fixed here.
CREATE FUNCTION app_privacy_inventory(kind text,account bigint,workspace uuid) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE spec text[]; specs text[][]; rows jsonb; result jsonb:='{}'::jsonb; total integer:=0; predicate text;
BEGIN
 IF kind='workspace' THEN
  specs:=ARRAY[ARRAY['organizations','id'],ARRAY['memberships','org_id'],ARRAY['pipeline_entries','org_id'],ARRAY['pipeline_activities','org_id'],
   ARRAY['brand_contacts','org_id'],ARRAY['pipeline_contacts','org_id'],ARRAY['reminders','org_id'],ARRAY['outreach_templates','org_id'],
   ARRAY['media_kits','org_id'],ARRAY['lead_links','org_id'],ARRAY['public_lead_receipts','org_id'],ARRAY['onboarding_requests','org_id'],
   ARRAY['onboarding_sessions','org_id'],ARRAY['onboarding_documents','org_id'],ARRAY['onboarding_submissions','org_id'],ARRAY['onboarding_events','org_id'],ARRAY['onboarding_return_links','org_id'],
   ARRAY['partner_invitations','brand_org_id'],ARRAY['partner_join_links','brand_org_id'],ARRAY['partner_shared_profiles','org_id'],ARRAY['workspace_sign_ins','org_id'],
   ARRAY['partnerships','relationship'],ARRAY['lead_registrations','relationship'],ARRAY['brand_requests','org_id'],
   ARRAY['automation_preferences','org_id'],ARRAY['automation_consent_events','org_id'],ARRAY['business_schedules','org_id'],ARRAY['schedule_runs','org_id'],ARRAY['follow_up_drafts','org_id'],ARRAY['notification_outbox','org_id'],
   ARRAY['invoice_drafts','org_id'],ARRAY['invoice_draft_lines','org_id'],ARRAY['financial_commands','org_id'],ARRAY['manual_payments','org_id'],ARRAY['payment_allocations','org_id'],ARRAY['payment_allocation_reversals','org_id'],ARRAY['payment_commands','org_id'],
   ARRAY['pilot_assignments','org_id'],ARRAY['pilot_admin_events','org_id'],ARRAY['audit_logs','org_id'],ARRAY['support_grants','org_id'],ARRAY['privacy_exports','org_id']];
 ELSIF kind='account' THEN
  specs:=ARRAY[ARRAY['users','id'],ARRAY['memberships','user_id'],ARRAY['sessions','user_id'],ARRAY['brand_requests','applicant_id'],ARRAY['platform_grants','user_id'],
   ARRAY['account_security_events','user_id'],ARRAY['account_recovery_codes','user_id'],ARRAY['account_notification_outbox','user_id'],
   ARRAY['automation_preferences','actor_id'],ARRAY['automation_consent_events','actor_id'],ARRAY['business_schedules','actor_id'],ARRAY['schedule_runs','actor_id'],ARRAY['follow_up_drafts','actor_id'],ARRAY['notification_outbox','actor_id'],ARRAY['audit_logs','actor_id'],ARRAY['privacy_exports','actor_id']];
 ELSE RAISE EXCEPTION 'Unknown closure scope' USING ERRCODE='22000'; END IF;
 FOREACH spec SLICE 1 IN ARRAY specs LOOP
  predicate:=CASE WHEN spec[2]='relationship' THEN 'brand_org_id=$1::uuid OR creator_org_id=$1::uuid' ELSE format('%I=$1::%s',spec[2],CASE WHEN kind='workspace' THEN 'uuid' ELSE 'bigint' END) END;
  EXECUTE format('SELECT coalesce(jsonb_agg(item ORDER BY item::text),''[]''::jsonb) FROM (
   SELECT jsonb_build_object(''keys'',jsonb_strip_nulls(jsonb_build_object(''id'',j->''id'',''org_id'',j->''org_id'',''user_id'',j->''user_id'',''actor_id'',j->''actor_id'',
    ''invoice_id'',j->''invoice_id'',''position'',j->''position'',''pipeline_entry_id'',j->''pipeline_entry_id'',''contact_id'',j->''contact_id'',''command_id'',j->''command_id'',''operation'',j->''operation'')),
    ''basis'',encode(sha256(convert_to((j-ARRAY[''password'',''remember_token'',''admin_totp_secret'',''admin_totp_counter'',''code_hash'',''payload'',''last_activity''])::text,''UTF8'')),''hex'')) AS item
   FROM (SELECT to_jsonb(r) AS j FROM public.%I r WHERE %s LIMIT 20001) source) projected',spec[1],predicate)
   INTO rows USING CASE WHEN kind='workspace' THEN workspace::text ELSE account::text END;
  total:=total+jsonb_array_length(rows);
  IF total>20000 THEN RAISE EXCEPTION 'This review is too large for self service. Contact the privacy team.' USING ERRCODE='22000'; END IF;
  result:=result||jsonb_build_object(spec[1],rows);
 END LOOP;
 RETURN result;
END $$;

CREATE FUNCTION app_privacy_manifest_hash(inventory jsonb) RETURNS text LANGUAGE sql IMMUTABLE SET search_path=pg_catalog AS $$
 -- Session IDs rotate during fresh verification; they are inventoried at confirmation but not approval basis.
 SELECT encode(sha256(convert_to((inventory-'sessions')::text,'UTF8')),'hex')
$$;
CREATE FUNCTION app_privacy_counts(inventory jsonb) RETURNS jsonb LANGUAGE sql IMMUTABLE SET search_path=pg_catalog AS $$
 SELECT coalesce(jsonb_object_agg(key,jsonb_array_length(value)),'{}'::jsonb) FROM jsonb_each(inventory)
$$;
CREATE FUNCTION app_privacy_blockers(kind text,account bigint,workspace uuid) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE blocked jsonb:='[]'::jsonb; missing record;
BEGIN
 IF kind='account' THEN
  IF public.app_account_closing(account) THEN blocked:=blocked||jsonb_build_array('This account already has an active closure request.'); END IF;
  IF EXISTS(SELECT 1 FROM public.platform_grants WHERE user_id=account AND revoked_at IS NULL) THEN blocked:=blocked||jsonb_build_array('Platform operators must transfer or revoke their grant through the protected operator process before closing their account.'); END IF;
  FOR missing IN SELECT o.name FROM public.memberships m JOIN public.organizations o ON o.id=m.org_id WHERE m.user_id=account AND m.role='owner' AND m.revoked_at IS NULL
   AND NOT public.app_workspace_closing(m.org_id) AND NOT public.app_privacy_usable_owner(m.org_id,account) ORDER BY m.org_id LOOP
   blocked:=blocked||jsonb_build_array('Another available owner is required for '||missing.name||'. Transfer ownership or close that workspace first.');
  END LOOP;
 ELSIF public.app_workspace_closing(workspace) THEN blocked:=blocked||jsonb_build_array('This workspace already has an active closure request.'); END IF;
 RETURN blocked;
END $$;
CREATE FUNCTION app_privacy_preview(kind text,workspace uuid) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE account bigint:=public.app_actor(); inventory jsonb; label text;
BEGIN
 IF account IS NULL OR public.app_account_suspended(account) OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=account AND email_verified_at IS NOT NULL) THEN RAISE EXCEPTION 'Verified account required' USING ERRCODE='42501'; END IF;
 IF kind='workspace' THEN
  IF public.app_account_closing(account) OR NOT public.app_privacy_raw_owner(workspace) OR public.app_workspace_suspended(workspace) THEN RAISE EXCEPTION 'Workspace owner required' USING ERRCODE='42501'; END IF;
  SELECT name INTO label FROM public.organizations WHERE id=workspace;
 ELSE
  IF kind<>'account' OR workspace IS NOT NULL THEN RAISE EXCEPTION 'Unknown scope' USING ERRCODE='22000'; END IF;
  SELECT name INTO label FROM public.users WHERE id=account;
 END IF;
 PERFORM public.app_privacy_lock_access(account,workspace);
 inventory:=public.app_privacy_inventory(kind,account,workspace);
 RETURN jsonb_build_object('scope',kind,'org_id',workspace,'label',label,'hash',public.app_privacy_manifest_hash(inventory),
  'counts',public.app_privacy_counts(inventory),'blockers',public.app_privacy_blockers(kind,account,workspace));
END $$;

CREATE FUNCTION app_privacy_close(target uuid,scope_kind text,workspace uuid,expected text,explanation text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE account bigint:=public.app_actor(); inventory jsonb; blockers jsonb; label text; owned record;
BEGIN
 IF account IS NULL OR explanation IS NULL OR length(trim(explanation)) NOT BETWEEN 1 AND 240 OR expected IS NULL THEN RAISE EXCEPTION 'Reason and reviewed scope required' USING ERRCODE='22000'; END IF;
 IF scope_kind='account' AND workspace IS NULL THEN
  PERFORM pg_advisory_xact_lock(hashtextextended('privacy-account:'||account::text,0));
  FOR owned IN SELECT org_id FROM public.memberships WHERE user_id=account AND role='owner' AND revoked_at IS NULL ORDER BY org_id LOOP
   PERFORM pg_advisory_xact_lock(hashtextextended('privacy-owner:'||owned.org_id::text,0));
  END LOOP;
 ELSIF scope_kind='workspace' AND workspace IS NOT NULL THEN
  PERFORM public.app_privacy_lock_access(account,NULL);
  PERFORM pg_advisory_xact_lock(hashtextextended('privacy-workspace:'||workspace::text,0));
  PERFORM pg_advisory_xact_lock(hashtextextended('privacy-owner:'||workspace::text,0));
 ELSE RAISE EXCEPTION 'Unknown scope' USING ERRCODE='22000'; END IF;
 IF scope_kind='workspace' THEN
  PERFORM 1 FROM public.partnerships WHERE workspace IN (brand_org_id,creator_org_id) ORDER BY id FOR UPDATE;
  PERFORM 1 FROM public.lead_registrations WHERE workspace IN (brand_org_id,creator_org_id) ORDER BY id FOR UPDATE;
 END IF;
 -- Fresh statement after the write barrier. Existing ordinary work has committed before inventory/checks.
 PERFORM 1 FROM public.users WHERE id=account AND email_verified_at IS NOT NULL FOR KEY SHARE;
 IF NOT FOUND OR public.app_account_suspended(account) OR public.app_account_closing(account) THEN RAISE EXCEPTION 'Account unavailable' USING ERRCODE='42501'; END IF;
 IF scope_kind='workspace' AND (NOT public.app_privacy_raw_owner(workspace) OR public.app_workspace_suspended(workspace)
  OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=account AND admin_totp_confirmed_at IS NOT NULL)) THEN RAISE EXCEPTION 'Verified workspace owner required' USING ERRCODE='42501'; END IF;
 blockers:=public.app_privacy_blockers(scope_kind,account,workspace);
 IF jsonb_array_length(blockers)>0 THEN RAISE EXCEPTION 'Closure is blocked. Review ownership and the current request.' USING ERRCODE='22000'; END IF;
 inventory:=public.app_privacy_inventory(scope_kind,account,workspace);
 IF public.app_privacy_manifest_hash(inventory)<>expected THEN RAISE EXCEPTION 'Records changed. Review the latest closure scope.' USING ERRCODE='40001'; END IF;
 SELECT CASE WHEN scope_kind='account' THEN (SELECT name FROM public.users WHERE id=account) ELSE (SELECT name FROM public.organizations WHERE id=workspace) END INTO label;
 INSERT INTO public.privacy_closure_requests(id,scope,user_id,org_id,requested_by,subject_label,reason,manifest,manifest_hash)
 VALUES(target,scope_kind,CASE WHEN scope_kind='account' THEN account END,workspace,account,label,trim(explanation),inventory,expected);
 IF scope_kind='workspace' THEN
  -- Keep the existing partnership-first order before touching publication, capabilities and subjects.
  PERFORM 1 FROM public.partnerships WHERE workspace IN (brand_org_id,creator_org_id) ORDER BY id FOR UPDATE;
  UPDATE public.organizations SET updated_at=clock_timestamp() WHERE id=workspace;
  UPDATE public.media_kits SET published=false,version=version+1 WHERE org_id=workspace AND published;
  UPDATE public.lead_links SET revoked_at=clock_timestamp() WHERE org_id=workspace AND revoked_at IS NULL;
  UPDATE public.partner_join_links SET revoked_at=clock_timestamp() WHERE brand_org_id=workspace AND revoked_at IS NULL;
  UPDATE public.partner_invitations SET status='revoked' WHERE brand_org_id=workspace AND status='pending';
  UPDATE public.partnerships SET status='revoked',revoked_at=clock_timestamp() WHERE workspace IN (brand_org_id,creator_org_id) AND status='active';
  UPDATE public.onboarding_requests SET status='revoked',version=version+1,expires_at=least(expires_at,clock_timestamp()) WHERE org_id=workspace AND status<>'revoked';
  DELETE FROM public.onboarding_sessions WHERE org_id=workspace;
  UPDATE public.onboarding_return_links SET expires_at=least(expires_at,clock_timestamp()) WHERE org_id=workspace;
  UPDATE public.support_grants SET state='revoked',ended_at=clock_timestamp(),ended_by=account,end_reason='Workspace closure',version=version+1 WHERE org_id=workspace AND state IN ('requested','approved');
 ELSE
  UPDATE public.users SET updated_at=clock_timestamp(),remember_token=NULL WHERE id=account;
  DELETE FROM public.sessions WHERE user_id=account;
  DELETE FROM public.password_reset_tokens WHERE email=(SELECT email FROM public.users WHERE id=account);
  UPDATE public.support_grants SET state='revoked',ended_at=clock_timestamp(),ended_by=account,end_reason='Account closure',version=version+1 WHERE operator_id=account AND state IN ('requested','approved');
 END IF;
 UPDATE public.business_schedules SET enabled=false,revoked_at=clock_timestamp(),version=version+1,updated_at=clock_timestamp()
  WHERE enabled AND ((scope_kind='workspace' AND org_id=workspace) OR (scope_kind='account' AND actor_id=account));
 UPDATE public.automation_preferences SET reminders=false,weekly_digest=false,follow_up_drafts=false,follow_up_reviews=false,version=version+1,updated_at=clock_timestamp()
  WHERE (scope_kind='workspace' AND org_id=workspace) OR (scope_kind='account' AND actor_id=account);
 UPDATE public.schedule_runs SET state='cancelled',completed_at=clock_timestamp() WHERE state='ready' AND ((scope_kind='workspace' AND org_id=workspace) OR (scope_kind='account' AND actor_id=account));
 UPDATE public.follow_up_drafts SET state='cancelled',version=version+1,approved_at=NULL,approved_version=NULL,updated_at=clock_timestamp()
  WHERE state IN ('draft','approved') AND ((scope_kind='workspace' AND org_id=workspace) OR (scope_kind='account' AND actor_id=account));
 UPDATE public.notification_outbox SET state='cancelled',encrypted_payload=NULL,completed_at=clock_timestamp()
  WHERE state='pending' AND ((scope_kind='workspace' AND org_id=workspace) OR (scope_kind='account' AND actor_id=account));
 UPDATE public.account_notification_outbox SET state='cancelled',completed_at=clock_timestamp()
  WHERE state='pending' AND ((scope_kind='account' AND user_id=account) OR (scope_kind='workspace' AND brand_request_id IN (SELECT id FROM public.brand_requests WHERE org_id=workspace)));
 UPDATE public.privacy_exports SET revoked_at=clock_timestamp() WHERE revoked_at IS NULL AND ((scope_kind='account' AND actor_id=account) OR (scope_kind='workspace' AND org_id=workspace));
 INSERT INTO public.privacy_closure_events(id,request_id,actor_id,event,reason) VALUES(gen_random_uuid(),target,account,'requested',trim(explanation));
 IF scope_kind='workspace' THEN INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),workspace,account,'privacy.closure.requested',target); END IF;
END $$;

CREATE FUNCTION app_privacy_cancel(target uuid,expected integer,explanation text,verification text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE r public.privacy_closure_requests; actor bigint:=public.app_actor(); self_service boolean;
BEGIN
 SELECT * INTO r FROM public.privacy_closure_requests WHERE id=target;
 IF r.id IS NULL THEN RAISE EXCEPTION 'Request unavailable' USING ERRCODE='P0002'; END IF;
 self_service:=(r.scope='account' AND r.user_id=actor) OR (r.scope='workspace' AND public.app_privacy_raw_owner(r.org_id) AND NOT public.app_account_closing(actor));
 IF self_service AND r.scope='workspace' AND NOT EXISTS(SELECT 1 FROM public.users WHERE id=actor AND admin_totp_confirmed_at IS NOT NULL) THEN RAISE EXCEPTION 'Enrolled owner required' USING ERRCODE='42501'; END IF;
 IF NOT self_service THEN
  PERFORM public.app_platform_require('platform.owner');
  IF verification IS NULL OR length(trim(verification)) NOT BETWEEN 8 AND 160 THEN RAISE EXCEPTION 'Record the verified subject request reference' USING ERRCODE='22000'; END IF;
 END IF;
 IF explanation IS NULL OR length(trim(explanation)) NOT BETWEEN 1 AND 240 THEN RAISE EXCEPTION 'Reason required' USING ERRCODE='22000'; END IF;
 IF r.scope='account' THEN PERFORM pg_advisory_xact_lock(hashtextextended('privacy-account:'||r.user_id::text,0));
 ELSE
  PERFORM pg_advisory_xact_lock(hashtextextended('privacy-workspace:'||r.org_id::text,0));
  PERFORM pg_advisory_xact_lock(hashtextextended('privacy-owner:'||r.org_id::text,0));
  IF NOT public.app_privacy_usable_owner(r.org_id,NULL) THEN RAISE EXCEPTION 'An available owner is required before reopening this workspace' USING ERRCODE='22000'; END IF;
 END IF;
 SELECT * INTO r FROM public.privacy_closure_requests WHERE id=target FOR UPDATE;
 IF r.state<>'cooling_off' OR r.version<>expected THEN RAISE EXCEPTION 'Request changed. Reload before cancelling.' USING ERRCODE='40001'; END IF;
 UPDATE public.privacy_closure_requests SET state='cancelled',cancelled_by=actor,cancelled_at=clock_timestamp(),version=version+1 WHERE id=target;
 IF r.scope='account' THEN UPDATE public.users SET updated_at=clock_timestamp() WHERE id=r.user_id;
 ELSE UPDATE public.organizations SET updated_at=clock_timestamp() WHERE id=r.org_id; END IF;
 INSERT INTO public.privacy_closure_events(id,request_id,actor_id,event,reason,verification_reference) VALUES(gen_random_uuid(),target,actor,'cancelled',trim(explanation),CASE WHEN NOT self_service THEN trim(verification) END);
 IF r.scope='workspace' THEN INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),r.org_id,actor,'privacy.closure.cancelled',target); END IF;
END $$;

CREATE FUNCTION app_privacy_history() RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT coalesce(jsonb_agg(row_to_json(r) ORDER BY r.requested_at DESC,r.id),'[]'::jsonb) FROM (
 SELECT c.id,c.scope,c.org_id,c.subject_label,c.state,c.version,c.reason,c.requested_at,c.not_before,c.cancelled_at,c.reviewed_at
 FROM public.privacy_closure_requests c WHERE c.requested_by=public.app_actor() OR (c.org_id IS NOT NULL AND public.app_privacy_raw_owner(c.org_id)) ORDER BY c.requested_at DESC,c.id LIMIT 100) r
$$;
CREATE FUNCTION app_privacy_targets() RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT coalesce(jsonb_agg(jsonb_build_object('id',o.id,'name',o.name) ORDER BY o.id),'[]'::jsonb) FROM public.organizations o
 WHERE public.app_privacy_raw_owner(o.id) AND NOT public.app_workspace_suspended(o.id)
$$;
CREATE FUNCTION app_privacy_review_list(page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM public.app_platform_require('platform.owner');
 IF page_number IS NULL OR page_number NOT BETWEEN 1 AND 100000 THEN RAISE EXCEPTION 'Invalid page' USING ERRCODE='22000'; END IF;
 RETURN jsonb_build_object('total',(SELECT count(*) FROM public.privacy_closure_requests),'rows',coalesce((SELECT jsonb_agg(row_to_json(x)) FROM (
  SELECT c.id,c.scope,c.subject_label,c.state,c.version,c.reason,c.requested_at,c.not_before,c.reviewed_at,c.assigned_to,
   u.name AS requested_by,u.email AS requester_email,(SELECT name FROM public.users WHERE id=c.assigned_to) AS handler,
   public.app_privacy_counts(c.manifest) AS counts
  FROM public.privacy_closure_requests c JOIN public.users u ON u.id=c.requested_by ORDER BY (c.state='cooling_off') DESC,c.requested_at,c.id LIMIT 20 OFFSET (page_number-1)*20) x),'[]'::jsonb));
END $$;
CREATE FUNCTION app_privacy_review(target uuid,expected integer,action text,explanation text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE r public.privacy_closure_requests;
BEGIN
 PERFORM public.app_platform_require('platform.owner');
 IF explanation IS NULL OR length(trim(explanation)) NOT BETWEEN 1 AND 240 OR action IS NULL OR action NOT IN ('assign','review') THEN RAISE EXCEPTION 'Review action and reason required' USING ERRCODE='22000'; END IF;
 SELECT * INTO r FROM public.privacy_closure_requests WHERE id=target FOR UPDATE;
 IF r.id IS NULL THEN RAISE EXCEPTION 'Request unavailable' USING ERRCODE='P0002'; END IF;
 IF r.version<>expected OR r.state<>'cooling_off' THEN RAISE EXCEPTION 'Request changed. Reload before reviewing.' USING ERRCODE='40001'; END IF;
 IF action='review' AND r.assigned_to IS DISTINCT FROM public.app_actor() THEN RAISE EXCEPTION 'Assign this request to yourself before reviewing' USING ERRCODE='22000'; END IF;
 UPDATE public.privacy_closure_requests SET assigned_to=public.app_actor(),reviewed_by=CASE WHEN action='review' THEN public.app_actor() ELSE reviewed_by END,
  reviewed_at=CASE WHEN action='review' THEN clock_timestamp() ELSE reviewed_at END,version=version+1 WHERE id=target;
 INSERT INTO public.privacy_closure_events(id,request_id,actor_id,event,reason) VALUES(gen_random_uuid(),target,public.app_actor(),CASE WHEN action='assign' THEN 'assigned' ELSE 'reviewed' END,trim(explanation));
 INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),'privacy.closure.'||action,target::text,trim(explanation));
END $$;

REVOKE ALL ON FUNCTION app_account_closing(bigint),app_workspace_closing(uuid),app_privacy_lock_access(bigint,uuid),app_privacy_mail_allowed(bigint),app_privacy_raw_owner(uuid),app_privacy_usable_owner(uuid,bigint),
 app_privacy_inventory(text,bigint,uuid),app_privacy_manifest_hash(jsonb),app_privacy_counts(jsonb),app_privacy_blockers(text,bigint,uuid),app_privacy_preview(text,uuid),
 app_privacy_close(uuid,text,uuid,text,text),app_privacy_cancel(uuid,integer,text,text),app_privacy_history(),app_privacy_targets(),app_privacy_review_list(integer),app_privacy_review(uuid,integer,text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_account_closing(bigint),app_workspace_closing(uuid),app_privacy_lock_access(bigint,uuid),app_privacy_mail_allowed(bigint),
 app_privacy_preview(text,uuid),app_privacy_close(uuid,text,uuid,text,text),app_privacy_cancel(uuid,integer,text,text),app_privacy_history(),app_privacy_targets(),app_privacy_review_list(integer),app_privacy_review(uuid,integer,text,text) TO weavdash_app;
