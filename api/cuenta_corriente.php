<?php
require_once __DIR__ . '/../includes/config.php';
// Todos los endpoints exigen sesión iniciada. El rol 'consulta' puede leer
// (GET) pero no modificar: las escrituras piden rol 'admin'. El SPA se sirve
// desde el mismo origen, así que no hacen falta cabeceras CORS (y un
// Allow-Origin: * sería contraproducente: impediría el envío de la cookie).
requireLoginAdminParaEscritura();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err('Método no permitido', 405);

$periodo   = $_GET['periodo']   ?? null;
$unidad_id = $_GET['unidad_id'] ?? null;

// GET ?periodo=YYYYMM — resumen de todas las unidades en ese período
if ($periodo && !$unidad_id) {
    $st = db()->prepare('
        SELECT cc.*, u.nombre, u.propietario, u.email
        FROM cuenta_corriente cc
        JOIN unidades u ON u.id = cc.unidad_id
        WHERE cc.periodo = ?
        ORDER BY u.orden, u.id
    ');
    $st->execute([$periodo]);
    json_ok($st->fetchAll());
}

// GET ?unidad_id=X — historial completo de una unidad
if ($unidad_id && !$periodo) {
    $st = db()->prepare('
        SELECT cc.*, u.nombre
        FROM cuenta_corriente cc
        JOIN unidades u ON u.id = cc.unidad_id
        WHERE cc.unidad_id = ?
        ORDER BY cc.periodo DESC
    ');
    $st->execute([$unidad_id]);
    json_ok($st->fetchAll());
}

// GET ?unidad_id=X&periodo=YYYYMM — saldo anterior para usar en liquidación
if ($periodo && $unidad_id) {
    // Buscar el período inmediatamente anterior con registro
    $st = db()->prepare('
        SELECT saldo_final FROM cuenta_corriente
        WHERE unidad_id = ? AND periodo < ?
        ORDER BY periodo DESC LIMIT 1
    ');
    $st->execute([$unidad_id, $periodo]);
    $row = $st->fetch();
    json_ok(['saldo_anterior' => $row ? (float)$row['saldo_final'] : 0]);
}

json_err('Faltan parámetros');
