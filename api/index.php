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
    $value = trim((string) ($data[$key] ?? ''));
    if ($value === '' || mb_strlen($value) > $maxLength) {
        respond(422, ['error' => "$label es obligatorio y admite hasta $maxLength caracteres."]);
    }

    return $value;
}

function optionalString(array $data, string $key, int $maxLength, string $label): ?string
{
    $value = trim((string) ($data[$key] ?? ''));
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
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1;
}

function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function moneyValue(array $data, string $key, string $label, bool $required): ?string
{
    $raw = trim((string) ($data[$key] ?? ''));
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

    if ($method !== 'GET') {
        $providedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($providedToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $providedToken)) {
            respond(403, ['error' => 'La sesión de seguridad venció. Recargá la página e intentá de nuevo.']);
        }
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
             ORDER BY total DESC
             LIMIT 100"
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
             ORDER BY total DESC
             LIMIT 5"
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

    if ($method === 'GET' && $action === 'catalogs') {
        respond(200, [
            'companies' => $db->query('SELECT id, code, name FROM companies WHERE active = 1 ORDER BY name')->fetchAll(),
            'costCenters' => $db->query('SELECT id, name FROM cost_centers WHERE active = 1 ORDER BY name')->fetchAll(),
            'categories' => $db->query('SELECT id, name FROM categories WHERE active = 1 ORDER BY name')->fetchAll(),
            'expenseTypes' => $db->query('SELECT id, name FROM expense_types WHERE active = 1 ORDER BY name')->fetchAll(),
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

            $supplierCuitInput = preg_replace('/\D+/', '', (string) ($data['supplier_cuit'] ?? ''));
            $supplierCuit = $supplierCuitInput === '' ? null : $supplierCuitInput;
            if ($supplierCuit !== null && strlen($supplierCuit) !== 11) {
                respond(422, ['error' => 'El CUIT debe contener 11 dígitos.']);
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
            $cuit = preg_replace('/\D+/', '', requiredString($data, 'cuit', 20, 'CUIT'));
            if (strlen($cuit) !== 11) {
                respond(422, ['error' => 'El CUIT debe contener 11 dígitos.']);
            }
            $legalName = requiredString($data, 'legal_name', 255, 'Razón social');
            $tradeName = optionalString($data, 'trade_name', 255, 'Nombre comercial');
            $statement = $db->prepare(
                'INSERT INTO suppliers (cuit, legal_name, trade_name)
                 VALUES (:cuit, :legal_name, :trade_name)'
            );
            try {
                $statement->execute(['cuit' => $cuit, 'legal_name' => $legalName, 'trade_name' => $tradeName]);
            } catch (PDOException $error) {
                if ($error->getCode() === '23000') {
                    respond(409, ['error' => 'Ya existe un proveedor con ese CUIT.']);
                }
                throw $error;
            }
            respond(201, ['id' => (int) $db->lastInsertId(), 'message' => 'Proveedor guardado.']);
        }

        if ($action === 'claim.resolve') {
            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                respond(422, ['error' => 'El pendiente indicado no es válido.']);
            }
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
