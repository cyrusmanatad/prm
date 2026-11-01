-- Public invitation landing resolves only the recipient of a pending, unexpired token; brand data stays private.
CREATE FUNCTION app_partner_invitation_recipient(digest text) RETURNS text LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
    SELECT recipient_email FROM public.partner_invitations WHERE token_hash=digest AND status='pending' AND expires_at>clock_timestamp();
$$;
REVOKE ALL ON FUNCTION app_partner_invitation_recipient(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_partner_invitation_recipient(text) TO weavdash_app;
