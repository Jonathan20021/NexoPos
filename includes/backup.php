<?php
/**
 * Respaldo de la base de datos: genera un dump .sql completo vía PDO (sin depender de mysqldump).
 */
function backup_sql_download(): void
{
    while (ob_get_level() > 0) ob_end_clean();
    $pdo = db();
    $dbname = DB_NAME;
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="respaldo_' . $dbname . '_' . date('Ymd_His') . '.sql"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');

    fwrite($out, "-- Respaldo de la base de datos `$dbname`\n-- " . APP_NAME . " · Generado: " . date('Y-m-d H:i:s') . "\n");
    fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

    $tablas = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tablas as $t) {
        fwrite($out, "-- ----------------------------\n-- Estructura de `$t`\n-- ----------------------------\n");
        fwrite($out, "DROP TABLE IF EXISTS `$t`;\n");
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
        fwrite($out, ($create['Create Table'] ?? '') . ";\n\n");

        $stmt = $pdo->query("SELECT * FROM `$t`");
        $cols = null;
        $buffer = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($cols === null) $cols = '`' . implode('`,`', array_keys($row)) . '`';
            $vals = [];
            foreach ($row as $v) {
                if ($v === null) {
                    $vals[] = 'NULL';
                } elseif (is_numeric($v) && !preg_match('/^0\d/', (string) $v)) {
                    $vals[] = $v;
                } else {
                    $vals[] = $pdo->quote((string) $v);
                }
            }
            $buffer[] = '(' . implode(',', $vals) . ')';
            if (count($buffer) >= 200) {
                fwrite($out, "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $buffer) . ";\n");
                $buffer = [];
            }
        }
        if ($buffer) {
            fwrite($out, "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $buffer) . ";\n");
        }
        fwrite($out, "\n");
    }
    fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($out);
    exit;
}

/** Estadísticas de las tablas para mostrar en la pantalla de respaldo. */
function backup_stats(): array
{
    $rows = qAll(
        "SELECT table_name AS tabla, table_rows AS filas, ROUND((data_length + index_length)/1024,1) AS kb
         FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name",
        [DB_NAME]
    );
    return $rows;
}
