CREATE TABLE platform_grants (
 user_id bigint PRIMARY KEY REFERENCES users(id), permissions text[] NOT NULL,
 version integer NOT NULL DEFAULT 1 CHECK(version>0), revoked_at timestamptz,
 CHECK(permissions <@ ARRAY['platform.owner','directory.read','plans.assign']::text[])
);
CREATE TABLE platform_audit (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, actor_id bigint REFERENCES users(id),
 operator varchar(160) NOT NULL DEFAULT 'application', event varchar(80) NOT NULL, target text NOT NULL, reason varchar(240) NOT NULL CHECK(length(trim(reason))>0),
 occurred_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE users ADD COLUMN admin_totp_secret text, ADD COLUMN admin_totp_confirmed_at timestamptz, ADD COLUMN admin_totp_counter bigint;
ALTER TABLE platform_grants ENABLE ROW LEVEL SECURITY;
ALTER TABLE platform_grants FORCE ROW LEVEL SECURITY;
CREATE POLICY grant_self_read ON platform_grants FOR SELECT USING(user_id=app_actor());
ALTER TABLE platform_audit ENABLE ROW LEVEL SECURITY;
ALTER TABLE platform_audit FORCE ROW LEVEL SECURITY;
-- No runtime mutation policies on either table. Only reviewed functions append audit.
CREATE FUNCTION app_platform_allowed(permission text) RETURNS boolean LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.platform_grants g JOIN public.users u ON u.id=g.user_id
 WHERE g.user_id=public.app_actor() AND g.revoked_at IS NULL AND u.email_verified_at IS NOT NULL
 AND u.admin_totp_confirmed_at IS NOT NULL
 AND g.version::text=nullif(current_setting('app.admin_version',true),'')
 AND (permission=ANY(g.permissions) OR 'platform.owner'=ANY(g.permissions)))
$$;
CREATE FUNCTION app_platform_audit(event_name text,target_id text,explanation text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM public.platform_grants WHERE user_id=public.app_actor() AND revoked_at IS NULL) THEN RAISE EXCEPTION 'Platform permission required' USING ERRCODE='42501'; END IF;
 INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),event_name,target_id,explanation);
END $$;
-- Metadata only; private tenant tables never appear in this projection.
CREATE FUNCTION app_platform_directory(term text,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb;
BEGIN
 IF NOT public.app_platform_allowed('directory.read') THEN RAISE EXCEPTION 'Platform permission required' USING ERRCODE='42501'; END IF;
 SELECT jsonb_build_object('workspaces',coalesce((SELECT jsonb_agg(row_to_json(w)) FROM (
  SELECT o.id,o.name,o.kind,o.experience,
   (SELECT max(version) FROM public.pilot_assignments a WHERE a.org_id=o.id) AS assignment_version,
   (SELECT count(*) FROM public.memberships m WHERE m.org_id=o.id AND m.revoked_at IS NULL) AS members
  FROM public.organizations o WHERE o.name ILIKE '%'||left(term,120)||'%' ORDER BY o.name,o.id LIMIT 25 OFFSET greatest(page_number-1,0)*25
 ) w),'[]'::jsonb), 'accounts',coalesce((SELECT jsonb_agg(row_to_json(u)) FROM (
  SELECT id,name,email,email_verified_at FROM public.users WHERE name ILIKE '%'||left(term,120)||'%' ORDER BY id LIMIT 25 OFFSET greatest(page_number-1,0)*25
 ) u),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;
CREATE FUNCTION app_platform_assign(target uuid,expected integer,plan_name text,limits jsonb,starts timestamptz,ends timestamptz,explanation text,plan_uuid uuid,assignment_uuid uuid) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE current_version integer; plan_version integer; resource text;
BEGIN
 -- Lock the grant to serialize revocation/recovery with privileged requests.
 PERFORM 1 FROM public.platform_grants WHERE user_id=public.app_actor() AND revoked_at IS NULL FOR SHARE;
 IF NOT public.app_platform_allowed('plans.assign') THEN RAISE EXCEPTION 'Platform permission required' USING ERRCODE='42501'; END IF;
 IF explanation IS NULL OR length(trim(explanation)) NOT BETWEEN 1 AND 240 OR plan_name IS NULL OR length(trim(plan_name)) NOT BETWEEN 1 AND 120
  OR starts IS NULL OR ends IS NULL OR ends<=starts OR expected IS NULL OR expected<0 OR jsonb_typeof(limits) IS DISTINCT FROM 'object' THEN
  RAISE EXCEPTION 'Plan, dates, reason and quotas required' USING ERRCODE='22000';
 END IF;
 IF (SELECT count(*) FROM jsonb_object_keys(limits))<>7 THEN RAISE EXCEPTION 'Seven quotas required' USING ERRCODE='22000'; END IF;
 FOREACH resource IN ARRAY ARRAY['pipeline_entries','brand_contacts','reminders','onboarding_requests','outreach_templates','invoice_drafts','manual_payments'] LOOP
  IF NOT limits ? resource OR jsonb_typeof(limits->resource)<>'number' OR (limits->>resource)!~'^(0|[1-9][0-9]{0,8})$' THEN
   RAISE EXCEPTION 'Explicit nonnegative integer quotas required' USING ERRCODE='22000';
  END IF;
 END LOOP;
 PERFORM pg_advisory_xact_lock(hashtextextended('pilot:'||target::text,0));
 IF NOT EXISTS(SELECT 1 FROM public.organizations WHERE id=target) THEN RAISE EXCEPTION 'Workspace unavailable' USING ERRCODE='22000'; END IF;
 SELECT coalesce(max(version),0) INTO current_version FROM public.pilot_assignments WHERE org_id=target;
 IF current_version<>expected THEN RAISE EXCEPTION 'Assignment changed. Reload before saving.' USING ERRCODE='40001'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('plan:'||plan_name,0));
 SELECT coalesce(max(version),0)+1 INTO plan_version FROM public.pilot_plan_versions WHERE name=plan_name;
 INSERT INTO public.pilot_plan_versions(id,name,version,quotas) VALUES(plan_uuid,plan_name,plan_version,limits);
 INSERT INTO public.pilot_assignments(id,org_id,plan_id,version,starts_at,ends_at) VALUES(assignment_uuid,target,plan_uuid,current_version+1,starts,ends);
 INSERT INTO public.pilot_admin_events(id,operator,reason,event,subject_id,org_id) VALUES(assignment_uuid,public.app_actor()::text,explanation,'plan.assigned',assignment_uuid,target);
 INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),'plan.assigned',target::text,explanation);
 RETURN current_version+1;
END $$;
REVOKE ALL ON FUNCTION app_platform_allowed(text),app_platform_audit(text,text,text),app_platform_directory(text,integer),app_platform_assign(uuid,integer,text,jsonb,timestamptz,timestamptz,text,uuid,uuid) FROM PUBLIC;
GRANT SELECT ON platform_grants TO weavdash_app;
GRANT EXECUTE ON FUNCTION app_platform_allowed(text),app_platform_audit(text,text,text),app_platform_directory(text,integer),app_platform_assign(uuid,integer,text,jsonb,timestamptz,timestamptz,text,uuid,uuid) TO weavdash_app;
-- SELECT FOR SHARE needs UPDATE policies on an RLS table. Keep runtime mutation
-- forbidden and lock only the authenticated actor's grant through this function.
CREATE FUNCTION app_lock_platform_grant() RETURNS TABLE(user_id bigint,permissions text[],version integer)
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT g.user_id,g.permissions,g.version FROM public.platform_grants g JOIN public.users u ON u.id=g.user_id
 WHERE g.user_id=public.app_actor() AND g.revoked_at IS NULL AND u.email_verified_at IS NOT NULL FOR SHARE OF g
$$;
REVOKE ALL ON FUNCTION app_lock_platform_grant() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_lock_platform_grant() TO weavdash_app;
CREATE FUNCTION app_platform_denied(path text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM public.users WHERE id=public.app_actor()) THEN
  INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),'request.denied',left(path,160),'Administrative authorization, verification or validation failed');
 END IF;
END $$;
REVOKE ALL ON FUNCTION app_platform_denied(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_platform_denied(text) TO weavdash_app;
