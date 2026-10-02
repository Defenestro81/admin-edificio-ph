<?php
require_once __DIR__ . '/../includes/config.php';
// Todos los endpoints exigen sesión iniciada. El SPA se sirve desde el mismo
// origen, así que no hacen falta cabeceras CORS (y un Allow-Origin: * sería
// contraproducente: impediría el envío de la cookie de sesión).
requireLogin();

$method  = $_SERVER['REQUEST_METHOD'];
$periodo = isset($_GET['periodo']) ? $_GET['periodo'] : null;

// GET — obtener liquidación de un período
if ($method === 'GET') {
    if (!$periodo) json_err('Falta período');
    $st = db()->prepare("SELECT * FROM liquidaciones WHERE periodo = ? AND tipo = 'ordinaria'");
    $st->execute([$periodo]);
    $liq = $st->fetch();
    if (!$liq) json_ok(null);

    $st2 = db()->prepare('
        SELECT ld.*, u.nombre, u.propietario, u.email,
            COALESCE(cc.saldo_anterior, 0) as saldo_anterior
        FROM liquidacion_detalle ld
        JOIN unidades u ON u.id = ld.unidad_id
        LEFT JOIN cuenta_corriente cc ON cc.unidad_id = ld.unidad_id AND cc.periodo = ?
        WHERE ld.liquidacion_id = ?
        ORDER BY u.orden, u.id
    ');
    $st2->execute([$periodo, $liq['id']]);
    $detalles = $st2->fetchAll();

    foreach ($detalles as &$d) {
        $st3 = db()->prepare('SELECT * FROM liquidacion_lineas WHERE detalle_id = ?');
        $st3->execute([$d['id']]);
        $d['lineas'] = $st3->fetchAll();
    }

    $liq['unidades'] = $detalles;
    json_ok($liq);
}

// POST — calcular (reemplaza si existe)
if ($method === 'POST') {
    $b = body();
    if (empty($b['periodo'])) json_err('Falta período');
    $periodo = $b['periodo'];

    $db = db();

    // Verificar suma coef
    $suma = (float) $db->query('SELECT COALESCE(SUM(coeficiente),0) FROM unidades')->fetchColumn();
    if (abs($suma - 1) > 0.001) json_err('La suma de coeficientes es ' . round($suma,4) . ', debe ser 1.0000');

    $unidades = $db->query('SELECT * FROM unidades ORDER BY orden, id')->fetchAll();
    if (!$unidades) json_err('No hay unidades configuradas');

    // Gastos fijos con sus unidades
    $gastosFijos = $db->query('SELECT * FROM gastos_fijos WHERE activo = 1')->fetchAll();
    foreach ($gastosFijos as &$g) {
        $st = $db->prepare('SELECT unidad_id FROM gastos_fijos_unidades WHERE gasto_fijo_id = ?');
        $st->execute([$g['id']]);
        $g['unidades_ids'] = array_column($st->fetchAll(), 'unidad_id');
    }

    // Gastos esporádicos del período
    $st = $db->prepare('SELECT * FROM gastos_esporadicos WHERE periodo = ?');
    $st->execute([$periodo]);
    $gastosEsp = $st->fetchAll();
    foreach ($gastosEsp as &$g) {
        $st2 = $db->prepare('SELECT unidad_id FROM gastos_esporadicos_unidades WHERE gasto_esporadico_id = ?');
        $st2->execute([$g['id']]);
        $g['unidades_ids'] = array_column($st2->fetchAll(), 'unidad_id');
    }

    // ─── Calcular ───
    $allGastos = array_merge(
        array_map(fn($g) => [...$g, 'tipo' => 'fijo'], $gastosFijos),
        array_map(fn($g) => [...$g, 'tipo' => 'esporadico'], $gastosEsp)
    );

    $resultados = [];
    foreach ($unidades as $u) {
        $lineas = [];
        $subtotal = 0;

        foreach ($allGastos as $g) {
            $aplica = $g['unidades_ids'];
            if (!in_array($u['id'], $aplica)) continue;

            if ($g['division'] === 'partes_iguales') {
                $parte = $g['importe'] / count($aplica);
            } else {
                $coefAfect = 0;
                foreach ($unidades as $uu) {
                    if (in_array($uu['id'], $aplica)) $coefAfect += (float)$uu['coeficiente'];
                }
                $parte = $coefAfect > 0 ? $g['importe'] * ((float)$u['coeficiente'] / $coefAfect) : 0;
            }

            $lineas[] = ['concepto' => $g['concepto'], 'importe' => round($parte, 2), 'tipo' => $g['tipo']];
            $subtotal += $parte;
        }

        // Saldo anterior: buscar el período previo con registro en cuenta corriente
        $stSaldo = $db->prepare('
            SELECT saldo_final FROM cuenta_corriente
            WHERE unidad_id = ? AND periodo < ?
            ORDER BY periodo DESC LIMIT 1
        ');
        $stSaldo->execute([$u['id'], $periodo]);
        $rowSaldo = $stSaldo->fetch();
        $saldoAnterior = $rowSaldo ? round((float)$rowSaldo['saldo_final'], 2) : 0;

        // El total a cobrar incluye el saldo anterior
        $total = round($subtotal + $saldoAnterior, 2);

        $resultados[] = [
            'unidad'         => $u,
            'lineas'         => $lineas,
            'subtotal'       => round($subtotal, 2),
            'saldo_anterior' => $saldoAnterior,
            'total'          => $total,
        ];
    }

    $totalGeneral = array_sum(array_column($resultados, 'total'));

    // Borrar liquidación ordinaria anterior si existe (no toca extraordinarias del período)
    $db->prepare("DELETE FROM liquidaciones WHERE periodo = ? AND tipo = 'ordinaria'")->execute([$periodo]);

    $db->beginTransaction();
    $db->prepare("INSERT INTO liquidaciones (periodo, tipo, total_general) VALUES (?,'ordinaria',?)")->execute([$periodo, $totalGeneral]);
    $liqId = $db->lastInsertId();

    $insDetalle = $db->prepare('INSERT INTO liquidacion_detalle (liquidacion_id, unidad_id, total) VALUES (?,?,?)');
    $insLinea   = $db->prepare('INSERT INTO liquidacion_lineas (detalle_id, concepto, importe, tipo) VALUES (?,?,?,?)');
    $insCC      = $db->prepare('
        INSERT INTO cuenta_corriente (unidad_id, periodo, deuda, saldo_anterior, pagado)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE deuda=VALUES(deuda), saldo_anterior=VALUES(saldo_anterior)
    ');

    foreach ($resultados as $r) {
        $insDetalle->execute([$liqId, $r['unidad']['id'], $r['total']]);
        $detId = $db->lastInsertId();
        foreach ($r['lineas'] as $l) $insLinea->execute([$detId, $l['concepto'], $l['importe'], $l['tipo']]);

        // Recuperar lo que ya pagó esta unidad en caja para este período
        $stPagado = $db->prepare('SELECT COALESCE(SUM(importe),0) FROM caja WHERE tipo=\'ingreso\' AND unidad_id=? AND periodo=?');
        $stPagado->execute([$r['unidad']['id'], $periodo]);
        $pagado = (float) $stPagado->fetchColumn();

        $insCC->execute([$r['unidad']['id'], $periodo, $r['subtotal'], $r['saldo_anterior'], $pagado]);
    }

    $db->commit();
    json_ok(['liquidacion_id' => $liqId, 'total_general' => $totalGeneral]);
}

// DELETE — borrar liquidación
if ($method === 'DELETE') {
    if (!$periodo) json_err('Falta período');
    $db = db();

    $st = $db->prepare("SELECT id FROM liquidaciones WHERE periodo = ? AND tipo = 'ordinaria'");
    $st->execute([$periodo]);
    $liq = $st->fetch();

    if ($liq) {
        $db->beginTransaction();

        $stDet = $db->prepare('SELECT id FROM liquidacion_detalle WHERE liquidacion_id = ?');
        $stDet->execute([$liq['id']]);
        $detalles = $stDet->fetchAll();

        $delLineas = $db->prepare('DELETE FROM liquidacion_lineas WHERE detalle_id = ?');
        foreach ($detalles as $d) {
            $delLineas->execute([$d['id']]);
        }

        $db->prepare('DELETE FROM liquidacion_detalle WHERE liquidacion_id = ?')->execute([$liq['id']]);
        // No se borra la fila de cuenta_corriente entera: podría tener pagos
        // (pagado) o cargos de extraordinarias (extraordinario) ya registrados.
        // Solo se anula la deuda ordinaria de ese período.
        $db->prepare('UPDATE cuenta_corriente SET deuda = 0 WHERE periodo = ?')->execute([$periodo]);
        $db->prepare('DELETE FROM liquidaciones WHERE id = ?')->execute([$liq['id']]);

        $db->commit();
    }

    json_ok();
}
