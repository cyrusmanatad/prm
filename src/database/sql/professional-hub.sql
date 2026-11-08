ALTER TABLE organizations ADD COLUMN experience varchar(16) NOT NULL DEFAULT 'partner' CHECK(experience IN ('partner','professional'));
ALTER TABLE organizations ADD CONSTRAINT professional_creator CHECK(experience<>'professional' OR kind='creator');
ALTER TABLE users ADD COLUMN registration_workspace_experience varchar(16) CHECK(registration_workspace_experience IN ('partner','professional'));
CREATE FUNCTION app_preserve_workspace_experience() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.experience IS DISTINCT FROM OLD.experience THEN RAISE EXCEPTION 'Workspace experience is immutable' USING ERRCODE='42501'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER organization_experience_immutable BEFORE UPDATE ON organizations FOR EACH ROW EXECUTE FUNCTION app_preserve_workspace_experience();
-- Set experience at INSERT from a transaction-local creation intent. Other INSERT paths default to partner.
CREATE FUNCTION app_workspace_creation_experience() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF current_setting('app.creation_experience',true)='professional' THEN NEW.experience:='professional'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER organization_creation_experience BEFORE INSERT ON organizations FOR EACH ROW EXECUTE FUNCTION app_workspace_creation_experience();
CREATE OR REPLACE FUNCTION app_create_experience_workspace(target uuid,label text,workspace_kind text,workspace_experience text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF workspace_experience IS NULL OR workspace_experience NOT IN ('partner','professional') OR (workspace_experience='professional' AND workspace_kind<>'creator') THEN
  RAISE EXCEPTION 'Invalid workspace experience' USING ERRCODE='22000';
 END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended(target::text,0));
 IF EXISTS(SELECT 1 FROM public.organizations WHERE id=target) THEN
  IF NOT public.app_has_membership(target,ARRAY['owner']) OR NOT EXISTS(SELECT 1 FROM public.organizations WHERE id=target AND kind=workspace_kind AND experience=workspace_experience) THEN
   RAISE EXCEPTION 'Workspace unavailable' USING ERRCODE='42501';
  END IF;
  RETURN;
 END IF;
 PERFORM set_config('app.creation_experience',workspace_experience,true);
 PERFORM public.app_create_typed_workspace(target,label,workspace_kind);
 PERFORM set_config('app.creation_experience','',true);
END $$;
REVOKE ALL ON FUNCTION app_create_experience_workspace(uuid,text,text,text),app_workspace_creation_experience(),app_preserve_workspace_experience() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_create_experience_workspace(uuid,text,text,text) TO weavdash_app;

CREATE FUNCTION app_professional_active(target uuid) RETURNS boolean LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT NOT EXISTS(SELECT 1 FROM public.organizations WHERE id=target AND experience='professional') OR
 EXISTS(SELECT 1 FROM (SELECT * FROM public.pilot_assignments WHERE org_id=target ORDER BY version DESC LIMIT 1) a
 WHERE a.starts_at<=clock_timestamp() AND a.ends_at>clock_timestamp())
$$;
REVOKE ALL ON FUNCTION app_professional_active(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_professional_active(uuid) TO weavdash_app;

-- All business writes, including capabilities and direct runtime SQL, share the subscription lock.
-- RLS continues to decide identity/role/record scope; these triggers only narrow access.
CREATE FUNCTION app_professional_write_guard() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE target uuid; old_data jsonb; new_data jsonb; exempt boolean:=false;
BEGIN
 target:=CASE WHEN TG_OP='DELETE' THEN OLD.org_id ELSE NEW.org_id END;
 PERFORM pg_advisory_xact_lock(hashtextextended('pilot:'||target::text,0));
 IF public.app_professional_active(target) THEN
  IF TG_OP='DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
 END IF;
 IF TG_OP='UPDATE' THEN
  old_data:=to_jsonb(OLD); new_data:=to_jsonb(NEW);
  IF TG_TABLE_NAME='business_schedules' THEN
   exempt:=NOT NEW.enabled AND NEW.revoked_at IS NOT NULL AND
    (old_data-ARRAY['enabled','revoked_at','version','updated_at','next_due_at'])=(new_data-ARRAY['enabled','revoked_at','version','updated_at','next_due_at']);
  ELSIF TG_TABLE_NAME='follow_up_drafts' THEN
   exempt:=NEW.state IN ('discarded','cancelled') AND NEW.approved_version IS NULL AND NEW.approved_at IS NULL AND
    (old_data-ARRAY['state','version','approved_version','approved_at','updated_at'])=(new_data-ARRAY['state','version','approved_version','approved_at','updated_at']);
  ELSIF TG_TABLE_NAME='notification_outbox' THEN
   exempt:=NEW.state IN ('cancelled','sent','captured','uncertain') AND NEW.encrypted_payload IS NULL AND
    (old_data-ARRAY['state','encrypted_payload','completed_at'])=(new_data-ARRAY['state','encrypted_payload','completed_at']);
  ELSIF TG_TABLE_NAME='lead_links' THEN
   exempt:=NEW.revoked_at IS NOT NULL AND (old_data-'revoked_at')=(new_data-'revoked_at');
  ELSIF TG_TABLE_NAME='onboarding_requests' THEN
   exempt:=NEW.status='revoked' AND (old_data-ARRAY['status','version','updated_at'])=(new_data-ARRAY['status','version','updated_at']);
  ELSIF TG_TABLE_NAME='media_kits' THEN
   exempt:=NOT NEW.published AND (old_data-ARRAY['published','consented_at','version','updated_at'])=(new_data-ARRAY['published','consented_at','version','updated_at']);
   -- Public view counters do not create business content or expand access.
   exempt:=exempt OR ((old_data-'views')=(new_data-'views'));
  END IF;
 END IF;
 IF NOT exempt THEN RAISE EXCEPTION 'Work is paused. Existing records remain readable and exportable.' USING ERRCODE='P0004'; END IF;
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION app_professional_write_guard() FROM PUBLIC;
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['pipeline_entries','pipeline_activities','brand_contacts','pipeline_contacts','reminders','reminder_events','outreach_templates','onboarding_requests','onboarding_documents','onboarding_submissions','media_kits','lead_links','invoice_drafts','invoice_draft_lines','manual_payments','payment_allocations','payment_allocation_reversals','financial_commands','payment_commands','notification_outbox','follow_up_drafts','business_schedules'] LOOP
  IF to_regclass('public.'||t) IS NOT NULL THEN
   EXECUTE format('CREATE TRIGGER professional_access BEFORE INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION app_professional_write_guard()',t);
   EXECUTE format('CREATE CONSTRAINT TRIGGER professional_commit_access AFTER INSERT OR UPDATE OR DELETE ON %I DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION app_professional_write_guard()',t);
  END IF;
 END LOOP;
END $$;
-- Existing pilot trigger otherwise allows unassigned-development; Professional requires activation.
ALTER FUNCTION app_schedule_basis(business_schedules) RENAME TO app_schedule_basis_before_professional;
CREATE FUNCTION app_schedule_basis(s business_schedules) RETURNS boolean LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT public.app_professional_active(s.org_id) AND public.app_schedule_basis_before_professional(s)
$$;
REVOKE ALL ON FUNCTION app_schedule_basis(business_schedules),app_schedule_basis_before_professional(business_schedules) FROM PUBLIC;

CREATE FUNCTION app_professional_assignment_reset() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM public.organizations WHERE id=NEW.org_id AND experience='professional') THEN
  UPDATE public.business_schedules SET enabled=false,revoked_at=clock_timestamp(),next_due_at=NULL,version=version+1,updated_at=clock_timestamp() WHERE org_id=NEW.org_id AND enabled;
  UPDATE public.schedule_runs SET state='cancelled',completed_at=clock_timestamp() WHERE org_id=NEW.org_id AND state='ready';
  UPDATE public.follow_up_drafts SET state='cancelled',version=version+1,approved_at=NULL,approved_version=NULL,updated_at=clock_timestamp() WHERE org_id=NEW.org_id AND state='approved';
  UPDATE public.notification_outbox SET state='cancelled',encrypted_payload=NULL,completed_at=clock_timestamp() WHERE org_id=NEW.org_id AND state='pending';
 END IF;
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION app_professional_assignment_reset() FROM PUBLIC;
CREATE TRIGGER professional_assignment_reset AFTER INSERT ON pilot_assignments FOR EACH ROW EXECUTE FUNCTION app_professional_assignment_reset();
CREATE FUNCTION app_public_profile_experience(target uuid) RETURNS text LANGUAGE sql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT o.experience FROM public.organizations o JOIN public.media_kits k ON k.org_id=o.id WHERE k.id=target AND k.published AND k.consented_at IS NOT NULL
$$;
REVOKE ALL ON FUNCTION app_public_profile_experience(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_public_profile_experience(uuid) TO weavdash_app;
