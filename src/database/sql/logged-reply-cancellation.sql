ALTER TABLE pipeline_activities DROP CONSTRAINT pipeline_activities_kind_check;
ALTER TABLE pipeline_activities ADD CONSTRAINT pipeline_activities_kind_check
 CHECK(kind IN ('created','updated','stage','snoozed','note','email','chat','call','reply'));
ALTER TABLE follow_up_drafts DROP CONSTRAINT follow_up_drafts_state_check;
ALTER TABLE follow_up_drafts ADD CONSTRAINT follow_up_drafts_state_check CHECK(state IN ('draft','approved','discarded','cancelled'));

-- Narrow write-only trigger: an authorized reply cancels all owners' obsolete work
-- without granting the caller access to private drafts, schedules or recipients.
CREATE FUNCTION app_cancel_logged_reply() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF NEW.org_id IS DISTINCT FROM public.app_org() OR NEW.actor_id IS DISTINCT FROM public.app_actor()
    OR NOT public.app_has_membership(NEW.org_id,ARRAY['owner','admin','manager','member']) THEN
  RAISE EXCEPTION 'Reply requires current editing membership' USING ERRCODE='42501';
 END IF;
 -- HTTP takes this lock before the lead lock, matching preparation and handoff.
 PERFORM pg_advisory_xact_lock(hashtextextended('pilot:' || NEW.org_id::text,0));
 UPDATE public.pipeline_entries SET next_action_due=NULL,updated_at=now()
  WHERE org_id=NEW.org_id AND id=NEW.pipeline_entry_id;
 UPDATE public.business_schedules SET enabled=false,revoked_at=now(),next_due_at=NULL,version=version+1,updated_at=now()
  WHERE org_id=NEW.org_id AND kind='follow_up_draft' AND subject_id=NEW.pipeline_entry_id AND enabled;
 UPDATE public.schedule_runs r SET state='cancelled',completed_at=now()
  FROM public.business_schedules s
  WHERE r.schedule_id=s.id AND r.org_id=NEW.org_id AND s.org_id=NEW.org_id
   AND s.kind='follow_up_draft' AND s.subject_id=NEW.pipeline_entry_id AND r.state='ready';
 UPDATE public.follow_up_drafts SET state='cancelled',version=version+1,
  approved_version=NULL,approved_at=NULL,updated_at=now()
  WHERE org_id=NEW.org_id AND pipeline_entry_id=NEW.pipeline_entry_id AND state IN ('draft','approved');
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION app_cancel_logged_reply() FROM PUBLIC;
CREATE TRIGGER logged_reply_cancellation AFTER INSERT ON pipeline_activities
 FOR EACH ROW WHEN (NEW.kind='reply') EXECUTE FUNCTION app_cancel_logged_reply();
