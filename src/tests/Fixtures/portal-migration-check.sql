DO $$ BEGIN
 IF NOT EXISTS(SELECT 1 FROM organizations WHERE id='11110000-0000-4000-8000-000000000001' AND name='Preserved creator' AND kind='creator') THEN RAISE EXCEPTION 'Creator backfill failed'; END IF;
 IF (SELECT count(*) FROM pipeline_entries WHERE org_id='11110000-0000-4000-8000-000000000001' AND notes='Private retained notes' AND ((stage='repeat-client' AND value_minor=123) OR (stage='lost' AND value_minor=456))) <> 2 THEN RAISE EXCEPTION 'CRM preservation failed'; END IF;
 IF EXISTS(SELECT 1 FROM partnerships) OR EXISTS(SELECT 1 FROM partner_invitations) THEN RAISE EXCEPTION 'Unexpected inferred partnership'; END IF;
END $$;
