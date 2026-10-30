<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestData(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && strlen($raw) > 1048576) {
        respond(413, ['error' => 'La solicitud supera el límite permitido.']);
    }
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) {
        respond(400, ['error' => 'El cuerpo de la solicitud debe ser JSON válido.']);
    }

    return $data;
}

function requiredString(array $data, string $key, int $maxLength, string $label): string
{
    $raw = $data[$key] ?? '';
    if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
        respond(422, ['error' => "$label debe ser un valor de texto."]);
    }
    $value = trim((string) $raw);
    if ($value === '' || mb_strlen($value) > $maxLength) {
        respond(422, ['error' => "$label es obligatorio y admite hasta $maxLength caracteres."]);
    }

    return $value;
}

function optionalString(array $data, string $key, int $maxLength, string $label): ?string
{
    $raw = $data[$key] ?? '';
    if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
        respond(422, ['error' => "$label debe ser un valor de texto."]);
    }
    $value = trim((string) $raw);
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value) > $maxLength) {
        respond(422, ['error' => "$label admite hasta $maxLength caracteres."]);
    }

    return $value;
}

function validMonth(string $value): bool
{
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1
        && (int) substr($value, 0, 4) >= 1000;
}

function validDate(string $value): bool
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || (int) substr($value, 0, 4) < 1000) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function normalizedCuit(array $data, string $key = 'cuit'): ?string
{
    $value = $data[$key] ?? '';
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return null;
    }
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^\d{2}-?\d{8}-?\d$/', $raw) !== 1) {
        return null;
    }

    $cuit = preg_replace('/\D+/', '', $raw);
    if ($cuit === null || strlen($cuit) !== 11) {
        return null;
    }

    $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    $sum = 0;
    for ($index = 0; $index < 10; $index++) {
        $sum += (int) $cuit[$index] * $weights[$index];
    }
    $checkDigit = 11 - ($sum % 11);
    if ($checkDigit === 11) {
        $checkDigit = 0;
    } elseif ($checkDigit === 10) {
        $checkDigit = 9;
    }

    return (int) $cuit[10] === $checkDigit ? $cuit : null;
}

function moneyValue(array $data, string $key, string $label, bool $required): ?string
{
    $value = $data[$key] ?? '';
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        respond(422, ['error' => "$label debe ser un importe numérico válido."]);
    }
    $raw = trim((string) $value);
    if ($raw === '') {
        if ($required) {
            respond(422, ['error' => "$label es obligatorio."]);
        }
        return null;
    }

    $normalized = preg_replace('/[\s$]/u', '', $raw);
    if ($normalized === null || preg_match('/^-?\d[\d.,]*$/', $normalized) !== 1) {
        respond(422, ['error' => "$label debe ser un importe numérico válido."]);
    }

    if (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $normalized) === 1) {
        $normalized = str_replace([',', '.'], '', $normalized);
    }

    $lastComma = strrpos($normalized, ',');
    $lastDot = strrpos($normalized, '.');
    if ($lastComma !== false && $lastDot !== false) {
        $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
        $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
        $normalized = str_replace($thousandsSeparator, '', $normalized);
        $normalized = str_replace($decimalSeparator, '.', $normalized);
    } elseif ($lastComma !== false) {
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
    }

    if (preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalized) !== 1) {
        respond(422, ['error' => "$label admite hasta dos decimales."]);
    }

    if (str_starts_with($normalized, '-')) {
        respond(422, ['error' => "$label no puede ser negativo."]);
    }

    [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');
    $whole = ltrim($whole, '0');
    $whole = $whole === '' ? '0' : $whole;
    if (strlen($whole) > 13) {
        respond(422, ['error' => "$label supera el máximo permitido para MySQL."]);
    }

    return $whole . '.' . str_pad($fraction, 2, '0');
}

function moneyToCents(string $value): int
{
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '00');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
}

function findSupplierId(PDO $db, ?string $cuit): ?int
{
    if ($cuit === null) {
        return null;
    }

    $statement = $db->prepare('SELECT id FROM suppliers WHERE cuit = :cuit AND active = 1');
    $statement->execute(['cuit' => $cuit]);
    $id = $statement->fetchColumn();
    return $id === false ? null : (int) $id;
}

function positiveIdList(mixed $value, string $label, int $maxItems): array
{
    if (!is_array($value) || count($value) > $maxItems) {
        respond(422, ['error' => "La selección de $label no es válida."]);
    }
    $ids = [];
    foreach ($value as $item) {
        if (!is_string($item) && !is_int($item)) {
            respond(422, ['error' => "La selección de $label contiene un identificador no válido."]);
        }
        $id = filter_var($item, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            respond(422, ['error' => "La selección de $label contiene un identificador no válido."]);
        }
        $ids[] = (int) $id;
    }
    return array_values(array_unique($ids));
}

function positiveInteger(mixed $value, string $label): int
{
    if (!is_string($value) && !is_int($value)) {
        respond(422, ['error' => "El identificador de $label no es válido."]);
    }
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        respond(422, ['error' => "El identificador de $label no es válido."]);
    }
    return (int) $id;
}

function booleanValue(mixed $value, string $label): bool
{
    if (!is_bool($value) && !is_string($value) && !is_int($value)) {
        respond(422, ['error' => "El estado de $label no es válido."]);
    }
    $result = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($result === null) {
        respond(422, ['error' => "El estado de $label no es válido."]);
    }
    return $result;
}

try {
    $configFile = dirname(__DIR__) . '/config/database.php';
    if (!is_file($configFile)) {
        respond(503, ['error' => 'Falta configurar MySQL. Seguí los pasos del README.md.']);
    }
    if (!is_file(dirname(__DIR__) . '/config/database.local.php')) {
        respond(503, ['error' => 'Falta config/database.local.php. Copiá el archivo de ejemplo como indica README.md.']);
    }

    $db = require $configFile;
    if (!$db instanceof PDO) {
        throw new RuntimeException('La conexión PDO no se inicializó.');
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string) ($_GET['action'] ?? '');

    if ($method === 'GET' && $action === 'csrf') {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        respond(200, ['csrfToken' => $_SESSION['csrf_token']]);
    }

    if ($method === 'GET' && $action === 'auth.session') {
        $adminId = filter_var($_SESSION['admin_id'] ?? null, FILTER_VALIDATE_INT);
        if ($adminId === false || $adminId === null || $adminId < 1) {
            respond(200, ['authenticated' => false]);
        }

        $statement = $db->prepare(
            'SELECT id, username, display_name FROM admin_users WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['id' => $adminId]);
        $admin = $statement->fetch();
        if ($admin === false) {
            unset($_SESSION['admin_id']);
            respond(200, ['authenticated' => false]);
        }

        respond(200, [
            'authenticated' => true,
            'user' => [
                'username' => $admin['username'],
                'displayName' => $admin['display_name'],
            ],
        ]);
    }

    if ($method !== 'GET') {
        $providedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($providedToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $providedToken)) {
            respond(403, ['error' => 'La sesión de seguridad venció. Recargá la página e intentá de nuevo.']);
        }
    }

    if ($method === 'POST' && $action === 'auth.login') {
        $data = requestData();
        $username = requiredString($data, 'username', 100, 'El usuario');
        $password = $data['password'] ?? null;
        if (!is_string($password) || $password === '' || strlen($password) > 4096) {
            respond(422, ['error' => 'La contraseña no es válida.']);
        }

        $statement = $db->prepare(
            'SELECT id, username, display_name, password_hash
             FROM admin_users WHERE username = :username AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $admin = $statement->fetch();
        if ($admin === false || !password_verify($password, $admin['password_hash'])) {
            respond(401, ['error' => 'Usuario o contraseña incorrectos.']);
        }

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('No se pudo renovar la sesión al iniciar sesión.');
        }
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        respond(200, [
            'authenticated' => true,
            'csrfToken' => $_SESSION['csrf_token'],
            'user' => [
                'username' => $admin['username'],
                'displayName' => $admin['display_name'],
            ],
        ]);
    }

    if ($method === 'POST' && $action === 'auth.logout') {
        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('No se pudo renovar la sesión al cerrar sesión.');
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        respond(200, ['csrfToken' => $_SESSION['csrf_token']]);
    }

    $adminId = filter_var($_SESSION['admin_id'] ?? null, FILTER_VALIDATE_INT);
    if ($adminId === false || $adminId === null || $adminId < 1) {
        respond(401, ['error' => 'Iniciá sesión como administrador para continuar.']);
    }
    $statement = $db->prepare(
        'SELECT id FROM admin_users WHERE id = :id AND is_active = 1 LIMIT 1'
    );
    $statement->execute(['id' => $adminId]);
    if ($statement->fetchColumn() === false) {
        unset($_SESSION['admin_id']);
        respond(401, ['error' => 'La sesión de administrador ya no está activa.']);
    }

    if ($db->query('SELECT DATABASE()')->fetchColumn() === 'duxs_desde_csv') {
        require_once __DIR__ . '/csv-database.php';
        handleCsvDatabase($db, $method, $action);
    }

    if ($method === 'GET' && $action === 'bootstrap') {
        $month = (string) ($_GET['month'] ?? date('Y-m'));
        if (!validMonth($month)) {
            respond(422, ['error' => 'El período debe tener formato AAAA-MM.']);
        }
        $period = $month . '-01';

        $summary = $db->prepare(
            "SELECT
                (SELECT COUNT(*) FROM suppliers WHERE active = 1) AS suppliers_count,
                (SELECT COUNT(*) FROM expenses WHERE accounting_month = :period1) AS expenses_count,
                (SELECT COUNT(*) FROM claims WHERE status = 'pending') AS pending_count,
                (SELECT COUNT(*) FROM categories WHERE active = 1) AS categories_count,
                (SELECT COUNT(*) FROM cost_centers WHERE active = 1) AS cost_centers_count,
                (SELECT COUNT(*) FROM expense_types WHERE active = 1) AS expense_types_count,
                COALESCE((SELECT SUM(total_amount) FROM expenses WHERE accounting_month = :period2), 0) AS total_amount,
                COALESCE((SELECT SUM(net_amount) FROM expenses WHERE accounting_month = :period3), 0) AS total_net_amount"
        );
        $summary->execute(['period1' => $period, 'period2' => $period, 'period3' => $period]);
        $totals = $summary->fetch();

        $categoryQuery = $db->prepare(
            "SELECT COALESCE(cat.name, 'Sin categoría') AS name,
                    COALESCE(cat.id, 0) AS category_id,
                    SUM(COALESCE(e.net_amount, 0)) AS total
             FROM expenses e
             LEFT JOIN classifications cl ON cl.id = e.classification_id
             LEFT JOIN categories cat ON cat.id = COALESCE(e.category_id, cl.category_id)
             WHERE e.accounting_month = :period
             GROUP BY COALESCE(cat.id, 0), cat.name
             ORDER BY total DESC"
        );
        $categoryQuery->execute(['period' => $period]);

        $expenseTypeQuery = $db->prepare(
            "SELECT COALESCE(et.name, 'Sin tipo de gasto') AS name,
                    SUM(COALESCE(e.net_amount, 0)) AS total
             FROM expenses e
             LEFT JOIN classifications cl ON cl.id = e.classification_id
             LEFT JOIN expense_types et ON et.id = cl.expense_type_id
             WHERE e.accounting_month = :period
             GROUP BY COALESCE(et.id, 0), et.name
             ORDER BY total DESC"
        );
        $expenseTypeQuery->execute(['period' => $period]);

        $latestExpenses = $db->prepare(
            'SELECT e.id, e.issue_date, e.document_number, e.supplier_name,
                    e.supplier_cuit, e.total_amount, c.code AS company, cat.name AS category
             FROM expenses e
             JOIN companies c ON c.id = e.company_id
             LEFT JOIN classifications cl ON cl.id = e.classification_id
             LEFT JOIN categories cat ON cat.id = COALESCE(e.category_id, cl.category_id)
             WHERE e.accounting_month = :period
             ORDER BY e.issue_date DESC, e.id DESC
             LIMIT 5'
        );
        $latestExpenses->execute(['period' => $period]);

        $latestClaims = $db->query(
            "SELECT id, created_at, supplier_name, supplier_cuit, amount, reason, status
             FROM claims
             WHERE status = 'pending'
             ORDER BY created_at DESC
             LIMIT 5"
        );

        respond(200, [
            'period' => $month,
            'summary' => $totals,
            'categories' => $categoryQuery->fetchAll(),
            'expense_types' => $expenseTypeQuery->fetchAll(),
            'expenses' => $latestExpenses->fetchAll(),
            'claims' => $latestClaims->fetchAll(),
        ]);
    }

    if ($method === 'GET' && $action === 'expenses') {
        $month = (string) ($_GET['month'] ?? '');
        $query = trim((string) ($_GET['q'] ?? ''));
        if ($month !== '' && !validMonth($month)) {
            respond(422, ['error' => 'El período debe tener formato AAAA-MM.']);
        }
        if (mb_strlen($query) > 120) {
            respond(422, ['error' => 'La búsqueda admite hasta 120 caracteres.']);
        }

        $sql = "SELECT e.id, e.accounting_month, e.issue_date, e.document_number,
                       e.supplier_cuit, e.supplier_name, e.net_amount, e.vat_total,
                       e.perceptions_total, e.other_taxes_total, e.total_amount,
                       e.observations, c.code AS company, cat.name AS category,
                       cc.name AS cost_center, et.name AS expense_type
                FROM expenses e
                JOIN companies c ON c.id = e.company_id
                LEFT JOIN classifications cl ON cl.id = e.classification_id
                LEFT JOIN categories cat ON cat.id = COALESCE(e.category_id, cl.category_id)
                LEFT JOIN cost_centers cc ON cc.id = cl.cost_center_id
                LEFT JOIN expense_types et ON et.id = cl.expense_type_id
                WHERE (:month_filter = '' OR e.accounting_month = :period)
                  AND (:query_filter = '' OR e.document_number LIKE :match1
                       OR e.supplier_name LIKE :match2 OR e.supplier_cuit LIKE :match3)
                ORDER BY e.accounting_month DESC, e.issue_date DESC, e.id DESC
                LIMIT 250";
        $statement = $db->prepare($sql);
        $statement->execute([
            'month_filter' => $month,
            'period' => $month === '' ? null : $month . '-01',
            'query_filter' => $query,
            'match1' => '%' . $query . '%',
            'match2' => '%' . $query . '%',
            'match3' => '%' . $query . '%',
        ]);
        respond(200, ['items' => $statement->fetchAll()]);
    }

    if ($method === 'GET' && $action === 'suppliers') {
        $query = trim((string) ($_GET['q'] ?? ''));
        $statement = $db->prepare(
            "SELECT s.id, s.cuit, s.legal_name, s.trade_name, s.active,
                    GROUP_CONCAT(DISTINCT cat.name ORDER BY cat.name SEPARATOR ' · ') AS categories,
                    GROUP_CONCAT(DISTINCT sc.classification_id ORDER BY sc.classification_id) AS classification_ids,
                    MAX(sc.is_default) AS has_default
             FROM suppliers s
             LEFT JOIN supplier_classifications sc ON sc.supplier_id = s.id
             LEFT JOIN classifications cl ON cl.id = sc.classification_id
             LEFT JOIN categories cat ON cat.id = cl.category_id
             WHERE (:query_filter = '' OR s.legal_name LIKE :match1 OR s.trade_name LIKE :match2 OR s.cuit LIKE :match3)
             GROUP BY s.id
             ORDER BY s.active DESC, s.legal_name
             LIMIT 250"
        );
        $statement->execute([
            'query_filter' => $query,
            'match1' => '%' . $query . '%',
            'match2' => '%' . $query . '%',
            'match3' => '%' . $query . '%',
        ]);
        respond(200, ['items' => $statement->fetchAll()]);
    }

    if ($method === 'GET' && $action === 'classifications') {
        $statement = $db->query(
            'SELECT cl.id, cc.name AS cost_center, cat.name AS category,
                    et.name AS expense_type, cl.account_code, cl.afip_code,
                    cl.active, COUNT(DISTINCT sc.supplier_id) AS suppliers_count
             FROM classifications cl
             LEFT JOIN cost_centers cc ON cc.id = cl.cost_center_id
             LEFT JOIN categories cat ON cat.id = cl.category_id
             LEFT JOIN expense_types et ON et.id = cl.expense_type_id
             LEFT JOIN supplier_classifications sc ON sc.classification_id = cl.id
             GROUP BY cl.id
             ORDER BY cat.name, cc.name
             LIMIT 500'
        );
        respond(200, ['items' => $statement->fetchAll()]);
    }

    if ($method === 'GET' && $action === 'claims') {
        $statement = $db->query(
            'SELECT cl.id, cl.created_at, cl.period, cl.document_number,
                    cl.supplier_cuit, cl.supplier_name, cl.amount, cl.reason,
                    cl.observations, cl.status, c.code AS company
             FROM claims cl
             LEFT JOIN companies c ON c.id = cl.company_id
             ORDER BY (cl.status = "pending") DESC, cl.created_at DESC
             LIMIT 250'
        );
        respond(200, ['items' => $statement->fetchAll()]);
    }

    if ($method === 'GET' && $action === 'claim.history') {
        $id = positiveInteger($_GET['id'] ?? null, 'reclamo');
        $statement = $db->prepare(
            'SELECT id, action, details, created_at
             FROM claim_history WHERE claim_id = :claim_id ORDER BY created_at, id'
        );
        $statement->execute(['claim_id' => $id]);
        respond(200, ['items' => $statement->fetchAll()]);
    }

    if ($method === 'GET' && $action === 'imports') {
        $statement = $db->query(
            'SELECT id, filename, status, total_rows, imported_rows, rejected_rows, created_at, completed_at
             FROM imports ORDER BY created_at DESC, id DESC LIMIT 100'
        );
        respond(200, ['items' => $statement->fetchAll()]);
    }

    if ($method === 'GET' && $action === 'catalogs') {
        $catalogManagement = [];
        foreach ([
            'category' => ['categories', 'Categoría'],
            'cost_center' => ['cost_centers', 'Centro de costo'],
            'expense_type' => ['expense_types', 'Tipo de gasto'],
        ] as $type => [$table, $label]) {
            $items = $db->query("SELECT id, name, active FROM $table ORDER BY name")->fetchAll();
            foreach ($items as $item) {
                $catalogManagement[] = [
                    'type' => $type,
                    'label' => $label,
                    'id' => (int) $item['id'],
                    'name' => $item['name'],
                    'active' => (int) $item['active'],
                ];
            }
        }
        respond(200, [
            'companies' => $db->query('SELECT id, code, name FROM companies WHERE active = 1 ORDER BY name')->fetchAll(),
            'costCenters' => $db->query('SELECT id, name FROM cost_centers WHERE active = 1 ORDER BY name')->fetchAll(),
            'categories' => $db->query('SELECT id, name FROM categories WHERE active = 1 ORDER BY name')->fetchAll(),
            'expenseTypes' => $db->query('SELECT id, name FROM expense_types WHERE active = 1 ORDER BY name')->fetchAll(),
            'classifications' => $db->query(
                'SELECT cl.id, cc.name AS cost_center, cat.name AS category, et.name AS expense_type
                 FROM classifications cl
                 LEFT JOIN cost_centers cc ON cc.id = cl.cost_center_id
                 LEFT JOIN categories cat ON cat.id = cl.category_id
                 LEFT JOIN expense_types et ON et.id = cl.expense_type_id
                 WHERE cl.active = 1 ORDER BY cat.name, cc.name, et.name'
            )->fetchAll(),
            'suppliers' => $db->query(
                'SELECT id, cuit, legal_name FROM suppliers WHERE active = 1 ORDER BY legal_name'
            )->fetchAll(),
            'catalogManagement' => $catalogManagement,
        ]);
    }

    if ($method === 'GET' && $action === 'periods') {
        $periods = $db->query(
            "SELECT DISTINCT DATE_FORMAT(accounting_month, '%Y-%m') AS period
             FROM expenses
             ORDER BY period DESC
             LIMIT 120"
        )->fetchAll(PDO::FETCH_COLUMN);
        $currentPeriod = date('Y-m');
        if (!in_array($currentPeriod, $periods, true)) {
            array_unshift($periods, $currentPeriod);
        }
        respond(200, ['items' => $periods]);
    }

    if ($method === 'POST') {
        $data = requestData();

        if ($action === 'expense.create') {
            $company = requiredString($data, 'company', 20, 'Titular');
            $month = requiredString($data, 'month', 7, 'Mes');
            $issueDate = requiredString($data, 'issue_date', 10, 'Fecha');
            $document = requiredString($data, 'document_number', 100, 'Comprobante');
            if (!validMonth($month) || !validDate($issueDate)) {
                respond(422, ['error' => 'Revisá el mes y la fecha del comprobante.']);
            }

            $companyQuery = $db->prepare('SELECT id FROM companies WHERE code = :company AND active = 1');
            $companyQuery->execute(['company' => strtoupper($company)]);
            $companyId = $companyQuery->fetchColumn();
            if ($companyId === false) {
                respond(422, ['error' => 'El titular debe ser DUXS o ENZO.']);
            }

            $supplierCuitValue = $data['supplier_cuit'] ?? '';
            if (!is_string($supplierCuitValue) && !is_int($supplierCuitValue) && !is_float($supplierCuitValue)) {
                respond(422, ['error' => 'El CUIT del proveedor no es válido.']);
            }
            $supplierCuitRaw = trim((string) $supplierCuitValue);
            $supplierCuit = $supplierCuitRaw === '' ? null : normalizedCuit($data, 'supplier_cuit');
            if ($supplierCuitRaw !== '' && $supplierCuit === null) {
                respond(422, ['error' => 'El CUIT no es válido. Verificá sus 11 dígitos y dígito verificador.']);
            }

            $duplicate = $db->prepare(
                'SELECT id FROM expenses
                 WHERE company_id = :company_id AND document_number = :document_number
                   AND (:cuit IS NULL OR supplier_cuit = :supplier_cuit)
                 LIMIT 1'
            );
            $duplicate->execute([
                'company_id' => (int) $companyId,
                'document_number' => $document,
                'cuit' => $supplierCuit,
                'supplier_cuit' => $supplierCuit,
            ]);
            if ($duplicate->fetchColumn() !== false && empty($data['confirm_duplicate'])) {
                respond(409, [
                    'code' => 'possible_duplicate',
                    'error' => 'Ya hay un comprobante con ese número para esta empresa. Confirmá si querés registrar igualmente.',
                ]);
            }

            $supplierName = optionalString($data, 'supplier_name', 255, 'Proveedor');
            $supplierId = findSupplierId($db, $supplierCuit);
            $costCenter = optionalString($data, 'cost_center', 150, 'Centro de costo');
            $category = optionalString($data, 'category', 150, 'Categoría');
            $expenseType = optionalString($data, 'expense_type', 150, 'Tipo de gasto');
            $categoryId = null;
            if ($category !== null) {
                $categoryQuery = $db->prepare('SELECT id FROM categories WHERE name = :name AND active = 1');
                $categoryQuery->execute(['name' => $category]);
                $foundCategory = $categoryQuery->fetchColumn();
                if ($foundCategory === false) {
                    respond(422, ['error' => 'Seleccioná una categoría habilitada del catálogo.']);
                }
                $categoryId = (int) $foundCategory;
            }
            $costCenterId = null;
            if ($costCenter !== null) {
                $costCenterQuery = $db->prepare('SELECT id FROM cost_centers WHERE name = :name AND active = 1');
                $costCenterQuery->execute(['name' => $costCenter]);
                $foundCostCenter = $costCenterQuery->fetchColumn();
                if ($foundCostCenter === false) {
                    respond(422, ['error' => 'Seleccioná un centro de costo habilitado del catálogo.']);
                }
                $costCenterId = (int) $foundCostCenter;
            }
            $expenseTypeId = null;
            if ($expenseType !== null) {
                $expenseTypeQuery = $db->prepare('SELECT id FROM expense_types WHERE name = :name AND active = 1');
                $expenseTypeQuery->execute(['name' => $expenseType]);
                $foundExpenseType = $expenseTypeQuery->fetchColumn();
                if ($foundExpenseType === false) {
                    respond(422, ['error' => 'Seleccioná un tipo de gasto habilitado del catálogo.']);
                }
                $expenseTypeId = (int) $foundExpenseType;
            }
            $classificationId = null;
            $net = moneyValue($data, 'net_amount', 'Total Neto', false);
            $vat = moneyValue($data, 'vat_total', 'Total IVA', false);
            $perceptions = moneyValue($data, 'perceptions_total', 'Percepciones', false);
            $otherTaxes = moneyValue($data, 'other_taxes_total', 'Otros impuestos', false);
            $total = moneyValue($data, 'total_amount', 'Total', true);
            if ($net !== null && $vat !== null && $perceptions !== null && $otherTaxes !== null
                && abs(moneyToCents($net) + moneyToCents($vat) + moneyToCents($perceptions)
                    + moneyToCents($otherTaxes) - moneyToCents($total)) > 1) {
                respond(422, ['error' => 'El total no coincide con la suma de neto, IVA, percepciones y otros impuestos.']);
            }
            $ivaCondition = optionalString($data, 'iva_condition', 100, 'Condición IVA');
            $jurisdiction = optionalString($data, 'jurisdiction', 100, 'Jurisdicción');
            $afipCode = optionalString($data, 'afip_code', 50, 'Código AFIP');
            $accountCode = optionalString($data, 'account_code', 100, 'Cuenta contable');
            $taxCredit = optionalString($data, 'tax_credit', 150, 'Crédito fiscal');
            $taxLabels = [
                'gravado-21' => ['gravado_21', 'Gravado al 21%'],
                'gravado-105' => ['gravado_105', 'Gravado al 10,5%'],
                'gravado-27' => ['gravado_27', 'Gravado al 27%'],
                'gravado-5' => ['gravado_5', 'Gravado al 5%'],
                'gravado-25' => ['gravado_25', 'Gravado al 2,5%'],
                'exento' => ['exento', 'Exento'],
                'no-gravado' => ['no_gravado', 'No gravado'],
                'iva-21' => ['iva_21', 'IVA 21%'],
                'iva-105' => ['iva_105', 'IVA 10,5%'],
                'iva-27' => ['iva_27', 'IVA 27%'],
                'iva-5' => ['iva_5', 'IVA 5%'],
                'iva-25' => ['iva_25', 'IVA 2,5%'],
                'retenciones' => ['retenciones', 'Retenciones'],
                'percepcion-iva' => ['percepcion_iva', 'Percepción IVA'],
                'percepcion-iibb' => ['percepcion_iibb', 'Percepción IIBB'],
                'percepcion-ganancias' => ['percepcion_ganancias', 'Percepción de Ganancias'],
                'impuesto-municipal' => ['impuesto_municipal', 'Impuesto municipal'],
                'impuesto-interno' => ['impuesto_interno', 'Impuesto interno'],
                'itc' => ['itc', 'ITC'],
                'otros-tributos' => ['otros_tributos', 'Otros tributos'],
                'iva-no-computable' => ['iva_no_computable', 'IVA no computable'],
            ];
            $taxDetails = [];
            $submittedTaxDetails = is_array($data['tax_details'] ?? null) ? $data['tax_details'] : [];
            foreach ($taxLabels as $inputName => [$code, $label]) {
                if (trim((string) ($submittedTaxDetails[$inputName] ?? '')) === '') {
                    continue;
                }
                $taxDetails[] = [
                    'code' => $code,
                    'label' => $label,
                    'amount' => moneyValue([$inputName => $submittedTaxDetails[$inputName]], $inputName, $label, true),
                ];
            }

            $db->beginTransaction();
            if ($costCenterId !== null || $categoryId !== null || $expenseTypeId !== null) {
                $classificationQuery = $db->prepare(
                    'SELECT id FROM classifications
                     WHERE cost_center_id <=> :cost_center_id
                       AND category_id <=> :category_id
                       AND expense_type_id <=> :expense_type_id
                       AND account_code IS NULL
                       AND active = 1
                     LIMIT 1'
                );
                $classificationQuery->execute([
                    'cost_center_id' => $costCenterId,
                    'category_id' => $categoryId,
                    'expense_type_id' => $expenseTypeId,
                ]);
                $foundClassification = $classificationQuery->fetchColumn();
                if ($foundClassification === false) {
                    $createClassification = $db->prepare(
                        'INSERT INTO classifications (cost_center_id, category_id, expense_type_id)
                         VALUES (:cost_center_id, :category_id, :expense_type_id)'
                    );
                    $createClassification->execute([
                        'cost_center_id' => $costCenterId,
                        'category_id' => $categoryId,
                        'expense_type_id' => $expenseTypeId,
                    ]);
                    $classificationId = (int) $db->lastInsertId();
                } else {
                    $classificationId = (int) $foundClassification;
                }
            }

            $statement = $db->prepare(
                'INSERT INTO expenses
                    (company_id, supplier_id, classification_id, category_id, accounting_month, issue_date, document_number,
                     supplier_cuit, supplier_name, net_amount, vat_total, perceptions_total,
                     other_taxes_total, total_amount, observations, iva_condition, jurisdiction,
                     afip_code, account_code, tax_credit, expense_label, source_sheet, source_payload)
                 VALUES
                    (:company_id, :supplier_id, :classification_id, :category_id, :accounting_month, :issue_date, :document_number,
                     :supplier_cuit, :supplier_name, :net_amount, :vat_total, :perceptions_total,
                     :other_taxes_total, :total_amount, :observations, :iva_condition, :jurisdiction,
                     :afip_code, :account_code, :tax_credit, :expense_label, "Web", :source_payload)'
            );

            $statement->execute([
                'company_id' => (int) $companyId,
                'supplier_id' => $supplierId,
                'classification_id' => $classificationId,
                'category_id' => $categoryId,
                'accounting_month' => $month . '-01',
                'issue_date' => $issueDate,
                'document_number' => $document,
                'supplier_cuit' => $supplierCuit,
                'supplier_name' => $supplierName,
                'net_amount' => $net,
                'vat_total' => $vat,
                'perceptions_total' => $perceptions,
                'other_taxes_total' => $otherTaxes,
                'total_amount' => $total,
                'observations' => optionalString($data, 'observations', 4000, 'Observaciones'),
                'iva_condition' => $ivaCondition,
                'jurisdiction' => $jurisdiction,
                'afip_code' => $afipCode,
                'account_code' => $accountCode,
                'tax_credit' => $taxCredit,
                'expense_label' => optionalString($data, 'expense_label', 150, 'Tipo'),
                'source_payload' => json_encode([
                    'cost_center' => $costCenter,
                    'category' => $category,
                    'expense_type' => $expenseType,
                    'tax_details' => is_array($data['tax_details'] ?? null) ? $data['tax_details'] : [],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $expenseId = (int) $db->lastInsertId();
            if ($taxDetails !== []) {
                $taxStatement = $db->prepare(
                    'INSERT INTO expense_tax_details (expense_id, component_code, component_label, amount)
                     VALUES (:expense_id, :component_code, :component_label, :amount)'
                );
                foreach ($taxDetails as $detail) {
                    $taxStatement->execute([
                        'expense_id' => $expenseId,
                        'component_code' => $detail['code'],
                        'component_label' => $detail['label'],
                        'amount' => $detail['amount'],
                    ]);
                }
            }
            $history = $db->prepare(
                'INSERT INTO change_history (entity_type, entity_id, action, details)
                 VALUES ("expense", :entity_id, "created", :details)'
            );
            $history->execute([
                'entity_id' => $expenseId,
                'details' => json_encode(['company' => strtoupper($company), 'document_number' => $document], JSON_UNESCAPED_UNICODE),
            ]);
            $db->commit();

            respond(201, ['id' => $expenseId, 'message' => 'Egreso guardado en MySQL.']);
        }

        if ($action === 'supplier.create') {
            $rawCuit = requiredString($data, 'cuit', 20, 'CUIT');
            $cuit = normalizedCuit(['cuit' => $rawCuit]);
            if ($cuit === null) {
                respond(422, ['error' => 'El CUIT no es válido. Verificá sus 11 dígitos y dígito verificador.']);
            }
            $legalName = requiredString($data, 'legal_name', 255, 'Razón social');
            $tradeName = optionalString($data, 'trade_name', 255, 'Nombre comercial');
            $classificationIds = positiveIdList($data['classification_ids'] ?? [], 'clasificaciones', 100);
            $db->beginTransaction();
            if ($classificationIds !== []) {
                $placeholders = implode(',', array_fill(0, count($classificationIds), '?'));
                $check = $db->prepare(
                    "SELECT COUNT(*) FROM classifications WHERE active = 1 AND id IN ($placeholders)"
                );
                $check->execute($classificationIds);
                if ((int) $check->fetchColumn() !== count($classificationIds)) {
                    respond(422, ['error' => 'Una o más clasificaciones no están habilitadas.']);
                }
            }
            try {
                $statement = $db->prepare(
                    'INSERT INTO suppliers (cuit, legal_name, trade_name)
                     VALUES (:cuit, :legal_name, :trade_name)'
                );
                $statement->execute(['cuit' => $cuit, 'legal_name' => $legalName, 'trade_name' => $tradeName]);
            } catch (PDOException $error) {
                if ($error->getCode() === '23000') {
                    respond(409, ['error' => 'Ya existe un proveedor con ese CUIT.']);
                }
                throw $error;
            }
            $supplierId = (int) $db->lastInsertId();
            if ($classificationIds !== []) {
                $link = $db->prepare(
                    'INSERT INTO supplier_classifications (supplier_id, classification_id, is_default)
                     VALUES (:supplier_id, :classification_id, :is_default)'
                );
                foreach ($classificationIds as $index => $classificationId) {
                    $link->execute([
                        'supplier_id' => $supplierId,
                        'classification_id' => $classificationId,
                        'is_default' => $index === 0 ? 1 : 0,
                    ]);
                }
            }
            $history = $db->prepare(
                'INSERT INTO change_history (entity_type, entity_id, action, details)
                 VALUES ("supplier", :entity_id, "created", :details)'
            );
            $history->execute([
                'entity_id' => $supplierId,
                'details' => json_encode(['cuit' => $cuit, 'legal_name' => $legalName], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $db->commit();
            respond(201, ['id' => $supplierId, 'message' => 'Proveedor guardado.']);
        }

        if ($action === 'supplier.update') {
            $id = positiveInteger($data['id'] ?? null, 'proveedor');
            $cuit = normalizedCuit($data);
            if ($cuit === null) {
                respond(422, ['error' => 'El CUIT no es válido. Verificá sus 11 dígitos y dígito verificador.']);
            }
            $legalName = requiredString($data, 'legal_name', 255, 'Razón social');
            $tradeName = optionalString($data, 'trade_name', 255, 'Nombre comercial');
            $active = booleanValue($data['active'] ?? null, 'proveedor');
            $classificationIds = positiveIdList($data['classification_ids'] ?? [], 'clasificaciones', 100);
            $db->beginTransaction();
            $checkSupplier = $db->prepare('SELECT id FROM suppliers WHERE id = :id FOR UPDATE');
            $checkSupplier->execute(['id' => $id]);
            if ($checkSupplier->fetchColumn() === false) {
                respond(404, ['error' => 'No se encontró el proveedor.']);
            }
            $checkDuplicate = $db->prepare('SELECT id FROM suppliers WHERE cuit = :cuit AND id <> :id');
            $checkDuplicate->execute(['cuit' => $cuit, 'id' => $id]);
            if ($checkDuplicate->fetchColumn() !== false) {
                respond(409, ['error' => 'Ya existe otro proveedor con ese CUIT.']);
            }
            if ($classificationIds !== []) {
                $placeholders = implode(',', array_fill(0, count($classificationIds), '?'));
                $check = $db->prepare(
                    "SELECT COUNT(*) FROM classifications WHERE active = 1 AND id IN ($placeholders)"
                );
                $check->execute($classificationIds);
                if ((int) $check->fetchColumn() !== count($classificationIds)) {
                    respond(422, ['error' => 'Una o más clasificaciones no están habilitadas.']);
                }
            }
            $update = $db->prepare(
                'UPDATE suppliers SET cuit = :cuit, legal_name = :legal_name,
                    trade_name = :trade_name, active = :active WHERE id = :id'
            );
            $update->execute([
                'cuit' => $cuit, 'legal_name' => $legalName, 'trade_name' => $tradeName,
                'active' => $active ? 1 : 0, 'id' => $id,
            ]);
            $db->prepare('DELETE FROM supplier_classifications WHERE supplier_id = :id')->execute(['id' => $id]);
            if ($classificationIds !== []) {
                $link = $db->prepare(
                    'INSERT INTO supplier_classifications (supplier_id, classification_id, is_default)
                     VALUES (:supplier_id, :classification_id, :is_default)'
                );
                foreach ($classificationIds as $index => $classificationId) {
                    $link->execute([
                        'supplier_id' => $id,
                        'classification_id' => $classificationId,
                        'is_default' => $index === 0 ? 1 : 0,
                    ]);
                }
            }
            $history = $db->prepare(
                'INSERT INTO change_history (entity_type, entity_id, action, details)
                 VALUES ("supplier", :entity_id, "updated", :details)'
            );
            $history->execute([
                'entity_id' => $id,
                'details' => json_encode(['cuit' => $cuit, 'legal_name' => $legalName, 'active' => $active], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $db->commit();
            respond(200, ['message' => 'Proveedor actualizado.']);
        }

        if ($action === 'catalog.create') {
            $catalogs = [
                'category' => ['categories', 'Categoría'],
                'cost_center' => ['cost_centers', 'Centro de costo'],
                'expense_type' => ['expense_types', 'Tipo de gasto'],
            ];
            $type = (string) ($data['type'] ?? '');
            if (!isset($catalogs[$type])) {
                respond(422, ['error' => 'El tipo de catálogo no es válido.']);
            }
            [$table, $label] = $catalogs[$type];
            $name = requiredString($data, 'name', 150, $label);
            try {
                $statement = $db->prepare("INSERT INTO $table (name) VALUES (:name)");
                $statement->execute(['name' => $name]);
            } catch (PDOException $error) {
                if ($error->getCode() === '23000') {
                    respond(409, ['error' => "Ya existe ese registro en $label."]);
                }
                throw $error;
            }
            respond(201, ['id' => (int) $db->lastInsertId(), 'message' => "$label agregado."]);
        }

        if ($action === 'catalog.update') {
            $catalogs = [
                'category' => ['categories', 'Categoría'],
                'cost_center' => ['cost_centers', 'Centro de costo'],
                'expense_type' => ['expense_types', 'Tipo de gasto'],
            ];
            $type = (string) ($data['type'] ?? '');
            if (!isset($catalogs[$type])) {
                respond(422, ['error' => 'El tipo de catálogo no es válido.']);
            }
            [$table, $label] = $catalogs[$type];
            $id = positiveInteger($data['id'] ?? null, 'catálogo');
            $name = requiredString($data, 'name', 150, $label);
            $exists = $db->prepare("SELECT id FROM $table WHERE id = :id");
            $exists->execute(['id' => $id]);
            if ($exists->fetchColumn() === false) {
                respond(404, ['error' => "No se encontró el elemento de $label."]);
            }
            $duplicate = $db->prepare("SELECT id FROM $table WHERE name = :name AND id <> :id");
            $duplicate->execute(['name' => $name, 'id' => $id]);
            if ($duplicate->fetchColumn() !== false) {
                respond(409, ['error' => "Ya existe otro elemento con ese nombre en $label."]);
            }
            $update = $db->prepare("UPDATE $table SET name = :name WHERE id = :id");
            $update->execute(['name' => $name, 'id' => $id]);
            respond(200, ['message' => "$label actualizado."]);
        }

        if ($action === 'catalog.toggle') {
            $catalogs = [
                'category' => 'categories',
                'cost_center' => 'cost_centers',
                'expense_type' => 'expense_types',
            ];
            $type = (string) ($data['type'] ?? '');
            if (!isset($catalogs[$type])) {
                respond(422, ['error' => 'El tipo de catálogo no es válido.']);
            }
            $id = positiveInteger($data['id'] ?? null, 'catálogo');
            $active = booleanValue($data['active'] ?? null, 'catálogo');
            $statement = $db->prepare('UPDATE ' . $catalogs[$type] . ' SET active = :active WHERE id = :id');
            $statement->execute(['active' => $active ? 1 : 0, 'id' => $id]);
            if ($statement->rowCount() !== 1) {
                respond(404, ['error' => 'No se encontró el elemento o no hubo cambios.']);
            }
            respond(200, ['message' => $active ? 'Elemento del catálogo activado.' : 'Elemento del catálogo desactivado.']);
        }

        if ($action === 'classification.create') {
            $ids = [];
            foreach (['cost_center_id', 'category_id', 'expense_type_id'] as $field) {
                $value = $data[$field] ?? null;
                if ($value !== '' && $value !== null && !is_string($value) && !is_int($value)) {
                    respond(422, ['error' => 'La clasificación seleccionada no es válida.']);
                }
                $id = $value === '' || $value === null ? null : filter_var($value, FILTER_VALIDATE_INT);
                if ($id === false || ($id !== null && $id < 1)) {
                    respond(422, ['error' => 'La clasificación seleccionada no es válida.']);
                }
                $ids[$field] = $id;
            }
            if ($ids['cost_center_id'] === null && $ids['category_id'] === null && $ids['expense_type_id'] === null) {
                respond(422, ['error' => 'Seleccioná al menos un elemento del catálogo.']);
            }
            $accountCode = optionalString($data, 'account_code', 100, 'Cuenta contable');
            foreach ([
                'cost_center_id' => 'cost_centers',
                'category_id' => 'categories',
                'expense_type_id' => 'expense_types',
            ] as $field => $table) {
                if ($ids[$field] !== null) {
                    $check = $db->prepare("SELECT id FROM $table WHERE id = :id AND active = 1");
                    $check->execute(['id' => $ids[$field]]);
                    if ($check->fetchColumn() === false) {
                        respond(422, ['error' => 'Seleccioná solo elementos habilitados del catálogo.']);
                    }
                }
            }
            $duplicate = $db->prepare(
                'SELECT id FROM classifications
                 WHERE cost_center_id <=> :cost_center_id AND category_id <=> :category_id
                   AND expense_type_id <=> :expense_type_id AND account_code <=> :account_code
                 LIMIT 1'
            );
            $duplicate->execute([
                'cost_center_id' => $ids['cost_center_id'],
                'category_id' => $ids['category_id'],
                'expense_type_id' => $ids['expense_type_id'],
                'account_code' => $accountCode,
            ]);
            if ($duplicate->fetchColumn() !== false) {
                respond(409, ['error' => 'Esa combinación de clasificación ya existe.']);
            }
            $supplierIds = positiveIdList($data['supplier_ids'] ?? [], 'proveedores', 500);
            if ($supplierIds !== []) {
                $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
                $checkSuppliers = $db->prepare(
                    "SELECT COUNT(*) FROM suppliers WHERE active = 1 AND id IN ($placeholders)"
                );
                $checkSuppliers->execute($supplierIds);
                if ((int) $checkSuppliers->fetchColumn() !== count($supplierIds)) {
                    respond(422, ['error' => 'Uno o más proveedores no están habilitados.']);
                }
            }
            $db->beginTransaction();
            $statement = $db->prepare(
                'INSERT INTO classifications (cost_center_id, category_id, expense_type_id, account_code)
                 VALUES (:cost_center_id, :category_id, :expense_type_id, :account_code)'
            );
            $statement->execute([
                'cost_center_id' => $ids['cost_center_id'], 'category_id' => $ids['category_id'],
                'expense_type_id' => $ids['expense_type_id'], 'account_code' => $accountCode,
            ]);
            $classificationId = (int) $db->lastInsertId();
            if ($supplierIds !== []) {
                $hasDefault = $db->prepare(
                    'SELECT 1 FROM supplier_classifications
                     WHERE supplier_id = :supplier_id AND is_default = 1 LIMIT 1'
                );
                $link = $db->prepare(
                    'INSERT INTO supplier_classifications (supplier_id, classification_id, is_default)
                     VALUES (:supplier_id, :classification_id, :is_default)'
                );
                foreach ($supplierIds as $supplierId) {
                    $hasDefault->execute(['supplier_id' => $supplierId]);
                    $link->execute([
                        'supplier_id' => $supplierId,
                        'classification_id' => $classificationId,
                        'is_default' => $hasDefault->fetchColumn() === false ? 1 : 0,
                    ]);
                }
            }
            $db->commit();
            respond(201, ['id' => $classificationId, 'message' => 'Clasificación agregada.']);
        }

        if ($action === 'classification.toggle') {
            $idValue = $data['id'] ?? null;
            $active = booleanValue($data['active'] ?? null, 'clasificación');
            $id = positiveInteger($idValue, 'clasificación');
            $statement = $db->prepare('UPDATE classifications SET active = :active WHERE id = :id');
            $statement->execute(['active' => $active ? 1 : 0, 'id' => $id]);
            if ($statement->rowCount() !== 1) {
                respond(404, ['error' => 'No se encontró la clasificación o no hubo cambios.']);
            }
            respond(200, ['message' => $active ? 'Clasificación activada.' : 'Clasificación desactivada.']);
        }

        if ($action === 'claim.create') {
            $supplierName = requiredString($data, 'supplier_name', 255, 'Proveedor');
            $reason = requiredString($data, 'reason', 255, 'Motivo');
            $rawCuitValue = $data['supplier_cuit'] ?? '';
            if (!is_string($rawCuitValue) && !is_int($rawCuitValue) && !is_float($rawCuitValue)) {
                respond(422, ['error' => 'El CUIT no es válido.']);
            }
            $rawCuit = trim((string) $rawCuitValue);
            $cuit = $rawCuit === '' ? null : normalizedCuit($data, 'supplier_cuit');
            if ($rawCuit !== '' && $cuit === null) {
                respond(422, ['error' => 'El CUIT no es válido. Verificá sus 11 dígitos y dígito verificador.']);
            }
            $period = optionalString($data, 'period', 7, 'Período');
            if ($period !== null && !validMonth($period)) {
                respond(422, ['error' => 'El período debe tener formato AAAA-MM.']);
            }
            $companyCode = optionalString($data, 'company', 20, 'Empresa');
            $companyId = null;
            if ($companyCode !== null) {
                $companyQuery = $db->prepare('SELECT id FROM companies WHERE code = :code AND active = 1');
                $companyQuery->execute(['code' => strtoupper($companyCode)]);
                $foundCompany = $companyQuery->fetchColumn();
                if ($foundCompany === false) {
                    respond(422, ['error' => 'La empresa debe ser DUXS o ENZO.']);
                }
                $companyId = (int) $foundCompany;
            }
            $supplierId = findSupplierId($db, $cuit);
            $amount = moneyValue($data, 'amount', 'Importe', false);
            $document = optionalString($data, 'document_number', 100, 'Comprobante');
            $observations = optionalString($data, 'observations', 4000, 'Observaciones');
            $db->beginTransaction();
            $insert = $db->prepare(
                'INSERT INTO claims
                    (supplier_id, company_id, period, document_number, supplier_cuit, supplier_name, amount, reason, observations)
                 VALUES
                    (:supplier_id, :company_id, :period, :document_number, :supplier_cuit, :supplier_name, :amount, :reason, :observations)'
            );
            $insert->execute([
                'supplier_id' => $supplierId, 'company_id' => $companyId,
                'period' => $period === null ? null : $period . '-01',
                'document_number' => $document, 'supplier_cuit' => $cuit,
                'supplier_name' => $supplierName, 'amount' => $amount,
                'reason' => $reason, 'observations' => $observations,
            ]);
            $claimId = (int) $db->lastInsertId();
            $history = $db->prepare(
                'INSERT INTO claim_history (claim_id, action, details)
                 VALUES (:claim_id, "created", :details)'
            );
            $history->execute([
                'claim_id' => $claimId,
                'details' => json_encode(['source' => 'web', 'reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $db->commit();
            respond(201, ['id' => $claimId, 'message' => 'Reclamo registrado.']);
        }

        if ($action === 'imports.validate' || $action === 'imports.create') {
            $filename = requiredString($data, 'filename', 255, 'Nombre del archivo');
            if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
                respond(422, ['error' => 'Solo se admiten archivos CSV.']);
            }
            $csv = requiredString($data, 'csv', 1048576, 'Contenido CSV');
            $csvStream = fopen('php://temp', 'r+');
            if ($csvStream === false || fwrite($csvStream, $csv) === false) {
                respond(422, ['error' => 'No se pudo leer el archivo CSV.']);
            }
            rewind($csvStream);
            $firstLine = strtok($csv, "\r\n") ?: '';
            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
            $rawHeaders = fgetcsv($csvStream, null, $delimiter, '"', '');
            if (!is_array($rawHeaders)) {
                fclose($csvStream);
                respond(422, ['error' => 'El archivo CSV está vacío.']);
            }
            $headers = array_map(static fn ($value): string => trim((string) $value), $rawHeaders);
            if (isset($headers[0])) {
                $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];
            }
            if (count($headers) > 30) {
                fclose($csvStream);
                respond(422, ['error' => 'El CSV contiene demasiadas columnas.']);
            }
            $requiredHeaders = [
                'company', 'month', 'issue_date', 'document_number', 'supplier_cuit', 'supplier_name',
                'net_amount', 'vat_total', 'perceptions_total', 'other_taxes_total', 'total_amount',
                'cost_center', 'category', 'expense_type', 'observations',
            ];
            if (count($headers) !== count(array_unique($headers)) || array_diff($requiredHeaders, $headers) !== []) {
                fclose($csvStream);
                respond(422, ['error' => 'El CSV no tiene las columnas esperadas. Descargá la plantilla para ver el formato requerido.']);
            }
            $rows = [];
            $seenDocuments = [];
            $sourceRow = 1;
            while (($values = fgetcsv($csvStream, null, $delimiter, '"', '')) !== false) {
                $sourceRow++;
                if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                    continue;
                }
                if (count($rows) >= 500) {
                    fclose($csvStream);
                    respond(422, ['error' => 'El CSV admite hasta 500 registros.']);
                }
                if (count($values) !== count($headers)) {
                    fclose($csvStream);
                    respond(422, ['error' => 'La fila ' . $sourceRow . ' tiene una cantidad incorrecta de columnas.']);
                }
                $row = array_combine($headers, $values);
                if (!is_array($row)) {
                    fclose($csvStream);
                    respond(422, ['error' => 'No se pudo interpretar la fila ' . $sourceRow . ' del CSV.']);
                }
                $row['company'] = strtoupper(trim((string) $row['company']));
                $row['month'] = trim((string) $row['month']);
                $row['issue_date'] = trim((string) $row['issue_date']);
                $row['document_number'] = trim((string) $row['document_number']);
                if (!in_array($row['company'], ['DUXS', 'ENZO'], true)
                    || !validMonth($row['month']) || !validDate($row['issue_date'])
                    || $row['document_number'] === '' || mb_strlen($row['document_number']) > 100) {
                    fclose($csvStream);
                    respond(422, ['error' => 'Revisá empresa, período, fecha y comprobante en la fila ' . $sourceRow . '.']);
                }
                $cuitInput = trim((string) $row['supplier_cuit']);
                $row['supplier_cuit'] = $cuitInput === '' ? null : normalizedCuit(['supplier_cuit' => $cuitInput], 'supplier_cuit');
                if ($cuitInput !== '' && $row['supplier_cuit'] === null) {
                    fclose($csvStream);
                    respond(422, ['error' => 'El CUIT de la fila ' . $sourceRow . ' no es válido.']);
                }
                $row['net_amount'] = moneyValue($row, 'net_amount', 'Total Neto (fila ' . $sourceRow . ')', false);
                $row['vat_total'] = moneyValue($row, 'vat_total', 'Total IVA (fila ' . $sourceRow . ')', false);
                $row['perceptions_total'] = moneyValue($row, 'perceptions_total', 'Percepciones (fila ' . $sourceRow . ')', false);
                $row['other_taxes_total'] = moneyValue($row, 'other_taxes_total', 'Otros impuestos (fila ' . $sourceRow . ')', false);
                $row['total_amount'] = moneyValue($row, 'total_amount', 'Total (fila ' . $sourceRow . ')', true);
                if ($row['net_amount'] !== null && $row['vat_total'] !== null
                    && $row['perceptions_total'] !== null && $row['other_taxes_total'] !== null
                    && abs(moneyToCents($row['net_amount']) + moneyToCents($row['vat_total'])
                        + moneyToCents($row['perceptions_total']) + moneyToCents($row['other_taxes_total'])
                        - moneyToCents($row['total_amount'])) > 1) {
                    fclose($csvStream);
                    respond(422, ['error' => 'El total no coincide con los importes de la fila ' . $sourceRow . '.']);
                }
                $row['supplier_name'] = trim((string) $row['supplier_name']);
                $row['cost_center'] = trim((string) $row['cost_center']);
                $row['category'] = trim((string) $row['category']);
                $row['expense_type'] = trim((string) $row['expense_type']);
                $row['observations'] = trim((string) $row['observations']);
                if (mb_strlen($row['supplier_name']) > 255 || mb_strlen($row['observations']) > 4000) {
                    fclose($csvStream);
                    respond(422, ['error' => 'El proveedor o las observaciones superan el límite en la fila ' . $sourceRow . '.']);
                }
                $companyQuery = $db->prepare('SELECT id FROM companies WHERE code = :code AND active = 1');
                $companyQuery->execute(['code' => $row['company']]);
                $companyId = $companyQuery->fetchColumn();
                if ($companyId === false) {
                    fclose($csvStream);
                    respond(422, ['error' => 'La empresa de la fila ' . $sourceRow . ' no existe en el catálogo.']);
                }
                $row['company_id'] = (int) $companyId;
                $row['supplier_id'] = findSupplierId($db, $row['supplier_cuit']);
                $row['classification_id'] = null;
                $row['category_id'] = null;
                foreach ([
                    'cost_center' => ['cost_centers', 'cost_center_id'],
                    'category' => ['categories', 'category_id'],
                    'expense_type' => ['expense_types', 'expense_type_id'],
                ] as $field => [$table, $idField]) {
                    $row[$idField] = null;
                    if ($row[$field] !== '') {
                        $catalogQuery = $db->prepare("SELECT id FROM $table WHERE name = :name AND active = 1");
                        $catalogQuery->execute(['name' => $row[$field]]);
                        $catalogId = $catalogQuery->fetchColumn();
                        if ($catalogId === false) {
                            fclose($csvStream);
                            respond(422, ['error' => 'El valor de ' . $field . ' no está en el catálogo en la fila ' . $sourceRow . '.']);
                        }
                        $row[$idField] = (int) $catalogId;
                    }
                }
                if ($row['category_id'] !== null) {
                    $row['classification_id'] = null;
                }
                $duplicateKey = $row['company'] . '|' . mb_strtolower($row['document_number']) . '|' . ($row['supplier_cuit'] ?? '');
                if (isset($seenDocuments[$duplicateKey])) {
                    fclose($csvStream);
                    respond(409, ['error' => 'El comprobante de la fila ' . $sourceRow . ' está duplicado en el archivo.']);
                }
                $seenDocuments[$duplicateKey] = true;
                $duplicate = $db->prepare(
                    'SELECT e.id FROM expenses e JOIN companies c ON c.id = e.company_id
                     WHERE c.code = :company AND e.document_number = :document_number
                       AND (:cuit IS NULL OR e.supplier_cuit = :supplier_cuit) LIMIT 1'
                );
                $duplicate->execute([
                    'company' => $row['company'], 'document_number' => $row['document_number'],
                    'cuit' => $row['supplier_cuit'], 'supplier_cuit' => $row['supplier_cuit'],
                ]);
                if ($duplicate->fetchColumn() !== false) {
                    fclose($csvStream);
                    respond(409, ['error' => 'El comprobante de la fila ' . $sourceRow . ' ya existe en la base.']);
                }
                $row['source_row'] = $sourceRow;
                $rows[] = $row;
            }
            fclose($csvStream);
            if ($rows === []) {
                respond(422, ['error' => 'El CSV no contiene registros para importar.']);
            }
            if ($action === 'imports.validate') {
                respond(200, ['valid_rows' => count($rows), 'message' => 'El archivo pasó las validaciones.']);
            }

            $db->beginTransaction();
            $createImport = $db->prepare(
                'INSERT INTO imports (filename, status, total_rows) VALUES (:filename, "pending", :total_rows)'
            );
            $createImport->execute(['filename' => basename($filename), 'total_rows' => count($rows)]);
            $importId = (int) $db->lastInsertId();
            foreach ($rows as $row) {
                if ($row['cost_center_id'] !== null || $row['category_id'] !== null || $row['expense_type_id'] !== null) {
                    $classification = $db->prepare(
                        'SELECT id FROM classifications
                         WHERE cost_center_id <=> :cost_center_id AND category_id <=> :category_id
                           AND expense_type_id <=> :expense_type_id AND account_code IS NULL AND active = 1 LIMIT 1'
                    );
                    $classification->execute([
                        'cost_center_id' => $row['cost_center_id'], 'category_id' => $row['category_id'],
                        'expense_type_id' => $row['expense_type_id'],
                    ]);
                    $found = $classification->fetchColumn();
                    if ($found === false) {
                        $classification = $db->prepare(
                            'INSERT INTO classifications (cost_center_id, category_id, expense_type_id)
                             VALUES (:cost_center_id, :category_id, :expense_type_id)'
                        );
                        $classification->execute([
                            'cost_center_id' => $row['cost_center_id'], 'category_id' => $row['category_id'],
                            'expense_type_id' => $row['expense_type_id'],
                        ]);
                        $row['classification_id'] = (int) $db->lastInsertId();
                    } else {
                        $row['classification_id'] = (int) $found;
                    }
                }
                $insertExpense = $db->prepare(
                    'INSERT INTO expenses
                        (company_id, supplier_id, classification_id, category_id, accounting_month, issue_date,
                         document_number, supplier_cuit, supplier_name, net_amount, vat_total, perceptions_total,
                         other_taxes_total, total_amount, observations, import_id, source_sheet, source_row, source_payload)
                     VALUES
                        (:company_id, :supplier_id, :classification_id, :category_id, :accounting_month, :issue_date,
                         :document_number, :supplier_cuit, :supplier_name, :net_amount, :vat_total, :perceptions_total,
                         :other_taxes_total, :total_amount, :observations, :import_id, "CSV", :source_row, :source_payload)'
                );
                $insertExpense->execute([
                    'company_id' => $row['company_id'], 'supplier_id' => $row['supplier_id'],
                    'classification_id' => $row['classification_id'], 'category_id' => $row['category_id'],
                    'accounting_month' => $row['month'] . '-01', 'issue_date' => $row['issue_date'],
                    'document_number' => $row['document_number'], 'supplier_cuit' => $row['supplier_cuit'],
                    'supplier_name' => $row['supplier_name'] === '' ? null : $row['supplier_name'],
                    'net_amount' => $row['net_amount'], 'vat_total' => $row['vat_total'],
                    'perceptions_total' => $row['perceptions_total'], 'other_taxes_total' => $row['other_taxes_total'],
                    'total_amount' => $row['total_amount'], 'observations' => $row['observations'] === '' ? null : $row['observations'],
                    'import_id' => $importId, 'source_row' => $row['source_row'],
                    'source_payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
            $completeImport = $db->prepare(
                'UPDATE imports SET status = "completed", imported_rows = :imported_rows,
                    completed_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $completeImport->execute(['imported_rows' => count($rows), 'id' => $importId]);
            $history = $db->prepare(
                'INSERT INTO change_history (entity_type, entity_id, action, details)
                 VALUES ("import", :entity_id, "completed", :details)'
            );
            $history->execute([
                'entity_id' => $importId,
                'details' => json_encode(
                    ['filename' => basename($filename), 'imported_rows' => count($rows)],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
            ]);
            $db->commit();
            respond(201, ['id' => $importId, 'imported_rows' => count($rows), 'message' => 'Importación guardada en MySQL.']);
        }

        if ($action === 'claim.resolve') {
            $id = positiveInteger($data['id'] ?? null, 'pendiente');
            $db->beginTransaction();
            $statement = $db->prepare(
                'UPDATE claims SET status = "resolved", resolved_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND status = "pending"'
            );
            $statement->execute(['id' => $id]);
            if ($statement->rowCount() !== 1) {
                $db->rollBack();
                respond(404, ['error' => 'No se encontró un pendiente abierto con ese identificador.']);
            }
            $history = $db->prepare(
                'INSERT INTO claim_history (claim_id, action, details)
                 VALUES (:claim_id, "resolved", JSON_OBJECT("source", "web"))'
            );
            $history->execute(['claim_id' => $id]);
            $db->commit();
            respond(200, ['message' => 'Pendiente marcado como resuelto.']);
        }

        respond(404, ['error' => 'La operación solicitada no está implementada.']);
    }

    respond(405, ['error' => 'Método no permitido.']);
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[DUXS API] ' . $error->getMessage());
    respond(503, ['error' => 'No se pudo completar la operación. Revisá la conexión a MySQL y el registro de errores de PHP.']);
}
