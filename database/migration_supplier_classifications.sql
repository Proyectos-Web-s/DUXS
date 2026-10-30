-- Ejecutar seleccionando la base existente duxs_desde_csv en phpMyAdmin.

CREATE TABLE IF NOT EXISTS proveedor_clasificaciones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  proveedor_id BIGINT UNSIGNED NOT NULL,
  centro_de_costo VARCHAR(100) NOT NULL,
  categoria VARCHAR(100) NOT NULL,
  tipo_de_gasto VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_proveedor_clasificacion (proveedor_id, centro_de_costo, categoria, tipo_de_gasto),
  CONSTRAINT fk_proveedor_clasificaciones_proveedor
    FOREIGN KEY (proveedor_id) REFERENCES proveedores (id)
    ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
