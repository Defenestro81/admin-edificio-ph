<?php
require_once __DIR__ . '/../includes/config.php';
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

// GET — listar todas
if ($method === 'GET' && !$id) {
    $rows = db()->query('SELECT * FROM unidades ORDER BY orden, id')->fetchAll();
    json_ok($rows);
}

// GET — una sola
if ($method === 'GET' && $id) {
    $st = db()->prepare('SELECT * FROM unidades WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    $row ? json_ok($row) : json_err('No encontrada', 404);
}

// POST — crear
if ($method === 'POST') {
    $b = body();
    if (empty($b['nombre']) || !isset($b['coeficiente'])) json_err('Faltan campos');

    // Verificar suma coef
    $suma = (float) db()->query('SELECT COALESCE(SUM(coeficiente),0) FROM unidades')->fetchColumn();
    if (round($suma + (float)$b['coeficiente'], 6) > 1.000001) json_err('La suma de coeficientes superaría 1.0000');

    $st = db()->prepare('INSERT INTO unidades (nombre, propietario, email, coeficiente, ascensor, orden) VALUES (?,?,?,?,?,?)');
    $st->execute([$b['nombre'], $b['propietario'] ?? '', $b['email'] ?? '', $b['coeficiente'], $b['ascensor'] ? 1 : 0, $b['orden'] ?? 0]);
    json_ok(['id' => db()->lastInsertId()]);
}

// PUT — editar
if ($method === 'PUT' && $id) {
    $b = body();
    if (empty($b['nombre']) || !isset($b['coeficiente'])) json_err('Faltan campos');

    $suma = (float) db()->query("SELECT COALESCE(SUM(coeficiente),0) FROM unidades WHERE id != $id")->fetchColumn();
    if (round($suma + (float)$b['coeficiente'], 6) > 1.000001) json_err('La suma de coeficientes superaría 1.0000');

    $st = db()->prepare('UPDATE unidades SET nombre=?, propietario=?, email=?, coeficiente=?, ascensor=?, orden=? WHERE id=?');
    $st->execute([$b['nombre'], $b['propietario'] ?? '', $b['email'] ?? '', $b['coeficiente'], $b['ascensor'] ? 1 : 0, $b['orden'] ?? 0, $id]);
    json_ok();
}

// DELETE
if ($method === 'DELETE' && $id) {
    db()->prepare('DELETE FROM unidades WHERE id = ?')->execute([$id]);
    json_ok();
}
