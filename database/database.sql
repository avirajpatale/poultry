-- =============================================================
-- Poultry Farm Management System - MySQL 8 Schema
-- Engine: InnoDB | Charset: utf8mb4
-- Default login: admin / admin123  (change immediately)
-- =============================================================

CREATE DATABASE IF NOT EXISTS poultry_farm
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE poultry_farm;

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------- USERS / RBAC -----------------------------
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;

CREATE TABLE roles (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(50)  NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code   VARCHAR(60) NOT NULL UNIQUE,       -- e.g. sales.create
  module VARCHAR(40) NOT NULL,
  name   VARCHAR(80) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id       INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id       INT UNSIGNED NOT NULL,
  name          VARCHAR(100) NOT NULL,
  username      VARCHAR(60)  NOT NULL UNIQUE,
  email         VARCHAR(120) NULL UNIQUE,
  phone         VARCHAR(20)  NULL,
  password_hash VARCHAR(255) NOT NULL,
  status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login    DATETIME NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id),
  INDEX idx_users_status (status)
) ENGINE=InnoDB;

-- ----------------------------- PARTIES -----------------------------
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS suppliers;

CREATE TABLE customers (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL UNIQUE,
  name            VARCHAR(120) NOT NULL,
  mobile          VARCHAR(20)  NULL,
  email           VARCHAR(120) NULL,
  address         TEXT NULL,
  customer_type   ENUM('retail','wholesale','hotel','bakery','farm','other') NOT NULL DEFAULT 'retail',
  opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- +ve = customer owes us
  status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_customers_name (name),
  INDEX idx_customers_mobile (mobile)
) ENGINE=InnoDB;

CREATE TABLE suppliers (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL UNIQUE,
  name            VARCHAR(120) NOT NULL,
  contact_person  VARCHAR(120) NULL,
  mobile          VARCHAR(20)  NULL,
  email           VARCHAR(120) NULL,
  address         TEXT NULL,
  supplier_type   ENUM('feed','medicine','chicks','equipment','packaging','other') NOT NULL DEFAULT 'other',
  opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- +ve = we owe supplier
  status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_suppliers_name (name)
) ENGINE=InnoDB;

-- ----------------------------- PRODUCTS / INVENTORY -----------------------------
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS inventory;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;

CREATE TABLE categories (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  type ENUM('egg','broiler','feed','medicine','vaccine','packaging','equipment','chicks','other') NOT NULL DEFAULT 'other',
  UNIQUE KEY uq_cat_name_type (name, type)
) ENGINE=InnoDB;

CREATE TABLE products (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id     INT UNSIGNED NOT NULL,
  name            VARCHAR(120) NOT NULL,
  sku             VARCHAR(40)  NULL UNIQUE,
  unit            VARCHAR(20)  NOT NULL DEFAULT 'pcs',  -- pcs, tray, kg, bag, litre, bottle
  sale_price      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  purchase_price  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  min_stock_level DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  track_stock     TINYINT(1) NOT NULL DEFAULT 1,
  status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id),
  INDEX idx_products_name (name),
  INDEX idx_products_cat  (category_id)
) ENGINE=InnoDB;

-- Authoritative on-hand stock for stockable items (feed, medicine, packaging...)
CREATE TABLE inventory (
  product_id     INT UNSIGNED PRIMARY KEY,
  quantity       DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  avg_cost       DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  last_movement  DATETIME NULL,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE stock_movements (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  movement_date  DATE NOT NULL,
  product_id     INT UNSIGNED NOT NULL,
  movement_type  ENUM('opening','purchase','consumption','sale','adjustment','wastage','return') NOT NULL,
  quantity_in    DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  quantity_out   DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  balance_after  DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  reference_type VARCHAR(30) NULL,   -- purchase, sale, expense, manual, layer_record
  reference_id   BIGINT UNSIGNED NULL,
  notes          VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_sm_product_date (product_id, movement_date),
  INDEX idx_sm_ref (reference_type, reference_id),
  INDEX idx_sm_date (movement_date)
) ENGINE=InnoDB;

-- ----------------------------- LAYER FARM -----------------------------
DROP TABLE IF EXISTS egg_stock_movements;
DROP TABLE IF EXISTS layer_daily_records;

CREATE TABLE layer_daily_records (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  record_date     DATE NOT NULL UNIQUE,
  opening_eggs    INT UNSIGNED NOT NULL DEFAULT 0,
  eggs_collected  INT UNSIGNED NOT NULL DEFAULT 0,
  eggs_purchased  INT UNSIGNED NOT NULL DEFAULT 0,
  eggs_sold       INT UNSIGNED NOT NULL DEFAULT 0,
  eggs_damaged    INT UNSIGNED NOT NULL DEFAULT 0,
  eggs_used       INT UNSIGNED NOT NULL DEFAULT 0,   -- internal use / hatching
  closing_eggs    INT NOT NULL DEFAULT 0,            -- opening+collected+purchased-sold-damaged-used
  birds_count     INT UNSIGNED NULL,
  mortality       INT UNSIGNED NOT NULL DEFAULT 0,
  feed_used_kg    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  notes           VARCHAR(255) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_ldr_date (record_date)
) ENGINE=InnoDB;

CREATE TABLE egg_stock_movements (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  movement_date  DATE NOT NULL,
  movement_type  ENUM('opening','production','purchase','sale','damage','internal_use','adjustment') NOT NULL,
  quantity       INT NOT NULL,           -- signed
  balance_after  INT NOT NULL,
  reference_type VARCHAR(30) NULL,
  reference_id   BIGINT UNSIGNED NULL,
  notes          VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_esm_date (movement_date),
  INDEX idx_esm_ref (reference_type, reference_id)
) ENGINE=InnoDB;

-- ----------------------------- BROILER FARM -----------------------------
DROP TABLE IF EXISTS bird_mortality;
DROP TABLE IF EXISTS broiler_daily_records;
DROP TABLE IF EXISTS broiler_batches;

CREATE TABLE broiler_batches (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_code       VARCHAR(20) NOT NULL UNIQUE,
  name             VARCHAR(100) NULL,
  supplier_id      INT UNSIGNED NULL,
  chick_date       DATE NOT NULL,
  initial_quantity INT UNSIGNED NOT NULL,
  chick_rate       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  chick_cost       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  sold_quantity    INT UNSIGNED NOT NULL DEFAULT 0,
  mortality_total  INT UNSIGNED NOT NULL DEFAULT 0,
  status           ENUM('active','closed') NOT NULL DEFAULT 'active',
  closing_date     DATE NULL,
  notes            VARCHAR(255) NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL,
  INDEX idx_batch_status (status)
) ENGINE=InnoDB;

CREATE TABLE broiler_daily_records (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id      INT UNSIGNED NOT NULL,
  record_date   DATE NOT NULL,
  mortality     INT UNSIGNED NOT NULL DEFAULT 0,
  feed_kg       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  avg_weight_g  DECIMAL(10,2) NULL,
  medicine_used VARCHAR(255) NULL,
  notes         VARCHAR(255) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bdr (batch_id, record_date),
  FOREIGN KEY (batch_id)   REFERENCES broiler_batches(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_bdr_date (record_date)
) ENGINE=InnoDB;

CREATE TABLE bird_mortality (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id       INT UNSIGNED NOT NULL,
  mortality_date DATE NOT NULL,
  quantity       INT UNSIGNED NOT NULL,
  cause          VARCHAR(120) NULL,
  notes          VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (batch_id)   REFERENCES broiler_batches(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_bm_batch (batch_id, mortality_date)
) ENGINE=InnoDB;

-- ----------------------------- SALES -----------------------------
DROP TABLE IF EXISTS sale_items;
DROP TABLE IF EXISTS sales;

CREATE TABLE sales (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_no     VARCHAR(30) NOT NULL UNIQUE,
  sale_date      DATE NOT NULL,
  customer_id    INT UNSIGNED NULL,               -- NULL = walk-in
  sale_type      ENUM('egg','broiler','other','mixed') NOT NULL DEFAULT 'mixed',
  subtotal       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  paid_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  balance_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_method ENUM('cash','upi','bank','credit','mixed') NOT NULL DEFAULT 'cash',
  payment_status ENUM('paid','partial','credit') NOT NULL DEFAULT 'paid',
  status         ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
  notes          VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by)  REFERENCES users(id)      ON DELETE SET NULL,
  INDEX idx_sales_date     (sale_date),
  INDEX idx_sales_customer (customer_id),
  INDEX idx_sales_status   (status)
) ENGINE=InnoDB;

CREATE TABLE sale_items (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id     BIGINT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NULL,
  batch_id    INT UNSIGNED NULL,            -- broiler batch (if broiler sale)
  description VARCHAR(160) NOT NULL,
  quantity    DECIMAL(12,3) NOT NULL,       -- billed qty (kg for broiler, pcs/tray otherwise)
  unit        VARCHAR(20)  NOT NULL,
  rate        DECIMAL(12,2) NOT NULL,
  amount      DECIMAL(12,2) NOT NULL,
  birds_count INT UNSIGNED NULL,            -- broiler items: live birds deducted from batch
  FOREIGN KEY (sale_id)    REFERENCES sales(id)          ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)       ON DELETE SET NULL,
  FOREIGN KEY (batch_id)   REFERENCES broiler_batches(id) ON DELETE SET NULL,
  INDEX idx_si_sale (sale_id),
  INDEX idx_si_product (product_id)
) ENGINE=InnoDB;

-- ----------------------------- PURCHASES -----------------------------
DROP TABLE IF EXISTS purchase_items;
DROP TABLE IF EXISTS purchases;

CREATE TABLE purchases (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_no     VARCHAR(40) NOT NULL,
  purchase_date  DATE NOT NULL,
  supplier_id    INT UNSIGNED NULL,
  subtotal       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  paid_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  balance_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_status ENUM('paid','partial','credit') NOT NULL DEFAULT 'credit',
  status         ENUM('received','cancelled') NOT NULL DEFAULT 'received',
  notes          VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_supplier_inv (supplier_id, invoice_no),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL,
  INDEX idx_purch_date    (purchase_date),
  INDEX idx_purch_supplier(supplier_id)
) ENGINE=InnoDB;

CREATE TABLE purchase_items (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id BIGINT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NULL,
  description VARCHAR(160) NOT NULL,
  quantity    DECIMAL(12,3) NOT NULL,
  unit        VARCHAR(20)  NOT NULL,
  rate        DECIMAL(12,2) NOT NULL,
  amount      DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id)  REFERENCES products(id)  ON DELETE SET NULL,
  INDEX idx_pi_purchase (purchase_id)
) ENGINE=InnoDB;

-- ----------------------------- PAYMENTS / LEDGERS -----------------------------
DROP TABLE IF EXISTS customer_ledger;
DROP TABLE IF EXISTS supplier_ledger;
DROP TABLE IF EXISTS payments;

CREATE TABLE payments (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_date   DATE NOT NULL,
  direction      ENUM('receipt','payment') NOT NULL,  -- receipt = from customer, payment = to supplier
  party_type     ENUM('customer','supplier') NOT NULL,
  party_id       INT UNSIGNED NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  method         ENUM('cash','upi','bank','cheque','other') NOT NULL DEFAULT 'cash',
  reference_no   VARCHAR(60) NULL,
  sale_id        BIGINT UNSIGNED NULL,
  purchase_id    BIGINT UNSIGNED NULL,
  notes          VARCHAR(255) NULL,
  status         ENUM('active','reversed') NOT NULL DEFAULT 'active',
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sale_id)     REFERENCES sales(id)     ON DELETE SET NULL,
  FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL,
  INDEX idx_pay_party (party_type, party_id),
  INDEX idx_pay_date  (payment_date)
) ENGINE=InnoDB;

-- debit  = customer owes more (sale), credit = customer paid
CREATE TABLE customer_ledger (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ledger_date    DATE NOT NULL,
  customer_id    INT UNSIGNED NOT NULL,
  entry_type     ENUM('opening','sale','payment','adjustment','reversal') NOT NULL,
  debit          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  credit         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  balance        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reference_type VARCHAR(30) NULL,
  reference_id   BIGINT UNSIGNED NULL,
  notes          VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL,
  INDEX idx_cl_cust_date (customer_id, ledger_date),
  INDEX idx_cl_ref (reference_type, reference_id)
) ENGINE=InnoDB;

-- credit = we owe more (purchase), debit = we paid
CREATE TABLE supplier_ledger (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ledger_date    DATE NOT NULL,
  supplier_id    INT UNSIGNED NOT NULL,
  entry_type     ENUM('opening','purchase','payment','adjustment','reversal') NOT NULL,
  debit          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  credit         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  balance        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reference_type VARCHAR(30) NULL,
  reference_id   BIGINT UNSIGNED NULL,
  notes          VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL,
  INDEX idx_sl_supp_date (supplier_id, ledger_date),
  INDEX idx_sl_ref (reference_type, reference_id)
) ENGINE=InnoDB;

-- ----------------------------- EXPENSES -----------------------------
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS expense_categories;

CREATE TABLE expense_categories (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name   VARCHAR(80) NOT NULL UNIQUE,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE expenses (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expense_date  DATE NOT NULL,
  category_id   INT UNSIGNED NOT NULL,
  description   VARCHAR(255) NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  payment_mode  ENUM('cash','upi','bank','credit') NOT NULL DEFAULT 'cash',
  batch_id      INT UNSIGNED NULL,
  vendor        VARCHAR(120) NULL,
  notes         VARCHAR(255) NULL,
  status        ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES expense_categories(id),
  FOREIGN KEY (batch_id)    REFERENCES broiler_batches(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by)  REFERENCES users(id)           ON DELETE SET NULL,
  INDEX idx_exp_date (expense_date),
  INDEX idx_exp_cat  (category_id)
) ENGINE=InnoDB;

-- ----------------------------- SETTINGS -----------------------------
DROP TABLE IF EXISTS settings;
CREATE TABLE settings (
  setting_key   VARCHAR(60) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================ SEED DATA ============================
INSERT INTO roles (id, name, description) VALUES
 (1,'admin','Full access - farm owner'),
 (2,'manager','Daily operations, purchases, sales, reports'),
 (3,'staff','Restricted daily data entry');

INSERT INTO permissions (id, code, module, name) VALUES
 (1,'dashboard.view','dashboard','View dashboard'),
 (2,'layers.manage','layers','Manage layer records'),
 (3,'broilers.manage','broilers','Manage broiler batches'),
 (4,'customers.manage','customers','Manage customers'),
 (5,'suppliers.manage','suppliers','Manage suppliers'),
 (6,'sales.manage','sales','Create/cancel sales'),
 (7,'purchases.manage','purchases','Create/cancel purchases'),
 (8,'inventory.manage','inventory','Manage inventory'),
 (9,'expenses.manage','expenses','Record expenses'),
 (10,'reports.view','reports','View reports'),
 (11,'users.manage','users','Manage users'),
 (12,'settings.manage','settings','Manage settings'),
 (13,'payments.manage','payments','Record payments');

-- admin: all
INSERT INTO role_permissions (role_id, permission_id)
 SELECT 1, id FROM permissions;
-- manager: everything except users/settings
INSERT INTO role_permissions (role_id, permission_id)
 SELECT 2, id FROM permissions WHERE code NOT IN ('users.manage','settings.manage');
-- staff: view dashboard + layers/broilers/sales/expenses entry
INSERT INTO role_permissions (role_id, permission_id)
 SELECT 3, id FROM permissions
 WHERE code IN ('dashboard.view','layers.manage','broilers.manage','sales.manage','expenses.manage','payments.manage');

-- password = admin123
INSERT INTO users (role_id, name, username, email, password_hash) VALUES
 (1,'Farm Owner','admin','admin@farm.local','$2y$10$Sj5vI.nYkxLpgb8N58qvB.9y0IGK79jg/J6lKjMkix4g5NbLDcKqK');

INSERT INTO categories (id, name, type) VALUES
 (1,'Eggs','egg'), (2,'Broiler Chicken','broiler'), (3,'Layer Feed','feed'),
 (4,'Broiler Feed','feed'), (5,'Medicines','medicine'), (6,'Vaccines','vaccine'),
 (7,'Packaging','packaging'), (8,'Equipment','equipment'), (9,'Chicks','chicks'),
 (10,'Other','other');

-- Default sellable products (inventory rows created lazily by app)
INSERT INTO products (category_id, name, sku, unit, sale_price, purchase_price, min_stock_level, track_stock) VALUES
 (1,'Egg (per piece)','EGG-PC','pcs',6.00,0.00,0,0),
 (1,'Egg Tray (30)','EGG-TRAY','tray',180.00,0.00,0,0),
 (2,'Broiler Chicken (live)','BRL-LIVE','kg',0.00,0.00,0,0),
 (3,'Layer Feed 50kg bag','FEED-L50','bag',0.00,0.00,5,1),
 (4,'Broiler Starter Feed','FEED-BSTART','bag',0.00,0.00,5,1),
 (4,'Broiler Finisher Feed','FEED-BFIN','bag',0.00,0.00,5,1),
 (5,'Multivitamin 1L','MED-VIT1','bottle',0.00,0.00,2,1),
 (6,'Ranikhet Vaccine','VAC-RK','vial',0.00,0.00,2,1),
 (7,'Egg Tray (packaging)','PKG-TRAY','pcs',0.00,0.00,50,1),
 (9,'Broiler Chick','CHK-BRL','pcs',0.00,0.00,0,0),
 (10,'Miscellaneous Item','MISC','pcs',0.00,0.00,0,0);

INSERT INTO expense_categories (id, name) VALUES
 (1,'Feed'),(2,'Medicine'),(3,'Vaccination'),(4,'Labour'),(5,'Electricity'),
 (6,'Water'),(7,'Transportation'),(8,'Maintenance'),(9,'Fuel'),(10,'Other');

INSERT INTO settings (setting_key, setting_value) VALUES
 ('farm_name','My Poultry Farm'),
 ('farm_address',''),
 ('farm_phone',''),
 ('currency','INR'),
 ('invoice_prefix','INV-'),
 ('egg_tray_size','30'),
 ('low_stock_alert','1');
