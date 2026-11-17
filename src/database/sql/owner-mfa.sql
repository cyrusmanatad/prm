-- Workspace-owner MFA (M1-04). Decisions: D18, D34, D35. Runs after platform-followup.sql.
-- The authenticator itself stays in users.admin_totp_*: one authenticator per account (D34).

-- Single-use recovery codes. Only a SHA-256 digest is stored; the codes carry 50 random bits.
CREATE TABLE account_recovery_codes (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 code_hash char(64) NOT NULL, used_at timestamptz, created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX account_recovery_codes_user ON account_recovery_codes(user_id);
-- Append-only record of authenticator changes. It never holds a secret or a code.
CREATE TABLE account_security_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 event varchar(40) NOT NULL CHECK(event IN ('authenticator.enrolled','authenticator.replaced','authenticator.reset','recovery_code.used','recovery_codes.regenerated')),
 occurred_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX account_security_events_user ON account_security_events(user_id,occurred_at);
-- No runtime policies: both tables are reachable only through the functions below.
ALTER TABLE account_recovery_codes ENABLE ROW LEVEL SECURITY;
ALTER TABLE account_recovery_codes FORCE ROW LEVEL SECURITY;
ALTER TABLE account_security_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE account_security_events FORCE ROW LEVEL SECURITY;

CREATE FUNCTION app_recovery_digest(code text) RETURNS char(64) LANGUAGE sql IMMUTABLE SET search_path=pg_catalog AS $$
 SELECT encode(sha256(convert_to(upper(regexp_replace(coalesce(code,''),'[^A-Za-z0-9]','','g')),'UTF8')),'hex')
$$;

-- An account must use an authenticator while it owns a workspace, and keeps using one once enrolled (D35).
CREATE FUNCTION app_account_requires_mfa(target bigint) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.memberships m WHERE m.user_id=target AND m.role='owner' AND m.revoked_at IS NULL)
  OR EXISTS(SELECT 1 FROM public.users u WHERE u.id=target AND u.admin_totp_confirmed_at IS NOT NULL)
$$;

-- The caller's own state. Accounts with an active platform grant have no usable recovery codes (D31, D35).
CREATE FUNCTION app_account_mfa_state() RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT jsonb_build_object(
  'enrolled',u.admin_totp_confirmed_at IS NOT NULL,
  'owner',EXISTS(SELECT 1 FROM public.memberships m WHERE m.user_id=u.id AND m.role='owner' AND m.revoked_at IS NULL),
  'operator',EXISTS(SELECT 1 FROM public.platform_grants g WHERE g.user_id=u.id AND g.revoked_at IS NULL),
  'recovery_remaining',CASE WHEN EXISTS(SELECT 1 FROM public.platform_grants g WHERE g.user_id=u.id AND g.revoked_at IS NULL) THEN 0
   ELSE (SELECT count(*) FROM public.account_recovery_codes c WHERE c.user_id=u.id AND c.used_at IS NULL) END)
 FROM public.users u WHERE u.id=public.app_actor()
$$;

CREATE FUNCTION app_account_security_event(event_name text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF public.app_actor() IS NULL THEN RAISE EXCEPTION 'Account required' USING ERRCODE='42501'; END IF;
 INSERT INTO public.account_security_events(user_id,event) VALUES(public.app_actor(),event_name);
END $$;

-- Replaces every recovery code of the caller. An empty set only clears them.
CREATE FUNCTION app_account_set_recovery_codes(codes text[]) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF public.app_actor() IS NULL OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=public.app_actor() AND admin_totp_confirmed_at IS NOT NULL) THEN
  RAISE EXCEPTION 'Enrolled account required' USING ERRCODE='42501'; END IF;
 IF cardinality(codes)>0 AND EXISTS(SELECT 1 FROM public.platform_grants WHERE user_id=public.app_actor() AND revoked_at IS NULL) THEN
  RAISE EXCEPTION 'Platform operators recover offline' USING ERRCODE='42501'; END IF;
 IF cardinality(codes)>20 OR EXISTS(SELECT 1 FROM unnest(codes) c WHERE c IS NULL OR length(regexp_replace(c,'[^A-Za-z0-9]','','g'))<10) THEN
  RAISE EXCEPTION 'Invalid recovery codes' USING ERRCODE='22000'; END IF;
 DELETE FROM public.account_recovery_codes WHERE user_id=public.app_actor();
 INSERT INTO public.account_recovery_codes(user_id,code_hash) SELECT public.app_actor(),public.app_recovery_digest(c) FROM unnest(codes) c;
END $$;

-- Consumes one unused code of the caller. Concurrent attempts with the same code serialize on the row.
CREATE FUNCTION app_account_use_recovery_code(code text) RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE matched bigint;
BEGIN
 IF public.app_actor() IS NULL OR EXISTS(SELECT 1 FROM public.platform_grants WHERE user_id=public.app_actor() AND revoked_at IS NULL)
  OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=public.app_actor() AND admin_totp_confirmed_at IS NOT NULL) THEN RETURN false; END IF;
 SELECT c.id INTO matched FROM public.account_recovery_codes c
  WHERE c.user_id=public.app_actor() AND c.used_at IS NULL AND c.code_hash=public.app_recovery_digest(code) FOR UPDATE;
 IF matched IS NULL THEN RETURN false; END IF;
 UPDATE public.account_recovery_codes SET used_at=clock_timestamp() WHERE id=matched AND used_at IS NULL;
 IF NOT FOUND THEN RETURN false; END IF;
 INSERT INTO public.account_security_events(user_id,event) VALUES(public.app_actor(),'recovery_code.used');
 RETURN true;
END $$;

-- Operator reset after the written identity check (D35). The account must enroll again at its next sign-in.
-- Accounts holding a platform grant are refused: their only route is the offline reset (D31).
CREATE FUNCTION app_platform_reset_authenticator(target bigint,explanation text) RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE ended integer;
BEGIN
 PERFORM public.app_platform_require('access.manage');
 IF explanation IS NULL OR length(trim(explanation)) NOT BETWEEN 1 AND 240 THEN RAISE EXCEPTION 'Reason required' USING ERRCODE='22000'; END IF;
 PERFORM 1 FROM public.users WHERE id=target FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Account unavailable' USING ERRCODE='P0002'; END IF;
 IF target=public.app_actor() THEN RAISE EXCEPTION 'You cannot reset your own authenticator' USING ERRCODE='22000'; END IF;
 IF EXISTS(SELECT 1 FROM public.platform_grants WHERE user_id=target AND revoked_at IS NULL) THEN
  RAISE EXCEPTION 'Platform operators are recovered offline, not from the console' USING ERRCODE='22000'; END IF;
 IF NOT EXISTS(SELECT 1 FROM public.users WHERE id=target AND admin_totp_confirmed_at IS NOT NULL) THEN
  RAISE EXCEPTION 'This account has no authenticator to reset' USING ERRCODE='22000'; END IF;
 UPDATE public.users SET admin_totp_secret=NULL,admin_totp_confirmed_at=NULL,admin_totp_counter=NULL WHERE id=target;
 DELETE FROM public.account_recovery_codes WHERE user_id=target;
 DELETE FROM public.sessions WHERE user_id=target;
 GET DIAGNOSTICS ended = ROW_COUNT;
 INSERT INTO public.account_security_events(user_id,event) VALUES(target,'authenticator.reset');
 INSERT INTO public.platform_audit(actor_id,event,target,reason) VALUES(public.app_actor(),'account.authenticator_reset',target::text,trim(explanation));
 RETURN ended;
END $$;

REVOKE ALL ON FUNCTION app_recovery_digest(text),app_account_requires_mfa(bigint),app_account_mfa_state(),app_account_security_event(text),
 app_account_set_recovery_codes(text[]),app_account_use_recovery_code(text),app_platform_reset_authenticator(bigint,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_account_requires_mfa(bigint),app_account_mfa_state(),app_account_security_event(text),
 app_account_set_recovery_codes(text[]),app_account_use_recovery_code(text),app_platform_reset_authenticator(bigint,text) TO weavdash_app;
