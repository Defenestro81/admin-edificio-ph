<?php
// ═══════════════════════════════════════════════════════════════
//  Migraciones de base de datos
//
//  Cada migración se aplica una sola vez y queda registrada en la
//  tabla `migraciones`. Para agregar una nueva, sumá una entrada al
//  final de migraciones() con la versión siguiente — nunca edites ni
//  reordenes una que ya pudo haberse aplicado en alguna instalación.
// ═══════════════════════════════════════════════════════════════

/**
 * Registro ordenado de migraciones. Cada una es un array de sentencias
 * SQL que se ejecutan en orden dentro de la misma migración.
 */
function migraciones(): array {
    return [

        // ── 001 — Esquema inicial ──────────────────────────────────
        [
            'version' => '001',
            'nombre'  => 'Esquema inicial',
            'sql'     => [
                "CREATE TABLE IF NOT EXISTS unidades (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    nombre VARCHAR(100) NOT NULL,
                    propietario VARCHAR(150),
                    email VARCHAR(150),
                    coeficiente DECIMAL(8,6) NOT NULL,
                    ascensor TINYINT(1) DEFAULT 1,
                    orden INT DEFAULT 0,
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                )",

                "CREATE TABLE IF NOT EXISTS gastos_fijos (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    concepto VARCHAR(150) NOT NULL,
                    importe DECIMAL(12,2) NOT NULL,
                    division ENUM('coeficiente','partes_iguales') DEFAULT 'coeficiente',
                    activo TINYINT(1) DEFAULT 1,
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                )",

                "CREATE TABLE IF NOT EXISTS gastos_fijos_unidades (
                    gasto_fijo_id INT NOT NULL,
                    unidad_id INT NOT NULL,
                    PRIMARY KEY (gasto_fijo_id, unidad_id),
                    FOREIGN KEY (gasto_fijo_id) REFERENCES gastos_fijos(id) ON DELETE CASCADE,
                    FOREIGN KEY (unidad_id) REFERENCES unidades(id) ON DELETE CASCADE
                )",

                "CREATE TABLE IF NOT EXISTS gastos_esporadicos (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    periodo CHAR(6) NOT NULL,
                    concepto VARCHAR(150) NOT NULL,
                    importe DECIMAL(12,2) NOT NULL,
                    division ENUM('coeficiente','partes_iguales') DEFAULT 'coeficiente',
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                )",

                "CREATE TABLE IF NOT EXISTS gastos_esporadicos_unidades (
                    gasto_esporadico_id INT NOT NULL,
                    unidad_id INT NOT NULL,
                    PRIMARY KEY (gasto_esporadico_id, unidad_id),
                    FOREIGN KEY (gasto_esporadico_id) REFERENCES gastos_esporadicos(id) ON DELETE CASCADE,
                    FOREIGN KEY (unidad_id) REFERENCES unidades(id) ON DELETE CASCADE
                )",

                // Solo puede existir una liquidación 'ordinaria' por período; eso lo
                // garantiza el código (api/liquidacion.php borra la anterior antes de
                // insertar), no una restricción UNIQUE, porque sí puede haber varias
                // 'extraordinaria' en el mismo período.
                "CREATE TABLE IF NOT EXISTS liquidaciones (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    periodo CHAR(6) NOT NULL,
                    calculada_en DATETIME DEFAULT CURRENT_TIMESTAMP,
                    total_general DECIMAL(14,2),
                    KEY periodo (periodo)
                )",

                "CREATE TABLE IF NOT EXISTS liquidacion_detalle (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    liquidacion_id INT NOT NULL,
                    unidad_id INT NOT NULL,
                    total DECIMAL(12,2) NOT NULL,
                    mail_enviado TINYINT(1) DEFAULT 0,
                    mail_enviado_en DATETIME,
                    FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones(id) ON DELETE CASCADE,
                    FOREIGN KEY (unidad_id) REFERENCES unidades(id) ON DELETE CASCADE
                )",

                "CREATE TABLE IF NOT EXISTS liquidacion_lineas (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    detalle_id INT NOT NULL,
                    concepto VARCHAR(150) NOT NULL,
                    importe DECIMAL(12,2) NOT NULL,
                    tipo ENUM('fijo','esporadico') DEFAULT 'fijo',
                    FOREIGN KEY (detalle_id) REFERENCES liquidacion_detalle(id) ON DELETE CASCADE
                )",

                "CREATE TABLE IF NOT EXISTS caja (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    fecha DATE NOT NULL,
                    tipo ENUM('ingreso','egreso') NOT NULL,
                    concepto VARCHAR(200) NOT NULL,
                    unidad_id INT DEFAULT NULL,
                    importe DECIMAL(12,2) NOT NULL,
                    periodo CHAR(6) DEFAULT NULL,
                    notas TEXT,
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (unidad_id) REFERENCES unidades(id) ON DELETE SET NULL
                )",

                "CREATE TABLE IF NOT EXISTS cuenta_corriente (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    unidad_id INT NOT NULL,
                    periodo CHAR(6) NOT NULL,
                    deuda DECIMAL(12,2) NOT NULL,
                    saldo_anterior DECIMAL(12,2) NOT NULL DEFAULT 0,
                    pagado DECIMAL(12,2) NOT NULL DEFAULT 0,
                    cerrado TINYINT(1) DEFAULT 0,
                    UNIQUE KEY uk_unidad_periodo (unidad_id, periodo),
                    FOREIGN KEY (unidad_id) REFERENCES unidades(id) ON DELETE CASCADE
                )",

                "CREATE TABLE IF NOT EXISTS plantilla_mail (
                    id INT PRIMARY KEY,
                    asunto VARCHAR(200) NOT NULL,
                    saludo VARCHAR(200) NOT NULL,
                    intro TEXT NOT NULL,
                    footer TEXT NOT NULL,
                    firma TEXT NOT NULL,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",

                "INSERT IGNORE INTO plantilla_mail (id, asunto, saludo, intro, footer, firma) VALUES (
                    1,
                    'Expensas {periodo} - {unidad}',
                    'Estimado/a {nombre},',
                    'Le enviamos la liquidación de expensas correspondiente al período {periodo}.',
                    'Por favor realice el pago antes del vencimiento del mes en curso.',
                    'Saludos cordiales,\\nAdministración del Edificio'
                )",
            ],
        ],

        // ── 002 — Liquidaciones extraordinarias ────────────────────
        //
        // Cada paso comprueba el esquema antes de tocarlo. No es prolijidad:
        // esta migración puede encontrarse con una base que ya tiene parte del
        // cambio aplicado a mano, porque antes de que existiera este motor el
        // cambio se aplicaba con un script SQL suelto (ya eliminado del repo,
        // pero que pudo correrse en bases que todavía están en uso). MySQL no
        // admite "IF NOT EXISTS" en ADD COLUMN, así que preguntar es la única
        // forma. Sin estas guardas la migración aborta a mitad de camino, no
        // queda registrada, y al reintentar falla en la primera sentencia: la
        // instalación se traba sin salida.
        [
            'version' => '002',
            'nombre'  => 'Liquidaciones extraordinarias',
            'sql'     => [
                function (PDO $db): void {
                    if (columnaExiste($db, 'liquidaciones', 'tipo')) return;
                    $db->exec("ALTER TABLE liquidaciones
                        ADD COLUMN tipo ENUM('ordinaria','extraordinaria') NOT NULL DEFAULT 'ordinaria' AFTER periodo,
                        ADD COLUMN titulo VARCHAR(150) NULL AFTER tipo");
                },

                function (PDO $db): void {
                    if (indiceExiste($db, 'liquidaciones', 'periodo')) {
                        $db->exec("ALTER TABLE liquidaciones DROP INDEX periodo");
                    }
                    if (!indiceExiste($db, 'liquidaciones', 'idx_periodo_tipo')) {
                        $db->exec("ALTER TABLE liquidaciones ADD INDEX idx_periodo_tipo (periodo, tipo)");
                    }
                },

                // MODIFY es idempotente: repetirlo deja la columna igual.
                "ALTER TABLE liquidacion_lineas
                    MODIFY COLUMN tipo ENUM('fijo','esporadico','extraordinario') DEFAULT 'fijo'",

                function (PDO $db): void {
                    if (columnaExiste($db, 'cuenta_corriente', 'extraordinario')) return;
                    $db->exec("ALTER TABLE cuenta_corriente
                        ADD COLUMN extraordinario DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER saldo_anterior");
                },

                // saldo_final es una columna generada. Si ya está, hay que
                // REDEFINIRLA: una base anterior la tiene con la fórmula vieja,
                // sin "+ extraordinario", y dejarla así calcularía mal los
                // saldos en silencio, que es peor que fallar.
                function (PDO $db): void {
                    $formula = 'saldo_anterior + deuda + extraordinario - pagado';
                    $actual  = expresionGenerada($db, 'cuenta_corriente', 'saldo_final');

                    if ($actual === null && !columnaExiste($db, 'cuenta_corriente', 'saldo_final')) {
                        $db->exec("ALTER TABLE cuenta_corriente
                            ADD COLUMN saldo_final DECIMAL(12,2)
                            GENERATED ALWAYS AS ($formula) STORED");
                        return;
                    }

                    if ($actual === null || !str_contains($actual, 'extraordinario')) {
                        $db->exec("ALTER TABLE cuenta_corriente
                            MODIFY COLUMN saldo_final DECIMAL(12,2)
                            GENERATED ALWAYS AS ($formula) STORED");
                    }
                },
            ],
        ],

        // ── 003 — Usuarios y autenticación ─────────────────────────
        [
            'version' => '003',
            'nombre'  => 'Usuarios y autenticación',
            'sql'     => [
                "CREATE TABLE IF NOT EXISTS usuarios (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    usuario VARCHAR(50) NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    nombre VARCHAR(150) NOT NULL,
                    intentos_fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    bloqueado_hasta DATETIME DEFAULT NULL,
                    ultimo_acceso DATETIME DEFAULT NULL,
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_usuario (usuario)
                )",
            ],
        ],

        // ── 004 — Roles, baja lógica y trazabilidad ────────────────
        [
            'version' => '004',
            'nombre'  => 'Roles de usuario y trazabilidad',
            // Mismo criterio que la 002: cada paso pregunta antes. Esta
            // migración puede encontrarse con la mitad del cambio aplicado si
            // se restauró un backup viejo, porque el volcado reemplaza algunas
            // tablas y deja otras intactas. MySQL no admite "IF NOT EXISTS" en
            // ADD COLUMN ni en ADD CONSTRAINT.
            'sql'     => [
                // 'admin' hace todo; 'consulta' solo lee.
                function (PDO $db): void {
                    $partes = [];
                    if (!columnaExiste($db, 'usuarios', 'rol')) {
                        $partes[] = "ADD COLUMN rol ENUM('admin','consulta') NOT NULL DEFAULT 'admin' AFTER nombre";
                    }
                    if (!columnaExiste($db, 'usuarios', 'activo')) {
                        $partes[] = 'ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER rol';
                    }
                    if ($partes !== []) {
                        $db->exec('ALTER TABLE usuarios ' . implode(', ', $partes));
                    }
                },

                // Quién cargó cada movimiento de caja. ON DELETE SET NULL para que
                // dar de baja un usuario nunca borre un asiento contable.
                function (PDO $db): void {
                    if (!columnaExiste($db, 'caja', 'usuario_id')) {
                        $db->exec('ALTER TABLE caja ADD COLUMN usuario_id INT NULL DEFAULT NULL');
                    }
                    if (!restriccionExiste($db, 'caja', 'fk_caja_usuario')) {
                        $db->exec('ALTER TABLE caja
                            ADD CONSTRAINT fk_caja_usuario
                                FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL');
                    }
                },

                function (PDO $db): void {
                    if (!columnaExiste($db, 'liquidaciones', 'usuario_id')) {
                        $db->exec('ALTER TABLE liquidaciones ADD COLUMN usuario_id INT NULL DEFAULT NULL');
                    }
                    if (!restriccionExiste($db, 'liquidaciones', 'fk_liquidaciones_usuario')) {
                        $db->exec('ALTER TABLE liquidaciones
                            ADD CONSTRAINT fk_liquidaciones_usuario
                                FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL');
                    }
                },
            ],
        ],

        // ── 005 — Datos del edificio administrado ──────────────────
        [
            'version' => '005',
            'nombre'  => 'Datos del edificio',
            'sql'     => [
                // Una sola fila (id = 1), como plantilla_mail. Saca de los
                // archivos el nombre y la foto, que son propios de cada
                // instalación y no deben vivir en el repositorio.
                "CREATE TABLE IF NOT EXISTS edificio (
                    id TINYINT UNSIGNED PRIMARY KEY,
                    nombre VARCHAR(150) NOT NULL DEFAULT '',
                    direccion VARCHAR(200) NULL,
                    localidad VARCHAR(100) NULL,
                    cuit VARCHAR(20) NULL,
                    administrador VARCHAR(150) NULL,
                    email_contacto VARCHAR(150) NULL,
                    telefono VARCHAR(50) NULL,
                    foto VARCHAR(255) NULL,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",

                "INSERT IGNORE INTO edificio (id, nombre) VALUES (1, 'Administración de Edificio')",
            ],
        ],

        // ── 006 — Límite de intentos de acceso por velocidad ───────
        [
            'version' => '006',
            'nombre'  => 'Límite de intentos de acceso',
            'sql'     => [
                // Registro de intentos para detectar ráfagas. Se purga solo:
                // no es una bitácora de auditoría, solo la ventana reciente.
                "CREATE TABLE IF NOT EXISTS intentos_login (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    ip VARCHAR(45) NOT NULL,
                    usuario VARCHAR(50) NULL,
                    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_ip_fecha (ip, creado_en)
                )",

                // El bloqueo se guarda por IP y no por usuario: si fuera por
                // usuario, un script evitaría el límite cambiando el nombre en
                // cada intento.
                "CREATE TABLE IF NOT EXISTS bloqueos_acceso (
                    ip VARCHAR(45) PRIMARY KEY,
                    bloqueado_hasta DATETIME NOT NULL,
                    motivo VARCHAR(100) NOT NULL,
                    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )",
            ],
        ],

    ];
}

// ───────────────────────────────────────────────────────────────
//  Inspección del esquema
//
//  Sirve para escribir migraciones idempotentes. MySQL no admite
//  "IF NOT EXISTS" en ADD COLUMN ni en ADD INDEX, así que la única
//  forma de que una migración tolere aplicarse sobre una base que ya
//  tiene parte del cambio es preguntar antes.
// ───────────────────────────────────────────────────────────────

function tablaExiste(PDO $db, string $tabla): bool {
    $st = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$tabla]);
    return (bool) $st->fetchColumn();
}

function columnaExiste(PDO $db, string $tabla, string $columna): bool {
    $st = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$tabla, $columna]);
    return (bool) $st->fetchColumn();
}

function indiceExiste(PDO $db, string $tabla, string $indice): bool {
    $st = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $st->execute([$tabla, $indice]);
    return (bool) $st->fetchColumn();
}

/** Existe una restricción (clave ajena, única, primaria) con ese nombre. */
function restriccionExiste(PDO $db, string $tabla, string $nombre): bool {
    $st = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?');
    $st->execute([$tabla, $nombre]);
    return (bool) $st->fetchColumn();
}

/** Expresión de una columna generada, o null si no lo es o no existe. */
function expresionGenerada(PDO $db, string $tabla, string $columna): ?string {
    $st = $db->prepare('SELECT GENERATION_EXPRESSION FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$tabla, $columna]);
    $v = $st->fetchColumn();
    return ($v === false || $v === '') ? null : (string) $v;
}

// ───────────────────────────────────────────────────────────────
//  Motor
// ───────────────────────────────────────────────────────────────

/** Crea la tabla de control si falta. Devuelve true si la acaba de crear. */
function migracionesInit(PDO $db): bool {
    $existia = (bool) $db->query("SHOW TABLES LIKE 'migraciones'")->fetchColumn();

    $db->exec("CREATE TABLE IF NOT EXISTS migraciones (
        version CHAR(3) PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        aplicada_en DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    return !$existia;
}

/**
 * ¿El esquema real tiene aplicada esta migración? Mira la base, no el registro.
 *
 * Una migración se da por aplicada solo si están TODAS las piezas que crea.
 * Alcanzaba con mirar una sola tabla, pero si la base venía incompleta —por
 * ejemplo con "unidades" pero sin "plantilla_mail"— quedaba registrada igual y
 * esa tabla no se creaba nunca: el motor ya no la vuelve a mirar.
 *
 * Devuelve null si la versión no se puede determinar por esquema, lo que pasa
 * con cualquier migración futura que no agregue estructura detectable.
 */
function migracionAplicadaEnEsquema(PDO $db, string $version): ?bool {
    switch ($version) {
        case '001':
            $tablas = [
                'unidades', 'gastos_fijos', 'gastos_fijos_unidades',
                'gastos_esporadicos', 'gastos_esporadicos_unidades',
                'liquidaciones', 'liquidacion_detalle', 'liquidacion_lineas',
                'caja', 'cuenta_corriente', 'plantilla_mail',
            ];
            return array_filter($tablas, fn($t) => !tablaExiste($db, $t)) === [];

        case '002':
            // Las tres piezas: la columna de tipo, la de cargos extraordinarios
            // y la fórmula de saldo_final ya redefinida. Darla por aplicada con
            // una sola de las tres dejaba saldos mal calculados sin aviso.
            $expr = expresionGenerada($db, 'cuenta_corriente', 'saldo_final');
            return columnaExiste($db, 'liquidaciones', 'tipo')
                && columnaExiste($db, 'cuenta_corriente', 'extraordinario')
                && $expr !== null && str_contains($expr, 'extraordinario');

        case '003':
            return tablaExiste($db, 'usuarios');

        case '004':
            return columnaExiste($db, 'usuarios', 'rol')
                && columnaExiste($db, 'usuarios', 'activo')
                && columnaExiste($db, 'caja', 'usuario_id')
                && columnaExiste($db, 'liquidaciones', 'usuario_id');

        case '005':
            return tablaExiste($db, 'edificio');

        case '006':
            return tablaExiste($db, 'intentos_login')
                && tablaExiste($db, 'bloqueos_acceso');
    }

    return null;
}

/**
 * Para bases que ya existían antes de que hubiera migraciones: detecta qué
 * partes del esquema ya están y las marca como aplicadas, para no reintentar
 * ALTERs que fallarían. Solo corre la primera vez.
 */
function migracionesBaseline(PDO $db): array {
    $marcadas = [];

    // Base vacía o a medias: que corran las migraciones desde cero. Los
    // CREATE TABLE de 001 llevan IF NOT EXISTS, así que completar lo que falta
    // es seguro aunque parte ya esté.
    if (migracionAplicadaEnEsquema($db, '001') !== true) return $marcadas;

    foreach (migraciones() as $m) {
        if (migracionAplicadaEnEsquema($db, $m['version']) !== true) continue;

        $st = $db->prepare('INSERT IGNORE INTO migraciones (version, nombre) VALUES (?, ?)');
        $st->execute([$m['version'], $m['nombre']]);
        $marcadas[] = $m['version'];
    }

    return $marcadas;
}

/**
 * Compara el registro contra el esquema real y corrige el registro.
 *
 * Hace falta porque restaurar un backup puede dejar los dos desincronizados:
 * el volcado reemplaza las tablas que contiene y deja intactas las que no,
 * pero la tabla `migraciones` suele estar entre las que sobreviven. Entonces
 * el registro sigue afirmando que todo está aplicado mientras el esquema
 * volvió atrás, nadie encuentra nada pendiente, y la base queda mal en
 * silencio: por ejemplo saldo_final sin "+ extraordinario".
 *
 * Solo borra filas que mienten; nunca toca el esquema. Las versiones que no se
 * pueden determinar por esquema se dejan como están, porque no hay forma de
 * saber si corrieron.
 *
 * Devuelve las versiones que se desmarcaron, que quedan pendientes.
 */
function migracionesReconciliar(PDO $db): array {
    migracionesInit($db);

    $aplicadas    = migracionesAplicadas($db);
    $desmarcadas  = [];

    foreach (migraciones() as $m) {
        $v = $m['version'];
        if (!in_array($v, $aplicadas, true)) continue;
        if (migracionAplicadaEnEsquema($db, $v) !== false) continue;

        $db->prepare('DELETE FROM migraciones WHERE version = ?')->execute([$v]);
        $desmarcadas[] = $v;
    }

    return $desmarcadas;
}

/** Versiones ya aplicadas. */
function migracionesAplicadas(PDO $db): array {
    return $db->query('SELECT version FROM migraciones')->fetchAll(PDO::FETCH_COLUMN);
}

/** Migraciones que faltan aplicar. */
function migracionesPendientes(PDO $db): array {
    $aplicadas = migracionesAplicadas($db);
    return array_values(array_filter(
        migraciones(),
        fn($m) => !in_array($m['version'], $aplicadas, true)
    ));
}

/**
 * Aplica las migraciones pendientes. Devuelve la lista de las que corrió.
 * Si una falla, lanza la excepción con el contexto de qué sentencia fue.
 */
function migracionesAplicar(PDO $db): array {
    $esNueva = migracionesInit($db);
    if ($esNueva) migracionesBaseline($db);

    $corridas = [];
    foreach (migracionesPendientes($db) as $m) {
        foreach ($m['sql'] as $i => $sentencia) {
            try {
                // Una sentencia puede ser SQL plano o un closure que recibe la
                // conexión, para los casos que necesitan mirar el esquema antes
                // de decidir qué ejecutar.
                if (is_callable($sentencia)) {
                    $sentencia($db);
                } else {
                    $db->exec($sentencia);
                }
            } catch (PDOException $e) {
                throw new RuntimeException(
                    "Falló la migración {$m['version']} ({$m['nombre']}), sentencia #" . ($i + 1) .
                    ': ' . $e->getMessage(), 0, $e
                );
            }
        }
        $st = $db->prepare('INSERT INTO migraciones (version, nombre) VALUES (?, ?)');
        $st->execute([$m['version'], $m['nombre']]);
        $corridas[] = $m;
    }

    return $corridas;
}
