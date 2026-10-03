<?php
require_once __DIR__ . '/../includes/config.php';

// El GET lo necesita cualquier usuario logueado: el nombre y la foto del
// edificio se muestran en la barra lateral para todos. Guardar pide admin.
requireLoginAdminParaEscritura();

const FOTO_DIR      = __DIR__ . '/../uploads/edificio';
const FOTO_MAX_BYTES = 8 * 1024 * 1024;   // 8 MB
const FOTO_MAX_LADO  = 1200;              // px, el lado mayor

/** Campos de texto editables, en el orden en que se guardan. */
function camposEdificio(): array {
    return ['nombre', 'direccion', 'localidad', 'cuit', 'administrador', 'email_contacto', 'telefono'];
}

function datosEdificio(): array {
    $row = db()->query('SELECT * FROM edificio WHERE id = 1')->fetch();
    if (!$row) {
        return array_fill_keys(array_merge(camposEdificio(), ['foto']), null)
             + ['nombre' => 'Administración de Edificio'];
    }
    // La foto se devuelve como ruta usable por el navegador, o null.
    $row['foto_url'] = $row['foto'] ? 'uploads/edificio/' . $row['foto'] : null;
    return $row;
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET — datos actuales ────────────────────────────────────────────────────
if ($method === 'GET') {
    json_ok(datosEdificio());
}

// ── PUT — guardar los campos de texto ───────────────────────────────────────
if ($method === 'PUT') {
    $b = body();

    $nombre = trim($b['nombre'] ?? '');
    if ($nombre === '') json_err('El nombre del edificio es obligatorio');
    if (mb_strlen($nombre) > 150) json_err('El nombre no puede superar los 150 caracteres');

    $email = trim($b['email_contacto'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_err('El email de contacto no tiene un formato válido');
    }

    $valores = [];
    foreach (camposEdificio() as $campo) {
        $v = trim((string) ($b[$campo] ?? ''));
        $valores[] = ($v === '') ? null : $v;
    }
    $valores[0] = $nombre;   // nombre no puede ser null

    $sets = implode(', ', array_map(fn($c) => "$c = ?", camposEdificio()));
    db()->prepare("UPDATE edificio SET $sets WHERE id = 1")->execute($valores);

    json_ok(datosEdificio());
}

// ── POST — subir o quitar la foto ───────────────────────────────────────────
if ($method === 'POST') {

    // Quitar la foto actual
    if (($_POST['accion'] ?? '') === 'quitar') {
        borrarFotoActual();
        db()->prepare('UPDATE edificio SET foto = NULL WHERE id = 1')->execute();
        json_ok(datosEdificio());
    }

    if (!isset($_FILES['foto'])) json_err('No se recibió ningún archivo');

    $f = $_FILES['foto'];

    if ($f['error'] !== UPLOAD_ERR_OK) {
        json_err(match ($f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'La imagen es demasiado grande',
            UPLOAD_ERR_PARTIAL                        => 'La subida se interrumpió',
            UPLOAD_ERR_NO_FILE                        => 'No se seleccionó ninguna imagen',
            default                                   => 'Error al subir la imagen',
        });
    }

    if ($f['size'] > FOTO_MAX_BYTES) json_err('La imagen no puede superar los 8 MB');

    // Se valida el tipo real leyendo la cabecera del archivo, no la extensión
    // ni el content-type que manda el navegador: los dos son del cliente.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($f['tmp_name']);

    $permitidos = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
    ];
    if (!isset($permitidos[$mime])) {
        json_err('Formato no admitido. Usá JPG, PNG o WEBP.');
    }

    $img = @$permitidos[$mime]($f['tmp_name']);
    if (!$img) json_err('No se pudo leer la imagen');

    // Redimensionado al lado mayor
    $ancho = imagesx($img);
    $alto  = imagesy($img);
    $lado  = max($ancho, $alto);

    if ($lado > FOTO_MAX_LADO) {
        $escala     = FOTO_MAX_LADO / $lado;
        $nuevoAncho = (int) round($ancho * $escala);
        $nuevoAlto  = (int) round($alto  * $escala);

        $dst = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);
        imagedestroy($img);
        $img = $dst;
    }

    if (!is_dir(FOTO_DIR) && !mkdir(FOTO_DIR, 0775, true)) {
        json_err('No se pudo crear el directorio de imágenes');
    }

    // Nombre al azar: nunca se reutiliza el que mandó el cliente.
    $nombreArchivo = bin2hex(random_bytes(16)) . '.jpg';
    $destino       = FOTO_DIR . '/' . $nombreArchivo;

    // Se re-codifica siempre a JPEG. Además de normalizar el formato, esto
    // descarta los metadatos EXIF del original — que en una foto sacada con
    // celular incluyen las coordenadas GPS del lugar.
    $ok = imagejpeg($img, $destino, 85);
    imagedestroy($img);

    if (!$ok) json_err('No se pudo guardar la imagen');

    borrarFotoActual();
    db()->prepare('UPDATE edificio SET foto = ? WHERE id = 1')->execute([$nombreArchivo]);

    json_ok(datosEdificio());
}

json_err('Método no permitido', 405);

// ── Auxiliares ──────────────────────────────────────────────────────────────

/** Borra del disco la foto que esté registrada, si existe. */
function borrarFotoActual(): void {
    $actual = db()->query('SELECT foto FROM edificio WHERE id = 1')->fetchColumn();
    if (!$actual) return;

    // basename() corta cualquier intento de salirse del directorio
    $ruta = FOTO_DIR . '/' . basename($actual);
    if (is_file($ruta)) @unlink($ruta);
}
