<?php
// ═══════════════════════════════════════════════
//  Configuración — los valores viven en el .env
//  (copiá .env.example como .env y completalo)
// ═══════════════════════════════════════════════

// ─── Lector de .env ───
function env_cargar(string $archivo): void {
    static $cargado = false;
    if ($cargado) return;
    $cargado = true;

    if (!is_readable($archivo)) {
        http_response_code(500);
        exit('Falta el archivo .env — copiá .env.example como .env y completá tus credenciales.');
    }

    foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || strpos($linea, '=') === false) continue;

        [$clave, $valor] = explode('=', $linea, 2);
        $clave = trim($clave);
        $valor = trim($valor);

        // Quita las comillas envolventes si las hay
        $largo = strlen($valor);
        if ($largo >= 2 && $valor[0] === '"' && $valor[$largo - 1] === '"') {
            $valor = substr($valor, 1, -1);
            // Las comillas dobles admiten escapes, y hay que deshacerlos: así
            // los escribe instalar.php. Sin esto una contraseña con \ o " se
            // lee mal y la conexión falla sin explicación. strtr hace una sola
            // pasada, de modo que \\" no se procesa dos veces.
            $valor = strtr($valor, ['\\\\' => '\\', '\\"' => '"']);
        } elseif ($largo >= 2 && $valor[0] === "'" && $valor[$largo - 1] === "'") {
            // Las comillas simples son literales: no se desescapa nada.
            $valor = substr($valor, 1, -1);
        }

        $_ENV[$clave] = $valor;
    }
}

function env(string $clave, ?string $default = null): ?string {
    return $_ENV[$clave] ?? $default;
}

env_cargar(__DIR__ . '/../.env');

// Avisa temprano si falta alguna clave en lugar de fallar con un error raro más adelante
foreach (['DB_USER', 'DB_PASS', 'DB_NAME', 'MAIL_USER', 'MAIL_PASS', 'MAIL_FROM'] as $requerida) {
    if (env($requerida) === null) {
        http_response_code(500);
        exit("Falta la clave {$requerida} en el archivo .env (ver .env.example).");
    }
}

// ─── Zona horaria ───
// XAMPP viene con date.timezone en Europe/Berlin, que no coincide con el reloj
// de MySQL ni con el del navegador. Fijarla acá evita que las fechas generadas
// por PHP queden corridas respecto de las de la base.
date_default_timezone_set(env('TZ', 'America/Argentina/Buenos_Aires'));

// ─── Base de datos ───
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_USER', env('DB_USER'));
define('DB_PASS', env('DB_PASS'));
define('DB_NAME', env('DB_NAME'));

// ─── Gmail para envío de mails ───
define('MAIL_HOST', env('MAIL_HOST', 'smtp.gmail.com'));      // Servidor SMTP
define('MAIL_PORT', (int) env('MAIL_PORT', '587'));           // Puerto SMTP, 587 por defecto
define('MAIL_USER', env('MAIL_USER'));                        // Cuenta que autentica
define('MAIL_PASS', env('MAIL_PASS'));                        // Contraseña de aplicación
define('MAIL_FROM', env('MAIL_FROM'));                        // Cuenta "from"
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'Administración del Edificio')); // Nombre que se muestra

// ─── Conexión PDO ───
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        try {
            $pdo = new PDO(
                'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            // Si MySQL está caído o cambiaron las credenciales, la excepción sin
            // capturar se renderiza en la respuesta con display_errors activado:
            // la traza muestra host, base, usuario y rutas del servidor. Se
            // responde algo genérico y el detalle va solo al log.
            error_log('Fallo de conexión a la base: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'No se pudo conectar a la base de datos']);
            exit;
        }
    }
    return $pdo;
}

// ─── Respuesta JSON ───
function json_ok($data = []): void {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function json_err(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function body(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// ─── Plantilla de mail (texto fijo editable) ───
function plantillaMailDefault(): array {
    return [
        'asunto' => 'Expensas {periodo} - {unidad}',
        'saludo' => 'Estimado/a {nombre},',
        'intro'  => 'Le enviamos la liquidación de expensas correspondiente al período {periodo}.',
        'footer' => 'Por favor realice el pago antes del vencimiento del mes en curso.',
        'firma'  => "Saludos cordiales,\nAdministración del Edificio",
    ];
}

function plantillaMail(): array {
    $row = db()->query('SELECT asunto, saludo, intro, footer, firma FROM plantilla_mail WHERE id = 1')->fetch();
    return $row ?: plantillaMailDefault();
}

// ─── Autenticación ───
// Va al final, cuando db() y json_err() ya están definidas.
require_once __DIR__ . '/auth.php';
