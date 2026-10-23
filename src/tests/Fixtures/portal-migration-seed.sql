-- Disposable fresh-setup database only; run as owner before M3A rollback/reapply.
INSERT INTO users(id,name,email,email_verified_at,password) VALUES(90000001,'Migration fixture','migration-fixture@example.test',now(),'not-a-login-hash');
INSERT INTO organizations(id,name) VALUES('11110000-0000-4000-8000-000000000001','Preserved creator');
INSERT INTO memberships(org_id,user_id,role) VALUES('11110000-0000-4000-8000-000000000001',90000001,'owner');
INSERT INTO pipeline_entries(id,org_id,brand_name,notes,stage,currency,value_minor) VALUES
 ('11110000-0000-4000-8000-000000000002','11110000-0000-4000-8000-000000000001','Preserved repeat','Private retained notes','repeat-client','PHP',123),
 ('11110000-0000-4000-8000-000000000003','11110000-0000-4000-8000-000000000001','Preserved lost','Private retained notes','lost','PHP',456);
