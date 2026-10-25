-- Synthetic local data only. Stable IDs make loading additive and repeatable.
CREATE TEMP TABLE demo_accounts (slug text, name text, roles jsonb, state text, verified boolean) ON COMMIT DROP;
INSERT INTO demo_accounts VALUES
 ('admin','Alex Administration','["admin"]','active',true),
 ('superadmin','Sam Finance','["super_admin"]','active',true),
 ('organizer','Camille Organisation','["runner"]','active',true),
 ('manager','Morgan Équipe','["runner"]','active',true),
 ('buyer','Charlie Coureur','["runner"]','active',true),
 ('seller','Louise Martin','["runner"]','active',true),
 ('seller2','Thomas Bernard','["runner"]','active',true),
 ('user','Noa Découverte','["runner"]','active',true),
 ('unverified','Jules À vérifier','["runner"]','active',false),
 ('suspended','Robin Suspendu','["runner"]','suspended',true);
INSERT INTO accounts(id,password_hash,roles,lifecycle_state,verified_at,terms_version,terms_accepted_at)
 SELECT md5('demo:account:'||slug)::uuid,__PASSWORD_HASH__,roles,state,CASE WHEN verified THEN CURRENT_TIMESTAMP END,'v1',CURRENT_TIMESTAMP FROM demo_accounts ON CONFLICT DO NOTHING;
INSERT INTO account_private_profiles(account_id,email,email_normalized,display_name,registration_profile)
 SELECT md5('demo:account:'||slug)::uuid,slug||'@example.test',slug||'@example.test',name,jsonb_build_object('first_name',split_part(name,' ',1),'last_name',split_part(name,' ',2)) FROM demo_accounts ON CONFLICT DO NOTHING;
INSERT INTO organizations(id,name,approval_state,approved_by,approved_at) VALUES
 (md5('demo:organization:1')::uuid,'Collectif Horizons — Démo','approved',md5('demo:account:admin')::uuid,CURRENT_TIMESTAMP),
 (md5('demo:organization:2')::uuid,'Club des Sentiers — Démo','pending',NULL,NULL) ON CONFLICT DO NOTHING;
INSERT INTO organization_memberships(id,organization_id,account_id,role,joined_at)
 SELECT md5('demo:membership:'||slug)::uuid,md5('demo:organization:1')::uuid,md5('demo:account:'||slug)::uuid,CASE WHEN slug='organizer' THEN 'owner' ELSE 'manager' END,CURRENT_TIMESTAMP FROM demo_accounts WHERE slug IN ('organizer','manager') ON CONFLICT DO NOTHING;
INSERT INTO organization_memberships(id,organization_id,account_id,role,joined_at) VALUES
 (md5('demo:membership:user')::uuid,md5('demo:organization:2')::uuid,md5('demo:account:user')::uuid,'owner',CURRENT_TIMESTAMP) ON CONFLICT DO NOTHING;
CREATE TEMP TABLE demo_races (n integer,name text,distance integer,city text,participation text,publication text) ON COMMIT DROP;
INSERT INTO demo_races VALUES
 (1,'Les 10 km des Quais — Démo',10000,'Paris','participating','published'),
 (2,'Semi des Lumières — Démo',21097,'Lyon','participating','published'),
 (3,'Marathon de la Côte — Démo',42195,'La Rochelle','participating','published'),
 (4,'Trail des Crêtes — Démo',32000,'Annecy','participating','published'),
 (5,'Les 10 km de l''Erdre — Démo',10000,'Nantes','nonparticipating','published'),
 (6,'Semi de la Garonne — Démo',21098,'Bordeaux','nonparticipating','published'),
 (7,'Marathon des Remparts — Démo',42195,'Saint-Malo','nonparticipating','published'),
 (8,'La Traversée des Pins — Démo',15000,'Fontainebleau','participating','draft');
INSERT INTO races(id,organization_id,name,starts_at,timezone,sport_code,distance_metres,city,country_code,description,official_url,participation,publication_state,resale_deadline,registration_schema_version,registration_schema,created_at,updated_at)
 SELECT md5('demo:race:'||n)::uuid,CASE WHEN participation='participating' THEN md5('demo:organization:1')::uuid END,name,CURRENT_TIMESTAMP+(60+n*7)*INTERVAL '1 day','Europe/Paris','running',distance,city,'FR','Course fictive pour explorer RelaisDossard. Aucun véritable dossard n’est proposé.','https://example.test/course-demo',participation,publication,CURRENT_TIMESTAMP+(58+n*7)*INTERVAL '1 day',1,'{"type":"object","properties":{},"required":[],"additionalProperties":false}',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP FROM demo_races ON CONFLICT DO NOTHING;
INSERT INTO eligibility_imports(id,race_id,actor_id,mode,state,started_at,completed_at,summary)
 SELECT md5('demo:import:'||n)::uuid,md5('demo:race:'||n)::uuid,md5('demo:account:organizer')::uuid,'skip_invalid','completed',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,'{"source":"synthetic-development-fixture"}' FROM demo_races WHERE participation='participating' ON CONFLICT DO NOTHING;
CREATE TEMP TABLE demo_offers(n integer,race integer,seller text,price bigint,state text) ON COMMIT DROP;
INSERT INTO demo_offers VALUES (1,1,'seller',2800,'published'),(2,2,'seller',5500,'published'),(3,3,'seller',9500,'published'),(4,4,'seller',6800,'published'),(5,5,'seller',2400,'published'),(6,6,'seller',4800,'published'),(7,7,'seller',8500,'published'),(8,1,'seller2',3200,'published'),(9,3,'seller2',10500,'draft'),(10,2,'seller2',5900,'withdrawn');
INSERT INTO bib_entitlements(id,race_id,bib_number,source_kind,import_id,original_holder_name,original_runner_email,current_owner_account_id)
 SELECT md5('demo:bib:'||o.n)::uuid,md5('demo:race:'||o.race)::uuid,'DEMO-'||lpad(o.n::text,4,'0'),CASE WHEN r.participation='participating' THEN 'import' ELSE 'document' END,CASE WHEN r.participation='participating' THEN md5('demo:import:'||o.race)::uuid END,a.name,o.seller||'@example.test',md5('demo:account:'||o.seller)::uuid FROM demo_offers o JOIN demo_races r ON r.n=o.race JOIN demo_accounts a ON a.slug=o.seller ON CONFLICT DO NOTHING;
-- Synthetic PDF files are installed in private storage by the loader.
INSERT INTO private_objects(id,object_key,category,mime_type,size_bytes,checksum,uploaded_by,created_at)
 SELECT md5('demo:object:'||o.n)::uuid,(md5('demo:object:'||o.n)::uuid)::text||'.blob','ownership_proof','application/pdf',__PROOF_SIZE__,__PROOF_CHECKSUM__,md5('demo:account:'||o.seller)::uuid,CURRENT_TIMESTAMP FROM demo_offers o JOIN demo_races r ON r.n=o.race WHERE r.participation='nonparticipating' ON CONFLICT DO NOTHING;
INSERT INTO ownership_proofs(id,submitted_by,race_id,proposed_bib_number,entitlement_id,source_kind,private_object_id,submitted_at,decision,reviewed_version,reviewer_id,reviewed_at)
 SELECT md5('demo:proof:'||o.n)::uuid,md5('demo:account:'||o.seller)::uuid,md5('demo:race:'||o.race)::uuid,'DEMO-'||lpad(o.n::text,4,'0'),md5('demo:bib:'||o.n)::uuid,CASE WHEN r.participation='participating' THEN 'import' ELSE 'document' END,CASE WHEN r.participation='nonparticipating' THEN md5('demo:object:'||o.n)::uuid END,CURRENT_TIMESTAMP,CASE WHEN o.state<>'draft' THEN 'approved' END,CASE WHEN o.state<>'draft' THEN 1 END,CASE WHEN o.state<>'draft' THEN md5('demo:account:admin')::uuid END,CASE WHEN o.state<>'draft' THEN CURRENT_TIMESTAMP END FROM demo_offers o JOIN demo_races r ON r.n=o.race ON CONFLICT DO NOTHING;
INSERT INTO resale_listings(id,race_id,entitlement_id,seller_id,proof_id,approved_proof_version,price_cents,state,published_at,withdrawn_at)
 SELECT md5('demo:listing:'||n)::uuid,md5('demo:race:'||race)::uuid,md5('demo:bib:'||n)::uuid,md5('demo:account:'||seller)::uuid,md5('demo:proof:'||n)::uuid,CASE WHEN state<>'draft' THEN 1 END,price,state,CASE WHEN state<>'draft' THEN CURRENT_TIMESTAMP-n*INTERVAL '1 minute' END,CASE WHEN state='withdrawn' THEN CURRENT_TIMESTAMP END FROM demo_offers ON CONFLICT DO NOTHING;
INSERT INTO race_waitlist_memberships(id,race_id,account_id,joined_at)
 SELECT md5('demo:waitlist:'||n)::uuid,md5('demo:race:'||n)::uuid,md5('demo:account:buyer')::uuid,CURRENT_TIMESTAMP-INTERVAL '2 days' FROM demo_races WHERE n IN (2,4) ON CONFLICT DO NOTHING;
INSERT INTO availability_events(id,listing_id,race_id,offer_version,price_cents,currency,available_at,priority_ends_at,recipient_count)
 SELECT md5('demo:event:'||n)::uuid,md5('demo:listing:'||n)::uuid,md5('demo:race:'||race)::uuid,1,price,'EUR',CASE WHEN n=2 THEN CURRENT_TIMESTAMP-INTERVAL '5 minutes' ELSE CURRENT_TIMESTAMP-INTERVAL '2 hours' END,CASE WHEN n=2 THEN CURRENT_TIMESTAMP+INTERVAL '55 minutes' ELSE CURRENT_TIMESTAMP-INTERVAL '1 hour' END,CASE WHEN n=2 THEN 1 ELSE 0 END FROM demo_offers WHERE state='published' ON CONFLICT DO NOTHING;
INSERT INTO availability_recipients(event_id,race_id,account_id,membership_id,snapshotted_at) VALUES
 (md5('demo:event:2')::uuid,md5('demo:race:2')::uuid,md5('demo:account:buyer')::uuid,md5('demo:waitlist:2')::uuid,CURRENT_TIMESTAMP-INTERVAL '5 minutes') ON CONFLICT DO NOTHING;
INSERT INTO cart_items(buyer_id,listing_id,added_at) VALUES (md5('demo:account:buyer')::uuid,md5('demo:listing:1')::uuid,CURRENT_TIMESTAMP) ON CONFLICT DO NOTHING;
INSERT INTO testimonies(id,author_id,content,moderation_state,moderated_by,moderated_at,published_at) VALUES
 (md5('demo:testimony:1')::uuid,md5('demo:account:buyer')::uuid,'Témoignage de démonstration : une nouvelle ligne de départ.','approved',md5('demo:account:admin')::uuid,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (md5('demo:testimony:2')::uuid,md5('demo:account:seller')::uuid,'Exemple fictif en attente de modération.','pending',NULL,NULL,NULL) ON CONFLICT DO NOTHING;
