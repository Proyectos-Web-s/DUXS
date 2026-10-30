<?php
declare(strict_types=1);

function csvDatabaseDate(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{5}(?:\.\d+)?$/', $value) === 1 && (float) $value < 100000) {
        return (new DateTimeImmutable('1899-12-30'))
            ->modify('+' . (int) floor((float) $value) . ' days');
    }

    foreach (['!j/n/Y', '!d/m/Y', '!Y-m-d'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date;
        }
    }

    return null;
}

function csvDatabaseHeaderName(string $value): string
{
    $value = mb_strtolower(trim($value));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = $ascii;
    }
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '');
}

function csvDatabasePeriod(?string $dateValue, ?string $monthValue): ?string
{
    $date = csvDatabaseDate($dateValue);
    if ($date === null) {
        return null;
    }

    $monthValue = trim((string) $monthValue);
    $month = ctype_digit($monthValue) ? (int) $monthValue : (int) $date->format('n');
    if ($month < 1 || $month > 12) {
        return null;
    }

    return $date->format('Y') . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT);
}

function csvDatabaseCuit(array $data, string $key = 'cuit'): ?string
{
    $cuit = normalizedCuit($data, $key);
    if ($cuit === null || !in_array(substr($cuit, 0, 2), ['20', '23', '24', '27', '30', '33', '34'], true)) {
        return null;
    }

    return $cuit;
}

function csvDatabaseCents(?string $value): ?int
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $normalized = preg_replace('/[\s\x{00A0}\x{202F}$]+/u', '', $raw);
    $accountingNegative = $normalized !== null
        && str_starts_with($normalized, '(')
        && str_ends_with($normalized, ')');
    if ($accountingNegative) {
        $normalized = trim($normalized, '()');
    }
    if ($normalized === null || preg_match('/^[+-]?\d[\d.,]*$/', $normalized) !== 1) {
        return null;
    }
    $negative = $accountingNegative || str_starts_with($normalized, '-');

    $comma = strrpos($normalized, ',');
    $dot = strrpos($normalized, '.');
    if ($comma !== false && $dot !== false) {
        $decimalSeparator = $comma > $dot ? ',' : '.';
        $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
        $normalized = str_replace($thousandsSeparator, '', $normalized);
        $normalized = str_replace($decimalSeparator, '.', $normalized);
    } elseif ($comma !== false) {
        if (strlen($normalized) - $comma - 1 <= 2) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '', $normalized);
        }
    } elseif ($dot !== false && strlen($normalized) - $dot - 1 > 2) {
        $normalized = str_replace('.', '', $normalized);
    }

    if (str_starts_with($normalized, '+') || str_starts_with($normalized, '-')) {
        $normalized = substr($normalized, 1);
    }
    if (preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized) !== 1) {
        return null;
    }

    [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
    $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    return $negative ? -$cents : $cents;
}

function csvDatabaseFormatCents(int $cents): string
{
    $negative = $cents < 0;
    $absolute = abs($cents);
    $formatted = intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    return $negative ? '-' . $formatted : $formatted;
}

function csvDatabaseImportAmount(array $row, string $field, string $label, int $sourceRow, bool $required): ?string
{
    $raw = trim((string) ($row[$field] ?? ''));
    if ($raw === '') {
        if ($required) {
            respond(422, ['error' => $label . ' es obligatorio en la fila ' . $sourceRow . '.']);
        }
        return null;
    }

    $cents = csvDatabaseCents($raw);
    if ($cents === null) {
        respond(422, ['error' => $label . ' debe ser un importe válido en la fila ' . $sourceRow . '.']);
    }
    return csvDatabaseFormatCents($cents);
}

function csvDatabaseAmountSum(array $records, string $field): string
{
    $sum = 0;
    foreach ($records as $record) {
        $sum += $record[$field . '_cents'] ?? 0;
    }

    return csvDatabaseFormatCents($sum);
}

function csvDatabasePublicRecord(array $record): array
{
    foreach (array_keys($record) as $key) {
        if (str_ends_with($key, '_cents')) {
            unset($record[$key]);
        }
    }

    return $record;
}

function csvDatabaseRecords(PDO $db, array $filters = []): array
{
    $queryInput = $filters['q'] ?? '';
    $companyInput = $filters['company'] ?? '';
    $periodInput = $filters['month'] ?? '';
    if (!is_string($queryInput) || !is_string($companyInput) || !is_string($periodInput)) {
        respond(422, ['error' => 'Los filtros de búsqueda no tienen un formato válido.']);
    }

    $query = trim($queryInput);
    if (mb_strlen($query) > 120) {
        respond(422, ['error' => 'La búsqueda admite hasta 120 caracteres.']);
    }
    $cuitQuery = preg_replace('/\D+/', '', $query) ?? '';

    $company = trim($companyInput);
    if ($company !== '' && !in_array($company, ['DUXS', 'ENZO'], true)) {
        respond(422, ['error' => 'La empresa seleccionada no es válida.']);
    }

    $statement = $db->prepare(
        'SELECT id, titular_empresa, mes, fecha, comprobante, cuit, proveedor,
                total_original, total_neto, total_iva, percepciones, otros_imp,
                centro_de_costo, categoria, tipo_de_gasto, observaciones_comprobante,
                condicion_iva, jurisdiccion, codigo_afip, cuenta_contable, credito_fiscal, tipo
         FROM comprobantes
         WHERE (:query_filter = \'\'
                OR comprobante LIKE :match1 OR proveedor LIKE :match2
                OR (:cuit_filter <> \'\' AND cuit LIKE :cuit_match)
                OR categoria LIKE :match4 OR centro_de_costo LIKE :match5 OR tipo_de_gasto LIKE :match6)
           AND (:company_filter = \'\' OR titular_empresa = :company)
         ORDER BY id DESC'
    );
    $statement->execute([
        'query_filter' => $query,
        'match1' => '%' . $query . '%',
        'match2' => '%' . $query . '%',
        'cuit_filter' => $cuitQuery,
        'cuit_match' => '%' . $cuitQuery . '%',
        'match4' => '%' . $query . '%',
        'match5' => '%' . $query . '%',
        'match6' => '%' . $query . '%',
        'company_filter' => $company,
        'company' => $company,
    ]);

    $records = [];
    foreach ($statement->fetchAll() as $row) {
        $date = csvDatabaseDate($row['fecha']);
        $amountFields = [
            'total_amount' => 'total_original',
            'net_amount' => 'total_neto',
            'vat_total' => 'total_iva',
            'perceptions_total' => 'percepciones',
            'other_taxes_total' => 'otros_imp',
        ];
        $record = [
            'id' => (int) $row['id'],
            'accounting_month' => csvDatabasePeriod($row['fecha'], $row['mes']),
            'issue_date' => $date?->format('Y-m-d'),
            'document_number' => $row['comprobante'],
            'supplier_cuit' => $row['cuit'],
            'supplier_name' => $row['proveedor'],
            'company' => $row['titular_empresa'],
            'category' => $row['categoria'],
            'cost_center' => $row['centro_de_costo'],
            'expense_type' => $row['tipo_de_gasto'],
            'observations' => $row['observaciones_comprobante'],
            'iva_condition' => $row['condicion_iva'],
            'jurisdiction' => $row['jurisdiccion'],
            'afip_code' => $row['codigo_afip'],
            'account_code' => $row['cuenta_contable'],
            'tax_credit' => $row['credito_fiscal'],
            'expense_label' => $row['tipo'],
        ];

        foreach ($amountFields as $outputField => $sourceField) {
            $cents = csvDatabaseCents($row[$sourceField]);
            $record[$outputField] = $cents === null ? null : csvDatabaseFormatCents($cents);
            $record[$outputField . '_cents'] = $cents;
        }
        $records[] = $record;
    }

    $period = trim($periodInput);
    if ($period !== '' && !validMonth($period)) {
        respond(422, ['error' => 'El período debe tener formato AAAA-MM.']);
    }
    if ($period !== '') {
        $records = array_values(array_filter(
            $records,
            static fn (array $record): bool => $record['accounting_month'] === $period
        ));
    }

    usort($records, static function (array $left, array $right): int {
        $periodOrder = strcmp((string) ($right['accounting_month'] ?? ''), (string) ($left['accounting_month'] ?? ''));
        if ($periodOrder !== 0) {
            return $periodOrder;
        }
        $dateOrder = strcmp((string) ($right['issue_date'] ?? ''), (string) ($left['issue_date'] ?? ''));
        return $dateOrder !== 0 ? $dateOrder : $right['id'] <=> $left['id'];
    });

    return $records;
}

function csvDatabaseGroups(array $records, string $field, string $label): array
{
    $groups = [];
    foreach ($records as $record) {
        $name = trim((string) ($record[$field] ?? ''));
        if ($name === '') {
            $name = $label;
        }
        $groups[$name] = ($groups[$name] ?? 0) + ($record['net_amount_cents'] ?? 0);
    }

    $items = [];
    foreach ($groups as $name => $cents) {
        $items[] = ['name' => $name, 'total' => csvDatabaseFormatCents($cents)];
    }
    usort($items, static fn (array $left, array $right): int => (float) $right['total'] <=> (float) $left['total']);

    return $items;
}

function csvDatabasePeriods(PDO $db): array
{
    $rows = $db->query('SELECT fecha, mes FROM comprobantes')->fetchAll();
    $periods = [];
    foreach ($rows as $row) {
        $period = csvDatabasePeriod($row['fecha'], $row['mes']);
        if ($period !== null) {
            $periods[$period] = true;
        }
    }
    $items = array_keys($periods);
    rsort($items, SORT_STRING);
    return $items;
}

function csvDatabaseCatalog(PDO $db, string $column): array
{
    $allowedColumns = [
        'centro_de_costo' => 'centro_de_costo',
        'categoria' => 'categoria',
        'tipo_de_gasto' => 'tipo_de_gasto',
    ];
    if (!isset($allowedColumns[$column])) {
        throw new LogicException('Columna de catálogo no permitida.');
    }

    $statement = $db->query(
        'SELECT DISTINCT ' . $allowedColumns[$column] . ' AS name
         FROM comprobantes
         WHERE ' . $allowedColumns[$column] . ' IS NOT NULL
           AND TRIM(' . $allowedColumns[$column] . ') <> \'\'
         ORDER BY name'
    );
    return $statement->fetchAll();
}

function csvDatabasePendingReclassifications(
    PDO $db,
    string $query = '',
    string $status = '',
    int $limit = 1000
): array
{
    $query = trim($query);
    if (mb_strlen($query) > 120) {
        respond(422, ['error' => 'La búsqueda admite hasta 120 caracteres.']);
    }
    if (!in_array($status, ['', 'Pendiente', 'Resuelto'], true)) {
        respond(422, ['error' => 'El estado seleccionado no es válido.']);
    }

    $statusFilter = $status === '' ? '' : ' AND pending.estado = :status';
    $parameters = [
        'query_filter' => $query,
        'match' => '%' . $query . '%',
    ];
    if ($status !== '') {
        $parameters['status'] = $status;
    }

    $statement = $db->prepare(
        'SELECT pending.id, pending.nombre_proveedor AS supplier_name,
                pending.estado AS status, pending.creado_en AS created_at,
                supplier_match.cuit AS supplier_cuit, supplier_match.id AS supplier_id,
                receipt_match.cuit AS receipt_cuit, receipt_supplier.id AS receipt_supplier_id
         FROM pendientes_reclasificacion AS pending
         LEFT JOIN (
             SELECT LOWER(TRIM(razon_social)) AS normalized_name, MIN(id) AS id, MIN(cuit) AS cuit
             FROM proveedores
             GROUP BY LOWER(TRIM(razon_social))
             HAVING COUNT(*) = 1
         ) AS supplier_match
           ON supplier_match.normalized_name = LOWER(TRIM(pending.nombre_proveedor))
         LEFT JOIN (
             SELECT LOWER(TRIM(proveedor)) AS normalized_name, MIN(cuit) AS cuit
             FROM comprobantes
             WHERE cuit IS NOT NULL AND TRIM(cuit) <> \'\'
               AND proveedor IS NOT NULL AND TRIM(proveedor) <> \'\'
             GROUP BY LOWER(TRIM(proveedor))
             HAVING COUNT(DISTINCT cuit) = 1
         ) AS receipt_match
           ON receipt_match.normalized_name = LOWER(TRIM(pending.nombre_proveedor))
         LEFT JOIN proveedores AS receipt_supplier
           ON receipt_supplier.cuit = receipt_match.cuit
         WHERE (:query_filter = \'\' OR pending.nombre_proveedor LIKE :match)'
         . $statusFilter .
         ' ORDER BY pending.creado_en DESC, pending.id DESC
         LIMIT ' . max(1, min($limit, 1000))
    );
    $statement->execute($parameters);

    $items = $statement->fetchAll();
    foreach ($items as &$item) {
        $supplierCuit = csvDatabaseCuit(['cuit' => $item['supplier_cuit']]);
        $receiptCuit = csvDatabaseCuit(['cuit' => $item['receipt_cuit']]);
        if ($supplierCuit !== null) {
            $item['cuit'] = $supplierCuit;
        } elseif ($receiptCuit !== null) {
            $item['cuit'] = $receiptCuit;
            $item['supplier_id'] = $item['receipt_supplier_id'];
        } else {
            $item['cuit'] = null;
            $item['supplier_id'] = $item['supplier_id'] ?? $item['receipt_supplier_id'];
        }
        unset($item['supplier_cuit'], $item['receipt_cuit'], $item['receipt_supplier_id']);
    }
    unset($item);

    return $items;
}

function csvDatabaseRequireSupplierClassificationTable(PDO $db): void
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table_name'
    );
    $statement->execute(['table_name' => 'proveedor_clasificaciones']);
    if ((int) $statement->fetchColumn() === 0) {
        respond(503, [
            'error' => 'Para consultar y guardar clasificaciones de proveedores, ejecutá database/migration_supplier_classifications.sql en duxs_desde_csv.',
            'code' => 'migration_required',
        ]);
    }
}

function csvDatabaseSupplierClassifications(PDO $db, int $supplierId): array
{
    $statement = $db->prepare(
        'SELECT centro_de_costo, categoria, tipo_de_gasto
         FROM proveedor_clasificaciones
         WHERE proveedor_id = :supplier_id
         ORDER BY centro_de_costo, categoria, tipo_de_gasto'
    );
    $statement->execute(['supplier_id' => $supplierId]);
    return $statement->fetchAll();
}

function csvDatabaseImportDuplicate(PDO $db, array $row): bool
{
    $statement = $db->prepare(
        'SELECT cuit, fecha
         FROM comprobantes
         WHERE titular_empresa = :company
           AND comprobante = :document_number
           AND (:cuit_filter IS NULL OR cuit = :cuit)
         '
    );
    $statement->execute([
        'company' => $row['company'],
        'document_number' => $row['document_number'],
        'cuit_filter' => $row['supplier_cuit'],
        'cuit' => $row['supplier_cuit'],
    ]);
    foreach ($statement->fetchAll() as $existing) {
        $existingDate = csvDatabaseDate($existing['fecha']);
        if ($existingDate !== null && $existingDate->format('Y-m-d') === $row['issue_date']) {
            return true;
        }
    }

    return false;
}

function csvDatabaseParseImport(PDO $db, string $filename, string $csv): array
{
    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
        respond(422, ['error' => 'Solo se admiten archivos CSV.']);
    }
    if (strlen($csv) > 800000) {
        respond(413, ['error' => 'El archivo CSV no puede superar 800 KB.']);
    }
    if (!mb_check_encoding($csv, 'UTF-8')) {
        respond(422, ['error' => 'El CSV debe estar codificado en UTF-8. Guardalo como CSV UTF-8 e intentá de nuevo.']);
    }

    $csvStream = fopen('php://temp', 'r+');
    if ($csvStream === false || fwrite($csvStream, $csv) === false) {
        respond(422, ['error' => 'No se pudo leer el archivo CSV.']);
    }
    rewind($csvStream);
    $firstLine = strtok($csv, "\r\n") ?: '';
    $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    $requiredHeaders = [
        'company', 'month', 'issue_date', 'document_number', 'supplier_cuit', 'supplier_name',
        'net_amount', 'vat_total', 'perceptions_total', 'other_taxes_total', 'total_amount',
        'cost_center', 'category', 'expense_type', 'observations',
    ];
    $headers = [];
    $headerIndexes = [];
    $accountantFormat = false;
    $accountantExtraIndexes = [];
    $accountantTotalFallbackIndex = null;
    $headerRowNumber = 0;
    for ($line = 1; $line <= 10; $line++) {
        $candidate = fgetcsv($csvStream, null, $delimiter, '"', '');
        if (!is_array($candidate)) {
            break;
        }
        $candidateHeaders = array_map(static fn ($value): string => trim((string) $value), $candidate);
        if (isset($candidateHeaders[0])) {
            $candidateHeaders[0] = preg_replace('/^\xEF\xBB\xBF/', '', $candidateHeaders[0]) ?? $candidateHeaders[0];
        }
        if (count($candidateHeaders) <= 30
            && count($candidateHeaders) === count(array_unique($candidateHeaders))
            && array_diff($requiredHeaders, $candidateHeaders) === []) {
            $headers = $candidateHeaders;
            $headerIndexes = array_flip($headers);
            $headerRowNumber = $line;
            break;
        }

        $normalized = array_map('csvDatabaseHeaderName', $candidateHeaders);
        $positions = [];
        foreach ($normalized as $index => $name) {
            $positions[$name][] = $index;
        }
        if (count($positions['centro de costo'] ?? []) >= 2
            && isset($positions['mes'], $positions['fecha'], $positions['comprobante'], $positions['cuit'],
                $positions['proveedor'], $positions['total neto'], $positions['total iva'],
                $positions['percepciones'], $positions['otros imp'], $positions['total'],
                $positions['categoria'], $positions['tipo de gasto'])) {
            $headers = $candidateHeaders;
            $headerRowNumber = $line;
            $accountantFormat = true;
            $headerIndexes = [
                'company' => $positions['centro de costo'][0],
                'month' => $positions['mes'][0],
                'issue_date' => $positions['fecha'][0],
                'document_number' => $positions['comprobante'][0],
                'supplier_cuit' => $positions['cuit'][0],
                'supplier_name' => $positions['proveedor'][0],
                'net_amount' => $positions['total neto'][0],
                'vat_total' => $positions['total iva'][0],
                'perceptions_total' => $positions['percepciones'][0],
                'other_taxes_total' => $positions['otros imp'][0],
                'total_amount' => $positions['total'][0],
                'cost_center' => $positions['centro de costo'][count($positions['centro de costo']) - 1],
                'category' => $positions['categoria'][0],
                'expense_type' => $positions['tipo de gasto'][0],
                'observations' => $positions['observaciones'][0] ?? null,
            ];
            $extraHeaders = [
                'condicion iva' => 'condicion_iva',
                'jurisdiccion' => 'jurisdiccion',
                'gravado al 21' => 'gravado_al_21_porcentaje',
                'gravado al 10 5' => 'gravado_al_10_5_porcentaje',
                'gravado al 27' => 'gravado_al_27_porcentaje',
                'gravado al 5' => 'gravado_al_5_porcentaje',
                'gravado al 2 5' => 'gravado_al_2_5_porcentaje',
                'gravado al 0' => 'gravado_al_0_porcentaje',
                'exento' => 'exento',
                'gravado otros' => 'gravado_otros',
                'gravado cf' => 'gravado_cf',
                'no gravado' => 'no_gravado',
                'no gravado cf' => 'no_gravado_cf',
                'iva 21' => 'iva_21_porcentaje',
                'iva 10 5' => 'iva_10_5_porcentaje',
                'iva 27' => 'iva_27_porcentaje',
                'iva 5' => 'iva_5_porcentaje',
                'iva 2 5' => 'iva_2_5_porcentaje',
                'iva otros' => 'iva_otros',
                'iva cf' => 'iva_cf',
                'retenciones' => 'retenciones',
                'percepcion iva sufrida' => 'percepcion_iva_sufrida',
                'percepcion iibb sufrida' => 'percepcion_iibb_sufrida',
                'percepcion gan sufrida' => 'percepcion_gan_sufrida',
                'imp munic' => 'imp_munic',
                'imp int' => 'imp_int',
                'itc' => 'itc',
                'otros trib' => 'otros_trib',
                'iva no computable' => 'iva_no_computable',
                'codigo afip' => 'codigo_afip',
                'cuenta contable' => 'cuenta_contable',
                'credito fiscal' => 'credito_fiscal',
                'tipo' => 'tipo',
                'observaciones' => 'observaciones_comprobante',
            ];
            $regionalTaxes = [
                'perc iibb 901 caba' => 'perc_iibb_901_caba',
                'perc iibb 902 bs as' => 'perc_iibb_902_bs_as',
                'perc iibb 903 catam' => 'perc_iibb_903_catam',
                'perc iibb 904 cordoba' => 'perc_iibb_904_cordoba',
                'perc iibb 905 corrien' => 'perc_iibb_905_corrien',
                'perc iibb 906 chaco' => 'perc_iibb_906_chaco',
                'perc iibb 907 chubut' => 'perc_iibb_907_chubut',
                'perc iibb 908 e rios' => 'perc_iibb_908_e_rios',
                'perc iibb 909 formosa' => 'perc_iibb_909_formosa',
                'perc iibb 910 jujuy' => 'perc_iibb_910_jujuy',
                'perc iibb 911 la pampa' => 'perc_iibb_911_la_pampa',
                'perc iibb 912 la rioja' => 'perc_iibb_912_la_rioja',
                'perc iibb 913 mendoza' => 'perc_iibb_913_mendoza',
                'perc iibb 914 misiones' => 'perc_iibb_914_misiones',
                'perc iibb 915 neuquen' => 'perc_iibb_915_neuquen',
                'perc iibb 916 rio negro' => 'perc_iibb_916_rio_negro',
                'perc iibb 917 salta' => 'perc_iibb_917_salta',
                'perc iibb 918 san juan' => 'perc_iibb_918_san_juan',
                'perc iibb 919 san luis' => 'perc_iibb_919_san_luis',
                'perc iibb 920 s cruz' => 'perc_iibb_920_s_cruz',
                'perc iibb 921 santa fe' => 'perc_iibb_921_santa_fe',
                'perc iibb 922 s estero' => 'perc_iibb_922_s_estero',
                'perc iibb 923 t fuego' => 'perc_iibb_923_t_fuego',
                'perc iibb 924 tucuman' => 'perc_iibb_924_tucuman',
            ];
            foreach ($extraHeaders + $regionalTaxes as $sourceHeader => $column) {
                if (isset($positions[$sourceHeader][0])) {
                    $accountantExtraIndexes[$column] = $positions[$sourceHeader][0];
                }
            }
            if (count($positions['total'] ?? []) > 1) {
                $accountantTotalFallbackIndex = $positions['total'][count($positions['total']) - 1];
                $accountantExtraIndexes['total_neto_iva_percepciones'] = $accountantTotalFallbackIndex;
            }
            if (count($positions['observaciones'] ?? []) > 1) {
                $accountantExtraIndexes['observaciones_clasificacion'] = $positions['observaciones'][count($positions['observaciones']) - 1];
            }
            break;
        }
    }
    if ($headers === [] || (!$accountantFormat && count($headers) !== count(array_unique($headers))) || count($headers) > 100) {
        fclose($csvStream);
        respond(422, ['error' => 'El CSV no tiene las columnas esperadas. Descargá la plantilla para ver el formato requerido.']);
    }

    $rawRows = [];
    $yearCounts = [];
    $sourceRow = $headerRowNumber;
    while (($values = fgetcsv($csvStream, null, $delimiter, '"', '')) !== false) {
        $sourceRow++;
        if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
            continue;
        }
        $rawRows[] = ['line' => $sourceRow, 'values' => $values];
        if ($accountantFormat) {
            $dateIndex = $headerIndexes['issue_date'];
            $date = csvDatabaseDate((string) ($values[$dateIndex] ?? ''));
            if ($date !== null) {
                $year = $date->format('Y');
                $yearCounts[$year] = ($yearCounts[$year] ?? 0) + 1;
            }
        }
    }
    fclose($csvStream);

    $inferredYear = null;
    if ($accountantFormat && $yearCounts !== []) {
        arsort($yearCounts, SORT_NUMERIC);
        $inferredYear = (string) array_key_first($yearCounts);
    } elseif (preg_match('/\b(20\d{2})\b/', $filename, $yearMatch) === 1) {
        $inferredYear = $yearMatch[1];
    }

    $rows = [];
    $seenDocuments = [];
    $ignoredRows = [];
    foreach ($rawRows as $rawRow) {
        $sourceRow = $rawRow['line'];
        $values = $rawRow['values'];
        if (count($rows) >= 5000) {
            respond(422, ['error' => 'El CSV admite hasta 5.000 registros.']);
        }
        if (count($values) !== count($headers)) {
            respond(422, ['error' => 'La fila ' . $sourceRow . ' tiene una cantidad incorrecta de columnas.']);
        }
        if ($accountantFormat) {
            $row = [];
            foreach ($headerIndexes as $field => $index) {
                $row[$field] = $index === null ? '' : (string) ($values[$index] ?? '');
            }
            if (trim((string) $row['total_amount']) === '' && $accountantTotalFallbackIndex !== null) {
                $row['total_amount'] = (string) ($values[$accountantTotalFallbackIndex] ?? '');
            }
        } else {
            $row = array_combine($headers, $values);
            if (!is_array($row)) {
                respond(422, ['error' => 'No se pudo interpretar la fila ' . $sourceRow . ' del CSV.']);
            }
        }
        $row['extra_fields'] = [];
        if ($accountantFormat) {
            $numericExtraFields = array_diff(array_keys($accountantExtraIndexes), [
                'condicion_iva', 'jurisdiccion', 'codigo_afip', 'cuenta_contable',
                'credito_fiscal', 'tipo', 'observaciones_comprobante', 'observaciones_clasificacion',
            ]);
            foreach ($accountantExtraIndexes as $field => $index) {
                $rawValue = trim((string) ($values[$index] ?? ''));
                if ($rawValue === '' || in_array(strtoupper($rawValue), ['#N/A', '#REF!', '#VALUE!', '#DIV/0!'], true)) {
                    $row['extra_fields'][$field] = null;
                } elseif (in_array($field, $numericExtraFields, true)) {
                    $cents = csvDatabaseCents($rawValue);
                    if ($cents === null) {
                        respond(422, ['error' => 'El importe de ' . $field . ' no es válido en la fila ' . $sourceRow . '.']);
                    }
                    $row['extra_fields'][$field] = csvDatabaseFormatCents($cents);
                } else {
                    if (mb_strlen($rawValue) > 4000) {
                        respond(422, ['error' => 'El campo ' . $field . ' supera el máximo permitido en la fila ' . $sourceRow . '.']);
                    }
                    $row['extra_fields'][$field] = $rawValue;
                }
            }
        }
        $row['company'] = strtoupper(trim((string) $row['company']));
        $row['issue_date'] = trim((string) $row['issue_date']);
        $row['document_number'] = trim((string) $row['document_number']);
        $date = csvDatabaseDate($row['issue_date']);
        $monthValue = trim((string) ($row['month'] ?? ''));
        if ($accountantFormat && ctype_digit($monthValue) && (int) $monthValue >= 1 && (int) $monthValue <= 12) {
            $year = $date?->format('Y') ?? $inferredYear;
            $row['month'] = $year === null
                ? ''
                : $year . '-' . str_pad($monthValue, 2, '0', STR_PAD_LEFT);
        } else {
            $row['month'] = $monthValue;
        }
        foreach (['cost_center', 'category', 'expense_type'] as $field) {
            if (in_array(strtoupper(trim((string) ($row[$field] ?? ''))), ['#N/A', '#REF!', '#VALUE!', '#DIV/0!'], true)) {
                $row[$field] = '';
            }
        }
        $isPayroll = false;
        foreach ([
            $row['category'] ?? '',
            $row['expense_type'] ?? '',
            $row['extra_fields']['tipo'] ?? '',
        ] as $classificationValue) {
            $normalizedValue = csvDatabaseHeaderName((string) $classificationValue);
            if (str_contains($normalizedValue, 'sueldo')
                || str_contains($normalizedValue, 'haberes')
                || str_contains($normalizedValue, 'nomina')) {
                $isPayroll = true;
                break;
            }
        }
        $categoryName = csvDatabaseHeaderName((string) ($row['category'] ?? ''));
        $isTaxSummary = str_contains($categoryName, 'impuesto');
        $isSummaryRow = $accountantFormat && $row['document_number'] === ''
            && trim((string) $row['supplier_name']) !== ''
            && ($isPayroll || $isTaxSummary);
        if ($accountantFormat && $row['document_number'] === '' && !$isSummaryRow) {
            $ignoredRows[] = $sourceRow;
            continue;
        }
        if (!in_array($row['company'], ['DUXS', 'ENZO'], true)
            || !validMonth($row['month'])
            || mb_strlen($row['document_number']) > 100) {
            respond(422, ['error' => 'Revisá empresa, período y comprobante en la fila ' . $sourceRow . '.']);
        }
        if ($date === null && $isSummaryRow && $inferredYear !== null) {
            $date = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $inferredYear . '-' . substr($row['month'], 5, 2) . '-01'
            );
        }
        if ($date === null || $date->format('Y') !== substr($row['month'], 0, 4)) {
            respond(422, ['error' => 'Revisá fecha y período en la fila ' . $sourceRow . '.']);
        }
        if ($row['document_number'] === '' && !$isSummaryRow) {
            respond(422, ['error' => 'Falta el número de comprobante en la fila ' . $sourceRow . '. Solo se importan sin número las filas identificadas como Sueldos o Impuestos.']);
        }
        if ($isSummaryRow && trim((string) $row['total_amount']) === '') {
            $ignoredRows[] = $sourceRow;
            continue;
        }
        $row['issue_date'] = $date->format('Y-m-d');
        $row['is_payroll'] = $isPayroll;
        $row['is_tax_summary'] = $isTaxSummary && $isSummaryRow;
        $row['is_summary'] = $isSummaryRow;
        $cuitInput = trim((string) $row['supplier_cuit']);
        $row['supplier_cuit'] = $cuitInput === ''
            ? null
            : csvDatabaseCuit(['supplier_cuit' => $cuitInput], 'supplier_cuit');
        if ($cuitInput !== '' && $row['supplier_cuit'] === null) {
            respond(422, ['error' => 'El CUIT de la fila ' . $sourceRow . ' no es válido.']);
        }
        if ($isSummaryRow
            && ($row['net_amount'] === null || csvDatabaseCents($row['net_amount']) === 0)
            && trim((string) $row['vat_total']) === ''
            && trim((string) $row['perceptions_total']) === ''
            && trim((string) $row['other_taxes_total']) === ''
            && trim((string) $row['total_amount']) !== '') {
            $row['net_amount'] = $row['total_amount'];
            $row['vat_total'] = '0';
            $row['perceptions_total'] = '0';
            $row['other_taxes_total'] = '0';
        }
        if ($isSummaryRow
            && (csvDatabaseCents($row['net_amount']) ?? 0) === 0
            && (csvDatabaseCents($row['vat_total']) ?? 0) === 0
            && (csvDatabaseCents($row['perceptions_total']) ?? 0) === 0
            && (csvDatabaseCents($row['other_taxes_total']) ?? 0) === 0
            && (csvDatabaseCents($row['total_amount']) ?? 0) !== 0) {
            $row['net_amount'] = $row['total_amount'];
        }
        foreach ([
            'net_amount' => ['Total Neto', false],
            'vat_total' => ['Total IVA', false],
            'perceptions_total' => ['Percepciones', false],
            'other_taxes_total' => ['Otros impuestos', false],
            'total_amount' => ['Total', true],
        ] as $field => $label) {
            [$amountLabel, $required] = $label;
            $row[$field] = csvDatabaseImportAmount(
                $row,
                $field,
                $amountLabel,
                $sourceRow,
                $required
            );
        }
        $netCents = csvDatabaseCents($row['net_amount']) ?? 0;
        $vatCents = csvDatabaseCents($row['vat_total']) ?? 0;
        $perceptionsCents = csvDatabaseCents($row['perceptions_total']) ?? 0;
        $otherTaxesCents = csvDatabaseCents($row['other_taxes_total']) ?? 0;
        $totalCents = csvDatabaseCents($row['total_amount']);
        $componentsTotal = $netCents + $vatCents + $perceptionsCents + $otherTaxesCents;
        $retentionsCents = csvDatabaseCents($row['extra_fields']['retenciones'] ?? null) ?? 0;
        $totalMatches = $totalCents !== null && (
            abs($componentsTotal - $totalCents) <= 1
            || ($accountantFormat && abs($componentsTotal + $retentionsCents - $totalCents) <= 1)
            || ($accountantFormat && abs($componentsTotal - $retentionsCents - $totalCents) <= 1)
        );
        if (!$totalMatches) {
            respond(422, ['error' => 'El total no coincide con los importes de la fila ' . $sourceRow . '.']);
        }

        foreach (['supplier_name' => 255, 'cost_center' => 150, 'category' => 150, 'expense_type' => 150, 'observations' => 4000] as $field => $limit) {
            $row[$field] = trim((string) $row[$field]);
            if (mb_strlen($row[$field]) > $limit) {
                respond(422, ['error' => 'El campo ' . $field . ' supera el máximo permitido en la fila ' . $sourceRow . '.']);
            }
        }
        if ($row['document_number'] === '') {
            $payrollKey = implode('|', [
                $row['company'],
                $row['month'],
                $row['issue_date'],
                csvDatabaseHeaderName($row['supplier_name']),
                csvDatabaseHeaderName($row['category']),
                csvDatabaseHeaderName($row['expense_type']),
                $row['total_amount'],
            ]);
            $prefix = $isPayroll ? 'SUELDOS-' : 'RESUMEN-';
            $row['document_number'] = $prefix . str_replace('-', '', $row['month']) . '-'
                . strtoupper(substr(hash('sha256', $payrollKey), 0, 20));
        }
        $dateKey = $row['company'] . '|' . mb_strtolower($row['document_number']) . '|'
            . ($row['supplier_cuit'] ?? '') . '|' . $row['issue_date'];
        $row['duplicate'] = isset($seenDocuments[$dateKey]) || csvDatabaseImportDuplicate($db, $row);
        $seenDocuments[$dateKey] = true;
        $row['source_row'] = $sourceRow;
        $rows[] = $row;
    }
    if ($rows === []) {
        respond(422, ['error' => 'El CSV no contiene registros para importar.']);
    }

    $supplierNames = [];
    foreach ($rows as $row) {
        if ($row['duplicate'] || $row['supplier_cuit'] === null || $row['supplier_name'] === '') {
            continue;
        }
        $supplierNames[$row['supplier_cuit']] = $row['supplier_name'];
    }
    $newSuppliers = [];
    if ($supplierNames !== []) {
        $supplierLookup = $db->prepare('SELECT id FROM proveedores WHERE cuit = :cuit LIMIT 1');
        foreach ($supplierNames as $cuit => $name) {
            $supplierLookup->execute(['cuit' => $cuit]);
            if ($supplierLookup->fetchColumn() === false) {
                $newSuppliers[] = $name;
            }
        }
    }

    $duplicateRows = count(array_filter($rows, static fn (array $row): bool => $row['duplicate']));
    $newPayrollRows = count(array_filter(
        $rows,
        static fn (array $row): bool => $row['is_payroll'] && !$row['duplicate']
    ));
    $newTaxSummaryRows = count(array_filter(
        $rows,
        static fn (array $row): bool => $row['is_tax_summary'] && !$row['duplicate']
    ));
    return [
        'rows' => $rows,
        'total_rows' => count($rows),
        'new_rows' => count($rows) - $duplicateRows,
        'duplicate_rows' => $duplicateRows,
        'new_suppliers' => count($newSuppliers),
        'supplier_names' => $newSuppliers,
        'payroll_rows' => $newPayrollRows,
        'tax_summary_rows' => $newTaxSummaryRows,
        'ignored_rows' => $ignoredRows,
    ];
}

function csvDatabaseUnavailable(string $feature, string $neededTables): never
{
    respond(501, [
        'error' => $feature . ' no está disponible: la base duxs_desde_csv no incluye las tablas ' . $neededTables . '.',
        'code' => 'schema_not_available',
    ]);
}

function csvDatabaseCreateExpense(PDO $db): never
{
    $data = requestData();
    $company = requiredString($data, 'company', 20, 'Titular');
    if (!in_array($company, ['DUXS', 'ENZO'], true)) {
        respond(422, ['error' => 'El titular debe ser DUXS o ENZO.']);
    }

    $month = requiredString($data, 'month', 7, 'Mes');
    $issueDate = requiredString($data, 'issue_date', 10, 'Fecha');
    if (!validMonth($month) || !validDate($issueDate)) {
        respond(422, ['error' => 'El mes o la fecha no tienen un formato válido.']);
    }
    if (substr($month, 0, 4) !== substr($issueDate, 0, 4)) {
        respond(422, ['error' => 'La tabla existente guarda el mes como número sin año; el año del período debe coincidir con el año de la fecha.']);
    }

    $documentNumber = requiredString($data, 'document_number', 100, 'Comprobante');
    $supplierName = optionalString($data, 'supplier_name', 255, 'Proveedor');
    $cuitValue = $data['supplier_cuit'] ?? '';
    if (!is_string($cuitValue) && !is_int($cuitValue) && !is_float($cuitValue)) {
        respond(422, ['error' => 'El CUIT del comprobante debe ser un valor de texto.']);
    }
    $cuitInput = trim((string) $cuitValue);
    $cuit = $cuitInput === '' ? null : csvDatabaseCuit(['supplier_cuit' => $cuitInput], 'supplier_cuit');
    if ($cuitInput !== '' && $cuit === null) {
        respond(422, ['error' => 'El CUIT del comprobante no es válido.']);
    }

    $amounts = [
        'net_amount' => ['total_neto', 'Total Neto'],
        'vat_total' => ['total_iva', 'Total IVA'],
        'perceptions_total' => ['percepciones', 'Percepciones'],
        'other_taxes_total' => ['otros_imp', 'Otros impuestos'],
        'total_amount' => ['total_original', 'Total'],
    ];
    $validatedAmounts = [];
    foreach ($amounts as $key => [$column, $label]) {
        $validatedAmounts[$column] = moneyValue($data, $key, $label, $key === 'total_amount');
    }

    $textFields = [
        'centro_de_costo' => ['cost_center', 150, 'Centro de costo'],
        'categoria' => ['category', 150, 'Categoría'],
        'tipo_de_gasto' => ['expense_type', 150, 'Tipo de gasto'],
        'observaciones_comprobante' => ['observations', 4000, 'Observaciones'],
        'condicion_iva' => ['iva_condition', 100, 'Condición IVA'],
        'jurisdiccion' => ['jurisdiction', 100, 'Jurisdicción'],
        'codigo_afip' => ['afip_code', 50, 'Código AFIP'],
        'cuenta_contable' => ['account_code', 100, 'Cuenta contable'],
        'credito_fiscal' => ['tax_credit', 150, 'Crédito fiscal'],
        'tipo' => ['expense_label', 150, 'Tipo'],
    ];
    $values = [
        'titular_empresa' => $company,
        'mes' => (string) (int) substr($month, 5, 2),
        'fecha' => DateTimeImmutable::createFromFormat('!Y-m-d', $issueDate)?->format('j/n/Y'),
        'comprobante' => $documentNumber,
        'cuit' => $cuit,
        'proveedor' => $supplierName,
    ] + $validatedAmounts;
    foreach ($textFields as $column => [$key, $maxLength, $label]) {
        $values[$column] = optionalString($data, $key, $maxLength, $label);
    }

    $taxColumns = [
        'gravado-21' => 'gravado_al_21_porcentaje',
        'gravado-105' => 'gravado_al_10_5_porcentaje',
        'gravado-27' => 'gravado_al_27_porcentaje',
        'gravado-5' => 'gravado_al_5_porcentaje',
        'gravado-25' => 'gravado_al_2_5_porcentaje',
        'exento' => 'exento',
        'no-gravado' => 'no_gravado',
        'iva-21' => 'iva_21_porcentaje',
        'iva-105' => 'iva_10_5_porcentaje',
        'iva-27' => 'iva_27_porcentaje',
        'iva-5' => 'iva_5_porcentaje',
        'iva-25' => 'iva_2_5_porcentaje',
        'retenciones' => 'retenciones',
        'percepcion-iva' => 'percepcion_iva_sufrida',
        'percepcion-iibb' => 'percepcion_iibb_sufrida',
        'percepcion-ganancias' => 'percepcion_gan_sufrida',
        'impuesto-municipal' => 'imp_munic',
        'impuesto-interno' => 'imp_int',
        'itc' => 'itc',
        'otros-tributos' => 'otros_trib',
        'iva-no-computable' => 'iva_no_computable',
    ];
    if (isset($data['tax_details']) && !is_array($data['tax_details'])) {
        respond(422, ['error' => 'El desglose tributario no tiene un formato válido.']);
    }
    $taxData = $data['tax_details'] ?? [];
    if (!is_array($taxData)) {
        respond(422, ['error' => 'El desglose tributario no tiene un formato válido.']);
    }
    foreach ($taxColumns as $input => $column) {
        $values[$column] = moneyValue($taxData, $input, 'Desglose ' . $input, false);
    }

    $duplicate = $db->prepare(
        'SELECT id FROM comprobantes
         WHERE titular_empresa = :company AND comprobante = :document_number
           AND (:cuit_filter IS NULL OR cuit = :cuit)
         LIMIT 1'
    );
    $duplicate->execute([
        'company' => $company,
        'document_number' => $documentNumber,
        'cuit_filter' => $cuit,
        'cuit' => $cuit,
    ]);
    if ($duplicate->fetchColumn() !== false) {
        respond(409, ['error' => 'Ya existe un comprobante con esos datos.', 'code' => 'duplicate']);
    }

    $columns = array_keys($values);
    $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
    $insert = $db->prepare(
        'INSERT INTO comprobantes (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $placeholders) . ')'
    );
    $insert->execute($values);
    respond(201, ['message' => 'Comprobante guardado en MySQL.', 'id' => (int) $db->lastInsertId()]);
}

function csvDatabaseImport(PDO $db, string $action): never
{
    $data = requestData();
    $filename = requiredString($data, 'filename', 255, 'Nombre del archivo');
    $csv = requiredString($data, 'csv', 800000, 'Contenido CSV');
    $preview = csvDatabaseParseImport($db, $filename, $csv);
    if ($action === 'imports.validate') {
        respond(200, [
            'total_rows' => $preview['total_rows'],
            'new_rows' => $preview['new_rows'],
            'duplicate_rows' => $preview['duplicate_rows'],
            'new_suppliers' => $preview['new_suppliers'],
            'supplier_names' => $preview['supplier_names'],
            'payroll_rows' => $preview['payroll_rows'],
            'tax_summary_rows' => $preview['tax_summary_rows'],
            'ignored_rows' => $preview['ignored_rows'],
            'message' => 'El archivo se comparó con los comprobantes existentes.',
        ]);
    }

    csvDatabaseRequireSupplierClassificationTable($db);
    if ($preview['new_rows'] === 0) {
        respond(200, [
            'imported_rows' => 0,
            'skipped_rows' => $preview['duplicate_rows'],
            'new_suppliers' => 0,
            'message' => 'No hay comprobantes nuevos; las filas del archivo ya existen.',
        ]);
    }

    $db->beginTransaction();
    $preview = csvDatabaseParseImport($db, $filename, $csv);
    $baseInsertColumns = [
        'titular_empresa', 'mes', 'fecha', 'comprobante', 'cuit', 'proveedor',
        'total_neto', 'total_iva', 'percepciones', 'otros_imp', 'total_original',
        'centro_de_costo', 'categoria', 'tipo_de_gasto', 'observaciones_comprobante',
    ];
    $extraInsertColumns = [
        'condicion_iva', 'jurisdiccion', 'gravado_al_21_porcentaje', 'gravado_al_10_5_porcentaje',
        'gravado_al_27_porcentaje', 'gravado_al_5_porcentaje', 'gravado_al_2_5_porcentaje',
        'gravado_al_0_porcentaje', 'exento', 'gravado_otros', 'gravado_cf', 'no_gravado',
        'no_gravado_cf', 'iva_21_porcentaje', 'iva_10_5_porcentaje', 'iva_27_porcentaje',
        'iva_5_porcentaje', 'iva_2_5_porcentaje', 'iva_otros', 'iva_cf', 'retenciones',
        'percepcion_iva_sufrida', 'percepcion_iibb_sufrida', 'percepcion_gan_sufrida',
        'imp_munic', 'imp_int', 'itc', 'otros_trib', 'iva_no_computable',
        'perc_iibb_901_caba', 'perc_iibb_902_bs_as', 'perc_iibb_903_catam', 'perc_iibb_904_cordoba',
        'perc_iibb_905_corrien', 'perc_iibb_906_chaco', 'perc_iibb_907_chubut', 'perc_iibb_908_e_rios',
        'perc_iibb_909_formosa', 'perc_iibb_910_jujuy', 'perc_iibb_911_la_pampa', 'perc_iibb_912_la_rioja',
        'perc_iibb_913_mendoza', 'perc_iibb_914_misiones', 'perc_iibb_915_neuquen', 'perc_iibb_916_rio_negro',
        'perc_iibb_917_salta', 'perc_iibb_918_san_juan', 'perc_iibb_919_san_luis', 'perc_iibb_920_s_cruz',
        'perc_iibb_921_santa_fe', 'perc_iibb_922_s_estero', 'perc_iibb_923_t_fuego', 'perc_iibb_924_tucuman',
        'codigo_afip', 'cuenta_contable', 'credito_fiscal', 'tipo',
        'total_neto_iva_percepciones', 'observaciones_clasificacion',
    ];
    $allInsertColumns = array_merge($baseInsertColumns, $extraInsertColumns);
    $insert = $db->prepare(
        'INSERT INTO comprobantes (' . implode(', ', $allInsertColumns) . ')
         VALUES (:' . implode(', :', $allInsertColumns) . ')'
    );
    $supplierLookup = $db->prepare('SELECT id FROM proveedores WHERE cuit = :cuit LIMIT 1');
    $supplierInsert = $db->prepare('INSERT INTO proveedores (cuit, razon_social) VALUES (:cuit, :legal_name)');
    $classificationInsert = $db->prepare(
        'INSERT IGNORE INTO proveedor_clasificaciones
            (proveedor_id, centro_de_costo, categoria, tipo_de_gasto)
         VALUES (:supplier_id, :cost_center, :category, :expense_type)'
    );
    $importedRows = 0;
    $addedSuppliers = [];
    foreach ($preview['rows'] as $row) {
        if ($row['duplicate']) {
            continue;
        }

        $supplierId = null;
        if ($row['supplier_cuit'] !== null) {
            $supplierLookup->execute(['cuit' => $row['supplier_cuit']]);
            $foundSupplier = $supplierLookup->fetchColumn();
            if ($foundSupplier !== false) {
                $supplierId = (int) $foundSupplier;
            } elseif ($row['supplier_name'] !== '') {
                try {
                    $supplierInsert->execute([
                        'cuit' => $row['supplier_cuit'],
                        'legal_name' => $row['supplier_name'],
                    ]);
                    $supplierId = (int) $db->lastInsertId();
                    $addedSuppliers[$row['supplier_cuit']] = true;
                } catch (PDOException $error) {
                    if ($error->getCode() !== '23000') {
                        throw $error;
                    }
                    $supplierLookup->execute(['cuit' => $row['supplier_cuit']]);
                    $foundSupplier = $supplierLookup->fetchColumn();
                    if ($foundSupplier === false) {
                        throw $error;
                    }
                    $supplierId = (int) $foundSupplier;
                }
            }
        }

        $insertValues = array_fill_keys($extraInsertColumns, null);
        $insertValues = array_replace($insertValues, [
            'titular_empresa' => $row['company'],
            'mes' => (string) (int) substr($row['month'], 5, 2),
            'fecha' => DateTimeImmutable::createFromFormat('!Y-m-d', $row['issue_date'])->format('j/n/Y'),
            'comprobante' => $row['document_number'],
            'cuit' => $row['supplier_cuit'],
            'proveedor' => $row['supplier_name'] === '' ? null : $row['supplier_name'],
            'total_neto' => $row['net_amount'],
            'total_iva' => $row['vat_total'],
            'percepciones' => $row['perceptions_total'],
            'otros_imp' => $row['other_taxes_total'],
            'total_original' => $row['total_amount'],
            'centro_de_costo' => $row['cost_center'] === '' ? null : $row['cost_center'],
            'categoria' => $row['category'] === '' ? null : $row['category'],
            'tipo_de_gasto' => $row['expense_type'] === '' ? null : $row['expense_type'],
            'observaciones_comprobante' => $row['observations'] === '' ? null : $row['observations'],
        ], $row['extra_fields'] ?? []);
        $insert->execute($insertValues);
        if ($supplierId !== null
            && $row['cost_center'] !== '' && $row['category'] !== '' && $row['expense_type'] !== ''
            && mb_strlen($row['cost_center']) <= 100 && mb_strlen($row['category']) <= 100
            && mb_strlen($row['expense_type']) <= 100) {
            $assignmentCount = $db->prepare(
                'SELECT COUNT(*) FROM proveedor_clasificaciones WHERE proveedor_id = :supplier_id'
            );
            $assignmentCount->execute(['supplier_id' => $supplierId]);
            if ((int) $assignmentCount->fetchColumn() === 0) {
                $seedAssignments = $db->prepare(
                    'INSERT IGNORE INTO proveedor_clasificaciones
                        (proveedor_id, centro_de_costo, categoria, tipo_de_gasto)
                     SELECT :supplier_id, centro_de_costo, categoria, tipo_de_gasto
                     FROM comprobantes
                     WHERE cuit = :cuit
                       AND NULLIF(TRIM(centro_de_costo), \'\') IS NOT NULL
                       AND NULLIF(TRIM(categoria), \'\') IS NOT NULL
                       AND NULLIF(TRIM(tipo_de_gasto), \'\') IS NOT NULL
                       AND UPPER(TRIM(centro_de_costo)) NOT IN (\'N/A\', \'#N/A\', \'PENDIENTE CLASIFICACION\')
                       AND UPPER(TRIM(categoria)) NOT IN (\'N/A\', \'#N/A\', \'PENDIENTE CLASIFICACION\')
                       AND UPPER(TRIM(tipo_de_gasto)) NOT IN (\'N/A\', \'#N/A\', \'PENDIENTE CLASIFICACION\')
                       AND CHAR_LENGTH(centro_de_costo) <= 100
                       AND CHAR_LENGTH(categoria) <= 100
                       AND CHAR_LENGTH(tipo_de_gasto) <= 100'
                );
                $seedAssignments->execute([
                    'supplier_id' => $supplierId,
                    'cuit' => $row['supplier_cuit'],
                ]);
            }
            $classificationInsert->execute([
                'supplier_id' => $supplierId,
                'cost_center' => $row['cost_center'],
                'category' => $row['category'],
                'expense_type' => $row['expense_type'],
            ]);
        }
        $importedRows++;
    }
    $db->commit();
    respond(200, [
        'imported_rows' => $importedRows,
        'skipped_rows' => $preview['total_rows'] - $importedRows,
        'new_suppliers' => count($addedSuppliers),
        'message' => sprintf(
            '%d registros nuevos agregados; %d repetidos omitidos y %d proveedores nuevos creados.',
            $importedRows,
            $preview['total_rows'] - $importedRows,
            count($addedSuppliers)
        ),
    ]);
}

function handleCsvDatabase(PDO $db, string $method, string $action): never
{
    if ($method === 'GET' && $action === 'supplier.detail') {
        csvDatabaseRequireSupplierClassificationTable($db);
        $supplierId = positiveInteger($_GET['id'] ?? null, 'proveedor');
        $supplierQuery = $db->prepare(
            'SELECT id, cuit, razon_social AS legal_name, created_at
             FROM proveedores WHERE id = :id LIMIT 1'
        );
        $supplierQuery->execute(['id' => $supplierId]);
        $supplier = $supplierQuery->fetch();
        if ($supplier === false) {
            respond(404, ['error' => 'No se encontró el proveedor solicitado.']);
        }

        $historyQuery = $db->prepare(
            'SELECT centro_de_costo, categoria, tipo_de_gasto, cuenta_contable,
                    COUNT(*) AS records_count
             FROM comprobantes
             WHERE cuit = :cuit
             GROUP BY centro_de_costo, categoria, tipo_de_gasto, cuenta_contable
             ORDER BY records_count DESC, categoria, centro_de_costo, tipo_de_gasto'
        );
        $historyQuery->execute(['cuit' => $supplier['cuit']]);
        $history = $historyQuery->fetchAll();
        $assignments = csvDatabaseSupplierClassifications($db, $supplierId);
        $assignmentsFromHistory = false;
        if ($assignments === []) {
            $assignmentQuery = $db->prepare(
                'SELECT DISTINCT centro_de_costo, categoria, tipo_de_gasto
                 FROM comprobantes
                 WHERE cuit = :cuit
                   AND NULLIF(TRIM(centro_de_costo), \'\') IS NOT NULL
                   AND NULLIF(TRIM(categoria), \'\') IS NOT NULL
                   AND NULLIF(TRIM(tipo_de_gasto), \'\') IS NOT NULL
                   AND UPPER(TRIM(centro_de_costo)) NOT IN (\'N/A\', \'#N/A\', \'PENDIENTE CLASIFICACION\')
                   AND UPPER(TRIM(categoria)) NOT IN (\'N/A\', \'#N/A\', \'PENDIENTE CLASIFICACION\')
                   AND UPPER(TRIM(tipo_de_gasto)) NOT IN (\'N/A\', \'#N/A\', \'PENDIENTE CLASIFICACION\')
                 ORDER BY centro_de_costo, categoria, tipo_de_gasto'
            );
            $assignmentQuery->execute(['cuit' => $supplier['cuit']]);
            $assignments = $assignmentQuery->fetchAll();
            $assignmentsFromHistory = $assignments !== [];
        }
        respond(200, [
            'supplier' => [
                'id' => (int) $supplier['id'],
                'cuit' => $supplier['cuit'],
                'legal_name' => $supplier['legal_name'],
                'created_at' => $supplier['created_at'],
            ],
            'history' => $history,
            'assignments' => $assignments,
            'assignments_from_history' => $assignmentsFromHistory,
        ]);
    }

    if ($method === 'GET' && $action === 'periods') {
        respond(200, ['items' => csvDatabasePeriods($db)]);
    }

    if ($method === 'GET' && $action === 'bootstrap') {
        $periods = csvDatabasePeriods($db);
        $periodValue = $_GET['month'] ?? '';
        if (!is_string($periodValue)) {
            respond(422, ['error' => 'El período debe tener formato AAAA-MM.']);
        }
        $period = trim($periodValue);
        if ($period === '' && $periods !== []) {
            $period = $periods[0];
        }
        if ($period !== '' && !validMonth($period)) {
            respond(422, ['error' => 'El período debe tener formato AAAA-MM.']);
        }

        $records = csvDatabaseRecords($db, ['month' => $period]);
        $allRecords = csvDatabaseRecords($db);
        $totalAmount = csvDatabaseAmountSum($records, 'total_amount');
        $netAmount = csvDatabaseAmountSum($records, 'net_amount');
        $categories = csvDatabaseGroups($records, 'category', 'Sin categoría');
        $expenseTypes = csvDatabaseGroups($records, 'expense_type', 'Sin tipo de gasto');
        $supplierCount = (int) $db->query('SELECT COUNT(*) FROM proveedores')->fetchColumn();
        $pendingCount = (int) $db->query(
            'SELECT COUNT(*) FROM pendientes_reclasificacion WHERE estado = \'Pendiente\''
        )->fetchColumn();

        respond(200, [
            'period' => $period,
            'summary' => [
                'suppliers_count' => $supplierCount,
                'expenses_count' => count($records),
                'pending_count' => $pendingCount,
                'categories_count' => count(csvDatabaseCatalog($db, 'categoria')),
                'cost_centers_count' => count(csvDatabaseCatalog($db, 'centro_de_costo')),
                'expense_types_count' => count(csvDatabaseCatalog($db, 'tipo_de_gasto')),
                'total_amount' => $totalAmount,
                'total_net_amount' => $netAmount,
            ],
            'categories' => $categories,
            'expense_types' => $expenseTypes,
            'expenses' => array_map('csvDatabasePublicRecord', array_slice($records, 0, 5)),
            'claims' => csvDatabasePendingReclassifications($db, '', 'Pendiente', 5),
            'claims_available' => true,
            'imports_available' => false,
            'total_records' => count($allRecords),
            'unassigned_period_records' => count(array_filter(
                $allRecords,
                static fn (array $record): bool => $record['accounting_month'] === null
            )),
        ]);
    }

    if ($method === 'GET' && $action === 'expenses') {
        $items = csvDatabaseRecords($db, [
            'month' => $_GET['month'] ?? '',
            'q' => $_GET['q'] ?? '',
            'company' => $_GET['company'] ?? '',
        ]);
        respond(200, ['items' => array_map('csvDatabasePublicRecord', $items)]);
    }

    if ($method === 'GET' && $action === 'suppliers') {
        $queryValue = $_GET['q'] ?? '';
        if (!is_string($queryValue)) {
            respond(422, ['error' => 'La búsqueda debe ser texto.']);
        }
        $query = trim($queryValue);
        if (mb_strlen($query) > 120) {
            respond(422, ['error' => 'La búsqueda admite hasta 120 caracteres.']);
        }
        $cuitQuery = preg_replace('/\D+/', '', $query) ?? '';
        $statement = $db->prepare(
            'SELECT id, cuit, razon_social, created_at
             FROM proveedores
             WHERE (:query_filter = \'\' OR razon_social LIKE :match1
                    OR (:cuit_filter <> \'\' AND cuit LIKE :match2))
             ORDER BY razon_social, id
             LIMIT 1000'
        );
        $statement->execute([
            'query_filter' => $query,
            'match1' => '%' . $query . '%',
            'cuit_filter' => $cuitQuery,
            'match2' => '%' . $cuitQuery . '%',
        ]);
        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'cuit' => $row['cuit'],
            'legal_name' => $row['razon_social'],
            'created_at' => $row['created_at'],
        ], $statement->fetchAll());
        respond(200, ['items' => $items]);
    }

    if ($method === 'GET' && $action === 'catalogs') {
        respond(200, [
            'costCenters' => csvDatabaseCatalog($db, 'centro_de_costo'),
            'categories' => csvDatabaseCatalog($db, 'categoria'),
            'expenseTypes' => csvDatabaseCatalog($db, 'tipo_de_gasto'),
            'classifications' => [],
            'suppliers' => [],
            'catalogManagement' => [],
            'editable' => false,
        ]);
    }

    if ($method === 'GET' && $action === 'classifications') {
        $statement = $db->query(
            'SELECT centro_de_costo AS cost_center, categoria AS category,
                    tipo_de_gasto AS expense_type, cuenta_contable AS account_code,
                    COUNT(*) AS records_count, COUNT(DISTINCT NULLIF(cuit, \'\')) AS suppliers_count
             FROM comprobantes
             GROUP BY centro_de_costo, categoria, tipo_de_gasto, cuenta_contable
             ORDER BY categoria, centro_de_costo, tipo_de_gasto'
        );
        respond(200, ['items' => $statement->fetchAll(), 'editable' => false]);
    }

    if ($method === 'GET' && $action === 'claims') {
        $query = $_GET['q'] ?? '';
        $status = $_GET['status'] ?? '';
        if (!is_string($query) || !is_string($status)) {
            respond(422, ['error' => 'Los filtros de pendientes no tienen un formato válido.']);
        }
        respond(200, [
            'items' => csvDatabasePendingReclassifications($db, $query, $status),
            'available' => true,
        ]);
    }

    if ($method === 'GET' && $action === 'imports') {
        respond(200, [
            'items' => [],
            'available' => false,
            'message' => 'El escaneo compara cada archivo con comprobantes existentes; esta base no conserva un historial de cargas.',
        ]);
    }

    if ($method === 'POST' && $action === 'supplier.create') {
        $data = requestData();
        $legalName = requiredString($data, 'legal_name', 255, 'Razón social');
        $cuit = csvDatabaseCuit($data);
        if ($cuit === null) {
            respond(422, ['error' => 'Ingresá un CUIT válido de 11 dígitos con dígito verificador.']);
        }
        try {
            $insert = $db->prepare('INSERT INTO proveedores (cuit, razon_social) VALUES (:cuit, :legal_name)');
            $insert->execute(['cuit' => $cuit, 'legal_name' => $legalName]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                respond(409, ['error' => 'Ya existe un proveedor con ese CUIT.']);
            }
            throw $error;
        }
        respond(201, ['message' => 'Proveedor registrado en MySQL.', 'id' => (int) $db->lastInsertId()]);
    }

    if ($method === 'POST' && $action === 'supplier.update') {
        $data = requestData();
        $id = positiveInteger($data['id'] ?? null, 'proveedor');
        $legalName = requiredString($data, 'legal_name', 255, 'Razón social');
        $cuit = csvDatabaseCuit($data);
        if ($cuit === null) {
            respond(422, ['error' => 'Ingresá un CUIT válido de 11 dígitos con dígito verificador.']);
        }
        $exists = $db->prepare('SELECT id FROM proveedores WHERE id = :id');
        $exists->execute(['id' => $id]);
        if ($exists->fetchColumn() === false) {
            respond(404, ['error' => 'El proveedor ya no existe.']);
        }
        try {
            $update = $db->prepare(
                'UPDATE proveedores SET cuit = :cuit, razon_social = :legal_name WHERE id = :id'
            );
            $update->execute(['cuit' => $cuit, 'legal_name' => $legalName, 'id' => $id]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                respond(409, ['error' => 'Ya existe otro proveedor con ese CUIT.']);
            }
            throw $error;
        }
        respond(200, ['message' => 'Proveedor actualizado en MySQL.']);
    }

    if ($method === 'POST' && $action === 'supplier.classifications.update') {
        csvDatabaseRequireSupplierClassificationTable($db);
        $data = requestData();
        $supplierId = positiveInteger($data['supplier_id'] ?? null, 'proveedor');
        $rawAssignments = $data['assignments'] ?? null;
        if (!is_array($rawAssignments) || count($rawAssignments) > 100) {
            respond(422, ['error' => 'La lista de clasificaciones no es válida.']);
        }
        if ($rawAssignments === []) {
            respond(422, ['error' => 'Agregá al menos una combinación para guardar las asignaciones del proveedor.']);
        }

        $supplierQuery = $db->prepare('SELECT id FROM proveedores WHERE id = :id');
        $supplierQuery->execute(['id' => $supplierId]);
        if ($supplierQuery->fetchColumn() === false) {
            respond(404, ['error' => 'El proveedor ya no existe.']);
        }

        $allowedValues = [];
        foreach ([
            'centro_de_costo' => 'centro_de_costo',
            'categoria' => 'categoria',
            'tipo_de_gasto' => 'tipo_de_gasto',
        ] as $field => $column) {
            $allowedValues[$field] = array_fill_keys(
                array_column(csvDatabaseCatalog($db, $column), 'name'),
                true
            );
        }

        $assignments = [];
        foreach ($rawAssignments as $assignment) {
            if (!is_array($assignment)) {
                respond(422, ['error' => 'Una clasificación tiene un formato no válido.']);
            }
            $values = [];
            foreach (array_keys($allowedValues) as $field) {
                $value = requiredString($assignment, $field, 100, 'Cada clasificación requiere centro de costo, categoría y tipo de gasto.');
                if (!isset($allowedValues[$field][$value])) {
                    respond(422, ['error' => 'Elegí valores existentes en los catálogos de comprobantes.']);
                }
                $values[$field] = $value;
            }
            $key = json_encode(array_values($values), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $assignments[$key] = $values;
        }

        $db->beginTransaction();
        $delete = $db->prepare('DELETE FROM proveedor_clasificaciones WHERE proveedor_id = :supplier_id');
        $delete->execute(['supplier_id' => $supplierId]);
        $insert = $db->prepare(
            'INSERT INTO proveedor_clasificaciones
                (proveedor_id, centro_de_costo, categoria, tipo_de_gasto)
             VALUES (:supplier_id, :cost_center, :category, :expense_type)'
        );
        foreach ($assignments as $assignment) {
            $insert->execute([
                'supplier_id' => $supplierId,
                'cost_center' => $assignment['centro_de_costo'],
                'category' => $assignment['categoria'],
                'expense_type' => $assignment['tipo_de_gasto'],
            ]);
        }
        $db->commit();
        respond(200, [
            'message' => 'Clasificaciones del proveedor actualizadas.',
            'count' => count($assignments),
        ]);
    }

    if ($method === 'POST' && in_array($action, ['imports.validate', 'imports.create'], true)) {
        csvDatabaseImport($db, $action);
    }

    if ($method === 'POST' && $action === 'expense.create') {
        csvDatabaseCreateExpense($db);
    }

    if ($method === 'POST' && $action === 'reclassification.update') {
        $data = requestData();
        $id = positiveInteger($data['id'] ?? null, 'pendiente');
        $status = requiredString($data, 'status', 20, 'Estado');
        if (!in_array($status, ['Pendiente', 'Resuelto'], true)) {
            respond(422, ['error' => 'El estado debe ser Pendiente o Resuelto.']);
        }

        $check = $db->prepare('SELECT id FROM pendientes_reclasificacion WHERE id = :id');
        $check->execute(['id' => $id]);
        if ($check->fetchColumn() === false) {
            respond(404, ['error' => 'El pendiente de reclasificación ya no existe.']);
        }

        $update = $db->prepare(
            'UPDATE pendientes_reclasificacion SET estado = :status WHERE id = :id'
        );
        $update->execute(['status' => $status, 'id' => $id]);
        respond(200, [
            'message' => $status === 'Resuelto'
                ? 'Pendiente marcado como resuelto.'
                : 'Pendiente vuelto a marcar como pendiente.',
        ]);
    }

    if ($method === 'POST' && str_starts_with($action, 'claim.')) {
        csvDatabaseUnavailable('La gestión de reclamos', 'claims, claim_history');
    }
    if ($method === 'POST' && str_starts_with($action, 'classification.')) {
        csvDatabaseUnavailable('La edición de clasificaciones', 'classifications, supplier_classifications');
    }
    if ($method === 'POST' && str_starts_with($action, 'catalog.')) {
        csvDatabaseUnavailable('La edición de catálogos', 'categories, cost_centers, expense_types');
    }
    if ($method === 'POST' && str_starts_with($action, 'imports.')) {
        csvDatabaseUnavailable('La importación con historial', 'imports');
    }

    respond(404, ['error' => 'La operación solicitada no existe para esta API.']);
}
