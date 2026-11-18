-- Approved initial pilot targets. Activation is a separate migration-role operation.
CREATE TABLE mail_budget_policy (
 singleton boolean PRIMARY KEY DEFAULT true CHECK(singleton), enabled boolean NOT NULL DEFAULT false,
 total_limit integer NOT NULL CHECK(total_limit>0), business_limit integer NOT NULL CHECK(business_limit>0),
 account_limit integer NOT NULL CHECK(account_limit>0), CHECK(business_limit+account_limit<=total_limit)
);
INSERT INTO mail_budget_policy(total_limit,business_limit,account_limit) VALUES(250,200,50);
CREATE TABLE mail_budget_attempts (
 id uuid PRIMARY KEY, category text NOT NULL CHECK(category IN ('business','account')),
 recipients integer NOT NULL CHECK(recipients=1), reserved_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 consumed_at timestamptz
);
CREATE INDEX mail_budget_recent ON mail_budget_attempts((coalesce(consumed_at,reserved_at)));
ALTER TABLE mail_budget_policy ENABLE ROW LEVEL SECURITY;
ALTER TABLE mail_budget_policy FORCE ROW LEVEL SECURITY;
ALTER TABLE mail_budget_attempts ENABLE ROW LEVEL SECURITY;
ALTER TABLE mail_budget_attempts FORCE ROW LEVEL SECURITY;

CREATE FUNCTION app_mail_budget_reserve(kind text,recipient_count integer) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE p public.mail_budget_policy; used bigint; category_used bigint; receipt uuid; moment timestamptz;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('mail:daily-budget',0));
 SELECT * INTO STRICT p FROM public.mail_budget_policy WHERE singleton FOR SHARE;
 IF NOT p.enabled THEN RETURN jsonb_build_object('status','disabled'); END IF;
 IF kind NOT IN ('business','account') OR kind IS NULL OR recipient_count IS DISTINCT FROM 1 THEN
  RETURN jsonb_build_object('status','limited');
 END IF;
 moment:=clock_timestamp();
 -- The rolling bound also enforces the UTC-day ceiling and prevents midnight bursts.
 -- Unconsumed reservations and failed/uncertain attempts remain charged conservatively.
 -- A consumed receipt stays charged for 24 hours after transport admission, not merely reservation.
 SELECT coalesce(sum(recipients),0),coalesce(sum(recipients) FILTER(WHERE category=kind),0)
 INTO used,category_used FROM public.mail_budget_attempts WHERE coalesce(consumed_at,reserved_at)>moment-interval '24 hours';
 IF used+recipient_count>p.total_limit OR category_used+recipient_count>
  (CASE WHEN kind='business' THEN p.business_limit ELSE p.account_limit END) THEN
  RETURN jsonb_build_object('status','limited');
 END IF;
 receipt:=gen_random_uuid();
 INSERT INTO public.mail_budget_attempts(id,category,recipients,reserved_at) VALUES(receipt,kind,recipient_count,moment);
 RETURN jsonb_build_object('status','reserved','receipt',receipt);
END $$;

CREATE FUNCTION app_mail_budget_consume(receipt uuid,recipient_count integer) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 UPDATE public.mail_budget_attempts SET consumed_at=clock_timestamp()
 WHERE id=receipt AND recipients=recipient_count AND consumed_at IS NULL
  AND reserved_at>clock_timestamp()-interval '10 minutes';
 RETURN FOUND;
END $$;

CREATE FUNCTION app_mail_budget_snapshot() RETURNS jsonb LANGUAGE sql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT jsonb_build_object('enabled',p.enabled,'total_limit',p.total_limit,'business_limit',p.business_limit,'account_limit',p.account_limit,
  'rolling_total',coalesce(sum(a.recipients),0),
  'rolling_business',coalesce(sum(a.recipients) FILTER(WHERE a.category='business'),0),
  'rolling_account',coalesce(sum(a.recipients) FILTER(WHERE a.category='account'),0),
  'utc_day_total',coalesce(sum(a.recipients) FILTER(WHERE coalesce(a.consumed_at,a.reserved_at)>=(date_trunc('day',clock_timestamp() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC')),0))
 FROM public.mail_budget_policy p LEFT JOIN public.mail_budget_attempts a ON coalesce(a.consumed_at,a.reserved_at)>clock_timestamp()-interval '24 hours'
 WHERE p.singleton GROUP BY p.enabled,p.total_limit,p.business_limit,p.account_limit
$$;

-- Reservation and daily charge commit together before SMTP. A denied charge returns only this
-- just-reserved notice to pending within the same statement, before any transport was called.
CREATE FUNCTION app_account_notification_reserve_daily(target uuid,transport_ready boolean,account_limit integer,global_limit integer,external_transport boolean) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE result jsonb; budget jsonb;
BEGIN
 result:=public.app_account_notification_reserve(target,transport_ready,account_limit,global_limit);
 IF result->>'outcome'<>'reserved' OR NOT coalesce(external_transport,false) THEN RETURN result; END IF;
 budget:=public.app_mail_budget_reserve('account',1);
 IF budget->>'status'='limited' THEN
  RAISE EXCEPTION 'Daily mail budget exhausted' USING ERRCODE='P0005';
 END IF;
 RETURN result||jsonb_build_object('mail_budget_receipt',budget->>'receipt');
EXCEPTION WHEN SQLSTATE 'P0005' THEN
 -- The failed subtransaction already rolled back the unsent state and minute consumption.
 PERFORM public.app_account_notification_defer(target,'global_budget');
 RETURN jsonb_build_object('outcome','deferred','reason','daily_budget');
END $$;

REVOKE ALL ON FUNCTION app_mail_budget_reserve(text,integer),app_mail_budget_consume(uuid,integer),app_mail_budget_snapshot(),
 app_account_notification_reserve_daily(uuid,boolean,integer,integer,boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_mail_budget_reserve(text,integer),app_mail_budget_consume(uuid,integer),app_mail_budget_snapshot(),
 app_account_notification_reserve_daily(uuid,boolean,integer,integer,boolean) TO weavdash_app;
