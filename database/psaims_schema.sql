-- =====================================================
-- PSAIMS SELF ASSESSMENT TOOL - DATABASE SCHEMA
-- PostgreSQL 12+
-- =====================================================

-- Buat database (jalankan terpisah di psql)
-- CREATE DATABASE psaims_db WITH ENCODING 'UTF8';
-- \c psaims_db

-- =====================================================
-- TABEL USERS
-- =====================================================
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    role VARCHAR(20) DEFAULT 'user', -- admin, assessor, user
    department VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =====================================================
-- TABEL PSAIMS ELEMENTS (18 Elemen)
-- =====================================================
CREATE TABLE IF NOT EXISTS psaims_elements (
    id SERIAL PRIMARY KEY,
    element_number INTEGER UNIQUE NOT NULL,
    element_name VARCHAR(255) NOT NULL,
    element_code VARCHAR(20),
    description TEXT,
    icon VARCHAR(50),
    color VARCHAR(20),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =====================================================
-- TABEL ASSESSMENT QUESTIONS
-- =====================================================
CREATE TABLE IF NOT EXISTS assessment_questions (
    id SERIAL PRIMARY KEY,
    element_id INTEGER REFERENCES psaims_elements(id) ON DELETE CASCADE,
    question_number INTEGER,
    question_text TEXT NOT NULL,
    criteria TEXT,
    weight NUMERIC(5,2) DEFAULT 1.00,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =====================================================
-- TABEL ASSESSMENT SESSIONS (Periode Assessment)
-- =====================================================
CREATE TABLE IF NOT EXISTS assessment_sessions (
    id SERIAL PRIMARY KEY,
    session_name VARCHAR(200) NOT NULL,
    session_year INTEGER,
    session_period VARCHAR(50), -- Q1, Q2, Q3, Q4, Annual
    start_date DATE,
    end_date DATE,
    status VARCHAR(20) DEFAULT 'draft', -- draft, ongoing, completed, closed
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =====================================================
-- TABEL ASSESSMENT RESULTS
-- =====================================================
CREATE TABLE IF NOT EXISTS assessment_results (
    id SERIAL PRIMARY KEY,
    session_id INTEGER REFERENCES assessment_sessions(id) ON DELETE CASCADE,
    element_id INTEGER REFERENCES psaims_elements(id),
    question_id INTEGER REFERENCES assessment_questions(id),
    user_id INTEGER REFERENCES users(id),
    score INTEGER, -- 1-5 (Maturity Level)
    evidence TEXT,
    gap_analysis TEXT,
    action_plan TEXT,
    target_date DATE,
    responsible_person VARCHAR(100),
    attachment VARCHAR(255),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =====================================================
-- TABEL ACTIVITY LOG
-- =====================================================
CREATE TABLE IF NOT EXISTS activity_log (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),
    action VARCHAR(100),
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =====================================================
-- INSERT DATA AWAL - 18 ELEMEN PSAIMS
-- =====================================================
INSERT INTO psaims_elements (element_number, element_name, element_code, icon, color) VALUES
(1,  'Kepemimpinan dan Akuntabilitas', 'PSAIMS-01', 'fas fa-user-tie', 'primary'),
(2,  'Kebijakan PSAIMS', 'PSAIMS-02', 'fas fa-gavel', 'info'),
(3,  'Kepatuhan Terhadap Regulasi, Standar, Kode, Praktik dan Lisensi Operasi', 'PSAIMS-03', 'fas fa-balance-scale', 'warning'),
(4,  'Organisasi, Kompetensi, Informasi Terdokumentasi dan Partisipasi Pekerja', 'PSAIMS-04', 'fas fa-sitemap', 'success'),
(5,  'Keselamatan Kontraktor', 'PSAIMS-05', 'fas fa-handshake', 'danger'),
(6,  'Informasi Keselamatan Proses', 'PSAIMS-06', 'fas fa-info-circle', 'primary'),
(7,  'Analisa Bahaya Proses', 'PSAIMS-07', 'fas fa-exclamation-triangle', 'warning'),
(8,  'Prosedur Operasi', 'PSAIMS-08', 'fas fa-list-ol', 'info'),
(9,  'Kesiapan Operasi / Tinjauan Keselamatan Pra-Startup', 'PSAIMS-09', 'fas fa-play-circle', 'success'),
(10, 'Integritas Aset', 'PSAIMS-10', 'fas fa-shield-alt', 'primary'),
(11, 'Manajemen Perubahan', 'PSAIMS-11', 'fas fa-exchange-alt', 'warning'),
(12, 'Cara Kerja Aman', 'PSAIMS-12', 'fas fa-hard-hat', 'danger'),
(13, 'Rencana Tanggap Darurat', 'PSAIMS-13', 'fas fa-ambulance', 'danger'),
(14, 'Pelaksanaan Operasi', 'PSAIMS-14', 'fas fa-industry', 'info'),
(15, 'Manajemen Kinerja Keselamatan Proses', 'PSAIMS-15', 'fas fa-chart-line', 'success'),
(16, 'Belajar dari Kejadian', 'PSAIMS-16', 'fas fa-graduation-cap', 'primary'),
(17, 'Audit', 'PSAIMS-17', 'fas fa-clipboard-check', 'warning'),
(18, 'Tinjauan', 'PSAIMS-18', 'fas fa-search', 'info');

-- =====================================================
-- INSERT USER DEFAULT (Admin)
-- Password default: admin123 (hash bcrypt)
-- =====================================================
INSERT INTO users (username, password, full_name, email, role, department) VALUES
('admin', '$2y$10$DFhWqNZLOKYWH8vKVEjQaeZxk0EeF3PYyI5CtbvqEg4zxkxBqHrZW', 'Administrator', 'admin@ptg.co.id', 'admin', 'IT'),
('assessor', '$2y$10$DFhWqNZLOKYWH8vKVEjQaeZxk0EeF3PYyI5CtbvqEg4zxkxBqHrZW', 'Assessor PSAIMS', 'assessor@ptg.co.id', 'assessor', 'HSE');

-- =====================================================
-- SAMPLE QUESTIONS untuk Elemen 1 (Kepemimpinan dan Akuntabilitas)
-- =====================================================
INSERT INTO assessment_questions (element_id, question_number, question_text, criteria, weight) VALUES
(1, 1, 'Apakah pimpinan puncak telah menunjukkan komitmen terhadap PSAIMS melalui kebijakan tertulis?', 'Ada dokumen komitmen yang ditandatangani top management', 1.00),
(1, 2, 'Apakah akuntabilitas keselamatan proses telah didefinisikan dengan jelas pada setiap level organisasi?', 'Tersedia matriks RACI untuk PSAIMS', 1.00),
(1, 3, 'Apakah pimpinan melakukan management walkthrough secara berkala?', 'Ada jadwal dan dokumentasi management walkthrough minimal 1x/bulan', 1.00),
(1, 4, 'Apakah sumber daya (manusia, anggaran, peralatan) telah dialokasikan untuk implementasi PSAIMS?', 'Ada anggaran khusus PSAIMS dalam RKAP', 1.00),
(1, 5, 'Apakah KPI keselamatan proses diintegrasikan dengan KPI kinerja manajemen?', 'KPI PSAIMS masuk dalam penilaian kinerja manajer', 1.00);

-- =====================================================
-- INDEXES untuk Performance
-- =====================================================
CREATE INDEX idx_assessment_results_session ON assessment_results(session_id);
CREATE INDEX idx_assessment_results_element ON assessment_results(element_id);
CREATE INDEX idx_assessment_questions_element ON assessment_questions(element_id);
CREATE INDEX idx_users_username ON users(username);
CREATE INDEX idx_activity_log_user ON activity_log(user_id);

-- =====================================================
-- VIEWS untuk Dashboard
-- =====================================================
CREATE OR REPLACE VIEW v_assessment_summary AS
SELECT 
    e.id AS element_id,
    e.element_number,
    e.element_name,
    e.element_code,
    e.icon,
    e.color,
    COUNT(DISTINCT ar.id) AS total_assessed,
    ROUND(AVG(ar.score)::numeric, 2) AS avg_score,
    s.id AS session_id,
    s.session_name
FROM psaims_elements e
LEFT JOIN assessment_results ar ON e.id = ar.element_id
LEFT JOIN assessment_sessions s ON ar.session_id = s.id
GROUP BY e.id, e.element_number, e.element_name, e.element_code, e.icon, e.color, s.id, s.session_name
ORDER BY e.element_number;

-- =====================================================
-- SELESAI
-- =====================================================
-- Verifikasi
SELECT 'Elements created: ' || COUNT(*) FROM psaims_elements;
SELECT 'Users created: ' || COUNT(*) FROM users;
