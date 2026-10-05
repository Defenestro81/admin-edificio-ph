<?php
// ═══════════════════════════════════════════════════════════════
//  Instalador
//
//  Reemplaza el paso manual de ejecutar los .sql en phpMyAdmin:
//  crea la base si no existe, aplica las migraciones y escribe el
//  .env. Después del alta del primer usuario, el sistema queda listo.
//
//  Se bloquea solo: si ya hay un .env, se niega a correr. Un script
//  capaz de crear bases y reescribir credenciales no puede quedar
//  accesible en una instalación en uso.
// ═══════════════════════════════════════════════════════════════

declare(strict_types=1);

require_once __DIR__ . '/includes/migraciones.php';

// El instalador no pasa por config.php (todavía no hay .env que leer), así que
// fija la zona horaria por su cuenta. Sin esto, XAMPP deja PHP en Europe/Berlin
// y el .env queda fechado con varias horas de diferencia.
date_default_timezone_set('America/Argentina/Buenos_Aires');

const ENV_PATH = __DIR__ . '/.env';

$yaInstalado = is_file(ENV_PATH);
$errores     = [];
$resultado   = null;

// ── Procesamiento ───────────────────────────────────────────────────────────
if (!$yaInstalado && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $datos = [
        'DB_HOST'        => trim($_POST['db_host']        ?? 'localhost'),
        'DB_NAME'        => trim($_POST['db_name']        ?? ''),
        'DB_USER'        => trim($_POST['db_user']        ?? ''),
        'DB_PASS'        =>      $_POST['db_pass']        ?? '',
        'MAIL_HOST'      => trim($_POST['mail_host']      ?? 'smtp.gmail.com'),
        'MAIL_PORT'      => trim($_POST['mail_port']      ?? '587'),
        'MAIL_USER'      => trim($_POST['mail_user']      ?? ''),
        'MAIL_PASS'      =>      $_POST['mail_pass']      ?? '',
        'MAIL_FROM'      => trim($_POST['mail_from']      ?? ''),
        'MAIL_FROM_NAME' => trim($_POST['mail_from_name'] ?? 'Administración del Edificio'),

        // Nombre de la cookie de sesión, propio de esta instalación. Se genera
        // al azar y no se pregunta en el formulario: es un detalle técnico, y
        // dejarlo a elección solo abre la puerta a que dos instalaciones del
        // mismo servidor terminen con el mismo nombre y compartan la sesión.
        'SESSION_NAME'   => 'EDIFICIOSESS_' . bin2hex(random_bytes(8)),
    ];

    // ── Validación ──
    if ($datos['DB_HOST'] === '') {
        $errores[] = 'Falta el host de la base de datos.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{1,255}(:\d{1,5})?$/', $datos['DB_HOST'])) {
        // El host también se interpola en el DSN. Un ";" permitiría agregarle
        // parámetros arbitrarios, y como el error de conexión distingue "puerto
        // cerrado" de "credencial rechazada", serviría para rastrear la red
        // interna desde el formulario.
        $errores[] = 'El host solo puede tener letras, números, puntos, guiones y opcionalmente ":puerto".';
    }

    if ($datos['DB_USER'] === '') $errores[] = 'Falta el usuario de MySQL.';

    if ($datos['DB_NAME'] === '') {
        $errores[] = 'Falta el nombre de la base de datos.';
    } elseif (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $datos['DB_NAME'])) {
        // El nombre de la base se interpola en el CREATE DATABASE porque los
        // identificadores no admiten parámetros preparados. Por eso se restringe
        // el juego de caracteres en vez de confiar en un escape.
        $errores[] = 'El nombre de la base solo puede tener letras, números y guion bajo (máximo 64).';
    }

    if ($datos['MAIL_USER'] !== '' && !filter_var($datos['MAIL_USER'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'La cuenta de correo no tiene un formato válido.';
    }
    if ($datos['MAIL_FROM'] !== '' && !filter_var($datos['MAIL_FROM'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El remitente no tiene un formato válido.';
    }
    if (!ctype_digit($datos['MAIL_PORT'])) {
        $errores[] = 'El puerto SMTP debe ser un número.';
    }

    // Si no se completó el remitente, se usa la misma cuenta que autentica
    if ($datos['MAIL_FROM'] === '') $datos['MAIL_FROM'] = $datos['MAIL_USER'];

    // ── Usuario administrador (opcional) ──
    // Si se completa, el instalador lo crea y el sistema queda listo para
    // entrar. Si se deja vacío, la app muestra la pantalla de alta del primer
    // usuario, que es como funcionaba antes y sigue estando.
    $admin = [
        'usuario' => trim($_POST['admin_usuario'] ?? ''),
        'nombre'  => trim($_POST['admin_nombre']  ?? ''),
        'pass'    =>      $_POST['admin_pass']    ?? '',
        'pass2'   =>      $_POST['admin_pass2']   ?? '',
    ];
    $quiereAdmin = ($admin['usuario'] . $admin['nombre'] . $admin['pass']) !== '';

    // Acá solo se validan las reglas propias del formulario. El formato del
    // usuario y el largo de la contraseña los decide crearUsuario(), que es la
    // misma función que usa la app: duplicar esas reglas acá las dejaría
    // desincronizadas en cuanto una cambie. El formulario las anticipa con
    // pattern y minlength para que el navegador avise antes de enviar.
    if ($quiereAdmin) {
        if ($admin['usuario'] === '' || $admin['nombre'] === '' || $admin['pass'] === '') {
            $errores[] = 'Para crear el administrador hay que completar usuario, nombre y contraseña, o dejar los tres vacíos.';
        } elseif ($admin['pass'] !== $admin['pass2']) {
            $errores[] = 'Las dos contraseñas del administrador no coinciden.';
        }
    }

    if (!$errores && !is_writable(__DIR__)) {
        $errores[] = 'El directorio del proyecto no tiene permiso de escritura: no se puede crear el .env.';
    }

    // ── Instalación ──
    if (!$errores) {
        try {
            // El puerto, si viene en "host:puerto", va como parámetro propio
            // del DSN y no pegado al host.
            [$hostDsn, $puertoDsn] = array_pad(explode(':', $datos['DB_HOST'], 2), 2, null);
            $dsn = "mysql:host={$hostDsn}" . ($puertoDsn !== null ? ";port={$puertoDsn}" : '') . ';charset=utf8mb4';

            // Se conecta al servidor sin elegir base, para poder crearla.
            $pdo = new PDO(
                $dsn,
                $datos['DB_USER'],
                $datos['DB_PASS'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );

            $existia = (bool) $pdo->query(
                'SELECT 1 FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ' . $pdo->quote($datos['DB_NAME'])
            )->fetchColumn();

            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$datos['DB_NAME']}`
                        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$datos['DB_NAME']}`");

            $corridas = migracionesAplicar($pdo);

            // El .env se escribe al final, recién cuando la base quedó bien:
            // si algo falla antes, no queda una instalación a medias que el
            // instalador se niegue a retomar por existir el archivo.
            if (file_put_contents(ENV_PATH, generarEnv($datos), LOCK_EX) === false) {
                throw new RuntimeException('No se pudo escribir el archivo .env.');
            }
            // El .htaccess lo bloquea por web, pero en el disco quedaría 0644:
            // legible por cualquier cuenta local del servidor. En Windows no
            // tiene efecto y chmod devuelve false sin romper nada.
            @chmod(ENV_PATH, 0600);

            // El administrador se crea reusando crearUsuario(), la misma que
            // usa la app, para que la validación y el hasheo vivan en un solo
            // lugar. Hace falta config.php, que recién ahora tiene un .env que
            // leer: por eso este paso va después de escribirlo y no antes.
            $adminCreado = null;
            if ($quiereAdmin) {
                require_once __DIR__ . '/includes/config.php';
                $r = crearUsuario($admin['usuario'], $admin['pass'], $admin['nombre']);
                // Si el alta falla, la instalación igual quedó bien: la base y
                // el .env están. No se aborta, se informa, y el usuario se
                // puede crear desde la app.
                $adminCreado = $r['ok'] ? true : $r['error'];
            }

            $resultado = [
                'base_creada' => !$existia,
                'base'        => $datos['DB_NAME'],
                'migraciones' => $corridas,
                'total'       => count(migraciones()),
                'admin'       => $adminCreado,
                'admin_usuario' => $admin['usuario'],
            ];

        } catch (PDOException $e) {
            // No se devuelve el mensaje crudo: delata el usuario de MySQL, el
            // host y, en Linux, la ruta del socket. Mientras no exista el .env
            // esta página la alcanza cualquiera, así que se traduce a algo
            // accionable y el detalle queda en el log del servidor.
            error_log('instalar.php — fallo de conexión: ' . $e->getMessage());
            $codigo = (int) ($e->errorInfo[1] ?? 0);
            $errores[] = match (true) {
                $codigo === 1045 => 'Usuario o contraseña de MySQL incorrectos.',
                $codigo === 1044 => 'Ese usuario no tiene permisos sobre la base indicada.',
                $codigo === 1049 => 'La base indicada no existe y el usuario no puede crearla.',
                str_contains($e->getMessage(), '2002') => 'No se pudo contactar al servidor MySQL. Verificá que esté levantado y que el host sea correcto.',
                default => 'No se pudo conectar a MySQL. Revisá los datos y que el servidor esté activo.',
            };
        } catch (Throwable $e) {
            error_log('instalar.php — error de instalación: ' . $e->getMessage());
            $errores[] = 'No se pudo completar la instalación. Revisá el log del servidor para el detalle.';
        }
    }
}

/** Arma el contenido del .env. Los valores van entre comillas para que los
 *  caracteres especiales de una contraseña no rompan el formato. */
function generarEnv(array $d): string {
    $linea = function (string $clave, string $valor): string {
        // Se neutralizan comillas y barras, y se descartan saltos de línea, que
        // permitirían inyectar claves extra en el archivo.
        $limpio = str_replace(["\r", "\n"], '', $valor);
        $limpio = str_replace(['\\', '"'], ['\\\\', '\"'], $limpio);
        return "{$clave}=\"{$limpio}\"\n";
    };

    $out  = "# Generado por instalar.php el " . date('Y-m-d H:i') . "\n";
    $out .= "# Este archivo tiene credenciales y no se sube al repositorio.\n\n";
    $out .= "# ─── Base de datos MySQL ───\n";
    foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'] as $k) $out .= $linea($k, $d[$k]);
    $out .= "\n# ─── Sesión ───\n";
    $out .= "# Propio de esta instalación: es el nombre de la cookie. Dos\n";
    $out .= "# instancias en el mismo servidor necesitan nombres distintos, o\n";
    $out .= "# comparten la sesión entre sí.\n";
    $out .= $linea('SESSION_NAME', $d['SESSION_NAME']);
    $out .= "\n# ─── Gmail / SMTP para envío de expensas ───\n";
    foreach (['MAIL_HOST', 'MAIL_PORT', 'MAIL_USER', 'MAIL_PASS', 'MAIL_FROM', 'MAIL_FROM_NAME'] as $k) {
        $out .= $linea($k, $d[$k]);
    }
    return $out;
}

/** Escape para imprimir en el HTML. */
function h(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Valor reenviado al formulario tras un error, para no hacerlo tipear de nuevo. */
function viejo(string $campo, string $default = ''): string {
    return h($_POST[$campo] ?? $default);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalación — Administración de Edificio</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&family=DM+Serif+Display&family=DM+Mono:wght@400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
<style>
  body{display:flex;align-items:flex-start;justify-content:center;padding:40px 24px}
  .instalador{width:100%;max-width:680px}
  .instalador h1{font-family:'DM Serif Display',serif;font-size:26px;color:var(--accent);margin-bottom:6px}
  .instalador .sub{color:var(--text3);font-size:12px;font-family:'DM Mono',monospace;margin-bottom:28px;letter-spacing:.05em}
  .paso{font-size:11px;color:var(--text3);font-family:'DM Mono',monospace;text-transform:uppercase;letter-spacing:.08em;margin:24px 0 12px}
  .lista{list-style:none;font-size:13px;line-height:2}
  .lista li::before{content:'✓ ';color:var(--success)}
</style>
</head>
<body>
<div class="instalador">

  <h1>Administración de Edificio</h1>
  <div class="sub">Instalación</div>

<?php if ($yaInstalado): ?>

  <div class="card">
    <div class="card-title">El sistema ya está instalado</div>
    <p style="color:var(--text2);font-size:13px;line-height:1.7">
      Existe un archivo <strong>.env</strong>, así que el instalador no vuelve a
      ejecutarse: podría pisar las credenciales de una instalación en uso.
    </p>
    <div class="alert alert-warning" style="margin-top:16px">
      Si necesitás reinstalar, borrá el <strong>.env</strong> a mano y volvé a entrar acá.
      Eso no toca la base de datos ni los datos cargados.
    </div>
    <a class="btn btn-primary" href="index.html" style="margin-top:8px">Ir al sistema</a>
  </div>

<?php elseif ($resultado): ?>

  <div class="card">
    <div class="card-title">Instalación completada</div>
    <ul class="lista">
      <li>Base <strong><?= h($resultado['base']) ?></strong>
          <?= $resultado['base_creada'] ? 'creada' : 'ya existía, se reutilizó' ?></li>
      <li><?= count($resultado['migraciones']) ?> de <?= $resultado['total'] ?> migraciones aplicadas</li>
      <li>Archivo <strong>.env</strong> escrito</li>
    </ul>

    <?php if ($resultado['migraciones']): ?>
      <div style="background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;margin-top:16px;font-family:'DM Mono',monospace;font-size:12px;line-height:1.8">
        <?php foreach ($resultado['migraciones'] as $m): ?>
          <div><?= h($m['version']) ?> &nbsp; <?= h($m['nombre']) ?></div>
        <?php endforeach ?>
      </div>
    <?php else: ?>
      <div class="alert alert-info" style="margin-top:16px">
        No había migraciones pendientes: la base ya tenía la estructura al día.
      </div>
    <?php endif ?>

    <?php if ($resultado['admin'] === true): ?>
      <div class="alert alert-success" style="margin-top:16px">
        Administrador <strong><?= h($resultado['admin_usuario']) ?></strong> creado.
        Ya podés iniciar sesión.
      </div>
    <?php elseif (is_string($resultado['admin'])): ?>
      <div class="alert alert-warning" style="margin-top:16px">
        La base quedó instalada, pero <strong>no se pudo crear el administrador</strong>:
        <?= h($resultado['admin']) ?>.<br>
        No es grave: crealo desde la app, que al no haber usuarios te va a mostrar
        la pantalla de alta.
      </div>
    <?php endif ?>

    <div class="alert alert-warning" style="margin-top:20px">
      <strong>Último paso:</strong> borrá <strong>instalar.php</strong> del servidor.
      Mientras exista el <code>.env</code> el instalador no corre, pero lo prolijo es
      que no quede un script de instalación accesible.
    </div>

    <a class="btn btn-primary btn-lg" href="index.html">
      <?= $resultado['admin'] === true ? 'Iniciar sesión' : 'Entrar y crear el primer usuario' ?>
    </a>
  </div>

<?php else: ?>

  <?php if ($errores): ?>
    <div class="alert alert-danger">
      <?php foreach ($errores as $e): ?><div><?= h($e) ?></div><?php endforeach ?>
    </div>
  <?php endif ?>

  <form method="post">

    <div class="paso">1 — Base de datos</div>
    <div class="card">
      <p style="color:var(--text2);font-size:13px;line-height:1.7;margin-bottom:20px">
        Si la base no existe, se crea. Si ya existe, se reutiliza y solo se aplican
        las migraciones que falten: <strong>no se borra nada</strong>.
      </p>
      <div class="grid-2">
        <div class="form-group">
          <label class="form-label" for="db_host">Host</label>
          <input class="form-control" id="db_host" name="db_host" value="<?= viejo('db_host', 'localhost') ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="db_name">Nombre de la base *</label>
          <input class="form-control" id="db_name" name="db_name" value="<?= viejo('db_name', 'edificio') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="db_user">Usuario de MySQL *</label>
          <input class="form-control" id="db_user" name="db_user" value="<?= viejo('db_user') ?>" required autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label" for="db_pass">Contraseña de MySQL</label>
          <input class="form-control" id="db_pass" name="db_pass" type="password" autocomplete="new-password">
        </div>
      </div>
      <div style="font-size:11px;color:var(--text3);font-family:'DM Mono',monospace">
        El usuario necesita permiso para crear bases, o la base ya tiene que existir.
      </div>
    </div>

    <div class="paso">2 — Usuario administrador (se puede crear después)</div>
    <div class="card">
      <p style="color:var(--text2);font-size:13px;line-height:1.7;margin-bottom:20px">
        El primer usuario del sistema, con permisos para todo. Si lo dejás vacío,
        la app te va a pedir que lo crees la primera vez que entres.
      </p>
      <div class="grid-2">
        <div class="form-group">
          <label class="form-label" for="admin_usuario">Usuario</label>
          <input class="form-control" id="admin_usuario" name="admin_usuario"
                 value="<?= viejo('admin_usuario') ?>" autocomplete="off"
                 pattern="[a-zA-Z0-9._\-]{3,50}"
                 title="Entre 3 y 50 caracteres: letras, números, punto, guion o guion bajo">
        </div>
        <div class="form-group">
          <label class="form-label" for="admin_nombre">Nombre y apellido</label>
          <input class="form-control" id="admin_nombre" name="admin_nombre"
                 value="<?= viejo('admin_nombre') ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="admin_pass">Contraseña</label>
          <input class="form-control" id="admin_pass" name="admin_pass" type="password"
                 autocomplete="new-password" minlength="8">
        </div>
        <div class="form-group">
          <label class="form-label" for="admin_pass2">Repetir contraseña</label>
          <input class="form-control" id="admin_pass2" name="admin_pass2" type="password"
                 autocomplete="new-password" minlength="8">
        </div>
      </div>
      <div style="font-size:11px;color:var(--text3);font-family:'DM Mono',monospace">
        Mínimo 8 caracteres. Se guarda hasheada con bcrypt, nunca en claro.
      </div>
    </div>

    <div class="paso">3 — Envío de correo (se puede completar después)</div>
    <div class="card">
      <p style="color:var(--text2);font-size:13px;line-height:1.7;margin-bottom:20px">
        Para mandar las expensas por mail. Si lo dejás vacío, el sistema funciona
        igual y lo completás más adelante editando el <code>.env</code>.
        <strong>MAIL_PASS</strong> es la contraseña de aplicación de 16 caracteres
        que genera Google, no la de la cuenta.
      </p>
      <div class="grid-2">
        <div class="form-group">
          <label class="form-label" for="mail_host">Servidor SMTP</label>
          <input class="form-control" id="mail_host" name="mail_host" value="<?= viejo('mail_host', 'smtp.gmail.com') ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="mail_port">Puerto</label>
          <input class="form-control" id="mail_port" name="mail_port" value="<?= viejo('mail_port', '587') ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="mail_user">Cuenta</label>
          <input class="form-control" id="mail_user" name="mail_user" value="<?= viejo('mail_user') ?>" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label" for="mail_pass">Contraseña de aplicación</label>
          <input class="form-control" id="mail_pass" name="mail_pass" type="password" autocomplete="new-password">
        </div>
        <div class="form-group">
          <label class="form-label" for="mail_from">Remitente</label>
          <input class="form-control" id="mail_from" name="mail_from" value="<?= viejo('mail_from') ?>"
                 placeholder="igual que la cuenta">
        </div>
        <div class="form-group">
          <label class="form-label" for="mail_from_name">Nombre que se muestra</label>
          <input class="form-control" id="mail_from_name" name="mail_from_name"
                 value="<?= viejo('mail_from_name', 'Administración del Edificio') ?>">
        </div>
      </div>
    </div>

    <button class="btn btn-primary btn-lg" type="submit" style="width:100%;justify-content:center">
      Instalar
    </button>
  </form>

<?php endif ?>

</div>
</body>
</html>
