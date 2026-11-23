-- PRIV-05 (P4, D40): report-only reconciliation of managed onboarding uploads against their document records.
-- Nothing here deletes a stored file. A finding becomes eligible for a later, separately authorised deletion only
-- after it was seen in two consistent completed runs and first seen at least seven days earlier.
CREATE TABLE upload_reconciliation_runs (
 id uuid PRIMARY KEY, started_at timestamptz NOT NULL DEFAULT clock_timestamp(), finished_at timestamptz,
 status varchar(12) NOT NULL CHECK(status IN ('completed','failed')),
 listed integer NOT NULL DEFAULT 0 CHECK(listed>=0), referenced integer NOT NULL DEFAULT 0 CHECK(referenced>=0),
 skipped_recent integer NOT NULL DEFAULT 0 CHECK(skipped_recent>=0),
 failure varchar(160)
);
CREATE INDEX upload_reconciliation_runs_recent ON upload_reconciliation_runs(started_at DESC,id);
CREATE TABLE upload_reconciliation_findings (
 object_key varchar(300) PRIMARY KEY,
 kind varchar(16) NOT NULL CHECK(kind IN ('orphaned','missing','held','unrecognised')),
 org_id uuid, request_id uuid, document_id uuid, size bigint CHECK(size IS NULL OR size>=0),
 first_seen_at timestamptz NOT NULL, last_seen_at timestamptz NOT NULL,
 observations integer NOT NULL DEFAULT 1 CHECK(observations>0),
 last_run uuid NOT NULL REFERENCES upload_reconciliation_runs(id)
);
CREATE INDEX upload_reconciliation_findings_kind ON upload_reconciliation_findings(kind,first_seen_at,object_key);
ALTER TABLE upload_reconciliation_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE upload_reconciliation_runs FORCE ROW LEVEL SECURITY;
ALTER TABLE upload_reconciliation_findings ENABLE ROW LEVEL SECURITY;
ALTER TABLE upload_reconciliation_findings FORCE ROW LEVEL SECURITY;
-- No runtime table policies: the runtime role reaches these tables only through the functions below.

-- Every document's key and review state across workspaces, for the scheduled comparison: keys, identifiers, scan
-- states and whether the request can still be edited. No names, checksums or contents.
CREATE FUNCTION app_upload_reconcile_documents() RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT coalesce(jsonb_agg(jsonb_build_object('key',d.object_key,'state',d.scan_state,'editable',r.status IN ('draft','correction'),
  'org',d.org_id,'request',d.request_id,'document',d.id) ORDER BY d.object_key),'[]'::jsonb)
 FROM public.onboarding_documents d JOIN public.onboarding_requests r ON r.org_id=d.org_id AND r.id=d.request_id
$$;

-- Records one run. A failed run (storage or listing unavailable) leaves earlier findings untouched. A completed run
-- counts a finding again only when its kind is unchanged; a changed kind starts again, and a finding absent from a
-- completed run is dropped, so drift resets the count instead of carrying it forward.
CREATE FUNCTION app_upload_reconcile_record(run uuid,outcome text,findings jsonb,listed integer,referenced integer,skipped integer,failure text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE observed timestamptz := clock_timestamp();
BEGIN
 IF outcome NOT IN ('completed','failed') OR jsonb_typeof(coalesce(findings,'[]'::jsonb))<>'array' THEN
  RAISE EXCEPTION 'Invalid reconciliation result' USING ERRCODE='22000';
 END IF;
 INSERT INTO public.upload_reconciliation_runs(id,started_at,finished_at,status,listed,referenced,skipped_recent,failure)
  VALUES(run,observed,observed,outcome,greatest(listed,0),greatest(referenced,0),greatest(skipped,0),left(failure,160));
 IF outcome='failed' THEN RETURN; END IF;
 DROP TABLE IF EXISTS pg_temp.upload_observed;
 CREATE TEMP TABLE upload_observed ON COMMIT DROP AS
  SELECT f->>'key' AS object_key,f->>'kind' AS kind,
   -- A finding with a document record carries that record's identifiers; a stored file alone is read from its key.
   coalesce((f->>'org')::uuid,CASE WHEN f->>'key' ~ '^onboarding/[0-9a-f-]{36}/[0-9a-f-]{36}/[0-9a-f-]{36}$' THEN split_part(f->>'key','/',2)::uuid END) AS org_id,
   coalesce((f->>'request')::uuid,CASE WHEN f->>'key' ~ '^onboarding/[0-9a-f-]{36}/[0-9a-f-]{36}/[0-9a-f-]{36}$' THEN split_part(f->>'key','/',3)::uuid END) AS request_id,
   coalesce((f->>'document')::uuid,CASE WHEN f->>'key' ~ '^onboarding/[0-9a-f-]{36}/[0-9a-f-]{36}/[0-9a-f-]{36}$' THEN split_part(f->>'key','/',4)::uuid END) AS document_id,
   (f->>'size')::bigint AS size
  FROM jsonb_array_elements(findings) f;
 IF EXISTS(SELECT 1 FROM upload_observed WHERE kind IS NULL OR kind NOT IN ('orphaned','missing','held','unrecognised') OR object_key IS NULL OR length(object_key)>300) THEN
  RAISE EXCEPTION 'Invalid reconciliation finding' USING ERRCODE='22000';
 END IF;
 -- An unrecognised key is never attributed to a workspace, even when parts of it look like identifiers.
 UPDATE upload_observed SET org_id=NULL,request_id=NULL,document_id=NULL WHERE kind='unrecognised';
 DELETE FROM public.upload_reconciliation_findings f WHERE NOT EXISTS(SELECT 1 FROM upload_observed o WHERE o.object_key=f.object_key AND o.kind=f.kind);
 INSERT INTO public.upload_reconciliation_findings(object_key,kind,org_id,request_id,document_id,size,first_seen_at,last_seen_at,observations,last_run)
  SELECT object_key,kind,org_id,request_id,document_id,size,observed,observed,1,run FROM upload_observed
 ON CONFLICT(object_key) DO UPDATE SET observations=public.upload_reconciliation_findings.observations+1,last_seen_at=observed,
  size=excluded.size,last_run=run;
END $$;

-- Administration read for cleanup reviewers: the latest run, counts by kind and one page of 25 findings, longest-
-- standing first. `wanted` is a kind, 'eligible' or NULL for all; counts never follow it. Workspace names come from
-- the directory; keys, file names and contents are never included.
CREATE FUNCTION app_upload_reconciliation(wanted text,page_number integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb;
BEGIN
 PERFORM public.app_platform_require('cleanup.review');
 WITH scoped AS (
  SELECT f.*,(f.kind='orphaned' AND f.observations>=2 AND f.first_seen_at<=clock_timestamp()-interval '7 days') AS eligible
  FROM public.upload_reconciliation_findings f
 ), matched AS (
  SELECT * FROM scoped WHERE wanted IS NULL OR (wanted='eligible' AND eligible) OR kind=wanted
 )
 SELECT jsonb_build_object(
  'last_run',(SELECT row_to_json(r) FROM (SELECT id,started_at,status,listed,referenced,skipped_recent,failure FROM public.upload_reconciliation_runs ORDER BY started_at DESC,id LIMIT 1) r),
  'last_completed',(SELECT max(started_at) FROM public.upload_reconciliation_runs WHERE status='completed'),
  'total',(SELECT count(*) FROM matched),
  'counts',(SELECT jsonb_build_object('all',count(*),'orphaned',count(*) FILTER (WHERE kind='orphaned'),'missing',count(*) FILTER (WHERE kind='missing'),
    'held',count(*) FILTER (WHERE kind='held'),'unrecognised',count(*) FILTER (WHERE kind='unrecognised'),'eligible',count(*) FILTER (WHERE eligible)) FROM scoped),
  'findings',coalesce((SELECT jsonb_agg(row_to_json(x)) FROM (
   SELECT m.kind,m.org_id,o.name AS workspace,m.document_id,m.size,m.first_seen_at,m.last_seen_at,m.observations,m.eligible
   FROM matched m LEFT JOIN public.organizations o ON o.id=m.org_id
   ORDER BY m.first_seen_at,m.object_key LIMIT 25 OFFSET greatest(page_number-1,0)*25) x),'[]'::jsonb)) INTO result;
 RETURN result;
END $$;

REVOKE ALL ON FUNCTION app_upload_reconcile_documents(),app_upload_reconcile_record(uuid,text,jsonb,integer,integer,integer,text),app_upload_reconciliation(text,integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_upload_reconcile_documents(),app_upload_reconcile_record(uuid,text,jsonb,integer,integer,integer,text),app_upload_reconciliation(text,integer) TO weavdash_app;
