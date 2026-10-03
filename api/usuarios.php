<?php
require_once __DIR__ . '/../includes/config.php';

// Gestión de usuarios: solo administradores, en cualquier método.
requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];
$yo     = usuarioActual();

// ── GET — listado ───────────────────────────────────────────────────────────
if ($method === 'GET') {
    $filas = db()->query(
        'SELECT id, usuario, nombre, rol, activo, ultimo_acceso, creado_en
           FROM usuarios
          ORDER BY activo DESC, rol, usuario'
    )->fetchAll();

    // Se marca cuál es el propio para que el front no ofrezca acciones que el
    // backend va a rechazar igual (bajarse el rol, desactivarse).
    foreach ($filas as &$f) {
        $f['es_propio'] = ((int) $f['id'] === (int) $yo['id']);
        $f['activo']    = (bool) $f['activo'];
    }
    unset($f);

    json_ok($filas);
}

// ── POST — crear ────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $b = body();

    $r = crearUsuario(
        $b['usuario']  ?? '',
        $b['password'] ?? '',
        $b['nombre']   ?? '',
        $b['rol']      ?? 'consulta'
    );
    if (!$r['ok']) json_err($r['error']);

    json_ok(['id' => $r['id']]);
}

// ── PUT — editar nombre, rol, estado, o resetear contraseña ─────────────────
if ($method === 'PUT') {
    $b  = body();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) json_err('Falta el id del usuario');

    $st = db()->prepare('SELECT * FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) json_err('El usuario no existe', 404);

    $esPropio = ($id === (int) $yo['id']);

    // ── Guardas para no dejar la instalación sin administrador ──
    // Un admin no puede quitarse a sí mismo el rol ni darse de baja: sería
    // perder el acceso a esta misma pantalla sin forma de volver.
    $nuevoRol    = $b['rol']    ?? $u['rol'];
    $nuevoActivo = array_key_exists('activo', $b) ? (int) (bool) $b['activo'] : (int) $u['activo'];

    if ($esPropio && $nuevoRol !== $u['rol']) {
        json_err('No podés cambiarte el rol a vos mismo');
    }
    if ($esPropio && !$nuevoActivo) {
        json_err('No podés darte de baja a vos mismo');
    }

    // Y aunque sea sobre otro: si es el último admin activo, no se toca.
    $dejaDeSerAdmin = ($u['rol'] === 'admin' && $u['activo']) && ($nuevoRol !== 'admin' || !$nuevoActivo);
    if ($dejaDeSerAdmin && cantidadAdmins() <= 1) {
        json_err('Es el único administrador activo: asigná otro antes de cambiarlo');
    }

    if (!in_array($nuevoRol, ['admin', 'consulta'], true)) json_err('Rol inválido');

    $nombre = trim($b['nombre'] ?? $u['nombre']);
    if ($nombre === '') json_err('El nombre no puede quedar vacío');

    db()->prepare('UPDATE usuarios SET nombre = ?, rol = ?, activo = ? WHERE id = ?')
        ->execute([$nombre, $nuevoRol, $nuevoActivo, $id]);

    // Reseteo de contraseña: un admin puede fijar una nueva sin conocer la
    // anterior. Para la propia conviene usar Mi Cuenta, que sí la pide.
    if (!empty($b['password'])) {
        if (strlen($b['password']) < AUTH_PASS_MIN) {
            json_err('La contraseña debe tener al menos ' . AUTH_PASS_MIN . ' caracteres');
        }
        db()->prepare('UPDATE usuarios SET password_hash = ?, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?')
            ->execute([password_hash($b['password'], PASSWORD_DEFAULT), $id]);
    }

    // Desbloqueo manual tras intentos fallidos
    if (!empty($b['desbloquear'])) {
        db()->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?')
            ->execute([$id]);
    }

    json_ok(['mensaje' => 'Usuario actualizado']);
}

// ── DELETE — baja lógica ────────────────────────────────────────────────────
// No se borra la fila: los movimientos de caja y las liquidaciones apuntan al
// usuario que las hizo, y borrarlo dejaría esos registros sin autor.
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_err('Falta el id del usuario');
    if ($id === (int) $yo['id']) json_err('No podés darte de baja a vos mismo');

    $st = db()->prepare('SELECT rol, activo FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) json_err('El usuario no existe', 404);

    if ($u['rol'] === 'admin' && $u['activo'] && cantidadAdmins() <= 1) {
        json_err('Es el único administrador activo: asigná otro antes de darlo de baja');
    }

    db()->prepare('UPDATE usuarios SET activo = 0 WHERE id = ?')->execute([$id]);
    json_ok(['mensaje' => 'Usuario dado de baja']);
}

json_err('Método no permitido', 405);
