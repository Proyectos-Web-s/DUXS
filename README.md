# DUXS · Gestión de egresos

Aplicación interna en desarrollo para DUXS y ENZO. La web usa PHP/PDO para MySQL; JavaScript nunca se conecta directamente a la base.

## Requisitos

- XAMPP con Apache, MySQL/MariaDB y PHP con `pdo_mysql` habilitado.
- phpMyAdmin (incluido en XAMPP).
- El proyecto ubicado en `C:\xampp\htdocs\duxs`.

## Preparar la base local

1. Abrí el panel de XAMPP e iniciá **Apache** y **MySQL**.
2. Abrí `http://localhost/phpmyadmin/`.
3. Si todavía no existe la base, elegí **Importar**, seleccioná `database/schema.sql` y ejecutá la importación. El esquema crea `duxs_egresos`, las empresas DUXS y ENZO, y siembra los catálogos definidos en `DATOS` del Excel: categorías, centros de costo y tipos de gasto. No inserta facturas ni proveedores. Si ya habías importado el esquema anterior, ejecutá **una sola vez** `database/migration_categories.sql` en la base existente en lugar de volver a crearla; la migración crea el catálogo de categorías, agrega la relación por categoría a egresos y clasificaciones, y conserva las filas existentes.
4. Copiá `config/database.example.php` como `config/database.local.php`. Editá este último con el host, usuario y contraseña **locales** de MySQL. En una instalación XAMPP nueva puede ser `root` sin contraseña, pero usá la configuración real de tu equipo.
5. Confirmá que `config/database.local.php` no aparece en `git status`: está excluido por `.gitignore`.
6. Abrí `http://localhost/duxs/` (no abras `index.html` como archivo `file:///`; PHP necesita Apache).

La API devuelve un mensaje claro si faltan la configuración, el esquema o el servicio MySQL. La configuración local no se guarda en Git.

## Excel y migración

La referencia revisada es `PLANILLA EGRESOS 2026 - VERSION 1.xlsx`. El esquema toma los catálogos de `DATOS` (categorías en A1:A26, centros de costo en A29:A42 y tipos de gasto en A46:A49). La hoja `TABLA DINAMICA` contiene etiquetas adicionales —incluidos pendientes de clasificación— que no se agregan como categorías válidas automáticamente.

Las filas reales todavía no se importan. El esquema contempla el detalle tributario y el origen de cada registro (`source_payload`, hoja y fila), pero no reproduce automáticamente las fórmulas del Excel. Antes de migrar, hay que conciliar `EGRESOS`, `BD DUXS`, `BD ENZO`, `PROVEEDORES` y `BASE DE DATOS`, verificar fórmulas, duplicados y clasificaciones que no coincidan con el catálogo, hacer una copia del libro y comparar cantidades y totales. No guardar la migración definitiva hasta que el equipo la apruebe.

## Funciones conectadas en esta etapa

- Consulta agregada para el dashboard y los reportes por mes y categoría, con importes de `Total Neto` como en `TABLA DINAMICA`; los gastos por tipo siguen la taxonomía `Tipo de Gasto` del Excel.
- Consultas de egresos, proveedores, clasificaciones y pendientes.
- Alta de egresos y proveedores con consultas preparadas, validación de servidor, token CSRF y registro de cambios.
- Posibles duplicados de comprobante se señalan antes de permitir una confirmación.

No hay autenticación de usuarios todavía. Mantené Apache/MySQL en el entorno local y no publiques la aplicación en Internet o en una red compartida hasta implementar inicio de sesión y permisos.
