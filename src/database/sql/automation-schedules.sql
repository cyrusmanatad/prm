CREATE TABLE automation_preferences (
 org_id uuid NOT NULL REFERENCES organizations(id), actor_id bigint NOT NULL REFERENCES users(id),
 timezone varchar(80) NOT NULL DEFAULT 'UTC', reminders boolean NOT NULL DEFAULT false,
 weekly_digest boolean NOT NULL DEFAULT false, follow_up_drafts boolean NOT NULL DEFAULT false,
 version integer NOT NULL DEFAULT 1 CHECK(version>0), updated_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(org_id,actor_id)
);
CREATE TABLE business_schedules (
 id uuid PRIMARY KEY, org_id uuid NOT NULL, actor_id bigint NOT NULL,
 name varchar(160) NOT NULL CHECK(length(trim(name))>0),
 kind varchar(24) NOT NULL CHECK(kind IN ('reminder','weekly_digest','follow_up_draft')),
 subject_id uuid, subject_version integer,
 rule_version integer NOT NULL DEFAULT 1 CHECK(rule_version=1),
 version integer NOT NULL DEFAULT 1 CHECK(version>0), preference_version integer NOT NULL,
 timezone varchar(80) NOT NULL, weekday smallint CHECK(weekday BETWEEN 1 AND 7), local_time char(5),
 next_due_at timestamptz, enabled boolean NOT NULL DEFAULT true,
 consented_at timestamptz NOT NULL DEFAULT now(), revoked_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(org_id,actor_id) REFERENCES automation_preferences(org_id,actor_id),
 CHECK((kind='weekly_digest' AND subject_id IS NULL AND subject_version IS NULL AND weekday IS NOT NULL AND local_time ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$')
    OR (kind<>'weekly_digest' AND subject_id IS NOT NULL AND subject_version>0 AND weekday IS NULL AND local_time IS NULL)),
 UNIQUE(org_id,actor_id,id)
);
CREATE UNIQUE INDEX business_schedule_digest ON business_schedules(org_id,actor_id) WHERE kind='weekly_digest' AND enabled;
CREATE INDEX business_schedule_due ON business_schedules(next_due_at,id) WHERE enabled AND next_due_at IS NOT NULL;
CREATE TABLE schedule_runs (
 id uuid PRIMARY KEY, org_id uuid NOT NULL, actor_id bigint NOT NULL, schedule_id uuid NOT NULL,
 schedule_version integer NOT NULL, preference_version integer NOT NULL, rule_version integer NOT NULL,
 due_at timestamptz NOT NULL, state varchar(12) NOT NULL DEFAULT 'ready' CHECK(state IN ('ready','completed','cancelled')),
 created_at timestamptz NOT NULL DEFAULT now(), completed_at timestamptz,
 FOREIGN KEY(org_id,actor_id,schedule_id) REFERENCES business_schedules(org_id,actor_id,id),
 UNIQUE(schedule_id,schedule_version,due_at)
);
CREATE INDEX schedule_runs_ready ON schedule_runs(created_at,id) WHERE state='ready';
CREATE TABLE automation_consent_events (
 id uuid PRIMARY KEY, org_id uuid NOT NULL, actor_id bigint NOT NULL, schedule_id uuid,
 version integer NOT NULL CHECK(version>0), kind varchar(24) NOT NULL,
 granted boolean NOT NULL, occurred_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(org_id,actor_id) REFERENCES automation_preferences(org_id,actor_id),
 FOREIGN KEY(org_id,actor_id,schedule_id) REFERENCES business_schedules(org_id,actor_id,id)
);
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['automation_preferences','business_schedules','schedule_runs','automation_consent_events'] LOOP
  EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY',t);
  EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY',t);
  EXECUTE format('CREATE POLICY own_read ON %I FOR SELECT USING(org_id=app_org() AND actor_id=app_actor() AND app_has_membership(org_id))',t);
  EXECUTE format('CREATE POLICY own_insert ON %I FOR INSERT WITH CHECK(org_id=app_org() AND actor_id=app_actor() AND app_has_membership(org_id,ARRAY[''owner'',''admin'',''manager'',''member'']))',t);
  IF t<>'automation_consent_events' THEN
   EXECUTE format('CREATE POLICY own_update ON %I FOR UPDATE USING(org_id=app_org() AND actor_id=app_actor() AND app_has_membership(org_id,ARRAY[''owner'',''admin'',''manager'',''member''])) WITH CHECK(org_id=app_org() AND actor_id=app_actor() AND app_has_membership(org_id,ARRAY[''owner'',''admin'',''manager'',''member'']))',t);
  END IF;
 END LOOP;
END $$;

-- Internal predicate has no runtime EXECUTE grant. No recipient/content projection.
CREATE FUNCTION app_schedule_basis(s business_schedules) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT s.enabled AND s.revoked_at IS NULL AND s.rule_version=1
 AND EXISTS(SELECT 1 FROM public.memberships m JOIN public.users u ON u.id=m.user_id
   WHERE m.org_id=s.org_id AND m.user_id=s.actor_id AND m.revoked_at IS NULL
    AND m.role IN ('owner','admin','manager','member') AND u.email_verified_at IS NOT NULL)
 AND EXISTS(SELECT 1 FROM public.automation_preferences p WHERE p.org_id=s.org_id AND p.actor_id=s.actor_id
   AND p.version=s.preference_version AND p.timezone=s.timezone
   AND CASE s.kind WHEN 'reminder' THEN p.reminders WHEN 'weekly_digest' THEN p.weekly_digest ELSE p.follow_up_drafts END)
 AND NOT EXISTS(SELECT 1 FROM (SELECT * FROM public.pilot_assignments WHERE org_id=s.org_id ORDER BY version DESC LIMIT 1) a
   WHERE a.starts_at>now() OR a.ends_at<=now())
 AND CASE s.kind WHEN 'reminder' THEN EXISTS(SELECT 1 FROM public.reminders r WHERE r.org_id=s.org_id AND r.id=s.subject_id AND r.version=s.subject_version AND r.completed_at IS NULL)
   WHEN 'follow_up_draft' THEN EXISTS(SELECT 1 FROM public.pipeline_entries p WHERE p.org_id=s.org_id AND p.id=s.subject_id AND p.version=s.subject_version AND p.stage<>'lost')
   ELSE true END
$$;
CREATE FUNCTION app_schedule_current(target uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT coalesce((SELECT public.app_schedule_basis(s) FROM public.business_schedules s
  WHERE s.id=target AND s.org_id=public.app_org() AND s.actor_id=public.app_actor()),false)
$$;
CREATE FUNCTION app_reconcile_schedules() RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 UPDATE public.business_schedules s SET enabled=false, revoked_at=clock_timestamp(), updated_at=clock_timestamp()
  WHERE s.enabled AND NOT public.app_schedule_basis(s);
 UPDATE public.schedule_runs r SET state='cancelled',completed_at=clock_timestamp()
  WHERE r.state='ready' AND NOT EXISTS(SELECT 1 FROM public.business_schedules s WHERE s.id=r.schedule_id
    AND s.version=r.schedule_version AND s.preference_version=r.preference_version AND s.rule_version=r.rule_version
    AND public.app_schedule_basis(s));
END $$;
CREATE FUNCTION app_due_schedules() RETURNS TABLE(id uuid,org_id uuid,actor_id bigint)
LANGUAGE sql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT s.id,s.org_id,s.actor_id FROM public.business_schedules s
 WHERE s.enabled AND s.next_due_at<=now() ORDER BY s.next_due_at,s.id LIMIT 100
$$;
REVOKE ALL ON FUNCTION app_schedule_basis(business_schedules),app_schedule_current(uuid),app_reconcile_schedules(),app_due_schedules() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_schedule_current(uuid),app_reconcile_schedules(),app_due_schedules() TO weavdash_app;
GRANT SELECT,INSERT,UPDATE ON automation_preferences,business_schedules,schedule_runs TO weavdash_app;
GRANT SELECT,INSERT ON automation_consent_events TO weavdash_app;
