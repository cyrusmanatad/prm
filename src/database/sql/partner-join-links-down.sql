DO $$ BEGIN
 IF EXISTS(SELECT 1 FROM partnerships WHERE join_link_id IS NOT NULL) THEN
  RAISE EXCEPTION 'Cannot roll back: link-origin partnerships must be preserved';
 END IF;
END $$;
DROP FUNCTION app_join_partner(text,uuid),app_join_link_brand(text),app_disable_join_link(uuid,uuid),app_issue_join_link(uuid,text,text,uuid,uuid);

ALTER TABLE partnerships DROP CONSTRAINT partnership_single_origin;
ALTER TABLE partnerships DROP COLUMN join_link_id;
ALTER TABLE partnerships ALTER COLUMN invitation_id SET NOT NULL;
DROP TABLE partner_join_links;
