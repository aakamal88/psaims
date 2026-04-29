-- =====================================================
-- PSAIMS RASCI v2.1 — FIXED VERSION
-- Perbaikan error 25P02 (transaction aborted)
-- =====================================================

-- PENTING: Jalankan dulu ROLLBACK kalau ada transaksi gagal sebelumnya
ROLLBACK;

-- =====================================================
-- STEP 1: PRE-CHECK — Lihat state saat ini
-- =====================================================
SELECT 'Current roles: ' || COALESCE(STRING_AGG(role_code, ', '), 'none')
FROM psaims_roles;

SELECT 'Current users with role: ' || COUNT(*)
FROM users WHERE psaims_role_code IS NOT NULL;

-- =====================================================
-- STEP 2: MIGRASI ROLE USER DULU (sebelum hapus role lama)
-- Ini penting! Foreign key users.psaims_role_code → psaims_roles.role_code
-- Kalau role lama dihapus dulu, user akan orphan atau error constraint
-- =====================================================

-- Tambahkan role-role baru dulu (jangan hapus yang lama)
INSERT INTO psaims_roles (role_code, role_name, description) VALUES
('HCBS',  'HC & Business Support',
         'SDM, kompetensi, training, sertifikasi, partisipasi pekerja, business support'),
('QHSSE', 'QHSSE (HSE, QM & Performance Evaluation)',
         'HSE, Quality Management, kebijakan, kepatuhan, audit, kinerja, investigasi insiden'),
('INFRA', 'Infrastructure Management',
         'Asset reliability & integrity, inspeksi, RBI, pemeliharaan, mechanical integrity')
ON CONFLICT (role_code) DO NOTHING;

-- OPS dan TECH sudah ada, tidak perlu insert

-- Update deskripsi OPS & TECH (pastikan keterangan up-to-date)
UPDATE psaims_roles
SET role_name = 'Operational',
    description = 'Operasi harian, SOP/prosedur operasi, PTW, ERP, shift handover, eksekusi lapangan'
WHERE role_code = 'OPS';

UPDATE psaims_roles
SET role_name = 'Technical Management',
    description = 'Process Safety Engineering, HAZOP, MOC, PSI, PSSR, project engineering'
WHERE role_code = 'TECH';

-- Migrasi user dengan role lama → role baru
UPDATE users SET psaims_role_code = 'HCBS'  WHERE psaims_role_code = 'HC';
UPDATE users SET psaims_role_code = 'QHSSE' WHERE psaims_role_code IN ('QM', 'HSE');
UPDATE users SET psaims_role_code = 'INFRA' WHERE psaims_role_code = 'ASSET';
UPDATE users SET psaims_role_code = 'TECH'  WHERE psaims_role_code = 'PSE';
-- OPS & TECH tetap

-- Update username user demo lama (hindari error duplicate nanti)
UPDATE users SET username = 'user_hcbs',  full_name = 'User HC & Business Support'
WHERE username = 'user_hc';
UPDATE users SET username = 'user_qhsse', full_name = 'User QHSSE'
WHERE username = 'user_qm';
UPDATE users SET username = 'user_infra', full_name = 'User Infrastructure Mgmt'
WHERE username = 'user_asset';

-- Hapus user merged yang duplikat
DELETE FROM users WHERE username IN ('user_hse', 'user_pse');

-- =====================================================
-- STEP 3: HAPUS MAPPING & ROLE LAMA (sekarang aman)
-- =====================================================
DELETE FROM element_role_mapping;

-- Hapus role lama (HC, QM, HSE, ASSET, PSE) — sekarang tidak ada user yang pakai
DELETE FROM psaims_roles WHERE role_code IN ('HC', 'QM', 'HSE', 'ASSET', 'PSE');

-- =====================================================
-- STEP 4: FIX STRUKTUR TABLE — support multi-level per role
-- =====================================================

-- Constraint A/R/S/C/I
ALTER TABLE element_role_mapping
    DROP CONSTRAINT IF EXISTS element_role_mapping_responsibility_check;
ALTER TABLE element_role_mapping
    ADD CONSTRAINT element_role_mapping_responsibility_check
    CHECK (responsibility IN ('A', 'R', 'S', 'C', 'I'));

-- Drop unique constraint lama (element_id, role_id) kalau ada
DO $$
DECLARE
    constraint_name TEXT;
BEGIN
    SELECT con.conname INTO constraint_name
    FROM pg_constraint con
    JOIN pg_class rel ON rel.oid = con.conrelid
    WHERE rel.relname = 'element_role_mapping'
      AND con.contype = 'u'
      AND array_length(con.conkey, 1) = 2;

    IF constraint_name IS NOT NULL THEN
        EXECUTE 'ALTER TABLE element_role_mapping DROP CONSTRAINT ' || constraint_name;
    END IF;
END $$;

-- Tambah unique constraint baru (element_id, role_id, responsibility) — triple
ALTER TABLE element_role_mapping
    DROP CONSTRAINT IF EXISTS element_role_mapping_unique;
ALTER TABLE element_role_mapping
    ADD CONSTRAINT element_role_mapping_unique
    UNIQUE (element_id, role_id, responsibility);

-- =====================================================
-- STEP 5: INSERT MAPPING RASCI FINAL (5 role × 18 elemen)
-- =====================================================
INSERT INTO element_role_mapping (element_id, role_id, responsibility)
SELECT e.id, r.id, m.resp
FROM (VALUES
    -- E01 Kepemimpinan & Akuntabilitas: QHSSE A/R
    (1,'HCBS','C'),  (1,'QHSSE','A'), (1,'QHSSE','R'),  (1,'OPS','S'),  (1,'INFRA','I'), (1,'TECH','I'),
    -- E02 Kebijakan PSAIMS: QHSSE A/R
    (2,'HCBS','C'),  (2,'QHSSE','A'), (2,'QHSSE','R'),  (2,'OPS','I'),  (2,'INFRA','I'), (2,'TECH','C'),
    -- E03 Kepatuhan Regulasi: QHSSE A/R
    (3,'HCBS','I'),  (3,'QHSSE','A'), (3,'QHSSE','R'),  (3,'OPS','S'),  (3,'INFRA','S'), (3,'TECH','C'),
    -- E04 Organisasi & Kompetensi: HCBS A/R
    (4,'HCBS','A'),  (4,'HCBS','R'),  (4,'QHSSE','C'),  (4,'OPS','S'),  (4,'INFRA','S'), (4,'TECH','S'),
    -- E05 Keselamatan Kontraktor: QHSSE A, OPS+TECH R
    (5,'HCBS','I'),  (5,'QHSSE','A'), (5,'OPS','R'),    (5,'INFRA','S'),(5,'TECH','R'),
    -- E06 Info Keselamatan Proses: TECH A/R
    (6,'HCBS','I'),  (6,'QHSSE','C'), (6,'OPS','S'),    (6,'INFRA','S'),(6,'TECH','A'),  (6,'TECH','R'),
    -- E07 HAZOP: TECH A/R
    (7,'HCBS','I'),  (7,'QHSSE','C'), (7,'OPS','S'),    (7,'INFRA','S'),(7,'TECH','A'),  (7,'TECH','R'),
    -- E08 SOP: OPS A/R
    (8,'HCBS','I'),  (8,'QHSSE','C'), (8,'OPS','A'),    (8,'OPS','R'),  (8,'INFRA','S'),(8,'TECH','S'),
    -- E09 PSSR: TECH A/R
    (9,'HCBS','I'),  (9,'QHSSE','C'), (9,'OPS','S'),    (9,'INFRA','S'),(9,'TECH','A'),  (9,'TECH','R'),
    -- E10 Integritas Aset: INFRA A/R
    (10,'HCBS','I'), (10,'QHSSE','C'),(10,'OPS','S'),   (10,'INFRA','A'),(10,'INFRA','R'),(10,'TECH','S'),
    -- E11 MOC: TECH A/R
    (11,'HCBS','I'), (11,'QHSSE','C'),(11,'OPS','S'),   (11,'INFRA','S'),(11,'TECH','A'),(11,'TECH','R'),
    -- E12 PTW: OPS A/R
    (12,'HCBS','I'), (12,'QHSSE','C'),(12,'OPS','A'),   (12,'OPS','R'), (12,'INFRA','S'),(12,'TECH','S'),
    -- E13 ERP: OPS A/R
    (13,'HCBS','I'), (13,'QHSSE','C'),(13,'OPS','A'),   (13,'OPS','R'), (13,'INFRA','S'),(13,'TECH','S'),
    -- E14 Pelaksanaan Operasi: OPS A/R
    (14,'HCBS','I'), (14,'QHSSE','C'),(14,'OPS','A'),   (14,'OPS','R'), (14,'INFRA','S'),(14,'TECH','I'),
    -- E15 Manajemen Kinerja PSAIMS: QHSSE A/R, INFRA C
    (15,'HCBS','S'), (15,'QHSSE','A'),(15,'QHSSE','R'), (15,'OPS','S'), (15,'INFRA','C'),(15,'TECH','S'),
    -- E16 Belajar dari Kejadian: QHSSE A, TECH R
    (16,'HCBS','I'), (16,'QHSSE','A'),(16,'OPS','S'),   (16,'INFRA','S'),(16,'TECH','R'),
    -- E17 Audit: QHSSE A/R
    (17,'HCBS','I'), (17,'QHSSE','A'),(17,'QHSSE','R'), (17,'OPS','C'), (17,'INFRA','C'),(17,'TECH','C'),
    -- E18 Tinjauan: QHSSE A/R
    (18,'HCBS','I'), (18,'QHSSE','A'),(18,'QHSSE','R'), (18,'OPS','C'), (18,'INFRA','C'),(18,'TECH','C')
) AS m(elem_num, role_code, resp)
JOIN psaims_elements e ON e.element_number = m.elem_num
JOIN psaims_roles    r ON r.role_code      = m.role_code;

-- =====================================================
-- STEP 6: TAMBAH USER DEMO (kalau belum ada)
-- =====================================================
INSERT INTO users (username, password, full_name, email, role, psaims_role_code, is_active) VALUES
('user_hcbs',  '$2b$12$Q62Xk.RHb2NN1bRNttnCheNvNHJMurnTHpdO11AwXsv0vzpMwamLe',
 'User HC & Business Support',   'hcbs@ptg.co.id',  'user', 'HCBS',  TRUE),
('user_qhsse', '$2b$12$Q62Xk.RHb2NN1bRNttnCheNvNHJMurnTHpdO11AwXsv0vzpMwamLe',
 'User QHSSE',                   'qhsse@ptg.co.id', 'user', 'QHSSE', TRUE),
('user_ops',   '$2b$12$Q62Xk.RHb2NN1bRNttnCheNvNHJMurnTHpdO11AwXsv0vzpMwamLe',
 'User Operational',             'ops@ptg.co.id',   'user', 'OPS',   TRUE),
('user_infra', '$2b$12$Q62Xk.RHb2NN1bRNttnCheNvNHJMurnTHpdO11AwXsv0vzpMwamLe',
 'User Infrastructure Mgmt',     'infra@ptg.co.id', 'user', 'INFRA', TRUE),
('user_tech',  '$2b$12$Q62Xk.RHb2NN1bRNttnCheNvNHJMurnTHpdO11AwXsv0vzpMwamLe',
 'User Technical Management',    'tech@ptg.co.id',  'user', 'TECH',  TRUE)
ON CONFLICT (username) DO UPDATE SET
    psaims_role_code = EXCLUDED.psaims_role_code,
    is_active = TRUE;

-- =====================================================
-- STEP 7: VIEW untuk RASCI matrix
-- =====================================================
CREATE OR REPLACE VIEW v_rasci_matrix AS
SELECT
    e.element_number,
    e.element_name,
    STRING_AGG(DISTINCT CASE WHEN m.responsibility = 'A' THEN r.role_code END, ',') AS accountable,
    STRING_AGG(DISTINCT CASE WHEN m.responsibility = 'R' THEN r.role_code END, ',') AS responsible,
    STRING_AGG(DISTINCT CASE WHEN m.responsibility = 'S' THEN r.role_code END, ',') AS support,
    STRING_AGG(DISTINCT CASE WHEN m.responsibility = 'C' THEN r.role_code END, ',') AS consulted,
    STRING_AGG(DISTINCT CASE WHEN m.responsibility = 'I' THEN r.role_code END, ',') AS informed
FROM psaims_elements e
LEFT JOIN element_role_mapping m ON m.element_id = e.id
LEFT JOIN psaims_roles         r ON r.id         = m.role_id
GROUP BY e.id, e.element_number, e.element_name
ORDER BY e.element_number;

-- =====================================================
-- VERIFIKASI HASIL
-- =====================================================

-- 1. Daftar role
SELECT 'ROLES:' AS info;
SELECT id, role_code, role_name FROM psaims_roles ORDER BY id;

-- 2. User yang ada + role barunya
SELECT 'USERS:' AS info;
SELECT id, username, full_name, role, psaims_role_code FROM users ORDER BY id;

-- 3. Validasi RASCI: tiap elemen harus punya tepat 1 role A dan minimal 1 R
SELECT 'VALIDATION (harus kosong):' AS info;
SELECT e.element_number, e.element_name,
    COUNT(DISTINCT r.role_code) FILTER (WHERE m.responsibility = 'A') AS jml_A,
    COUNT(DISTINCT r.role_code) FILTER (WHERE m.responsibility = 'R') AS jml_R
FROM psaims_elements e
LEFT JOIN element_role_mapping m ON m.element_id = e.id
LEFT JOIN psaims_roles         r ON r.id         = m.role_id
GROUP BY e.id, e.element_number, e.element_name
HAVING COUNT(DISTINCT r.role_code) FILTER (WHERE m.responsibility = 'A') != 1
    OR COUNT(DISTINCT r.role_code) FILTER (WHERE m.responsibility = 'R') < 1
ORDER BY e.element_number;

-- 4. Lihat matrix final
SELECT 'FINAL MATRIX:' AS info;
SELECT * FROM v_rasci_matrix;