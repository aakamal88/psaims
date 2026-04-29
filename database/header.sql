-- =====================================================
-- RENAME ROLE: INFRA → IM, TECH → TM (FIXED)
-- Menghindari FK constraint dengan: insert baru → migrate users → delete lama
-- =====================================================

ROLLBACK;

-- =====================================================
-- STEP 1: Insert role baru DULU (IM dan TM)
-- Keep INFRA dan TECH tetap ada untuk sementara
-- =====================================================
INSERT INTO psaims_roles (role_code, role_name, description) VALUES
('IM', 'Infrastructure Management',
 'Asset reliability & integrity, inspeksi, RBI, pemeliharaan, mechanical integrity'),
('TM', 'Technical Management',
 'Process Safety Engineering, HAZOP, MOC, PSI, PSSR, project engineering')
ON CONFLICT (role_code) DO NOTHING;

-- =====================================================
-- STEP 2: Pindahkan semua mapping dari role lama ke role baru
-- element_role_mapping punya FK ke psaims_roles juga, jadi update role_id-nya
-- =====================================================
UPDATE element_role_mapping
SET role_id = (SELECT id FROM psaims_roles WHERE role_code = 'IM')
WHERE role_id = (SELECT id FROM psaims_roles WHERE role_code = 'INFRA');

UPDATE element_role_mapping
SET role_id = (SELECT id FROM psaims_roles WHERE role_code = 'TM')
WHERE role_id = (SELECT id FROM psaims_roles WHERE role_code = 'TECH');

-- =====================================================
-- STEP 3: Pindahkan user yang pakai role lama
-- =====================================================
UPDATE users SET psaims_role_code = 'IM' WHERE psaims_role_code = 'INFRA';
UPDATE users SET psaims_role_code = 'TM' WHERE psaims_role_code = 'TECH';

-- =====================================================
-- STEP 4: Rename username demo (biar konsisten)
-- =====================================================
UPDATE users SET username = 'user_im',
                 full_name = 'User Infrastructure Mgmt'
WHERE username = 'user_infra';

UPDATE users SET username = 'user_tm',
                 full_name = 'User Technical Management'
WHERE username = 'user_tech';

-- =====================================================
-- STEP 5: Sekarang aman hapus role lama (sudah tidak ada yang reference)
-- =====================================================
DELETE FROM psaims_roles WHERE role_code IN ('INFRA', 'TECH');

-- =====================================================
-- VERIFIKASI
-- =====================================================
SELECT 'ROLES (final):' AS info;
SELECT id, role_code, role_name FROM psaims_roles ORDER BY id;

SELECT 'USERS:' AS info;
SELECT id, username, full_name, role, psaims_role_code
FROM users ORDER BY id;

SELECT 'MAPPING COUNT per ROLE:' AS info;
SELECT r.role_code, COUNT(*) AS jumlah_assignment
FROM element_role_mapping m
JOIN psaims_roles r ON r.id = m.role_id
GROUP BY r.role_code
ORDER BY r.role_code;

-- Pastikan tidak ada user dengan role yang sudah dihapus
SELECT 'ORPHAN USERS (harus kosong):' AS info;
SELECT id, username, psaims_role_code FROM users
WHERE psaims_role_code IS NOT NULL
  AND psaims_role_code NOT IN (SELECT role_code FROM psaims_roles);