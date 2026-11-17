-- Restores the access functions to their definitions before suspension existed.
CREATE OR REPLACE FUNCTION app_has_membership(target uuid, allowed_roles text[] DEFAULT NULL)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
    SELECT EXISTS (
        SELECT 1 FROM public.memberships m
        WHERE m.org_id = target AND m.user_id = public.app_actor()
          AND m.revoked_at IS NULL
          AND (allowed_roles IS NULL OR m.role = ANY(allowed_roles))
    )
$$;
CREATE OR REPLACE FUNCTION app_lock_membership(target uuid)
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
CREATE OR REPLACE FUNCTION app_platform_allowed(permission text) RETURNS boolean LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT EXISTS(SELECT 1 FROM public.platform_grants g JOIN public.users u ON u.id=g.user_id
 WHERE g.user_id=public.app_actor() AND g.revoked_at IS NULL AND u.email_verified_at IS NOT NULL
 AND u.admin_totp_confirmed_at IS NOT NULL
 AND g.version::text=nullif(current_setting('app.admin_version',true),'')
 AND (permission=ANY(g.permissions) OR 'platform.owner'=ANY(g.permissions)))
$$;
CREATE OR REPLACE FUNCTION app_lock_platform_grant() RETURNS TABLE(user_id bigint,permissions text[],version integer)
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT g.user_id,g.permissions,g.version FROM public.platform_grants g JOIN public.users u ON u.id=g.user_id
 WHERE g.user_id=public.app_actor() AND g.revoked_at IS NULL AND u.email_verified_at IS NOT NULL FOR SHARE OF g
$$;
DROP FUNCTION app_cleanup_run(uuid),app_cleanup_batch(uuid),app_cleanup_batches(),app_cleanup_dry_run(uuid,uuid,integer),app_cleanup_mark(uuid,integer,text,text,text,boolean),app_cleanup_batch_create(uuid,text,text),
 app_support_records(uuid,text,integer),app_workspace_support_decide(uuid,integer,text),app_workspace_support_requests(),app_platform_support_end(uuid,integer,text),app_platform_support_grant(uuid),
 app_platform_support_list(jsonb,integer),app_support_request(uuid,uuid,text,text[],integer),app_support_row(support_grants),app_support_state(support_grants),
 app_platform_sign_out(bigint,text),app_platform_reactivate(text,text,integer,text),app_platform_suspend(text,text,integer,text),app_platform_access_impact(text,text),
 app_usable_owner_besides(bigint),app_suspension_version(text,bigint,uuid),app_public_link_suspended(text,text);
DROP TABLE cleanup_runs,fixture_marks,fixture_batches,support_grants;
