-- Migrazione 14: import 10 promoter dal DB Notion "Contatti" (stato=verificato, tipologia=Promoter,
-- ordinati per data di ultima modifica più recente).
-- Password: hash placeholder condiviso (redatto qui: era finito per errore in un repo git
-- pubblico il 2026-07-04, va considerato compromesso). Ciascun promoter dovrà usare
-- "Password dimenticata" con la propria email per impostarne una propria — nel frattempo
-- consigliato forzare il reset per questi 10 account lato admin.
-- Stato "active": sono già verificati su Notion, quindi vedono subito i cachet.

INSERT INTO users (email, password_hash, role, display_name, status, email_verified) VALUES
('enri.evangelista@libero.it', '<HASH_PLACEHOLDER_ESPOSTO_RUOTARE>', 'promoter', 'Associazione Comitato S. Mercurio', 'active', 1),
('ninnitomasiello@tiscali.it', '<HASH_PLACEHOLDER_ESPOSTO_RUOTARE>', 'promoter', 'Numero Uno Spettacoli', 'active', 1),
('eventi@mercenari.it', '<HASH_PLACEHOLDER_ESPOSTO_RUOTARE>', 'promoter', 'Associazione Aranceri Mercenari', 'active', 1),
('patri.lactobacillus@gmail.com', '<HASH_PLACEHOLDER_ESPOSTO_RUOTARE>', 'promoter', 'Mojo', 'active', 1),
('denyaddari@libero.it', '<HASH_PLACEHOLDER_ESPOSTO_RUOTARE>', 'promoter', 'Vari Festival', 'active', 1),
('eventi.prolococolleferro@gmail.com', '<HASH_PLACEHOLDER_ESPOSTO_RUOTARE>', 'promoter', 'Pro Loco Città di Colleferro APS', 'active', 1),
('valentino.novelli@virgilio.it', '<HASH_PLACEHOLDER_ESPOSTO_RUOTARE>', 'promoter', 'Druso', 'active', 1),
('info@asdcassina.eu', '<HASH_PLACEHOLDER_ESPOSTO_RUOTARE>', 'promoter', 'ASD Cassina Biffi', 'active', 1),
('gianmarcotrotta@tiscali.it', '<HASH_PLACEHOLDER_ESPOSTO_RUOTARE>', 'promoter', 'Gianmarco Trotta', 'active', 1),
('presidente@altrovecantiereculturale.org', '<HASH_PLACEHOLDER_ESPOSTO_RUOTARE>', 'promoter', 'ALTROVE APS', 'active', 1);

INSERT INTO promoter_profiles (user_id, org_name, tipo, phone, comune, provincia, lat, lng, website) VALUES
((SELECT id FROM users WHERE email='enri.evangelista@libero.it'), 'Associazione Comitato S. Mercurio', 'associazione', '', 'Toro', 'CB', 41.572428, 14.764932, NULL),
((SELECT id FROM users WHERE email='ninnitomasiello@tiscali.it'), 'Numero Uno Spettacoli', 'agenzia', '+393485232230', 'Quartu Sant''Elena', 'CA', 39.239973, 9.188097, NULL),
((SELECT id FROM users WHERE email='eventi@mercenari.it'), 'Associazione Aranceri Mercenari', 'festival', '+393403850740', 'Ivrea', 'TO', 45.451803, 7.893250, 'https://www.mercenari.it'),
((SELECT id FROM users WHERE email='patri.lactobacillus@gmail.com'), 'Mojo', 'locale', '+393498125410', 'Peschiera del Garda', 'VR', 45.439158, 10.680396, NULL),
((SELECT id FROM users WHERE email='denyaddari@libero.it'), 'Vari Festival', 'festival', '3486456430', 'Oristano', 'OR', 39.905950, 8.591611, NULL),
((SELECT id FROM users WHERE email='eventi.prolococolleferro@gmail.com'), 'Pro Loco Città di Colleferro APS', 'associazione', '3391457830', 'Colleferro', 'RM', 41.727033, 13.004158, NULL),
((SELECT id FROM users WHERE email='valentino.novelli@virgilio.it'), 'Druso', 'locale', '+393408799518', 'Ranica', 'BG', 45.728454, 9.712490, 'https://drusobg.it/'),
((SELECT id FROM users WHERE email='info@asdcassina.eu'), 'ASD Cassina Biffi', 'associazione', '3396923527', 'Merate', NULL, 45.698114, 9.417298, NULL),
((SELECT id FROM users WHERE email='gianmarcotrotta@tiscali.it'), 'Gianmarco Trotta', 'altro', '3397524632', 'Campobasso', 'CB', 41.559794, 14.660273, NULL),
((SELECT id FROM users WHERE email='presidente@altrovecantiereculturale.org'), 'ALTROVE APS', 'associazione', '+393937841400', 'Vibo Valentia', 'VV', 38.674854, 16.098528, 'https://www.altrovecantiereculturale.org/');
