DROP FUNCTION app_join_partner(text,uuid),app_join_link_brand(text),app_disable_join_link(uuid),app_issue_join_link(uuid,text,text,uuid);
DELETE FROM partnerships WHERE join_link_id IS NOT NULL;
ALTER TABLE partnerships DROP CONSTRAINT partnership_single_origin;
ALTER TABLE partnerships DROP COLUMN join_link_id;
ALTER TABLE partnerships ALTER COLUMN invitation_id SET NOT NULL;
DROP TABLE partner_join_links;
