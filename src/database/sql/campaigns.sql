CREATE TABLE campaigns (
 id uuid PRIMARY KEY,
 org_id uuid NOT NULL REFERENCES organizations(id),
 title varchar(160) NOT NULL CHECK (btrim(title) <> ''),
 brief varchar(8000) NOT NULL DEFAULT '',
 due_date date,
 partnership_id uuid REFERENCES partnerships(id),
 version integer NOT NULL DEFAULT 1 CHECK (version > 0),
 created_at timestamptz NOT NULL DEFAULT now(),
 CHECK (partnership_id IS NULL OR (btrim(brief) <> '' AND due_date IS NOT NULL))
);
CREATE INDEX campaigns_owner ON campaigns(org_id, created_at DESC, id);
CREATE INDEX campaigns_recipient ON campaigns(partnership_id) WHERE partnership_id IS NOT NULL;

ALTER TABLE campaigns ENABLE ROW LEVEL SECURITY;
ALTER TABLE campaigns FORCE ROW LEVEL SECURITY;
CREATE POLICY campaign_read ON campaigns FOR SELECT USING (
 (org_id = app_org() AND app_has_membership(org_id)) OR
 (app_has_membership(app_org()) AND app_partnership_sharing(partnership_id) AND EXISTS (
    SELECT 1 FROM partnerships p WHERE p.id = campaigns.partnership_id
      AND p.brand_org_id = campaigns.org_id AND p.creator_org_id = app_org()
 ))
);
CREATE POLICY campaign_create ON campaigns FOR INSERT WITH CHECK (
 org_id = app_org() AND app_has_membership(org_id, ARRAY['owner','admin'])
 AND partnership_id IS NULL AND version = 1
 AND EXISTS (SELECT 1 FROM organizations WHERE id = app_org() AND kind = 'brand')
);

-- Only eligible Partner workspaces are offered, including when a Brand also has Professional relationships.
CREATE FUNCTION app_campaign_partners() RETURNS TABLE(id uuid, name text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
 SELECT p.id, p.creator_name::text FROM public.partnerships p
 JOIN public.organizations o ON o.id=p.creator_org_id AND o.experience='partner'
 WHERE p.brand_org_id=public.app_org() AND public.app_has_membership(public.app_org(), ARRAY['owner','admin'])
 AND public.app_partnership_sharing(p.id) ORDER BY lower(p.creator_name),p.id
$$;

CREATE FUNCTION app_save_campaign(target uuid, expected integer, content text, deadline date, recipient uuid)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
BEGIN
 IF NOT public.app_has_membership(public.app_org(), ARRAY['owner','admin']) THEN RETURN 'denied'; END IF;
 IF recipient IS NOT NULL THEN
    PERFORM 1 FROM public.partnerships p JOIN public.organizations o ON o.id=p.creator_org_id
    WHERE p.id=recipient AND p.brand_org_id=public.app_org() AND p.status='active'
      AND o.experience='partner' AND public.app_partnership_sharing(p.id) FOR SHARE OF p;
    IF NOT FOUND THEN RETURN 'unavailable'; END IF;
 END IF;
 UPDATE public.campaigns SET brief=content, due_date=deadline, partnership_id=recipient, version=version+1
 WHERE id=target AND org_id=public.app_org() AND version=expected AND partnership_id IS NULL;
 IF FOUND THEN RETURN 'saved'; END IF;
 IF EXISTS (SELECT 1 FROM public.campaigns WHERE id=target AND org_id=public.app_org()) THEN RETURN 'stale'; END IF;
 RETURN 'missing';
END $$;
REVOKE ALL ON FUNCTION app_campaign_partners(),app_save_campaign(uuid,integer,text,date,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_campaign_partners(),app_save_campaign(uuid,integer,text,date,uuid) TO weavdash_app;
