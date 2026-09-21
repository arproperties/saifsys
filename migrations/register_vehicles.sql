-- Register 15 vehicles (14 new + 1 existing fixed) from the three sheets (Ain Al Reem, Heroes Zone, Prime Elite).
-- Checked against fleet_vehicles and companies on local (2026-09-21).
-- Company ids from local; confirm they match live before running:
--   SELECT id, name FROM companies WHERE id IN (1, 2, 7);
SET @ain_al_reem = 2;   -- AIN AL REEM PROPERTIES L.L.C
SET @heroes_zone = 1;   -- Heroes Zone Building Cleaning
SET @prime_elite = 7;   -- Prime Elite Transportation LLC

-- Vehicle #1 already exists as plate '21040' (Toyota, under Ain Al Reem).
-- It is the Prime Elite Toyota Rush O 21040: fix it in place so its trip
-- history stays attached, instead of adding a second row.
UPDATE fleet_vehicles
SET plate_no = 'O 21040', name = 'Toyota Rush', vehicle_type = 'car', company_id = @prime_elite,
    notes = 'Driver: Shafi / Gul Alam. VIP: Prime Elite. Salik: OK'
WHERE plate_no = '21040';

-- INSERT IGNORE: a plate that is already registered is skipped, not duplicated.
INSERT IGNORE INTO fleet_vehicles (company_id, plate_no, name, vehicle_type, notes) VALUES
-- Ain Al Reem
(@ain_al_reem, 'M 55137',  'Ford Figo',          'car',    'Driver: Ashraf. VIP: OK. Salik: Ain Al Reem'),
(@ain_al_reem, 'F 9603',   'Nissan Patrol',      'car',    'Driver: Sir Amran. VIP: OK. Salik: Ain Al Reem'),
(@ain_al_reem, 'D 1224',   'Mercedes S 580',     'car',    'Driver: Sir Amran. In garage - engine problem. Salik: Ain Al Reem'),
(@ain_al_reem, 'L 52757',  'Toyota Tundra',      'pickup', 'Driver: Sir Amran. VIP: Police. Salik: Gurinda Akhtar'),
-- Heroes Zone
(@heroes_zone, 'K 18167',  'Toyota Rush',        'car',    'Driver: Ali Raza. VIP: OK. Salik: Cleaning'),
(@heroes_zone, 'D 96733',  'Suzuki Ertiga',      'car',    'Driver: Hani. VIP: OK. Salik: Cleaning'),
(@heroes_zone, 'U 68651',  'Mitsubishi Lancer',  'car',    'Driver: Ajmal. VIP: OK. Salik: Pest Control'),
(@heroes_zone, 'P 80437',  'Suzuki Ertiga',      'car',    'Driver: Shakeel. VIP: OK. Salik: Cleaning'),
-- Prime Elite
(@prime_elite, 'EE 90917', 'Mitsubishi Xpander', 'car',    'Driver: Arslan. VIP: Prime Elite. Salik: OK'),
(@prime_elite, 'BB 19361', 'Mitsubishi Canter',  'truck',  'Driver: Shaid. VIP: Prime Elite. Salik: OK'),
(@prime_elite, 'E 24959',  'Dodge RAM',          'pickup', 'Driver: Mr Amran. VIP: Prime Elite. Salik: OK'),
(@prime_elite, 'W 73451',  'Nissan Micra',       'car',    'Driver: Khan Gulanm. VIP: Prime Elite. Salik: OK'),
(@prime_elite, 'O 21040',  'Toyota Rush',        'car',    'Driver: Shafi / Gul Alam. VIP: Prime Elite. Salik: OK'),
(@prime_elite, 'O 15647',  'Ford Figo',          'car',    'Driver: Mohammad. VIP: Prime Elite. Salik: OK'),
(@prime_elite, 'C 86027',  'Ford Ranger',        'pickup', 'Driver: Saeed. Madar Alwadi');

-- Check
SELECT v.id, c.name AS company, v.plate_no, v.name, v.vehicle_type, v.notes
FROM fleet_vehicles v LEFT JOIN companies c ON c.id = v.company_id
ORDER BY c.name, v.plate_no;
