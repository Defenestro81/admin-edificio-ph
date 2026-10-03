<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/migraciones.php';

// Estado y aplicación del esquema. Es de administrador en cualquier método:
// el GET revela la estructura interna y el POST modifica la base.
//
// Existe porque hasta ahora las migraciones corrían únicamente desde
// instalar.php, que se autobloquea en cuanto hay un .env. Una instalación en
// uso quedaba sin forma de aplicar una migración nueva salvo borrando el .env
// a mano y recargando las credenciales.
requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];
$db     = db();

// GET — qué falta aplicar
if ($method === 'GET') {
    migracionesInit($db);

    json_ok([
        'aplicadas'  => migracionesAplicadas($db),
        'pendientes' => array_map(
            fn($m) => ['version' => $m['version'], 'nombre' => $m['nombre']],
            migracionesPendientes($db)
        ),
    ]);
}

// POST — aplicar lo que falte
if ($method === 'POST') {
    try {
        // Se reconcilia primero por si el registro quedó desfasado del esquema
        // real, que es lo que pasa al restaurar un backup viejo.
        $revertidas = migracionesReconciliar($db);
        $corridas   = array_map(
            fn($m) => ['version' => $m['version'], 'nombre' => $m['nombre']],
            migracionesAplicar($db)
        );
    } catch (Throwable $e) {
        // El mensaje de RuntimeException ya dice qué migración y qué sentencia
        // falló, y eso es justamente lo que un admin necesita para destrabar.
        error_log('Falló aplicar migraciones: ' . $e->getMessage());
        json_err($e->getMessage(), 500);
    }

    json_ok([
        'revertidas' => $revertidas,
        'corridas'   => $corridas,
        'pendientes' => array_map(
            fn($m) => ['version' => $m['version'], 'nombre' => $m['nombre']],
            migracionesPendientes($db)
        ),
    ]);
}

json_err('Método no permitido', 405);
