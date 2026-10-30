# DUXS · Gestión de egresos

Aplicación local en PHP, MySQL, HTML, CSS y JavaScript. El navegador consulta la API PHP; solo el servidor abre la conexión PDO.

## Conectar la base `duxs_desde_csv`

1. Iniciá Apache y MySQL desde XAMPP.
2. Confirmá en phpMyAdmin que la base existente se llama `duxs_desde_csv` y contiene las tablas `comprobantes` y `proveedores`.
3. La configuración de ejemplo ya usa esa base. Copiá `config/database.example.php` como `config/database.local.php` y cambiá usuario, contraseña, host o puerto si tu MySQL local los requiere.
4. `config/database.local.php` está excluido por `.gitignore`; no lo agregues a Git.
5. Abrí el proyecto mediante Apache, no como archivo `file:///`. Si la carpeta está en `C:\xampp\htdocs\duxs`, usá `http://localhost/duxs/`. En este checkout, que está en `C:\xampp\htdocs\Proyectos\Proyecto-DUXS\DUXS`, usá `http://localhost/Proyectos/Proyecto-DUXS/DUXS/`.

**No importes `database/schema.sql` ni `database/migration_categories.sql` en `duxs_desde_csv`.** Esos archivos pertenecen al esquema anterior (`duxs_egresos`) y crearían estructuras distintas a las que ya importaste.

## Habilitar el login de administradores

1. En phpMyAdmin, seleccioná la base `duxs_desde_csv` y ejecutá `database/migration_admin_auth.sql`. Esta migración solo crea `admin_users`; no modifica los comprobantes ni crea usuarios por defecto.
2. Generá un hash seguro desde PowerShell o CMD con el PHP de XAMPP. El comando te pide la contraseña por entrada estándar para no dejarla escrita en el historial:

   ```text
   C:\xampp\php\php.exe -r "fwrite(STDOUT, 'Escribi la contrasena y presiona Enter: '); echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), PHP_EOL;"
   ```

3. Copiá el hash que imprime PHP y ejecutá en phpMyAdmin, sobre `duxs_desde_csv`, reemplazando el usuario, el nombre visible y el hash:

   ```sql
   INSERT INTO admin_users (username, display_name, password_hash)
   VALUES ('admin', 'Administrador', 'PEGAR_AQUI_EL_HASH_GENERADO');
   ```

   No guardes la contraseña en texto plano ni publiques el hash. Para dar de baja un acceso, ejecutá `UPDATE admin_users SET is_active = 0 WHERE username = 'admin';`.
4. Recargá la aplicación y entrá con el usuario y la contraseña que definiste. Todo el acceso a la API, incluidas las lecturas, requiere una sesión de un administrador activo. Las sesiones usan cookies `HttpOnly`, `SameSite=Strict`, regeneración del identificador y protección CSRF.

## Fichas y clasificación de proveedores

En phpMyAdmin, seleccioná `duxs_desde_csv` y ejecutá `database/migration_supplier_classifications.sql`. Crea `proveedor_clasificaciones`, relacionada con `proveedores`, para guardar varias combinaciones de centro de costo, categoría y tipo por proveedor. Los valores elegibles salen de los comprobantes existentes.

La pestaña Proveedores muestra la ficha, permite editar CUIT y razón social, y muestra las clasificaciones históricas encontradas en sus comprobantes. Si todavía no hay asignaciones guardadas, las combinaciones completas del historial se proponen como punto de partida; al guardarlas, quedan editables en `proveedor_clasificaciones`. Las asignaciones no reescriben comprobantes anteriores. En Pendientes se muestra el CUIT cuando se puede resolver por una coincidencia única de nombre; si no está en la base, “Ver / clasificar” abre el alta del proveedor para completar sus datos.

En Egresos, podés subir el CSV generado desde la hoja `BASE DE DATOS` del Excel del contador (guardalo como CSV UTF-8) o usar la plantilla CSV de la aplicación. No hace falta copiar las filas manualmente. El escaneo reconoce los encabezados de esa hoja, valida sus importes y fechas y compara con `comprobantes` por empresa, número, proveedor (si se indicó CUIT) y fecha; el resumen indica cuántas filas nuevas se agregarán y cuántas repetidas se omitirán. Las filas clasificadas como Sueldos pueden no tener CUIT ni número de comprobante: si tienen nombre y total, se importan como egresos sin CUIT, se toma el total de la planilla como neto cuando no hay desglose y se asigna un identificador interno determinista para evitar reimportar el mismo registro. Los resúmenes de Impuestos sin fecha ni número también se pueden importar si tienen nombre y total: se usa como fecha el día 1 del mes, con el año predominante de las demás fechas de la planilla, y se les asigna un identificador interno estable para evitar duplicados. El mes del período puede diferir del mes de la fecha, pero ambos deben pertenecer al mismo año. Al confirmar, agrega solo las nuevas, crea proveedores que no existan cuando la fila trae CUIT y razón social, y registra la combinación de clasificación del comprobante para el proveedor. Las filas sin CUIT válido no crean proveedores. Se admiten archivos CSV UTF-8 de hasta 5.000 filas y 800 KB. Esta base no conserva un historial separado de los archivos importados.

## Funciones conectadas a las columnas existentes

- Dashboard, períodos, egresos, búsqueda, filtros por empresa y reportes consultan `comprobantes`.
- El período se obtiene del año de `fecha` y el número de `mes`; las fechas de origen se interpretan como día/mes/año. Los registros sin fecha válida siguen visibles al listar todos los comprobantes, pero se excluyen de los indicadores mensuales y se informan en el dashboard. Los importes de texto se normalizan para admitir el formato argentino con punto de miles y coma decimal y los valores decimales simples.
- Los totales de comprobantes usan `total_original`; los gráficos y el reporte por categoría/tipo suman `total_neto`. Las categorías, los centros de costo y los tipos se derivan de sus valores reales en la tabla, no de catálogos inventados.
- Proveedores se listan y buscan en `proveedores`. Se pueden registrar y editar usando `cuit` y `razon_social`, con validación del dígito verificador y del índice único de CUIT.
- El formulario de comprobantes inserta campos existentes en `comprobantes`. Valida CUIT, fecha, período e importes, detecta duplicados probables y envía los cambios con CSRF. Como `mes` no conserva año, el año del período debe coincidir con el año de la fecha.
- “Pendientes de reclasificación” consulta `pendientes_reclasificacion` (`id`, `nombre_proveedor`, `estado`, `creado_en`), permite buscar por proveedor, filtrar por estado y alternar entre “Pendiente” y “Resuelto” con CSRF.
- Egresos y el resumen por categoría se pueden exportar como CSV.

## Límites del esquema importado

La tabla `pendientes_reclasificacion` solo permite gestionar el estado básico de reclasificación; la base no incluye reclamos con historial (`claims`, `claim_history`), catálogos independientes ni historial de importaciones. Las clasificaciones guardadas para proveedores se almacenan aparte y no actualizan comprobantes anteriores.

La aplicación permite acceso solo a cuentas creadas en `admin_users`. Usá HTTPS si la desplegás fuera del entorno local y mantené el PHP y MySQL actualizados.
