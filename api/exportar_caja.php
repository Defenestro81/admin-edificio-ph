<?php
require_once __DIR__ . '/../includes/config.php';
// Todos los endpoints exigen sesión iniciada. El SPA se sirve desde el mismo
// origen, así que no hacen falta cabeceras CORS (y un Allow-Origin: * sería
// contraproducente: impediría el envío de la cookie de sesión).
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err('Método no permitido', 405);

$db = db();

// Obtener TODOS los movimientos sin filtros
$sql = 'SELECT c.*, u.nombre AS unidad_nombre
        FROM caja c
        LEFT JOIN unidades u ON u.id = c.unidad_id
        ORDER BY c.fecha DESC, c.id DESC';

$st = $db->query($sql);
$movimientos = $st->fetchAll();

// Calcular saldo total
$saldo = (float) $db->query(
    'SELECT COALESCE(SUM(CASE tipo WHEN \'ingreso\' THEN importe ELSE -importe END), 0) FROM caja'
)->fetchColumn();

// Crear el contenido CSV
$csv = [];
$csv[] = ['MOVIMIENTOS DE CAJA - EXPORTACIÓN COMPLETA'];
$csv[] = ['Fecha de exportación: ' . date('d/m/Y H:i:s')];
$csv[] = ['Saldo actual del fondo: $' . number_format($saldo, 2, ',', '.')];
$csv[] = [];
$csv[] = ['Fecha', 'Concepto', 'Unidad', 'Tipo', 'Importe', 'Notas', 'Período'];

foreach ($movimientos as $m) {
    $csv[] = [
        $m['fecha'],
        $m['concepto'],
        $m['unidad_nombre'] ?: '-',
        $m['tipo'] === 'ingreso' ? 'Ingreso' : 'Egreso',
        ($m['tipo'] === 'ingreso' ? '' : '-') . number_format($m['importe'], 2, ',', '.'),
        $m['notas'] ?: '',
        $m['periodo'] ?: ''
    ];
}

// Generar el archivo CSV con BOM para que Excel lo abra correctamente con UTF-8
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="movimientos_caja_' . date('Ymd_His') . '.csv"');

// BOM para UTF-8
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');
foreach ($csv as $row) {
    fputcsv($output, $row, ';'); // Usar punto y coma como separador para Excel en español
}
fclose($output);
exit;