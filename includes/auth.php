<?php
// ═══════════════════════════════════════════════════════════════
//  Autenticación por sesión
//
//  Lo incluye config.php al final, así cualquier endpoint que ya
//  hace require del config tiene estas funciones disponibles y solo
//  necesita agregar requireLogin() arriba de todo.
// ═══════════════════════════════════════════════════════════════

const AUTH_MAX_INTENTOS   = 5;    // fallos seguidos antes de bloquear la cuenta
const AUTH_BLOQUEO_MIN    = 15;   // minutos de bloqueo de la cuenta
const AUTH_INACTIVIDAD_MIN = 480; // cierra sesión tras 8 h sin actividad
const AUTH_PASS_MIN       = 8;    // largo mínimo de contraseña

// Límite por velocidad, independiente del anterior. Aquel cuenta fallos de una
// cuenta; este mide el ritmo de los intentos desde una IP y salta aunque sean
// exitosos, porque esa cadencia es la firma de un script, no de una persona.
const AUTH_RAFAGA_INTENTOS = 3;   // intentos...
const AUTH_RAFAGA_SEGUNDOS = 5;   // ...dentro de esta ventana
const AUTH_RAFAGA_BLOQUEO_MIN = 5; // minutos de bloqueo de la IP

/** IP del cliente. No se mira X-Forwarded-For: lo pone el cliente y se falsea. */
function ipCliente(): string {
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

/**
 * Minutos que faltan para que se levante el bloqueo de esta IP, o 0 si no
 * está bloqueada. La comparación va entera en SQL: mezclar NOW() de MySQL con
 * time() de PHP falla si los relojes están en zonas distintas.
 */
function bloqueoRestanteMin(): int {
    $st = db()->prepare(
        'SELECT GREATEST(TIMESTAMPDIFF(MINUTE, NOW(), bloqueado_hasta), 1)
           FROM bloqueos_acceso
          WHERE ip = ? AND bloqueado_hasta > NOW()'
    );
    $st->execute([ipCliente()]);
    return (int) ($st->fetchColumn() ?: 0);
}

/**
 * Deja constancia del intento y, si la IP superó el ritmo permitido, la
 * bloquea. Devuelve true si a partir de ahora está bloqueada.
 */
function registrarIntento(?string $usuario): bool {
    $ip = ipCliente();
    $db = db();

    $db->prepare('INSERT INTO intentos_login (ip, usuario) VALUES (?, ?)')
       ->execute([$ip, $usuario !== null ? substr($usuario, 0, 50) : null]);

    // Se purga la cola vieja acá mismo para no necesitar una tarea programada.
    $db->exec('DELETE FROM intentos_login WHERE creado_en < NOW() - INTERVAL 1 HOUR');

    $st = $db->prepare(
        'SELECT COUNT(*) FROM intentos_login
          WHERE ip = ? AND creado_en > NOW() - INTERVAL ' . AUTH_RAFAGA_SEGUNDOS . ' SECOND'
    );
    $st->execute([$ip]);

    if ((int) $st->fetchColumn() < AUTH_RAFAGA_INTENTOS) return false;

    $db->prepare(
        'INSERT INTO bloqueos_acceso (ip, bloqueado_hasta, motivo)
         VALUES (?, NOW() + INTERVAL ' . AUTH_RAFAGA_BLOQUEO_MIN . ' MINUTE, ?)
         ON DUPLICATE KEY UPDATE
            bloqueado_hasta = NOW() + INTERVAL ' . AUTH_RAFAGA_BLOQUEO_MIN . ' MINUTE,
            motivo = VALUES(motivo)'
    )->execute([$ip, AUTH_RAFAGA_INTENTOS . ' intentos en ' . AUTH_RAFAGA_SEGUNDOS . ' segundos']);

    return true;
}

/** Arranca la sesión con cookies endurecidas. Idempotente. */
function sesionIniciar(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['SERVER_PORT'] ?? null) == 443);

    session_name('EDIFICIOSESS');
    session_set_cookie_params([
        'lifetime' => 0,          // dura lo que dure el navegador abierto
        'path'     => '/',
        'httponly' => true,       // JS no puede leer la cookie
        'samesite' => 'Strict',   // no viaja en requests desde otros sitios (anti-CSRF)
        'secure'   => $https,
    ]);
    ini_set('session.use_strict_mode', '1');   // rechaza IDs de sesión inventados
    session_start();

    // Expiración por inactividad
    if (isset($_SESSION['ultima_actividad'])) {
        if (time() - $_SESSION['ultima_actividad'] > AUTH_INACTIVIDAD_MIN * 60) {
            logout();
            session_start();
            return;
        }
    }
    $_SESSION['ultima_actividad'] = time();
}

/** ¿Hay al menos un usuario creado? Si no, la app pide crear el primero. */
function hayUsuarios(): bool {
    try {
        return (bool) db()->query('SELECT 1 FROM usuarios LIMIT 1')->fetchColumn();
    } catch (PDOException) {
        return false;   // la tabla todavía no existe
    }
}

/** Devuelve el usuario logueado, o null. */
function usuarioActual(): ?array {
    sesionIniciar();
    if (empty($_SESSION['usuario_id'])) return null;

    $st = db()->prepare('SELECT id, usuario, nombre, rol, activo, ultimo_acceso FROM usuarios WHERE id = ?');
    $st->execute([$_SESSION['usuario_id']]);
    $u = $st->fetch();

    // Si lo borraron o lo dieron de baja con la sesión abierta, se corta acá:
    // el cambio de permisos tiene efecto en el próximo request, sin esperar a
    // que venza la sesión.
    if (!$u || !$u['activo']) {
        logout();
        return null;
    }
    return $u;
}

/** Corta con 401 si no hay sesión. Para usar arriba de cada endpoint. */
function requireLogin(): void {
    if (usuarioActual() === null) {
        json_err('No autenticado', 401);
    }
}

/** ¿El usuario logueado es administrador? */
function esAdmin(): bool {
    $u = usuarioActual();
    return $u !== null && $u['rol'] === 'admin';
}

/** Corta con 403 si el usuario no es administrador. */
function requireAdmin(): void {
    requireLogin();
    if (!esAdmin()) {
        json_err('Esta acción requiere permisos de administrador', 403);
    }
}

/**
 * Exige sesión siempre, y rol admin solo si el request modifica algo.
 * Es la que usan los endpoints que mezclan lectura y escritura: el rol
 * 'consulta' puede hacer GET pero no POST/PUT/DELETE.
 */
function requireLoginAdminParaEscritura(): void {
    requireLogin();
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        requireAdmin();
    }
}

/**
 * Valida credenciales y abre la sesión.
 * Devuelve ['ok' => bool, 'error' => string|null].
 */
function login(string $usuario, string $password): array {
    sesionIniciar();

    // El límite por ráfaga se evalúa antes que nada: si la IP ya está
    // bloqueada no se toca la base de usuarios ni se verifica ningún hash.
    $restante = bloqueoRestanteMin();
    if ($restante > 0) {
        return ['ok' => false, 'error' => "Demasiados intentos seguidos. Esperá {$restante} min."];
    }

    if (registrarIntento($usuario)) {
        return ['ok' => false, 'error' => 'Demasiados intentos seguidos. Esperá ' . AUTH_RAFAGA_BLOQUEO_MIN . ' min.'];
    }

    // El estado del bloqueo se resuelve dentro de MySQL a propósito: comparar
    // una fecha de la base contra time() de PHP falla si los dos relojes están
    // en zonas horarias distintas, y el bloqueo nacería ya vencido.
    $st = db()->prepare(
        'SELECT *,
                (bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()) AS esta_bloqueado,
                GREATEST(TIMESTAMPDIFF(MINUTE, NOW(), bloqueado_hasta), 1) AS bloqueo_restante_min
           FROM usuarios WHERE usuario = ?'
    );
    $st->execute([$usuario]);
    $u = $st->fetch();

    // Usuario inexistente: se compara igual contra un hash dummy para que la
    // respuesta tarde lo mismo que con uno real y no se pueda deducir cuáles
    // existen midiendo el tiempo.
    if (!$u) {
        password_verify($password, '$2y$10$usuarioinexistenteusuarioinexistenteusuarioinexistentexxxx');
        return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos'];
    }

    if (!$u['activo']) {
        return ['ok' => false, 'error' => 'Esta cuenta está dada de baja'];
    }

    if ($u['esta_bloqueado']) {
        $min = (int) $u['bloqueo_restante_min'];
        return ['ok' => false, 'error' => "Demasiados intentos fallidos. Probá de nuevo en {$min} min."];
    }

    if (!password_verify($password, $u['password_hash'])) {
        $intentos = $u['intentos_fallidos'] + 1;
        if ($intentos >= AUTH_MAX_INTENTOS) {
            db()->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?')
                ->execute([AUTH_BLOQUEO_MIN, $u['id']]);
            return ['ok' => false, 'error' => 'Demasiados intentos fallidos. Cuenta bloqueada ' . AUTH_BLOQUEO_MIN . ' min.'];
        }
        db()->prepare('UPDATE usuarios SET intentos_fallidos = ? WHERE id = ?')->execute([$intentos, $u['id']]);
        return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos'];
    }

    // Credenciales correctas
    session_regenerate_id(true);   // evita fijación de sesión
    $_SESSION['usuario_id']      = $u['id'];
    $_SESSION['ultima_actividad'] = time();

    db()->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = NOW() WHERE id = ?')
        ->execute([$u['id']]);

    // Si el hash quedó viejo frente al algoritmo actual de PHP, se re-genera
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }

    return ['ok' => true, 'error' => null];
}

/** Cierra la sesión y borra la cookie. */
function logout(): void {
    sesionIniciar();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Crea un usuario. Devuelve ['ok' => bool, 'error' => string|null].
 * Valida largo mínimo y unicidad del nombre de usuario.
 */
function crearUsuario(string $usuario, string $password, string $nombre, string $rol = 'admin'): array {
    $usuario = trim($usuario);
    $nombre  = trim($nombre);

    if ($usuario === '' || $nombre === '') {
        return ['ok' => false, 'error' => 'Usuario y nombre son obligatorios'];
    }
    if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $usuario)) {
        return ['ok' => false, 'error' => 'El usuario debe tener entre 3 y 50 caracteres (letras, números, punto, guion o guion bajo)'];
    }
    if (strlen($password) < AUTH_PASS_MIN) {
        return ['ok' => false, 'error' => 'La contraseña debe tener al menos ' . AUTH_PASS_MIN . ' caracteres'];
    }
    if (!in_array($rol, ['admin', 'consulta'], true)) {
        return ['ok' => false, 'error' => 'Rol inválido'];
    }

    try {
        db()->prepare('INSERT INTO usuarios (usuario, password_hash, nombre, rol) VALUES (?, ?, ?, ?)')
            ->execute([$usuario, password_hash($password, PASSWORD_DEFAULT), $nombre, $rol]);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) {
            return ['ok' => false, 'error' => 'Ese nombre de usuario ya existe'];
        }
        throw $e;
    }

    return ['ok' => true, 'error' => null, 'id' => (int) db()->lastInsertId()];
}

/**
 * Cuántos administradores activos quedan. Sirve para no permitir que la
 * instalación se quede sin ningún admin (nadie podría volver a entrar a
 * gestionar usuarios).
 */
function cantidadAdmins(): int {
    return (int) db()->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1")->fetchColumn();
}

/** Cambia la contraseña del usuario logueado, validando la actual. */
function cambiarPassword(int $usuarioId, string $actual, string $nueva): array {
    if (strlen($nueva) < AUTH_PASS_MIN) {
        return ['ok' => false, 'error' => 'La contraseña nueva debe tener al menos ' . AUTH_PASS_MIN . ' caracteres'];
    }

    $st = db()->prepare('SELECT password_hash FROM usuarios WHERE id = ?');
    $st->execute([$usuarioId]);
    $hash = $st->fetchColumn();

    if (!$hash || !password_verify($actual, $hash)) {
        return ['ok' => false, 'error' => 'La contraseña actual no es correcta'];
    }

    db()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($nueva, PASSWORD_DEFAULT), $usuarioId]);

    return ['ok' => true, 'error' => null];
}
