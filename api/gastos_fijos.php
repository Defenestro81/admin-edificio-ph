<?php
require_once __DIR__ . '/../includes/config.php';
// Todos los endpoints exigen sesión iniciada. El SPA se sirve desde el mismo
// origen, así que no hacen falta cabeceras CORS (y un Allow-Origin: * sería
// contraproducente: impediría el envío de la cookie de sesión).
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

// GET — listar con sus unidades asociadas
if ($method === 'GET' && !$id) {
    $gastos = db()->query('SELECT * FROM gastos_fijos WHERE activo = 1 ORDER BY id')->fetchAll();
    foreach ($gastos as &$g) {
        $st = db()->prepare('SELECT unidad_id FROM gastos_fijos_unidades WHERE gasto_fijo_id = ?');
        $st->execute([$g['id']]);
        $g['unidades'] = array_column($st->fetchAll(), 'unidad_id');
    }
    json_ok($gastos);
}

// POST — crear
if ($method === 'POST') {
    $b = body();
    if (empty($b['concepto']) || !isset($b['importe']) || empty($b['unidades'])) json_err('Faltan campos');

    $db = db();
    $db->beginTransaction();
    $st = $db->prepare('INSERT INTO gastos_fijos (concepto, importe, division) VALUES (?,?,?)');
    $st->execute([$b['concepto'], $b['importe'], $b['division'] ?? 'coeficiente']);
    $gid = $db->lastInsertId();

    $ins = $db->prepare('INSERT INTO gastos_fijos_unidades (gasto_fijo_id, unidad_id) VALUES (?,?)');
    foreach ($b['unidades'] as $uid) $ins->execute([$gid, $uid]);

    $db->commit();
    json_ok(['id' => $gid]);
}

// PUT — editar
if ($method === 'PUT' && $id) {
    $b = body();
    if (empty($b['concepto']) || !isset($b['importe']) || empty($b['unidades'])) json_err('Faltan campos');

    $db = db();
    $db->beginTransaction();
    $db->prepare('UPDATE gastos_fijos SET concepto=?, importe=?, division=? WHERE id=?')
       ->execute([$b['concepto'], $b['importe'], $b['division'] ?? 'coeficiente', $id]);
    $db->prepare('DELETE FROM gastos_fijos_unidades WHERE gasto_fijo_id=?')->execute([$id]);

    $ins = $db->prepare('INSERT INTO gastos_fijos_unidades (gasto_fijo_id, unidad_id) VALUES (?,?)');
    foreach ($b['unidades'] as $uid) $ins->execute([$id, $uid]);

    $db->commit();
    json_ok();
}

// DELETE
if ($method === 'DELETE' && $id) {
    db()->prepare('UPDATE gastos_fijos SET activo=0 WHERE id=?')->execute([$id]);
    json_ok();
}
