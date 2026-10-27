<?php
declare(strict_types=1);

$localConfig = __DIR__ . '/database.local.php';

if (!is_file($localConfig)) {
    throw new RuntimeException(
        'Falta config/database.local.php. Copiá config/database.example.php y configurá tus datos locales.'
    );
}

$config = require $localConfig;
$requiredKeys = ['host', 'port', 'database', 'username', 'password', 'charset'];

if (!is_array($config) || array_diff($requiredKeys, array_keys($config)) !== []) {
    throw new RuntimeException('La configuración local de la base de datos está incompleta.');
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['host'],
    $config['port'],
    $config['database'],
    $config['charset']
);

return new PDO($dsn, $config['username'], $config['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
]);
