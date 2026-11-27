-- Run as the migration owner after migrations. Role names match Compose/CI.
GRANT USAGE ON SCHEMA public TO weavdash_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO weavdash_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO weavdash_app;
GRANT EXECUTE ON FUNCTION app_has_membership(uuid, text[]) TO weavdash_app;
GRANT EXECUTE ON FUNCTION app_create_workspace(uuid, text) TO weavdash_app;
-- The web runtime must not inspect/change migration bookkeeping.
REVOKE ALL ON TABLE migrations FROM weavdash_app;

DO $$ BEGIN
 IF to_regclass('public.privacy_closure_requests') IS NOT NULL THEN
  REVOKE ALL ON public.privacy_closure_requests,public.privacy_closure_events FROM weavdash_app;
 END IF;
END $$;

-- PRIV-05 upload reconciliation is reachable only through its functions.
DO $$ BEGIN
 IF to_regclass('public.upload_reconciliation_runs') IS NOT NULL THEN
  REVOKE ALL ON public.upload_reconciliation_runs,public.upload_reconciliation_findings FROM weavdash_app;
 END IF;
END $$;

DO $$ BEGIN
 IF to_regclass('public.privacy_export_events') IS NOT NULL THEN
  REVOKE UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER ON public.privacy_export_events FROM weavdash_app;
  REVOKE DELETE,TRUNCATE,REFERENCES,TRIGGER ON public.privacy_exports FROM weavdash_app;
 END IF;
END $$;

GRANT EXECUTE ON FUNCTION app_onboarding_access(uuid), app_onboarding_scope(text), app_exchange_onboarding(text,text,uuid), app_public_kit(uuid), app_capture_lead(text,uuid,text,text,text,text,uuid) TO weavdash_app;

-- Reusable link mutations must remain available only through their guarded functions.
DO $$ BEGIN
 IF to_regclass('public.partner_join_links') IS NOT NULL THEN
  REVOKE INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON public.partner_join_links FROM weavdash_app;
 END IF;
END $$;

-- Brand lead visibility (D38): registrations, sign-in times and shared profiles change only through their
-- guarded functions. Sign-in times are not readable directly at all.
DO $$ BEGIN
 IF to_regclass('public.lead_registrations') IS NOT NULL THEN
  REVOKE INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON public.lead_registrations, public.partner_shared_profiles FROM weavdash_app;
  REVOKE ALL ON public.workspace_sign_ins FROM weavdash_app;
 END IF;
END $$;

DO $$ BEGIN
 IF to_regclass('public.mail_budget_policy') IS NOT NULL THEN
  REVOKE ALL ON public.mail_budget_policy,public.mail_budget_attempts FROM weavdash_app;
 END IF;
END $$;

DO $$ BEGIN
 IF to_regclass('public.campaigns') IS NOT NULL THEN
  REVOKE UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON public.campaigns FROM weavdash_app;
 END IF;
END $$;
