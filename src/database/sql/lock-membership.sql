-- Own-actor, current-workspace lock only. No membership data or mutation is exposed.
-- FOR SHARE blocks revocation/demotion until the request/job transaction ends.
-- Runtime cannot lock memberships directly because it has no UPDATE RLS policy.
CREATE FUNCTION app_lock_membership(target uuid)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
BEGIN
    IF target IS DISTINCT FROM public.app_org() OR public.app_actor() IS NULL THEN
        RETURN false;
    END IF;
    PERFORM 1 FROM public.memberships
        WHERE org_id = target AND user_id = public.app_actor() AND revoked_at IS NULL
        FOR SHARE;
    RETURN FOUND;
END
$$;
REVOKE ALL ON FUNCTION app_lock_membership(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_lock_membership(uuid) TO weavdash_app;
