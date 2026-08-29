-- Pharmacy Management System - Database Schema
-- Compatible with MySQL 5.7+ / MariaDB 10.3+

CREATE DATABASE IF NOT EXISTS pharmacy_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pharmacy_db;

-- ============================================================
-- PHASE 1: INVENTORY MANAGEMENT
-- ============================================================

CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    name_ar VARCHAR(100),
    description TEXT,
    color VARCHAR(7) DEFAULT '#3B82F6',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE cabinets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    location VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE shelves (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cabinet_id INT NOT NULL,
    shelf_number INT NOT NULL,
    label VARCHAR(50),
    FOREIGN KEY (cabinet_id) REFERENCES cabinets(id) ON DELETE CASCADE
);

CREATE TABLE medicines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barcode VARCHAR(50) UNIQUE,
    name VARCHAR(200) NOT NULL,
    name_ar VARCHAR(200),
    generic_name VARCHAR(200),
    strength VARCHAR(50),
    form ENUM('tablet','capsule','syrup','injection','cream','ointment','drops','inhaler','suppository','powder','gel','spray','patch','solution','suspension','other') DEFAULT 'tablet',
    category_id INT,
    shelf_id INT,
    manufacturer VARCHAR(150),
    country_of_origin VARCHAR(100),
    requires_prescription TINYINT(1) DEFAULT 0,
    is_controlled TINYINT(1) DEFAULT 0,
    controlled_schedule VARCHAR(10),
    is_subsidized TINYINT(1) DEFAULT 0,
    subsidy_percentage DECIMAL(5,2) DEFAULT 0,
    unit VARCHAR(30) DEFAULT 'box',
    units_per_box INT DEFAULT 1,
    cost_price DECIMAL(10,2) DEFAULT 0,
    sell_price DECIMAL(10,2) DEFAULT 0,
    moph_price DECIMAL(10,2),
    quantity_in_stock INT DEFAULT 0,
    min_stock_level INT DEFAULT 5,
    max_stock_level INT DEFAULT 100,
    expiry_date DATE,
    batch_number VARCHAR(50),
    storage_conditions VARCHAR(100),
    notes TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (shelf_id) REFERENCES shelves(id) ON DELETE SET NULL,
    INDEX idx_name (name),
    INDEX idx_barcode (barcode),
    INDEX idx_category (category_id),
    INDEX idx_expiry (expiry_date),
    INDEX idx_stock (quantity_in_stock)
);

CREATE TABLE stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id INT NOT NULL,
    type ENUM('in','out','adjustment','return','expired','damaged') NOT NULL,
    quantity INT NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    batch_number VARCHAR(50),
    expiry_date DATE,
    cost_price DECIMAL(10,2),
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE
);

-- ============================================================
-- PHASE 2: POINT OF SALE
-- ============================================================

CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    insurance_provider_id INT,
    insurance_number VARCHAR(50),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(30) UNIQUE NOT NULL,
    customer_id INT,
    sale_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    subtotal DECIMAL(12,2) DEFAULT 0,
    discount_amount DECIMAL(12,2) DEFAULT 0,
    discount_type ENUM('fixed','percentage') DEFAULT 'fixed',
    tax_amount DECIMAL(12,2) DEFAULT 0,
    total_amount DECIMAL(12,2) DEFAULT 0,
    amount_paid DECIMAL(12,2) DEFAULT 0,
    change_amount DECIMAL(12,2) DEFAULT 0,
    payment_method ENUM('cash','card','credit','insurance') DEFAULT 'cash',
    currency ENUM('LBP','USD') DEFAULT 'USD',
    exchange_rate DECIMAL(12,2) DEFAULT 89500,
    insurance_claim_id INT,
    prescription_number VARCHAR(50),
    doctor_name VARCHAR(100),
    status ENUM('completed','refunded','partial_refund','cancelled') DEFAULT 'completed',
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    medicine_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    cost_price DECIMAL(10,2) DEFAULT 0,
    discount DECIMAL(10,2) DEFAULT 0,
    total_price DECIMAL(12,2) NOT NULL,
    is_subsidized TINYINT(1) DEFAULT 0,
    subsidy_amount DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id)
);

CREATE TABLE sale_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    sale_item_id INT NOT NULL,
    quantity INT NOT NULL,
    reason TEXT,
    refund_amount DECIMAL(10,2),
    return_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (sale_id) REFERENCES sales(id),
    FOREIGN KEY (sale_item_id) REFERENCES sale_items(id)
);

-- ============================================================
-- PHASE 3: FINANCE & SUPPLIERS
-- ============================================================

CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    payment_terms VARCHAR(100),
    notes TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_number VARCHAR(30) UNIQUE NOT NULL,
    supplier_id INT NOT NULL,
    order_date DATE NOT NULL,
    expected_delivery DATE,
    actual_delivery DATE,
    subtotal DECIMAL(12,2) DEFAULT 0,
    discount DECIMAL(12,2) DEFAULT 0,
    tax DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    amount_paid DECIMAL(12,2) DEFAULT 0,
    currency ENUM('LBP','USD') DEFAULT 'USD',
    status ENUM('draft','ordered','partial','received','cancelled') DEFAULT 'draft',
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
);

CREATE TABLE purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    medicine_id INT NOT NULL,
    quantity_ordered INT NOT NULL,
    quantity_received INT DEFAULT 0,
    unit_cost DECIMAL(10,2) NOT NULL,
    total_cost DECIMAL(12,2) NOT NULL,
    batch_number VARCHAR(50),
    expiry_date DATE,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id)
);

CREATE TABLE expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category ENUM('rent','utilities','salaries','supplies','maintenance','marketing','taxes','license','other') NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency ENUM('LBP','USD') DEFAULT 'USD',
    expense_date DATE NOT NULL,
    payment_method ENUM('cash','card','bank_transfer','check') DEFAULT 'cash',
    receipt_number VARCHAR(50),
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tax_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    total_sales DECIMAL(14,2) DEFAULT 0,
    total_purchases DECIMAL(14,2) DEFAULT 0,
    vat_collected DECIMAL(12,2) DEFAULT 0,
    vat_paid DECIMAL(12,2) DEFAULT 0,
    net_vat DECIMAL(12,2) DEFAULT 0,
    income_tax DECIMAL(12,2) DEFAULT 0,
    municipality_tax DECIMAL(12,2) DEFAULT 0,
    status ENUM('draft','filed','paid') DEFAULT 'draft',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- PHASE 4: MOPH COMPLIANCE
-- ============================================================

CREATE TABLE moph_price_list (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medicine_name VARCHAR(200) NOT NULL,
    barcode VARCHAR(50),
    public_price_usd DECIMAL(10,2),
    public_price_lbp DECIMAL(14,2),
    hospital_price_usd DECIMAL(10,2),
    agent_name VARCHAR(150),
    effective_date DATE,
    is_subsidized TINYINT(1) DEFAULT 0,
    subsidy_category VARCHAR(50),
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_medicine_name (medicine_name),
    INDEX idx_barcode (barcode)
);

CREATE TABLE controlled_substance_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id INT NOT NULL,
    transaction_type ENUM('received','dispensed','destroyed','returned') NOT NULL,
    quantity INT NOT NULL,
    balance_after INT NOT NULL,
    prescription_number VARCHAR(50),
    doctor_name VARCHAR(100),
    doctor_license VARCHAR(50),
    patient_name VARCHAR(100),
    patient_id VARCHAR(50),
    supplier_name VARCHAR(100),
    witness_name VARCHAR(100),
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id)
);

-- ============================================================
-- PHASE 5: INSURANCE
-- ============================================================

CREATE TABLE insurance_providers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    type ENUM('NSSF','army','ISF','public_sector','private','cooperative','other') NOT NULL,
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    coverage_percentage DECIMAL(5,2) DEFAULT 0,
    payment_terms VARCHAR(100),
    contract_start DATE,
    contract_end DATE,
    is_active TINYINT(1) DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE insurance_claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    claim_number VARCHAR(30) UNIQUE NOT NULL,
    insurance_provider_id INT NOT NULL,
    customer_id INT,
    sale_id INT,
    claim_date DATE NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    covered_amount DECIMAL(12,2) DEFAULT 0,
    patient_copay DECIMAL(12,2) DEFAULT 0,
    status ENUM('pending','submitted','approved','rejected','paid','partial') DEFAULT 'pending',
    rejection_reason TEXT,
    payment_date DATE,
    payment_amount DECIMAL(12,2),
    batch_number VARCHAR(50),
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (insurance_provider_id) REFERENCES insurance_providers(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (sale_id) REFERENCES sales(id)
);

CREATE TABLE insurance_claim_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    claim_id INT NOT NULL,
    medicine_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    covered_amount DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (claim_id) REFERENCES insurance_claims(id) ON DELETE CASCADE,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id)
);

-- ============================================================
-- SYSTEM TABLES
-- ============================================================

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin','pharmacist','cashier','viewer') DEFAULT 'pharmacist',
    email VARCHAR(100),
    phone VARCHAR(20),
    is_active TINYINT(1) DEFAULT 1,
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_group VARCHAR(50) DEFAULT 'general',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(50) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action (action),
    INDEX idx_table (table_name),
    INDEX idx_date (created_at)
);

-- ============================================================
-- DEFAULT DATA
-- ============================================================

INSERT INTO users (username, password, full_name, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin');
-- Default password: password

INSERT INTO categories (name, name_ar, color) VALUES
('Respiratory', 'تنفسي', '#3B82F6'),
('Pediatric', 'أطفال', '#EC4899'),
('Gastrointestinal', 'جهاز هضمي', '#F59E0B'),
('Pain & Anti-inflammatory', 'مسكنات ومضاد التهاب', '#EF4444'),
('Cardiovascular', 'قلب وأوعية دموية', '#8B5CF6'),
('Antibiotics', 'مضادات حيوية', '#10B981'),
('Dermatology', 'جلدية', '#F97316'),
('Vitamins & Supplements', 'فيتامينات ومكملات', '#06B6D4'),
('Diabetes', 'سكري', '#6366F1'),
('Neurology', 'أعصاب', '#84CC16'),
('Ophthalmology', 'عيون', '#14B8A6'),
('ENT', 'أنف أذن حنجرة', '#A855F7'),
('Urology', 'مسالك بولية', '#D946EF'),
('Gynecology', 'نسائية', '#FB7185'),
('Orthopedic', 'عظام', '#78716C');

INSERT INTO settings (setting_key, setting_value, setting_group) VALUES
('pharmacy_name', 'My Pharmacy', 'general'),
('pharmacy_name_ar', 'صيدليتي', 'general'),
('pharmacy_address', '', 'general'),
('pharmacy_phone', '', 'general'),
('pharmacy_license', '', 'general'),
('pharmacist_name', '', 'general'),
('pharmacist_license', '', 'general'),
('currency_primary', 'USD', 'finance'),
('currency_secondary', 'LBP', 'finance'),
('exchange_rate', '89500', 'finance'),
('vat_rate', '11', 'finance'),
('low_stock_threshold', '5', 'inventory'),
('expiry_warning_days', '90', 'inventory'),
('receipt_footer', 'Thank you for your visit!', 'pos'),
('receipt_show_arabic', '1', 'pos');

INSERT INTO insurance_providers (name, type, coverage_percentage) VALUES
('NSSF - الصندوق الوطني للضمان الاجتماعي', 'NSSF', 85),
('Lebanese Army - الجيش اللبناني', 'army', 100),
('ISF - قوى الأمن الداخلي', 'ISF', 90),
('Public Sector - القطاع العام', 'public_sector', 85);

INSERT INTO suppliers (name, contact_person, phone) VALUES
('Pharmaline', '', ''),
('Benta Trading', '', ''),
('Droguerie de l''Union', '', ''),
('Fattal Group', '', ''),
('Mersaco', '', '');
