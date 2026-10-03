<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/SMTP.php';
require_once __DIR__ . '/../vendor/phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Todos los endpoints exigen sesión iniciada. El rol 'consulta' puede leer
// (GET) pero no modificar: las escrituras piden rol 'admin'. El SPA se sirve
// desde el mismo origen, así que no hacen falta cabeceras CORS (y un
// Allow-Origin: * sería contraproducente: impediría el envío de la cookie).
requireLoginAdminParaEscritura();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Método no permitido', 405);

$b = body();
if (empty($b['detalle_id'])) json_err('Falta detalle_id');

// Verificar si se enviaron asunto y cuerpo personalizados
$asuntoPersonalizado = !empty($b['asunto']) ? trim($b['asunto']) : null;
$cuerpoPersonalizado = !empty($b['cuerpo']) ? trim($b['cuerpo']) : null;

$db = db();

// Obtener detalle + saldo anterior desde cuenta_corriente
$st = $db->prepare('
    SELECT ld.*, u.nombre, u.propietario, u.email, l.periodo,
           COALESCE(cc.saldo_anterior, 0) as saldo_anterior
    FROM liquidacion_detalle ld
    JOIN unidades u ON u.id = ld.unidad_id
    JOIN liquidaciones l ON l.id = ld.liquidacion_id
    LEFT JOIN cuenta_corriente cc ON cc.unidad_id = ld.unidad_id AND cc.periodo = l.periodo
    WHERE ld.id = ?
');
$st->execute([$b['detalle_id']]);
$detalle = $st->fetch();
if (!$detalle) json_err('Detalle no encontrado', 404);
if (empty($detalle['email'])) json_err('La unidad no tiene email registrado');

// Obtener líneas
$st2 = $db->prepare('SELECT * FROM liquidacion_lineas WHERE detalle_id = ?');
$st2->execute([$b['detalle_id']]);
$lineas = $st2->fetchAll();

// Datos generales
$meses = ['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio',
          '07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];
$mes          = $meses[substr($detalle['periodo'], 4, 2)] ?? '';
$anio         = substr($detalle['periodo'], 0, 4);
$periodoLabel = "$mes $anio";
$nombre       = $detalle['propietario'] ?: $detalle['nombre'];
$saldoAnt     = (float) $detalle['saldo_anterior'];
$total        = '$ ' . number_format($detalle['total'], 2, ',', '.');

// ── Plantilla editable (texto fijo) ────────────────────────────
$plantilla = plantillaMail();
$vars = ['{nombre}' => $nombre, '{unidad}' => $detalle['nombre'], '{periodo}' => $periodoLabel];
$asuntoDefault = strtr($plantilla['asunto'], $vars);
$saludo        = strtr($plantilla['saludo'], $vars);
$intro         = strtr($plantilla['intro'], $vars);
$footer        = strtr($plantilla['footer'], $vars);
$firma         = $plantilla['firma'];

// ── Cuerpo texto plano ────────────────────────────────────────
$lineasTexto = '';
foreach ($lineas as $l) {
    $lineasTexto .= '  · ' . str_pad($l['concepto'], 32) . '$ ' . number_format($l['importe'], 2, ',', '.') . "\n";
}
if ($saldoAnt != 0) {
    $signo = $saldoAnt < 0 ? '-$ ' : '$ ';
    $lineasTexto .= '  · ' . str_pad('Saldo anterior', 32) . $signo . number_format(abs($saldoAnt), 2, ',', '.') . "\n";
}

$bodyText = "$saludo\n\n"
    . "$intro\n\n"
    . "DETALLE:\n$lineasTexto\n"
    . str_repeat('─', 46) . "\n"
    . "  TOTAL A PAGAR" . str_pad('', 18) . "$total\n"
    . str_repeat('─', 46) . "\n\n"
    . "$footer\n\n"
    . $firma;

// ── Cuerpo HTML ───────────────────────────────────────────────
$bodyHtml = '<!DOCTYPE html><html><head><meta charset="utf-8">
<style>
  body { font-family: Arial, sans-serif; background:#f5f5f5; margin:0; padding:20px; }
  .card { background:#fff; border-radius:8px; padding:32px; max-width:520px; margin:auto; }
  h2 { color:#1a1a18; font-size:20px; margin-bottom:4px; }
  .period { color:#888; font-size:13px; margin-bottom:24px; }
  table { width:100%; border-collapse:collapse; margin-bottom:16px; }
  td { padding:8px 4px; border-bottom:1px solid #eee; font-size:14px; }
  td:last-child { text-align:right; font-family:monospace; }
  .total-row td { border-top:2px solid #c8a96e; border-bottom:none; font-weight:bold; font-size:16px; color:#1a1a18; padding-top:12px; }
  .saldo-row td { color:#c0392b; font-style:italic; font-size:13px; }
  .saldo-favor td { color:#27ae60; font-style:italic; font-size:13px; }
  .badge { display:inline-block; padding:2px 7px; border-radius:10px; font-size:10px; margin-left:6px; }
  .fijo { background:#e8f0fe; color:#1a6c; }
  .esporadico { background:#fef3e2; color:#b45; }
  .footer { margin-top:24px; font-size:12px; color:#aaa; text-align:center; }
</style></head><body>
<div class="card">
  <h2>Expensas ' . htmlspecialchars($periodoLabel) . '</h2>
  <div class="period">Unidad: <strong>' . htmlspecialchars($detalle['nombre']) . '</strong></div>
  <p>' . nl2br(htmlspecialchars($saludo)) . '</p>
  <p>' . nl2br(htmlspecialchars($intro)) . '</p>
  <table>';

foreach ($lineas as $l) {
    $badge = $l['tipo'] === 'esporadico'
        ? '<span class="badge esporadico">esporádico</span>'
        : '<span class="badge fijo">fijo</span>';
    $bodyHtml .= '<tr><td>' . htmlspecialchars($l['concepto']) . $badge . '</td><td>$ ' . number_format($l['importe'], 2, ',', '.') . '</td></tr>';
}

// Agregar saldo anterior si existe
if ($saldoAnt != 0) {
    $esDeuda = $saldoAnt > 0;
    $clase   = $esDeuda ? 'saldo-row' : 'saldo-favor';
    $label   = $esDeuda ? 'Saldo anterior (deuda)' : 'Saldo anterior (a su favor)';
    $valor   = ($esDeuda ? '$ ' : '-$ ') . number_format(abs($saldoAnt), 2, ',', '.');
    $bodyHtml .= '<tr class="' . $clase . '"><td>' . $label . '</td><td>' . $valor . '</td></tr>';
}

$bodyHtml .= '
  <tr class="total-row"><td>TOTAL A PAGAR</td><td>' . $total . '</td></tr>
  </table>
  <p style="font-size:13px;color:#555">' . nl2br(htmlspecialchars($footer)) . '</p>
  <div class="footer">' . nl2br(htmlspecialchars($firma)) . '</div>
</div></body></html>';

// ── Enviar ────────────────────────────────────────────────────
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USER;
    $mail->Password   = MAIL_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->addAddress($detalle['email'], $nombre);

    // Usar asunto personalizado si existe, sino usar el predeterminado de la plantilla
    $mail->Subject = $asuntoPersonalizado ?: $asuntoDefault;

    // Si hay cuerpo personalizado, enviarlo como texto plano
    if ($cuerpoPersonalizado) {
        $mail->isHTML(false);
        $mail->Body = $cuerpoPersonalizado;
    } else {
        // Usar el formato HTML predeterminado
        $mail->isHTML(true);
        $mail->Body    = $bodyHtml;
        $mail->AltBody = $bodyText;
    }

    $mail->send();

    $db->prepare('UPDATE liquidacion_detalle SET mail_enviado=1, mail_enviado_en=NOW() WHERE id=?')
       ->execute([$b['detalle_id']]);

    json_ok(['mensaje' => 'Mail enviado a ' . $detalle['email']]);

} catch (Exception $e) {
    json_err('Error al enviar: ' . $mail->ErrorInfo);
}
