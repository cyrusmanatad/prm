-- M6-03 monitoring. Aggregate, content-free signals and alert state for the scheduled monitor.
-- Nothing here returns a workspace, account, recipient, payload or job argument.

-- The worker proves it is consuming the queue by running a heartbeat job the scheduler dispatches.
ALTER TABLE platform_observations DROP CONSTRAINT platform_observations_source_check;
ALTER TABLE platform_observations ADD CONSTRAINT platform_observations_source_check CHECK(source IN ('scheduler','worker'));

CREATE OR REPLACE FUNCTION app_record_observation(origin text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF origin IS NULL OR origin NOT IN ('scheduler','worker') THEN RAISE EXCEPTION 'Unknown observation source' USING ERRCODE='22000'; END IF;
 INSERT INTO public.platform_observations(source,observed_at) VALUES(origin,clock_timestamp())
 ON CONFLICT(source) DO UPDATE SET observed_at=excluded.observed_at;
END $$;

-- One row per check: the current state and when it last changed or notified. No history is kept.
CREATE TABLE monitor_checks (
 name varchar(40) PRIMARY KEY CHECK(name ~ '^[a-z][a-z0-9_]{1,39}$'),
 state varchar(8) NOT NULL DEFAULT 'ok' CHECK(state IN ('ok','firing')),
 value bigint, breached_since timestamptz, notified_at timestamptz,
 changed_at timestamptz NOT NULL DEFAULT clock_timestamp(), evaluated_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE monitor_checks ENABLE ROW LEVEL SECURITY;
ALTER TABLE monitor_checks FORCE ROW LEVEL SECURITY;
-- No policies: the runtime role reaches this table only through app_monitor_record.

-- Cross-tenant counts and ages only. Ages are whole seconds; a missing observation is NULL, never zero.
CREATE FUNCTION app_monitor_snapshot() RETURNS jsonb LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT jsonb_build_object(
  'observed_at',clock_timestamp(),
  'scheduler_age',(SELECT floor(extract(epoch FROM clock_timestamp()-s.observed_at))::bigint FROM public.platform_observations s WHERE s.source='scheduler'),
  'worker_age',(SELECT floor(extract(epoch FROM clock_timestamp()-s.observed_at))::bigint FROM public.platform_observations s WHERE s.source='worker'),
  -- Lag counts only mail that could be sent now: past its backoff, in a workspace whose delivery is active.
  'outbox_lag',(SELECT floor(extract(epoch FROM clock_timestamp()-min(greatest(n.created_at,n.available_at))))::bigint FROM public.notification_outbox n
    WHERE n.state='pending' AND n.available_at<=clock_timestamp() AND public.app_professional_active(n.org_id)),
  'outbox_uncertain',(SELECT count(*) FROM public.notification_outbox n WHERE n.state='uncertain'
    AND NOT EXISTS(SELECT 1 FROM public.notification_resolutions r WHERE r.notification_id=n.id)),
  'transport_deferred',(SELECT count(*) FROM public.notification_outbox WHERE state='pending' AND failure_code IN ('transport_unavailable','queue_unavailable')),
  'budget_deferred',(SELECT count(*) FROM public.notification_outbox WHERE state='pending' AND failure_code IN ('workspace_budget','global_budget')),
  'failed_jobs',(SELECT count(*) FROM public.failed_jobs WHERE failed_at>(clock_timestamp()-interval '1 hour')),
  'schedule_late',(SELECT floor(extract(epoch FROM clock_timestamp()-min(s.next_due_at)))::bigint FROM public.business_schedules s WHERE s.enabled AND s.next_due_at<=clock_timestamp()),
  'uploads_pending',(SELECT count(*) FROM public.onboarding_documents WHERE scan_state='pending'),
  'db_connections',(SELECT count(*) FROM pg_stat_activity WHERE datname=current_database()),
  'db_connection_limit',current_setting('max_connections')::bigint,
  'db_bytes',pg_database_size(current_database()))
$$;

-- Records one evaluation and returns what to announce: 'fire', 'remind', 'recover' or 'none'.
-- A check fires only after it has been breached continuously for hold_seconds.
CREATE FUNCTION app_monitor_record(check_name text,breached boolean,observed bigint,hold_seconds integer,repeat_seconds integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE c public.monitor_checks; moment timestamptz := clock_timestamp(); action text := 'none';
BEGIN
 IF breached IS NULL OR hold_seconds IS NULL OR hold_seconds<0 OR repeat_seconds IS NULL OR repeat_seconds<0 THEN RAISE EXCEPTION 'Invalid monitor evaluation' USING ERRCODE='22000'; END IF;
 INSERT INTO public.monitor_checks(name) VALUES(check_name) ON CONFLICT(name) DO NOTHING;
 SELECT * INTO c FROM public.monitor_checks WHERE name=check_name FOR UPDATE;
 IF breached THEN
  c.breached_since := coalesce(c.breached_since,moment);
  IF c.state='ok' AND moment>=c.breached_since+make_interval(secs=>hold_seconds) THEN
   c.state := 'firing'; c.changed_at := moment; c.notified_at := moment; action := 'fire';
  ELSIF c.state='firing' AND repeat_seconds>0 AND moment>=c.notified_at+make_interval(secs=>repeat_seconds) THEN
   c.notified_at := moment; action := 'remind';
  END IF;
 ELSE
  c.breached_since := NULL;
  IF c.state='firing' THEN c.state := 'ok'; c.changed_at := moment; c.notified_at := NULL; action := 'recover'; END IF;
 END IF;
 UPDATE public.monitor_checks SET state=c.state,value=observed,breached_since=c.breached_since,notified_at=c.notified_at,changed_at=c.changed_at,evaluated_at=moment WHERE name=check_name;
 RETURN action;
END $$;

REVOKE ALL ON FUNCTION app_monitor_snapshot(),app_monitor_record(text,boolean,bigint,integer,integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_monitor_snapshot(),app_monitor_record(text,boolean,bigint,integer,integer) TO weavdash_app;
