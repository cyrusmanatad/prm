-- Run as the migration owner after migrations. Role names match Compose/CI.
GRANT USAGE ON SCHEMA public TO weavdash_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO weavdash_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO weavdash_app;
GRANT EXECUTE ON FUNCTION app_has_membership(uuid, text[]) TO weavdash_app;
GRANT EXECUTE ON FUNCTION app_create_workspace(uuid, text) TO weavdash_app;
-- The web runtime must not inspect/change migration bookkeeping.
REVOKE ALL ON TABLE migrations FROM weavdash_app;
