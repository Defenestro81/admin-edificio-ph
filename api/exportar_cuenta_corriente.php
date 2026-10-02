<?php
require_once __DIR__ . '/../includes/config.php';
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err('Método no permitido', 405);

$unidad_id = isset($_GET['unidad_id']) ? (int)$_GET['unidad_id'] : null;

if (!$unidad_id) {
    json_err('Falta unidad_id');
}

$db = db();

// Obtener datos de la unidad
$stUnidad = $db->prepare('SELECT * FROM unidades WHERE id = ?');
$stUnidad->execute([$unidad_id]);
$unidad = $stUnidad->fetch();

if (!$unidad) {
    json_err('Unidad no encontrada', 404);
}

// Obtener historial completo de cuenta corriente
$st = $db->prepare('
    SELECT cc.*
    FROM cuenta_corriente cc
    WHERE cc.unidad_id = ?
    ORDER BY cc.periodo DESC
');
$st->execute([$unidad_id]);
$movimientos = $st->fetchAll();

// Mapeo de meses
$meses = [
    '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo',
    '04' => 'Abril', '05' => 'Mayo', '06' => 'Junio',
    '07' => 'Julio', '08' => 'Agosto', '09' => 'Septiembre',
    '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'
];

// Crear el contenido CSV
$csv = [];
$csv[] = ['CUENTA CORRIENTE - ' . $unidad['nombre']];
$csv[] = ['Propietario: ' . ($unidad['propietario'] ?: '-')];
$csv[] = ['Email: ' . ($unidad['email'] ?: '-')];
$csv[] = ['Fecha de exportación: ' . date('d/m/Y H:i:s')];
$csv[] = [];
$csv[] = ['Período', 'Saldo Anterior', 'Deuda del Mes', 'Extraordinario', 'Pagado', 'Saldo Final', 'Estado'];

foreach ($movimientos as $m) {
    $mesNum = substr($m['periodo'], 4, 2);
    $anio = substr($m['periodo'], 0, 4);
    $periodoLabel = $meses[$mesNum] . ' ' . $anio;

    $saldoFinal = (float)$m['saldo_final'];
    $estado = $saldoFinal <= 0 ? 'Al día' : 'Debe $' . number_format($saldoFinal, 2, ',', '.');

    $csv[] = [
        $periodoLabel,
        number_format($m['saldo_anterior'], 2, ',', '.'),
        number_format($m['deuda'], 2, ',', '.'),
        number_format($m['extraordinario'], 2, ',', '.'),
        number_format($m['pagado'], 2, ',', '.'),
        number_format($m['saldo_final'], 2, ',', '.'),
        $estado
    ];
}

// Generar el archivo CSV con BOM para que Excel lo abra correctamente con UTF-8
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="cuenta_corriente_' . preg_replace('/[^a-zA-Z0-9]/', '_', $unidad['nombre']) . '_' . date('Ymd_His') . '.csv"');

// BOM para UTF-8
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');
foreach ($csv as $row) {
    fputcsv($output, $row, ';'); // Usar punto y coma como separador para Excel en español
}
fclose($output);
exit;