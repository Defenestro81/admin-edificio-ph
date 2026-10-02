<?php
require_once __DIR__ . '/../includes/config.php';
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,PUT,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$method = $_SERVER['REQUEST_METHOD'];

// GET — obtener plantilla actual (o la predeterminada si no fue configurada)
if ($method === 'GET') {
    json_ok(plantillaMail());
}

// PUT — guardar plantilla
if ($method === 'PUT') {
    $b = body();
    foreach (['asunto', 'saludo', 'intro', 'footer', 'firma'] as $campo) {
        if (!isset($b[$campo]) || trim($b[$campo]) === '') json_err("Falta el campo $campo");
    }

    $st = db()->prepare('
        INSERT INTO plantilla_mail (id, asunto, saludo, intro, footer, firma)
        VALUES (1, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE asunto=VALUES(asunto), saludo=VALUES(saludo), intro=VALUES(intro), footer=VALUES(footer), firma=VALUES(firma)
    ');
    $st->execute([$b['asunto'], $b['saludo'], $b['intro'], $b['footer'], $b['firma']]);

    json_ok(plantillaMail());
}
