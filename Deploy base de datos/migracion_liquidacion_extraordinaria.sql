-- ═══════════════════════════════════════════════════════════════
--  Migración: Liquidaciones extraordinarias
--  Ejecutar una sola vez sobre una base "edificio" ya existente.
--  No borra datos. Recomendado: hacer un backup antes (Backup → Exportar).
-- ═══════════════════════════════════════════════════════════════

-- 1) liquidaciones: distinguir ordinaria/extraordinaria y permitir
--    varias filas por período (una ordinaria + N extraordinarias).
ALTER TABLE liquidaciones
    ADD COLUMN tipo ENUM('ordinaria','extraordinaria') NOT NULL DEFAULT 'ordinaria' AFTER periodo,
    ADD COLUMN titulo VARCHAR(150) NULL AFTER tipo;

ALTER TABLE liquidaciones DROP INDEX periodo;
ALTER TABLE liquidaciones ADD INDEX idx_periodo_tipo (periodo, tipo);

-- 2) liquidacion_lineas: nuevo tipo de línea para conceptos extraordinarios
ALTER TABLE liquidacion_lineas
    MODIFY COLUMN tipo ENUM('fijo','esporadico','extraordinario') DEFAULT 'fijo';

-- 3) cuenta_corriente: columna separada para cargos extraordinarios,
--    para que nunca se pisen con el recálculo de la liquidación ordinaria.
ALTER TABLE cuenta_corriente
    ADD COLUMN extraordinario DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER saldo_anterior;

ALTER TABLE cuenta_corriente
    MODIFY COLUMN saldo_final DECIMAL(12,2)
        GENERATED ALWAYS AS (saldo_anterior + deuda + extraordinario - pagado) STORED;
