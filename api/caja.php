<?php
require_once __DIR__ . '/../includes/config.php';
// Todos los endpoints exigen sesión iniciada. El rol 'consulta' puede leer
// (GET) pero no modificar: las escrituras piden rol 'admin'. El SPA se sirve
// desde el mismo origen, así que no hacen falta cabeceras CORS (y un
// Allow-Origin: * sería contraproducente: impediría el envío de la cookie).
requireLoginAdminParaEscritura();

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

// GET — listar movimientos con filtros y paginación
if ($method === 'GET') {
    $where  = ['1=1'];
    $params = [];

    // Filtro tipo (ingreso/egreso)
    if (!empty($_GET['tipo'])) {
        $where[] = 'c.tipo = ?';
        $params[] = $_GET['tipo'];
    }

    // Filtro año
    if (!empty($_GET['anio'])) {
        $where[] = 'YEAR(c.fecha) = ?';
        $params[] = (int)$_GET['anio'];
    }

    // Filtro mes
    if (!empty($_GET['mes'])) {
        $where[] = 'MONTH(c.fecha) = ?';
        $params[] = (int)$_GET['mes'];
    }

    // Filtro búsqueda por descripción (concepto o notas)
    if (isset($_GET['buscar']) && trim($_GET['buscar']) !== '') {
        $where[] = '(c.concepto LIKE ? OR c.notas LIKE ?)';
        $like     = '%' . trim($_GET['buscar']) . '%';
        $params[] = $like;
        $params[] = $like;
    }

    // Paginación
    $porPagina = 50;
    $pagina    = max(1, (int)($_GET['pagina'] ?? 1));
    $offset    = ($pagina - 1) * $porPagina;

    // Total de filas para calcular páginas
    $sqlCount = 'SELECT COUNT(*) FROM caja c WHERE ' . implode(' AND ', $where);
    $stCount  = db()->prepare($sqlCount);
    $stCount->execute($params);
    $total     = (int)$stCount->fetchColumn();
    $totalPags = (int)ceil($total / $porPagina);

    // Movimientos de la página
    $sql = 'SELECT c.*, u.nombre AS unidad_nombre
            FROM caja c
            LEFT JOIN unidades u ON u.id = c.unidad_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY c.fecha DESC, c.id DESC
            LIMIT ? OFFSET ?';

    $st = db()->prepare($sql);

    // bind de filtros
    $pos = 1;

    foreach ($params as $p) {
        $st->bindValue($pos++, $p);
    }

    // bind correcto de LIMIT y OFFSET
    $st->bindValue($pos++, (int)$porPagina, PDO::PARAM_INT);
    $st->bindValue($pos++, (int)$offset, PDO::PARAM_INT);

    $st->execute();

    $rows = $st->fetchAll();

    // Saldo total acumulado (siempre sobre toda la caja, sin filtros)
    $saldo = (float) db()->query(
        'SELECT COALESCE(SUM(CASE tipo WHEN \'ingreso\' THEN importe ELSE -importe END), 0) FROM caja'
    )->fetchColumn();

    json_ok([
        'movimientos' => $rows,
        'saldo_total' => $saldo,
        'pagina'      => $pagina,
        'total_pags'  => $totalPags,
        'total_filas' => $total,
    ]);
}

// POST — registrar movimiento
if ($method === 'POST') {
    $b = body();
    if (empty($b['fecha']) || empty($b['tipo']) || empty($b['concepto']) || !isset($b['importe'])) {
        json_err('Faltan campos obligatorios');
    }
    if (!in_array($b['tipo'], ['ingreso', 'egreso'], true)) {
        json_err('Tipo inválido');
    }

    $unidadId = !empty($b['unidad_id']) ? (int)$b['unidad_id'] : null;
    $periodo  = !empty($b['periodo'])  ? $b['periodo']          : null;

    if ($periodo && !preg_match('/^\d{4}(0[1-9]|1[0-2])$/', $periodo)) {
        json_err('Formato de período inválido (debe ser YYYYMM)');
    }

    $db    = db();
    $newId = null;
    try {
        $db->beginTransaction();

        $st = $db->prepare('INSERT INTO caja (fecha, tipo, concepto, unidad_id, importe, periodo, notas, usuario_id) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([
            $b['fecha'],
            $b['tipo'],
            $b['concepto'],
            $unidadId,
            (float)$b['importe'],
            $periodo,
            $b['notas'] ?? null,
            usuarioActual()['id'],
        ]);
        $newId = (int)$db->lastInsertId();

        // Si es ingreso de una unidad con período, actualizar cuenta corriente
        if ($b['tipo'] === 'ingreso' && $unidadId && $periodo) {
            actualizarPagadoCC($db, $unidadId, $periodo);
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        json_err('Error al guardar el movimiento: ' . $e->getMessage());
    }

    json_ok(['id' => $newId]);
}

// DELETE — eliminar movimiento
if ($method === 'DELETE' && $id) {
    $db = db();
    $st = $db->prepare('SELECT * FROM caja WHERE id = ?');
    $st->execute([$id]);
    $mov = $st->fetch();

    if (!$mov) {
        json_err('Movimiento no encontrado', 404);
    }

    try {
        $db->beginTransaction();

        $db->prepare('DELETE FROM caja WHERE id = ?')->execute([$id]);

        if ($mov['tipo'] === 'ingreso' && $mov['unidad_id'] && $mov['periodo']) {
            actualizarPagadoCC($db, (int)$mov['unidad_id'], $mov['periodo']);
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        json_err('Error al eliminar el movimiento: ' . $e->getMessage());
    }

    json_ok();
}

function actualizarPagadoCC(PDO $db, int $unidadId, string $periodo): void {
    $st = $db->prepare('SELECT COALESCE(SUM(importe),0) FROM caja WHERE tipo=\'ingreso\' AND unidad_id=? AND periodo=?');
    $st->execute([$unidadId, $periodo]);
    $pagado = (float) $st->fetchColumn();
    $db->prepare('UPDATE cuenta_corriente SET pagado=? WHERE unidad_id=? AND periodo=?')
       ->execute([$pagado, $unidadId, $periodo]);
}