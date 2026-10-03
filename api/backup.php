<?php
require_once __DIR__ . '/../includes/config.php';

// Exporta y restaura la base completa: ambas operaciones son de administrador,
// así que se exige el rol en cualquier método, no solo en las escrituras.
requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET')       exportar();
elseif ($method === 'POST')  importar();
else                         json_err('Método no permitido', 405);

// ── Export ──────────────────────────────────────────────────────────────────

function exportar(): void {
    $db     = db();
    $tablas = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    $out  = "-- ==========================================================\n";
    $out .= "--  Backup — Administración de Edificio\n";
    $out .= "--  Generado: " . date('Y-m-d H:i:s') . "\n";
    $out .= "-- ==========================================================\n\n";
    $out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tablas as $tabla) {
        // DDL completo de la tabla
        $ddl  = $db->query("SHOW CREATE TABLE `{$tabla}`")->fetch(PDO::FETCH_NUM);
        $out .= "-- tabla: {$tabla}\n";
        $out .= "DROP TABLE IF EXISTS `{$tabla}`;\n";
        $out .= $ddl[1] . ";\n\n";

        // Solo columnas no generadas para los INSERT
        $cols = $db->query(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = " . $db->quote($tabla) . "
               AND EXTRA NOT LIKE '%STORED GENERATED%'
               AND EXTRA NOT LIKE '%VIRTUAL GENERATED%'
             ORDER BY ORDINAL_POSITION"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (empty($cols)) continue;

        $colsSql = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
        $filas   = $db->query("SELECT {$colsSql} FROM `{$tabla}`")->fetchAll(PDO::FETCH_NUM);

        foreach ($filas as $fila) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $db->quote($v), $fila);
            $out .= "INSERT INTO `{$tabla}` ({$colsSql}) VALUES (" . implode(', ', $vals) . ");\n";
        }
        $out .= "\n";
    }

    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $nombre = 'edificio_backup_' . date('Ymd_His') . '.sql';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Cache-Control: no-cache, must-revalidate');
    echo $out;
    exit;
}

// ── Import ───────────────────────────────────────────────────────────────────

function importar(): void {
    if (empty($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
        json_err('No se recibió el archivo o hubo un error al subirlo');
    }

    $archivo = $_FILES['backup'];

    if (strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)) !== 'sql') {
        json_err('Solo se aceptan archivos .sql');
    }

    if ($archivo['size'] > 50 * 1024 * 1024) {
        json_err('Archivo demasiado grande (máximo 50 MB)');
    }

    $contenido = file_get_contents($archivo['tmp_name']);
    if (!$contenido) {
        json_err('El archivo está vacío');
    }

    $sentencias = parsearSql($contenido);
    if (empty($sentencias)) {
        json_err('No se encontraron sentencias SQL válidas en el archivo');
    }

    $db        = db();
    $ejecutadas = 0;

    try {
        $db->exec('SET FOREIGN_KEY_CHECKS=0');

        foreach ($sentencias as $stmt) {
            // Las sentencias SET FK_CHECKS del dump las saltamos; ya las controlamos nosotros
            if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS/i', $stmt)) continue;
            $db->exec($stmt);
            $ejecutadas++;
        }

        $db->exec('SET FOREIGN_KEY_CHECKS=1');
        json_ok(['sentencias' => $ejecutadas]);
    } catch (PDOException $e) {
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
        json_err('Error al restaurar: ' . $e->getMessage());
    }
}

// ── Utilidad: divide SQL en sentencias individuales ──────────────────────────

function parsearSql(string $sql): array {
    // Quitar comentarios
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

    $stmts = [];
    $buf   = '';
    $len   = strlen($sql);
    $inStr = false;
    $delim = '';

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];

        if (!$inStr && ($c === "'" || $c === '"' || $c === '`')) {
            $inStr = true;
            $delim = $c;
            $buf  .= $c;
        } elseif ($inStr && $c === $delim) {
            // Comilla escapada duplicada: '' o ""
            if (isset($sql[$i + 1]) && $sql[$i + 1] === $delim) {
                $buf .= $c . $delim;
                $i++;
            } else {
                $inStr = false;
                $buf  .= $c;
            }
        } elseif (!$inStr && $c === ';') {
            $s = trim($buf);
            if ($s !== '') $stmts[] = $s;
            $buf = '';
        } else {
            $buf .= $c;
        }
    }

    $last = trim($buf);
    if ($last !== '') $stmts[] = $last;

    return $stmts;
}
