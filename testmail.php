<?php
// ═══════════════════════════════════════════════
//  TEST DE ENVÍO — borrar después de usar
//  Acceder desde: http://localhost/edificio/test_mail.php
// ═══════════════════════════════════════════════

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';
require_once __DIR__ . '/vendor/phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Las credenciales salen del .env; sólo el destino del test se pone acá
$gmail_user = MAIL_USER;
$gmail_pass = MAIL_PASS;
$destino    = $_GET['to'] ?? MAIL_FROM;   // ?to=alguien@dominio.com para probar otro destinatario




echo "<pre style='font-family:monospace;font-size:14px;padding:20px'>";
echo "=== TEST PHPMAILER ===\n\n";

// Verificar que los archivos existen
$files = [
    'vendor/phpmailer/PHPMailer.php',
    'vendor/phpmailer/SMTP.php',
    'vendor/phpmailer/Exception.php',
];
foreach ($files as $f) {
    echo file_exists(__DIR__.'/'.$f)
        ? "✓ $f\n"
        : "✗ NO ENCONTRADO: $f\n";
}
echo "\n";

// Verificar extensión OpenSSL
echo extension_loaded('openssl') ? "✓ OpenSSL activo\n" : "✗ OpenSSL NO disponible\n";
echo "\n";

$mail = new PHPMailer(true);
$mail->SMTPDebug = SMTP::DEBUG_SERVER; // muestra toda la conversación SMTP
$mail->Debugoutput = function($str, $level) {
    echo htmlspecialchars($str) . "\n";
};

try {
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = $gmail_user;
    $mail->Password   = $gmail_pass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom($gmail_user, 'Test Edificio');
    $mail->addAddress($destino);
    $mail->Subject = 'Test envío desde XAMPP';
    $mail->Body    = 'Si recibís esto, el mail funciona correctamente.';

    $mail->send();
    echo "\n✓ MAIL ENVIADO CORRECTAMENTE\n";

} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "Detalle: " . $mail->ErrorInfo . "\n";
}

echo "</pre>";