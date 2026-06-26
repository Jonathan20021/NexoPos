<?php
require_once __DIR__ . '/config.php';

/**
 * Conexión PDO compartida (singleton).
 * @param bool $sinBase  true para conectar sin seleccionar base (usado por el instalador).
 */
function db(bool $sinBase = false): PDO
{
    static $pdo = null;
    if ($sinBase) {
        $dsn = 'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET;
        return new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '-04:00'"); // Santo Domingo (sin horario de verano)
    }
    return $pdo;
}
