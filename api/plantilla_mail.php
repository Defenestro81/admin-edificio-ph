<?php
require_once __DIR__ . '/../includes/config.php';
// Todos los endpoints exigen sesión iniciada. El SPA se sirve desde el mismo
// origen, así que no hacen falta cabeceras CORS (y un Allow-Origin: * sería
// contraproducente: impediría el envío de la cookie de sesión).
requireLogin();

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
