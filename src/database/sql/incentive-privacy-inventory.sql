-- Workspace closure review includes Brand incentives (D44): offers and their audience belong to the Brand
-- workspace; awards and their cited leads belong to both sides of the relationship. Other scopes are unchanged.
CREATE OR REPLACE FUNCTION app_privacy_inventory(kind text,account bigint,workspace uuid) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
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
   ARRAY['pilot_assignments','org_id'],ARRAY['pilot_admin_events','org_id'],ARRAY['audit_logs','org_id'],ARRAY['support_grants','org_id'],ARRAY['privacy_exports','org_id'],
   ARRAY['campaigns','org_id'],
   ARRAY['incentives','org_id'],ARRAY['incentive_partners','relationship'],ARRAY['incentive_awards','relationship'],ARRAY['incentive_award_registrations','relationship']];
 ELSIF kind='account' THEN
  specs:=ARRAY[ARRAY['users','id'],ARRAY['memberships','user_id'],ARRAY['sessions','user_id'],ARRAY['brand_requests','applicant_id'],ARRAY['platform_grants','user_id'],
   ARRAY['account_security_events','user_id'],ARRAY['account_recovery_codes','user_id'],ARRAY['account_notification_outbox','user_id'],
   ARRAY['automation_preferences','actor_id'],ARRAY['automation_consent_events','actor_id'],ARRAY['business_schedules','actor_id'],ARRAY['schedule_runs','actor_id'],ARRAY['follow_up_drafts','actor_id'],ARRAY['notification_outbox','actor_id'],ARRAY['audit_logs','actor_id'],ARRAY['privacy_exports','actor_id']];
 ELSE RAISE EXCEPTION 'Unknown closure scope' USING ERRCODE='22000'; END IF;
 FOREACH spec SLICE 1 IN ARRAY specs LOOP
  predicate:=CASE WHEN spec[2]='relationship' THEN 'brand_org_id=$1::uuid OR creator_org_id=$1::uuid' ELSE format('%I=$1::%s',spec[2],CASE WHEN kind='workspace' THEN 'uuid' ELSE 'bigint' END) END;
  EXECUTE format('SELECT coalesce(jsonb_agg(item ORDER BY item::text),''[]''::jsonb) FROM (
   SELECT jsonb_build_object(''keys'',jsonb_strip_nulls(jsonb_build_object(''id'',j->''id'',''org_id'',j->''org_id'',''user_id'',j->''user_id'',''actor_id'',j->''actor_id'',
    ''invoice_id'',j->''invoice_id'',''position'',j->''position'',''pipeline_entry_id'',j->''pipeline_entry_id'',''contact_id'',j->''contact_id'',''command_id'',j->''command_id'',''operation'',j->''operation'',''incentive_id'',j->''incentive_id'',''award_id'',j->''award_id'',''registration_id'',j->''registration_id'')),
    ''basis'',encode(sha256(convert_to((j-ARRAY[''password'',''remember_token'',''admin_totp_secret'',''admin_totp_counter'',''code_hash'',''payload'',''last_activity''])::text,''UTF8'')),''hex'')) AS item
   FROM (SELECT to_jsonb(r) AS j FROM public.%I r WHERE %s LIMIT 20001) source) projected',spec[1],predicate)
   INTO rows USING CASE WHEN kind='workspace' THEN workspace::text ELSE account::text END;
  total:=total+jsonb_array_length(rows);
  IF total>20000 THEN RAISE EXCEPTION 'This review is too large for self service. Contact the privacy team.' USING ERRCODE='22000'; END IF;
  result:=result||jsonb_build_object(spec[1],rows);
 END LOOP;
 RETURN result;
END $$;
