<?php
require_once __DIR__ . '/../includes/config.php';
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,DELETE,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$method = $_SERVER['REQUEST_METHOD'];
$db     = db();

// GET — detalle de una extraordinaria, o listado (todas / por período)
if ($method === 'GET') {
    $id      = isset($_GET['id'])      ? (int)$_GET['id'] : null;
    $periodo = isset($_GET['periodo']) ? $_GET['periodo']  : null;

    if ($id) {
        $st = $db->prepare("SELECT * FROM liquidaciones WHERE id = ? AND tipo = 'extraordinaria'");
        $st->execute([$id]);
        $liq = $st->fetch();
        if (!$liq) json_ok(null);

        $st2 = $db->prepare('
            SELECT ld.*, u.nombre, u.propietario, u.email
            FROM liquidacion_detalle ld
            JOIN unidades u ON u.id = ld.unidad_id
            WHERE ld.liquidacion_id = ?
            ORDER BY u.orden, u.id
        ');
        $st2->execute([$liq['id']]);
        $detalles = $st2->fetchAll();

        foreach ($detalles as &$d) {
            $st3 = $db->prepare('SELECT * FROM liquidacion_lineas WHERE detalle_id = ?');
            $st3->execute([$d['id']]);
            $d['lineas'] = $st3->fetchAll();
        }

        $liq['unidades'] = $detalles;
        json_ok($liq);
    }

    $sql = "SELECT l.*,
                (SELECT COUNT(*) FROM liquidacion_detalle WHERE liquidacion_id = l.id) AS cant_unidades,
                (SELECT COUNT(*) FROM liquidacion_detalle WHERE liquidacion_id = l.id AND mail_enviado = 1) AS cant_enviados
            FROM liquidaciones l
            WHERE l.tipo = 'extraordinaria'";
    $params = [];
    if ($periodo) { $sql .= ' AND l.periodo = ?'; $params[] = $periodo; }
    $sql .= ' ORDER BY l.calculada_en DESC';

    $st = $db->prepare($sql);
    $st->execute($params);
    json_ok($st->fetchAll());
}

// POST — emitir una liquidación extraordinaria
if ($method === 'POST') {
    $b = body();

    $titulo   = trim($b['titulo'] ?? '');
    $periodo  = $b['periodo'] ?? '';
    $conceptos = $b['conceptos'] ?? [];

    if ($titulo === '') json_err('Falta el título / motivo');
    if (!preg_match('/^\d{4}(0[1-9]|1[0-2])$/', $periodo)) json_err('Período inválido');
    if (!is_array($conceptos) || !$conceptos) json_err('Agregá al menos un concepto');

    $unidades = $db->query('SELECT * FROM unidades ORDER BY orden, id')->fetchAll();
    if (!$unidades) json_err('No hay unidades configuradas');

    $porUnidad = [];
    foreach ($unidades as $u) $porUnidad[$u['id']] = ['unidad' => $u, 'lineas' => [], 'subtotal' => 0.0];

    foreach ($conceptos as $c) {
        $concepto = trim($c['concepto'] ?? '');
        $importe  = (float)($c['importe'] ?? 0);
        $division = in_array($c['division'] ?? '', ['coeficiente', 'partes_iguales'], true) ? $c['division'] : 'coeficiente';
        $aplica   = array_map('intval', $c['unidades'] ?? []);

        if ($concepto === '' || $importe <= 0 || !$aplica) {
            json_err('Revisá el concepto "' . ($concepto ?: '(sin nombre)') . '": falta importe o unidades');
        }

        if ($division === 'partes_iguales') {
            $parte = $importe / count($aplica);
            foreach ($aplica as $uid) {
                if (!isset($porUnidad[$uid])) continue;
                $porUnidad[$uid]['lineas'][]  = ['concepto' => $concepto, 'importe' => round($parte, 2)];
                $porUnidad[$uid]['subtotal'] += $parte;
            }
        } else {
            $coefAfect = 0;
            foreach ($unidades as $uu) if (in_array($uu['id'], $aplica, true)) $coefAfect += (float)$uu['coeficiente'];
            if ($coefAfect <= 0) json_err('Las unidades elegidas para "' . $concepto . '" no tienen coeficiente asignado');

            foreach ($aplica as $uid) {
                if (!isset($porUnidad[$uid])) continue;
                $u     = $porUnidad[$uid]['unidad'];
                $parte = $importe * ((float)$u['coeficiente'] / $coefAfect);
                $porUnidad[$uid]['lineas'][]  = ['concepto' => $concepto, 'importe' => round($parte, 2)];
                $porUnidad[$uid]['subtotal'] += $parte;
            }
        }
    }

    $afectadas = array_filter($porUnidad, fn($r) => $r['subtotal'] > 0);
    if (!$afectadas) json_err('Ningún concepto quedó asignado a una unidad válida');

    $totalGeneral = 0;
    foreach ($afectadas as $r) $totalGeneral += round($r['subtotal'], 2);

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO liquidaciones (periodo, tipo, titulo, total_general) VALUES (?, 'extraordinaria', ?, ?)")
           ->execute([$periodo, $titulo, $totalGeneral]);
        $liqId = $db->lastInsertId();

        $insDetalle = $db->prepare('INSERT INTO liquidacion_detalle (liquidacion_id, unidad_id, total) VALUES (?,?,?)');
        $insLinea   = $db->prepare("INSERT INTO liquidacion_lineas (detalle_id, concepto, importe, tipo) VALUES (?,?,?,'extraordinario')");
        $stSaldo    = $db->prepare('
            SELECT saldo_final FROM cuenta_corriente
            WHERE unidad_id = ? AND periodo < ?
            ORDER BY periodo DESC LIMIT 1
        ');
        $insCC = $db->prepare('
            INSERT INTO cuenta_corriente (unidad_id, periodo, deuda, saldo_anterior, extraordinario, pagado)
            VALUES (?, ?, 0, ?, ?, 0)
            ON DUPLICATE KEY UPDATE extraordinario = extraordinario + VALUES(extraordinario)
        ');

        foreach ($afectadas as $uid => $r) {
            $total = round($r['subtotal'], 2);

            $insDetalle->execute([$liqId, $uid, $total]);
            $detId = $db->lastInsertId();
            foreach ($r['lineas'] as $l) $insLinea->execute([$detId, $l['concepto'], $l['importe']]);

            $stSaldo->execute([$uid, $periodo]);
            $rowSaldo      = $stSaldo->fetch();
            $saldoAnterior = $rowSaldo ? round((float)$rowSaldo['saldo_final'], 2) : 0;

            $insCC->execute([$uid, $periodo, $saldoAnterior, $total]);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        json_err('Error al emitir la extraordinaria: ' . $e->getMessage());
    }

    json_ok(['id' => $liqId, 'total_general' => $totalGeneral]);
}

// DELETE — anular una extraordinaria (revierte el cargo en cuenta_corriente)
if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if (!$id) json_err('Falta id');

    $st = $db->prepare("SELECT * FROM liquidaciones WHERE id = ? AND tipo = 'extraordinaria'");
    $st->execute([$id]);
    $liq = $st->fetch();
    if (!$liq) json_err('Extraordinaria no encontrada', 404);

    $stDet = $db->prepare('SELECT unidad_id, total FROM liquidacion_detalle WHERE liquidacion_id = ?');
    $stDet->execute([$id]);
    $detalles = $stDet->fetchAll();

    $db->beginTransaction();
    try {
        $updCC = $db->prepare('UPDATE cuenta_corriente SET extraordinario = extraordinario - ? WHERE unidad_id = ? AND periodo = ?');
        foreach ($detalles as $d) $updCC->execute([$d['total'], $d['unidad_id'], $liq['periodo']]);

        // liquidacion_detalle y liquidacion_lineas se borran en cascada (FK ON DELETE CASCADE)
        $db->prepare('DELETE FROM liquidaciones WHERE id = ?')->execute([$id]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        json_err('Error al anular: ' . $e->getMessage());
    }

    json_ok();
}
