-- Source campaign on lead registrations (D43). Runs after campaign-shared-at.sql.
-- A registration can name one brief shared on its own partnership. The link time records when it was last set;
-- the Brand confirms it by approving an incentive award that cites the registration (D44).
CREATE UNIQUE INDEX campaigns_partnership_key ON campaigns(id, partnership_id);
ALTER TABLE lead_registrations
 ADD COLUMN campaign_id uuid,
 ADD COLUMN campaign_linked_at timestamptz,
 ADD COLUMN campaign_confirmed_at timestamptz,
 ADD CONSTRAINT lead_registration_campaign FOREIGN KEY (campaign_id, partnership_id) REFERENCES campaigns(id, partnership_id),
 ADD CONSTRAINT lead_registration_campaign_time CHECK ((campaign_id IS NULL) = (campaign_linked_at IS NULL)
  AND (campaign_confirmed_at IS NULL OR campaign_id IS NOT NULL));
CREATE INDEX lead_registration_campaign ON lead_registrations(campaign_id, registered_at DESC, id DESC) WHERE campaign_id IS NOT NULL;

-- True when the campaign is a brief already shared on this partnership. Drafts have no partnership.
CREATE FUNCTION app_campaign_on_partnership(campaign uuid, relationship uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
 SELECT campaign IS NULL OR EXISTS(SELECT 1 FROM public.campaigns c WHERE c.id=campaign AND c.partnership_id=relationship)
$$;

DROP FUNCTION app_register_lead(uuid,uuid,uuid,text,text,boolean);
DROP FUNCTION app_update_lead_registration(uuid,integer,text,text,boolean);

CREATE FUNCTION app_register_lead(new_id uuid, relationship uuid, source uuid, label text, note text, with_contact boolean, source_campaign uuid) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE p public.partnerships; e public.pipeline_entries; r public.lead_registrations; moment timestamptz := now();
BEGIN
 IF NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin']) THEN RETURN jsonb_build_object('status','denied'); END IF;
 SELECT * INTO p FROM public.partnerships WHERE id=relationship AND creator_org_id=public.app_org() AND status='active' FOR SHARE;
 IF NOT FOUND OR public.app_workspace_suspended(p.brand_org_id) THEN RETURN jsonb_build_object('status','unavailable'); END IF;
 IF NOT public.app_campaign_on_partnership(source_campaign,p.id) THEN RETURN jsonb_build_object('status','campaign'); END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('pilot:'||public.app_org()::text,0));
 IF NOT public.app_professional_active(public.app_org()) THEN
  RAISE EXCEPTION 'Work is paused. Existing records remain readable and exportable.' USING ERRCODE='P0004';
 END IF;
 SELECT * INTO e FROM public.pipeline_entries WHERE id=source AND org_id=public.app_org() FOR UPDATE;
 IF NOT FOUND THEN RETURN jsonb_build_object('status','missing'); END IF;
 INSERT INTO public.lead_registrations(id,partnership_id,brand_org_id,creator_org_id,pipeline_entry_id,display_name,summary,share_contact,contact_name,contact_email,stage,
   registered_by,registered_at,shared_updated_at,campaign_id,campaign_linked_at)
  VALUES(new_id,p.id,p.brand_org_id,p.creator_org_id,e.id,trim(label),coalesce(note,''),coalesce(with_contact,false),
   CASE WHEN with_contact THEN e.contact_name END,CASE WHEN with_contact THEN e.contact_email END,e.stage,public.app_actor(),moment,moment,
   source_campaign,CASE WHEN source_campaign IS NOT NULL THEN moment END)
  ON CONFLICT (partnership_id,pipeline_entry_id) WHERE pipeline_entry_id IS NOT NULL DO NOTHING;
 IF FOUND THEN
  INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),public.app_org(),public.app_actor(),'lead_registration.registered',new_id);
  IF source_campaign IS NOT NULL THEN
   INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),public.app_org(),public.app_actor(),'lead_registration.campaign_set',new_id);
  END IF;
  RETURN jsonb_build_object('status','created','id',new_id);
 END IF;
 SELECT * INTO r FROM public.lead_registrations WHERE partnership_id=p.id AND pipeline_entry_id=e.id FOR UPDATE;
 -- A repeated request is a no-op: nothing is duplicated and the first registration time and link are kept.
 IF r.state='active' THEN RETURN jsonb_build_object('status','existing','id',r.id); END IF;
 -- An explicit re-share keeps the first registration time. The source campaign follows the request.
 UPDATE public.lead_registrations SET state='active',withdrawn_at=NULL,withdrawn_reason=NULL,display_name=trim(label),summary=coalesce(note,''),
  share_contact=coalesce(with_contact,false),contact_name=CASE WHEN with_contact THEN e.contact_name END,contact_email=CASE WHEN with_contact THEN e.contact_email END,
  stage=e.stage,shared_updated_at=moment,last_event='updated',version=version+1,
  campaign_id=source_campaign,
  campaign_linked_at=CASE WHEN source_campaign IS NULL THEN NULL WHEN source_campaign IS NOT DISTINCT FROM r.campaign_id THEN r.campaign_linked_at ELSE moment END,
  campaign_confirmed_at=CASE WHEN source_campaign IS NOT DISTINCT FROM r.campaign_id THEN r.campaign_confirmed_at END
 WHERE id=r.id;
 INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),public.app_org(),public.app_actor(),'lead_registration.reshared',r.id);
 IF source_campaign IS DISTINCT FROM r.campaign_id THEN
  INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),public.app_org(),public.app_actor(),
   CASE WHEN source_campaign IS NULL THEN 'lead_registration.campaign_cleared' ELSE 'lead_registration.campaign_set' END,r.id);
 END IF;
 RETURN jsonb_build_object('status','reshared','id',r.id);
END $$;

CREATE FUNCTION app_update_lead_registration(target uuid, expected integer, label text, note text, with_contact boolean, source_campaign uuid) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE relationship uuid; e public.pipeline_entries; r public.lead_registrations; name_now text; email_now text; changed boolean; relinked boolean;
BEGIN
 IF NOT public.app_has_membership(public.app_org(),ARRAY['owner','admin']) THEN RETURN 'denied'; END IF;
 SELECT partnership_id INTO relationship FROM public.lead_registrations WHERE id=target AND creator_org_id=public.app_org();
 IF NOT FOUND THEN RETURN 'missing'; END IF;
 PERFORM 1 FROM public.partnerships WHERE id=relationship AND creator_org_id=public.app_org() AND status='active' FOR SHARE;
 IF NOT FOUND THEN RETURN 'missing'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('pilot:'||public.app_org()::text,0));
 IF NOT public.app_professional_active(public.app_org()) THEN
  RAISE EXCEPTION 'Work is paused. Existing records remain readable and exportable.' USING ERRCODE='P0004';
 END IF;
 SELECT * INTO r FROM public.lead_registrations WHERE id=target AND creator_org_id=public.app_org() FOR UPDATE;
 IF NOT FOUND OR r.state<>'active' THEN RETURN 'missing'; END IF;
 IF r.version<>expected THEN RETURN 'stale'; END IF;
 IF NOT public.app_campaign_on_partnership(source_campaign,relationship) THEN RETURN 'campaign'; END IF;
 SELECT * INTO e FROM public.pipeline_entries WHERE id=r.pipeline_entry_id AND org_id=public.app_org();
 name_now := CASE WHEN with_contact THEN e.contact_name END;
 email_now := CASE WHEN with_contact THEN e.contact_email END;
 changed := r.display_name<>trim(label) OR r.summary<>coalesce(note,'') OR r.share_contact<>coalesce(with_contact,false)
  OR r.contact_name IS DISTINCT FROM name_now OR r.contact_email IS DISTINCT FROM email_now;
 relinked := source_campaign IS DISTINCT FROM r.campaign_id;
 IF NOT changed AND NOT relinked THEN RETURN 'ok'; END IF;
 UPDATE public.lead_registrations SET display_name=trim(label),summary=coalesce(note,''),share_contact=coalesce(with_contact,false),
  contact_name=name_now,contact_email=email_now,shared_updated_at=now(),last_event='updated',version=version+1,
  campaign_id=source_campaign,
  campaign_linked_at=CASE WHEN NOT relinked THEN r.campaign_linked_at WHEN source_campaign IS NOT NULL THEN now() END,
  campaign_confirmed_at=CASE WHEN NOT relinked THEN r.campaign_confirmed_at END
 WHERE id=target;
 IF changed THEN
  INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),public.app_org(),public.app_actor(),'lead_registration.updated',target);
 END IF;
 IF relinked THEN
  INSERT INTO public.audit_logs(id,org_id,actor_id,event,subject_id) VALUES(gen_random_uuid(),public.app_org(),public.app_actor(),
   CASE WHEN source_campaign IS NULL THEN 'lead_registration.campaign_cleared' ELSE 'lead_registration.campaign_set' END,target);
 END IF;
 RETURN 'ok';
END $$;

REVOKE ALL ON FUNCTION app_campaign_on_partnership(uuid,uuid),app_register_lead(uuid,uuid,uuid,text,text,boolean,uuid),
 app_update_lead_registration(uuid,integer,text,text,boolean,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_register_lead(uuid,uuid,uuid,text,text,boolean,uuid),app_update_lead_registration(uuid,integer,text,text,boolean,uuid) TO weavdash_app;
