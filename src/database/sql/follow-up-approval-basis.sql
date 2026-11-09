CREATE INDEX follow_up_approved_basis ON follow_up_drafts(org_id,actor_id,pipeline_entry_id) WHERE state='approved';

-- Internal predicates and write-only triggers never project another owner's content.
CREATE FUNCTION app_follow_up_basis(d follow_up_drafts) RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.memberships m JOIN public.users u ON u.id=m.user_id
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

-- Expiry has no write event. Reconstruct invalidation after scheduler/queue downtime.
CREATE FUNCTION app_reconcile_follow_up_approvals() RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE candidate record;
BEGIN
 FOR candidate IN SELECT d.id,d.org_id FROM public.follow_up_drafts d
  WHERE d.state='approved' AND NOT public.app_follow_up_basis(d) ORDER BY d.org_id,d.id LIMIT 100 LOOP
  PERFORM pg_advisory_xact_lock(hashtextextended('pilot:'||candidate.org_id::text,0));
  WITH invalidated AS (
   UPDATE public.follow_up_drafts d SET state=CASE WHEN NOT public.app_professional_active(d.org_id) THEN 'cancelled' ELSE 'discarded' END,
    version=version+1,approved_version=NULL,approved_at=NULL,updated_at=clock_timestamp()
   WHERE d.id=candidate.id AND d.state='approved' AND NOT public.app_follow_up_basis(d)
   RETURNING d.id,d.org_id,d.actor_id
  ) INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id)
   SELECT gen_random_uuid(),org_id,actor_id,'follow_up.invalidated',id FROM invalidated;
 END LOOP;
END $$;

CREATE FUNCTION app_invalidate_follow_up_approvals() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE target_org uuid; target_actor bigint; target_lead uuid; target_schedule uuid; target_run uuid; scheduled_only boolean:=false; scope_org uuid; basis record;
BEGIN
 IF TG_OP='DELETE' THEN basis:=OLD; ELSE basis:=NEW; END IF;
 CASE TG_TABLE_NAME
 WHEN 'pipeline_entries' THEN target_org:=basis.org_id; target_lead:=basis.id;
 WHEN 'schedule_runs' THEN target_org:=basis.org_id; target_run:=basis.id;
 WHEN 'business_schedules' THEN target_org:=basis.org_id; target_schedule:=basis.id;
 WHEN 'automation_preferences' THEN target_org:=basis.org_id; target_actor:=basis.actor_id; scheduled_only:=true;
 WHEN 'memberships' THEN target_org:=basis.org_id; target_actor:=basis.user_id;
 WHEN 'users' THEN target_actor:=basis.id;
 WHEN 'pilot_assignments' THEN target_org:=basis.org_id;
 END CASE;
 -- Deferred lead invalidation lets explicit reply cancellation retain its terminal state.
 -- Schedule/run events are also deferred; recorded changes still invalidate after restore.
 -- Membership, identity, preference and entitlement events invalidate immediately.
 FOR scope_org IN SELECT DISTINCT d.org_id FROM public.follow_up_drafts d
  WHERE d.state='approved' AND (target_org IS NULL OR d.org_id=target_org)
   AND (target_actor IS NULL OR d.actor_id=target_actor) ORDER BY d.org_id LOOP
  PERFORM pg_advisory_xact_lock(hashtextextended('pilot:'||scope_org::text,0));
 WITH invalidated AS (
  UPDATE public.follow_up_drafts d SET state=CASE WHEN TG_TABLE_NAME='pilot_assignments' AND EXISTS(SELECT 1 FROM public.organizations o WHERE o.id=d.org_id AND o.experience='professional') THEN 'cancelled' ELSE 'discarded' END,
   version=version+1,approved_version=NULL,approved_at=NULL,updated_at=clock_timestamp()
  WHERE d.state='approved' AND d.org_id=scope_org
   AND (target_actor IS NULL OR d.actor_id=target_actor)
   AND (target_lead IS NULL OR (d.pipeline_entry_id=target_lead AND NOT public.app_follow_up_basis(d)))
   AND (target_run IS NULL OR d.schedule_run_id=target_run)
   AND (NOT scheduled_only OR d.schedule_run_id IS NOT NULL)
   AND (target_schedule IS NULL OR EXISTS(SELECT 1 FROM public.schedule_runs r WHERE r.id=d.schedule_run_id AND r.schedule_id=target_schedule))
  RETURNING d.id,d.org_id,d.actor_id
 ) INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id)
  SELECT gen_random_uuid(),org_id,actor_id,'follow_up.invalidated',id FROM invalidated;
 END LOOP;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER follow_up_lead_basis AFTER UPDATE ON pipeline_entries DEFERRABLE INITIALLY DEFERRED
 FOR EACH ROW WHEN (OLD.version IS DISTINCT FROM NEW.version OR OLD.stage IS DISTINCT FROM NEW.stage)
 EXECUTE FUNCTION app_invalidate_follow_up_approvals();
CREATE CONSTRAINT TRIGGER follow_up_schedule_basis AFTER UPDATE ON business_schedules DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
 WHEN ((OLD.version,OLD.preference_version,OLD.rule_version,OLD.enabled,OLD.revoked_at) IS DISTINCT FROM
       (NEW.version,NEW.preference_version,NEW.rule_version,NEW.enabled,NEW.revoked_at))
 EXECUTE FUNCTION app_invalidate_follow_up_approvals();
CREATE CONSTRAINT TRIGGER follow_up_run_basis AFTER UPDATE ON schedule_runs DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
 WHEN ((OLD.state,OLD.schedule_version,OLD.preference_version,OLD.rule_version) IS DISTINCT FROM
       (NEW.state,NEW.schedule_version,NEW.preference_version,NEW.rule_version))
 EXECUTE FUNCTION app_invalidate_follow_up_approvals();
CREATE TRIGGER follow_up_preference_basis AFTER UPDATE ON automation_preferences FOR EACH ROW
 WHEN (OLD IS DISTINCT FROM NEW) EXECUTE FUNCTION app_invalidate_follow_up_approvals();
CREATE TRIGGER follow_up_membership_basis AFTER UPDATE ON memberships FOR EACH ROW
 WHEN ((OLD.role,OLD.revoked_at) IS DISTINCT FROM (NEW.role,NEW.revoked_at)) EXECUTE FUNCTION app_invalidate_follow_up_approvals();
CREATE TRIGGER follow_up_membership_removed AFTER DELETE ON memberships FOR EACH ROW EXECUTE FUNCTION app_invalidate_follow_up_approvals();
CREATE TRIGGER follow_up_entitlement_removed AFTER DELETE ON pilot_assignments FOR EACH ROW EXECUTE FUNCTION app_invalidate_follow_up_approvals();
CREATE TRIGGER follow_up_identity_basis AFTER UPDATE ON users FOR EACH ROW
 WHEN ((OLD.email,OLD.email_verified_at) IS DISTINCT FROM (NEW.email,NEW.email_verified_at)) EXECUTE FUNCTION app_invalidate_follow_up_approvals();
CREATE TRIGGER zz_follow_up_entitlement_basis AFTER INSERT OR UPDATE ON pilot_assignments FOR EACH ROW
 EXECUTE FUNCTION app_invalidate_follow_up_approvals();

-- Even direct runtime content changes cannot retain approval for different content.
CREATE FUNCTION app_follow_up_content_changed() RETURNS trigger
LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN
 IF OLD.state='approved' AND NEW.state='approved' AND
  (OLD.recipient,OLD.subject,OLD.body,OLD.pipeline_entry_id,OLD.subject_version,OLD.schedule_run_id) IS DISTINCT FROM
  (NEW.recipient,NEW.subject,NEW.body,NEW.pipeline_entry_id,NEW.subject_version,NEW.schedule_run_id) THEN
  NEW.state:='draft'; NEW.approved_version:=NULL; NEW.approved_at:=NULL;
  NEW.version:=greatest(NEW.version,OLD.version+1);
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER follow_up_content_basis BEFORE UPDATE ON follow_up_drafts FOR EACH ROW EXECUTE FUNCTION app_follow_up_content_changed();
REVOKE ALL ON FUNCTION app_follow_up_basis(follow_up_drafts),app_invalidate_follow_up_approvals(),app_follow_up_content_changed(),app_reconcile_follow_up_approvals() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_reconcile_follow_up_approvals() TO weavdash_app;
