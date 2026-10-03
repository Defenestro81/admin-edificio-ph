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
        [
            'version' => '002',
            'nombre'  => 'Liquidaciones extraordinarias',
            'sql'     => [
                "ALTER TABLE liquidaciones
                    ADD COLUMN tipo ENUM('ordinaria','extraordinaria') NOT NULL DEFAULT 'ordinaria' AFTER periodo,
                    ADD COLUMN titulo VARCHAR(150) NULL AFTER tipo",

                "ALTER TABLE liquidaciones DROP INDEX periodo",
                "ALTER TABLE liquidaciones ADD INDEX idx_periodo_tipo (periodo, tipo)",

                "ALTER TABLE liquidacion_lineas
                    MODIFY COLUMN tipo ENUM('fijo','esporadico','extraordinario') DEFAULT 'fijo'",

                "ALTER TABLE cuenta_corriente
                    ADD COLUMN extraordinario DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER saldo_anterior",

                "ALTER TABLE cuenta_corriente
                    ADD COLUMN saldo_final DECIMAL(12,2)
                    GENERATED ALWAYS AS (saldo_anterior + deuda + extraordinario - pagado) STORED",
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
            'sql'     => [
                // 'admin' hace todo; 'consulta' solo lee.
                "ALTER TABLE usuarios
                    ADD COLUMN rol ENUM('admin','consulta') NOT NULL DEFAULT 'admin' AFTER nombre,
                    ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER rol",

                // Quién cargó cada movimiento de caja. ON DELETE SET NULL para que
                // dar de baja un usuario nunca borre un asiento contable.
                "ALTER TABLE caja
                    ADD COLUMN usuario_id INT NULL DEFAULT NULL,
                    ADD CONSTRAINT fk_caja_usuario
                        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL",

                "ALTER TABLE liquidaciones
                    ADD COLUMN usuario_id INT NULL DEFAULT NULL,
                    ADD CONSTRAINT fk_liquidaciones_usuario
                        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL",
            ],
        ],

    ];
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
 * Para bases que ya existían antes de que hubiera migraciones: detecta qué
 * partes del esquema ya están y las marca como aplicadas, para no reintentar
 * ALTERs que fallarían. Solo corre la primera vez.
 */
function migracionesBaseline(PDO $db): array {
    $marcadas = [];

    // ¿La base ya tenía el esquema inicial?
    if (!$db->query("SHOW TABLES LIKE 'unidades'")->fetchColumn()) {
        return $marcadas;   // base vacía: que corran todas desde cero
    }
    $marcadas[] = '001';

    // ¿Ya tenía aplicada la migración de extraordinarias?
    $tieneTipo = $db->query("SHOW COLUMNS FROM liquidaciones LIKE 'tipo'")->fetchColumn();
    if ($tieneTipo) $marcadas[] = '002';

    $todas = array_column(migraciones(), null, 'version');
    foreach ($marcadas as $v) {
        $st = $db->prepare('INSERT IGNORE INTO migraciones (version, nombre) VALUES (?, ?)');
        $st->execute([$v, $todas[$v]['nombre'] ?? 'baseline']);
    }

    return $marcadas;
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
                $db->exec($sentencia);
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
