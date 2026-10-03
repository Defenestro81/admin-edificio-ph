<?php
require_once __DIR__ . '/../includes/config.php';

// Sin cabeceras CORS a propósito: el SPA se sirve desde el mismo origen que
// la API. Un Access-Control-Allow-Origin: * acá sería además inútil, porque
// los navegadores no mandan cookies de sesión a un origen comodín.

/** Forma en que el front recibe al usuario. El rol lo usa para ocultar
 *  las acciones de escritura cuando es de solo lectura. */
function usuarioParaFront(array $u): array {
    return [
        'id'      => (int) $u['id'],
        'usuario' => $u['usuario'],
        'nombre'  => $u['nombre'],
        'rol'     => $u['rol'],
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET — estado de la sesión ───────────────────────────────────────────────
// Es el único endpoint que se puede consultar sin estar logueado: el front lo
// usa al arrancar para decidir si muestra el login, el alta inicial o la app.
if ($method === 'GET') {
    $u = usuarioActual();
    json_ok([
        'autenticado'  => $u !== null,
        'usuario'      => $u ? usuarioParaFront($u) : null,
        'hay_usuarios' => hayUsuarios(),
    ]);
}

// ── POST — login, o alta del primer usuario ─────────────────────────────────
if ($method === 'POST') {
    $b      = body();
    $accion = $b['accion'] ?? 'login';

    if ($accion === 'crear_primero') {
        // Solo cuando la instalación todavía no tiene ningún usuario. Una vez
        // que existe uno, esta vía queda cerrada para siempre.
        if (hayUsuarios()) {
            json_err('Ya existe un usuario. Iniciá sesión.', 403);
        }

        $r = crearUsuario($b['usuario'] ?? '', $b['password'] ?? '', $b['nombre'] ?? '');
        if (!$r['ok']) json_err($r['error']);

        // Se deja la sesión abierta para no obligar a loguearse de inmediato
        $login = login($b['usuario'], $b['password']);
        if (!$login['ok']) json_err($login['error']);

        $u = usuarioActual();
        json_ok(['usuario' => usuarioParaFront($u)]);
    }

    if ($accion === 'login') {
        $usuario  = trim($b['usuario'] ?? '');
        $password = $b['password'] ?? '';

        if ($usuario === '' || $password === '') {
            json_err('Completá usuario y contraseña');
        }

        $r = login($usuario, $password);
        if (!$r['ok']) json_err($r['error'], 401);

        $u = usuarioActual();
        json_ok(['usuario' => usuarioParaFront($u)]);
    }

    json_err('Acción desconocida');
}

// ── PUT — cambiar la propia contraseña ──────────────────────────────────────
if ($method === 'PUT') {
    requireLogin();
    $u = usuarioActual();
    $b = body();

    $r = cambiarPassword($u['id'], $b['actual'] ?? '', $b['nueva'] ?? '');
    if (!$r['ok']) json_err($r['error']);

    json_ok(['mensaje' => 'Contraseña actualizada']);
}

// ── DELETE — cerrar sesión ──────────────────────────────────────────────────
if ($method === 'DELETE') {
    logout();
    json_ok(['mensaje' => 'Sesión cerrada']);
}

json_err('Método no permitido', 405);
