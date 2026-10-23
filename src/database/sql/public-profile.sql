ALTER TABLE media_kits ADD COLUMN social_links jsonb NOT NULL DEFAULT '[]'::jsonb
    CHECK (jsonb_typeof(social_links) = 'array' AND jsonb_array_length(social_links) <= 8);
DROP FUNCTION app_public_kit(uuid);
CREATE FUNCTION app_public_kit(target uuid)
RETURNS TABLE(title varchar,bio text,website varchar,social_links jsonb)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
    UPDATE public.media_kits k SET views=k.views+1
        WHERE k.id=target AND k.published AND k.consented_at IS NOT NULL;
    RETURN QUERY SELECT k.title,k.bio,k.website,
        (SELECT coalesce(jsonb_agg(jsonb_build_object('label', item->>'label', 'url', item->>'url')), '[]'::jsonb)
            FROM jsonb_array_elements(k.social_links) item)
        FROM public.media_kits k WHERE k.id=target AND k.published AND k.consented_at IS NOT NULL;
END $$;
REVOKE ALL ON FUNCTION app_public_kit(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_public_kit(uuid) TO weavdash_app;
