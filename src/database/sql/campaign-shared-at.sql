-- When a brief was shared. Existing shared rows take their creation time; the exact time was not recorded.
ALTER TABLE campaigns ADD COLUMN shared_at timestamptz;
UPDATE campaigns SET shared_at = created_at WHERE partnership_id IS NOT NULL;
ALTER TABLE campaigns ADD CONSTRAINT campaigns_shared_at CHECK ((partnership_id IS NULL) = (shared_at IS NULL));

CREATE OR REPLACE FUNCTION app_save_campaign(target uuid, expected integer, content text, deadline date, recipient uuid)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
BEGIN
 IF NOT public.app_has_membership(public.app_org(), ARRAY['owner','admin']) THEN RETURN 'denied'; END IF;
 IF recipient IS NOT NULL THEN
    PERFORM 1 FROM public.partnerships p JOIN public.organizations o ON o.id=p.creator_org_id
    WHERE p.id=recipient AND p.brand_org_id=public.app_org() AND p.status='active'
      AND o.experience='partner' AND public.app_partnership_sharing(p.id) FOR SHARE OF p;
    IF NOT FOUND THEN RETURN 'unavailable'; END IF;
 END IF;
 UPDATE public.campaigns SET brief=content, due_date=deadline, partnership_id=recipient,
   shared_at=CASE WHEN recipient IS NOT NULL THEN clock_timestamp() END, version=version+1
 WHERE id=target AND org_id=public.app_org() AND version=expected AND partnership_id IS NULL;
 IF FOUND THEN RETURN 'saved'; END IF;
 IF EXISTS (SELECT 1 FROM public.campaigns WHERE id=target AND org_id=public.app_org()) THEN RETURN 'stale'; END IF;
 RETURN 'missing';
END $$;
