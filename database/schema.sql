CREATE DATABASE IF NOT EXISTS duxs_egresos
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE duxs_egresos;

CREATE TABLE IF NOT EXISTS companies (
  id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(100) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id),
  UNIQUE KEY uq_companies_code (code)
) ENGINE=InnoDB;

INSERT INTO companies (code, name)
VALUES ('DUXS', 'DUXS'), ('ENZO', 'ENZO')
ON DUPLICATE KEY UPDATE name = VALUES(name);

CREATE TABLE IF NOT EXISTS suppliers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cuit CHAR(11) NULL,
  legal_name VARCHAR(255) NOT NULL,
  trade_name VARCHAR(255) NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  source_payload JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_cuit (cuit),
  KEY idx_suppliers_legal_name (legal_name),
  KEY idx_suppliers_active (active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cost_centers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cost_centers_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB;

INSERT INTO categories (name) VALUES
  ('Indumentaria y epp'),
  ('Alquiler'),
  ('Sueldos Tercerizados'),
  ('Sueldos DUXS'),
  ('Honorarios'),
  ('Seguros'),
  ('Sistemas'),
  ('Publicidad'),
  ('Merchandasing'),
  ('Redes Sociales'),
  ('Impuestos'),
  ('Gastos Generales'),
  ('Gastos Consumibles'),
  ('Fortalecimiento de equipo'),
  ('Librería'),
  ('Vehículo Seguro'),
  ('Vehículo Impuesto'),
  ('Vehículo Gastos'),
  ('Vehiculo Combustible'),
  ('Vehículo Mantenimiento'),
  ('Inversión General'),
  ('Capacitacion'),
  ('Gastos Personales'),
  ('Donaciones'),
  ('N/A'),
  ('Mensajería')
ON DUPLICATE KEY UPDATE active = TRUE;

CREATE TABLE IF NOT EXISTS expense_types (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expense_types_name (name)
) ENGINE=InnoDB;

INSERT INTO cost_centers (name) VALUES
  ('GATOS MENSUALES'),
  ('SAFIAR'),
  ('SEGUROS'),
  ('DONWEB'),
  ('MICRACEL'),
  ('N/A'),
  ('CAPACITACION'),
  ('DONACIONES'),
  ('ESTUDIOS'),
  ('SERVICIOS'),
  ('BASE'),
  ('OBRA LAS OVEJAS'),
  ('PROYECTO')
ON DUPLICATE KEY UPDATE active = TRUE;

INSERT INTO expense_types (name) VALUES
  ('FIJO'),
  ('DONACIONES'),
  ('VARIABLE'),
  ('N/A')
ON DUPLICATE KEY UPDATE active = TRUE;

CREATE TABLE IF NOT EXISTS classifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cost_center_id BIGINT UNSIGNED NULL,
  category_id BIGINT UNSIGNED NULL,
  expense_type_id BIGINT UNSIGNED NULL,
  account_code VARCHAR(100) NULL,
  afip_code VARCHAR(50) NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id),
  UNIQUE KEY uq_classification_tuple (cost_center_id, category_id, expense_type_id, account_code),
  CONSTRAINT fk_classification_cost_center FOREIGN KEY (cost_center_id) REFERENCES cost_centers (id),
  CONSTRAINT fk_classification_category FOREIGN KEY (category_id) REFERENCES categories (id),
  CONSTRAINT fk_classification_expense_type FOREIGN KEY (expense_type_id) REFERENCES expense_types (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supplier_classifications (
  supplier_id BIGINT UNSIGNED NOT NULL,
  classification_id BIGINT UNSIGNED NOT NULL,
  is_default BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (supplier_id, classification_id),
  KEY idx_supplier_classifications_default (supplier_id, is_default),
  CONSTRAINT fk_supplier_classification_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT fk_supplier_classification_classification FOREIGN KEY (classification_id) REFERENCES classifications (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS imports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  filename VARCHAR(255) NOT NULL,
  source_sheet VARCHAR(100) NULL,
  status ENUM('pending', 'validated', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_rows INT UNSIGNED NOT NULL DEFAULT 0,
  error_summary JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_imports_status_created (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expenses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id TINYINT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NULL,
  classification_id BIGINT UNSIGNED NULL,
  category_id BIGINT UNSIGNED NULL,
  accounting_month DATE NOT NULL,
  issue_date DATE NULL,
  document_number VARCHAR(100) NOT NULL,
  supplier_cuit CHAR(11) NULL,
  supplier_name VARCHAR(255) NULL,
  iva_condition VARCHAR(100) NULL,
  jurisdiction VARCHAR(100) NULL,
  net_amount DECIMAL(15,2) NULL,
  vat_total DECIMAL(15,2) NULL,
  perceptions_total DECIMAL(15,2) NULL,
  other_taxes_total DECIMAL(15,2) NULL,
  total_amount DECIMAL(15,2) NULL,
  observations TEXT NULL,
  afip_code VARCHAR(50) NULL,
  account_code VARCHAR(100) NULL,
  tax_credit VARCHAR(150) NULL,
  expense_label VARCHAR(150) NULL,
  import_id BIGINT UNSIGNED NULL,
  source_sheet VARCHAR(100) NULL,
  source_row INT UNSIGNED NULL,
  source_payload JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_expenses_month_company (accounting_month, company_id),
  KEY idx_expenses_issue_date (issue_date),
  KEY idx_expenses_supplier_cuit (supplier_cuit),
  KEY idx_expenses_document_number (document_number),
  KEY idx_expenses_supplier (supplier_id),
  KEY idx_expenses_classification (classification_id),
  KEY idx_expenses_month_category (accounting_month, category_id),
  KEY idx_expenses_import (import_id),
  CONSTRAINT fk_expenses_company FOREIGN KEY (company_id) REFERENCES companies (id),
  CONSTRAINT fk_expenses_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT fk_expenses_classification FOREIGN KEY (classification_id) REFERENCES classifications (id),
  CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES categories (id),
  CONSTRAINT fk_expenses_import FOREIGN KEY (import_id) REFERENCES imports (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expense_tax_details (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  expense_id BIGINT UNSIGNED NOT NULL,
  component_code VARCHAR(80) NOT NULL,
  component_label VARCHAR(150) NOT NULL,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expense_tax_component (expense_id, component_code),
  CONSTRAINT fk_expense_tax_details_expense FOREIGN KEY (expense_id) REFERENCES expenses (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS claims (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  expense_id BIGINT UNSIGNED NULL,
  supplier_id BIGINT UNSIGNED NULL,
  company_id TINYINT UNSIGNED NULL,
  period DATE NULL,
  document_number VARCHAR(100) NULL,
  supplier_cuit CHAR(11) NULL,
  supplier_name VARCHAR(255) NOT NULL,
  amount DECIMAL(15,2) NULL,
  reason VARCHAR(255) NOT NULL,
  observations TEXT NULL,
  status ENUM('pending', 'resolved') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_claims_status_period (status, period),
  KEY idx_claims_supplier (supplier_id),
  KEY idx_claims_cuit (supplier_cuit),
  CONSTRAINT fk_claims_expense FOREIGN KEY (expense_id) REFERENCES expenses (id),
  CONSTRAINT fk_claims_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT fk_claims_company FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS claim_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  claim_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(50) NOT NULL,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_claim_history_claim (claim_id, created_at),
  CONSTRAINT fk_claim_history_claim FOREIGN KEY (claim_id) REFERENCES claims (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS change_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(50) NOT NULL,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_change_history_entity (entity_type, entity_id, created_at)
) ENGINE=InnoDB;
