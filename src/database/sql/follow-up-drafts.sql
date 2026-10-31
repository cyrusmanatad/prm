CREATE TABLE follow_up_drafts (
 id uuid PRIMARY KEY, org_id uuid NOT NULL REFERENCES organizations(id), actor_id bigint NOT NULL REFERENCES users(id),
 pipeline_entry_id uuid NOT NULL, subject_version integer NOT NULL CHECK(subject_version>0),
 schedule_run_id uuid UNIQUE REFERENCES schedule_runs(id),
 recipient varchar(255) NOT NULL DEFAULT '', subject varchar(240) NOT NULL DEFAULT '', body text NOT NULL DEFAULT '' CHECK(length(body)<=10000),
 state varchar(12) NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','approved','discarded')),
 version integer NOT NULL DEFAULT 1 CHECK(version>0), approved_version integer, approved_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(org_id,pipeline_entry_id) REFERENCES pipeline_entries(org_id,id) ON DELETE CASCADE,
 FOREIGN KEY(org_id,actor_id,schedule_run_id) REFERENCES schedule_runs(org_id,actor_id,id),
 CHECK((state='approved' AND approved_version IS NOT NULL AND approved_version=version AND approved_at IS NOT NULL) OR (state<>'approved' AND approved_version IS NULL AND approved_at IS NULL))
);
ALTER TABLE follow_up_drafts ENABLE ROW LEVEL SECURITY;
ALTER TABLE follow_up_drafts FORCE ROW LEVEL SECURITY;
CREATE POLICY draft_read ON follow_up_drafts FOR SELECT USING(org_id=app_org() AND actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY draft_insert ON follow_up_drafts FOR INSERT WITH CHECK(org_id=app_org() AND actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE POLICY draft_update ON follow_up_drafts FOR UPDATE USING(org_id=app_org() AND actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member'])) WITH CHECK(org_id=app_org() AND actor_id=app_actor() AND app_has_membership(org_id,ARRAY['owner','admin','manager','member']));
CREATE INDEX follow_up_inbox ON follow_up_drafts(org_id,actor_id,created_at DESC,id DESC);
CREATE FUNCTION app_ready_follow_up_drafts() RETURNS TABLE(id uuid,org_id uuid,actor_id bigint,schedule_id uuid)
LANGUAGE sql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT r.id,r.org_id,r.actor_id,r.schedule_id FROM public.schedule_runs r JOIN public.business_schedules s ON s.id=r.schedule_id
 WHERE r.state='ready' AND s.kind='follow_up_draft' ORDER BY r.created_at,r.id LIMIT 100
$$;
REVOKE ALL ON FUNCTION app_ready_follow_up_drafts() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_ready_follow_up_drafts() TO weavdash_app;
GRANT SELECT,INSERT,UPDATE ON follow_up_drafts TO weavdash_app;
