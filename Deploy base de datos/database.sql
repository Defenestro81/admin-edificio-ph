-- ═══════════════════════════════════════════════
--  Administración de Edificio — Base de datos
--  Ejecutar en phpMyAdmin o MySQL
-- ═══════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS edificio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE edificio;

-- ─── Unidades del edificio ───
CREATE TABLE IF NOT EXISTS unidades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    propietario VARCHAR(150),
    email VARCHAR(150),
    coeficiente DECIMAL(8,6) NOT NULL,
    ascensor TINYINT(1) DEFAULT 1,
    orden INT DEFAULT 0,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ─── Gastos fijos (se repiten cada período) ───
CREATE TABLE IF NOT EXISTS gastos_fijos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    concepto VARCHAR(150) NOT NULL,
    importe DECIMAL(12,2) NOT NULL,
    division ENUM('coeficiente','partes_iguales') DEFAULT 'coeficiente',
    activo TINYINT(1) DEFAULT 1,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ─── Unidades a las que aplica cada gasto fijo ───
CREATE TABLE IF NOT EXISTS gastos_fijos_unidades (
    gasto_fijo_id INT NOT NULL,
    unidad_id INT NOT NULL,
    PRIMARY KEY (gasto_fijo_id, unidad_id),
    FOREIGN KEY (gasto_fijo_id) REFERENCES gastos_fijos(id) ON DELETE CASCADE,
    FOREIGN KEY (unidad_id) REFERENCES unidades(id) ON DELETE CASCADE
);

-- ─── Gastos esporádicos por período ───
CREATE TABLE IF NOT EXISTS gastos_esporadicos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    periodo CHAR(6) NOT NULL,
    concepto VARCHAR(150) NOT NULL,
    importe DECIMAL(12,2) NOT NULL,
    division ENUM('coeficiente','partes_iguales') DEFAULT 'coeficiente',
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ─── Unidades a las que aplica cada gasto esporádico ───
CREATE TABLE IF NOT EXISTS gastos_esporadicos_unidades (
    gasto_esporadico_id INT NOT NULL,
    unidad_id INT NOT NULL,
    PRIMARY KEY (gasto_esporadico_id, unidad_id),
    FOREIGN KEY (gasto_esporadico_id) REFERENCES gastos_esporadicos(id) ON DELETE CASCADE,
    FOREIGN KEY (unidad_id) REFERENCES unidades(id) ON DELETE CASCADE
);

-- ─── Liquidaciones (una por período) ───
CREATE TABLE IF NOT EXISTS liquidaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    periodo CHAR(6) NOT NULL,   -- YYYYMM
    tipo ENUM('ordinaria','extraordinaria') NOT NULL DEFAULT 'ordinaria',
    titulo VARCHAR(150) DEFAULT NULL,  -- motivo de la extraordinaria
    calculada_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    total_general DECIMAL(14,2),
    KEY idx_periodo_tipo (periodo, tipo)
    -- Solo puede existir una liquidación 'ordinaria' por período; eso lo
    -- garantiza el código (api/liquidacion.php borra la anterior antes de
    -- insertar), no una restricción UNIQUE, porque sí puede haber varias
    -- 'extraordinaria' en el mismo período.
);

-- ─── Detalle de liquidación por unidad ───
CREATE TABLE IF NOT EXISTS liquidacion_detalle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    liquidacion_id INT NOT NULL,
    unidad_id INT NOT NULL,
    total DECIMAL(12,2) NOT NULL,
    mail_enviado TINYINT(1) DEFAULT 0,
    mail_enviado_en DATETIME,
    FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (unidad_id) REFERENCES unidades(id) ON DELETE CASCADE
);

-- ─── Líneas de cada detalle ───
CREATE TABLE IF NOT EXISTS liquidacion_lineas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    detalle_id INT NOT NULL,
    concepto VARCHAR(150) NOT NULL,
    importe DECIMAL(12,2) NOT NULL,
    tipo ENUM('fijo','esporadico','extraordinario') DEFAULT 'fijo',
    FOREIGN KEY (detalle_id) REFERENCES liquidacion_detalle(id) ON DELETE CASCADE
);

-- ─── Movimientos de caja del edificio ───
CREATE TABLE IF NOT EXISTS caja (
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
);

-- ─── Cuenta corriente por unidad ───
CREATE TABLE IF NOT EXISTS cuenta_corriente (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unidad_id INT NOT NULL,
    periodo CHAR(6) NOT NULL,
    deuda DECIMAL(12,2) NOT NULL,
    saldo_anterior DECIMAL(12,2) NOT NULL DEFAULT 0,
    extraordinario DECIMAL(12,2) NOT NULL DEFAULT 0,  -- cargos de liquidaciones extraordinarias
    pagado DECIMAL(12,2) NOT NULL DEFAULT 0,
    saldo_final DECIMAL(12,2) GENERATED ALWAYS AS (saldo_anterior + deuda + extraordinario - pagado) STORED,
    cerrado TINYINT(1) DEFAULT 0,
    UNIQUE KEY uk_unidad_periodo (unidad_id, periodo),
    FOREIGN KEY (unidad_id) REFERENCES unidades(id) ON DELETE CASCADE
);

-- ─── Plantilla de mail (texto fijo editable de los correos) ───
CREATE TABLE IF NOT EXISTS plantilla_mail (
    id INT PRIMARY KEY,
    asunto VARCHAR(200) NOT NULL,
    saludo VARCHAR(200) NOT NULL,
    intro TEXT NOT NULL,
    footer TEXT NOT NULL,
    firma TEXT NOT NULL,
    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO plantilla_mail (id, asunto, saludo, intro, footer, firma) VALUES (
    1,
    'Expensas {periodo} - {unidad}',
    'Estimado/a {nombre},',
    'Le enviamos la liquidación de expensas correspondiente al período {periodo}.',
    'Por favor realice el pago antes del vencimiento del mes en curso.',
    'Saludos cordiales,\nAdministración del Edificio'
);

