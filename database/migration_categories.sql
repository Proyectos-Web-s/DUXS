USE duxs_egresos;

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

ALTER TABLE classifications
  ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER cost_center_id,
  DROP INDEX uq_classification_tuple,
  ADD UNIQUE KEY uq_classification_tuple (cost_center_id, category_id, expense_type_id, account_code),
  ADD CONSTRAINT fk_classification_category
    FOREIGN KEY (category_id) REFERENCES categories (id);

ALTER TABLE expenses
  ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER classification_id,
  ADD KEY idx_expenses_month_category (accounting_month, category_id),
  ADD CONSTRAINT fk_expenses_category
    FOREIGN KEY (category_id) REFERENCES categories (id);

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
