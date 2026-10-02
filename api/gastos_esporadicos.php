<?php
require_once __DIR__ . '/../includes/config.php';
// Todos los endpoints exigen sesión iniciada. El SPA se sirve desde el mismo
// origen, así que no hacen falta cabeceras CORS (y un Allow-Origin: * sería
// contraproducente: impediría el envío de la cookie de sesión).
requireLogin();

$method  = $_SERVER['REQUEST_METHOD'];
$id      = isset($_GET['id'])     ? (int)$_GET['id']     : null;
$periodo = isset($_GET['periodo']) ? $_GET['periodo']      : null;

// GET — listar por período
if ($method === 'GET') {
    if (!$periodo) json_err('Falta período');
    $st = db()->prepare('SELECT * FROM gastos_esporadicos WHERE periodo = ? ORDER BY id');
    $st->execute([$periodo]);
    $gastos = $st->fetchAll();
    foreach ($gastos as &$g) {
        $st2 = db()->prepare('SELECT unidad_id FROM gastos_esporadicos_unidades WHERE gasto_esporadico_id = ?');
        $st2->execute([$g['id']]);
        $g['unidades'] = array_column($st2->fetchAll(), 'unidad_id');
    }
    json_ok($gastos);
}

// POST — crear
if ($method === 'POST') {
    $b = body();
    if (empty($b['concepto']) || !isset($b['importe']) || empty($b['unidades']) || empty($b['periodo'])) json_err('Faltan campos');

    // Invalidar liquidación ORDINARIA del período (no toca extraordinarias)
    db()->prepare("DELETE FROM liquidaciones WHERE periodo = ? AND tipo = 'ordinaria'")->execute([$b['periodo']]);

    $db = db();
    $db->beginTransaction();
    $st = $db->prepare('INSERT INTO gastos_esporadicos (periodo, concepto, importe, division) VALUES (?,?,?,?)');
    $st->execute([$b['periodo'], $b['concepto'], $b['importe'], $b['division'] ?? 'coeficiente']);
    $gid = $db->lastInsertId();

    $ins = $db->prepare('INSERT INTO gastos_esporadicos_unidades (gasto_esporadico_id, unidad_id) VALUES (?,?)');
    foreach ($b['unidades'] as $uid) $ins->execute([$gid, $uid]);

    $db->commit();
    json_ok(['id' => $gid]);
}

// DELETE
if ($method === 'DELETE' && $id) {
    $st = db()->prepare('SELECT periodo FROM gastos_esporadicos WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row) db()->prepare("DELETE FROM liquidaciones WHERE periodo = ? AND tipo = 'ordinaria'")->execute([$row['periodo']]);
    db()->prepare('DELETE FROM gastos_esporadicos WHERE id = ?')->execute([$id]);
    json_ok();
}
