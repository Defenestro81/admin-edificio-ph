# Manual de implementación y uso
## Administración de Edificio

---

## Índice

1. [Requisitos](#1-requisitos)
2. [Instalación paso a paso](#2-instalación-paso-a-paso)
3. [Configuración inicial del sistema](#3-configuración-inicial-del-sistema)
4. [Operación mensual](#4-operación-mensual)
5. [Módulos del sistema](#5-módulos-del-sistema)
   - 5.1 [Dashboard](#51-dashboard)
   - 5.2 [Unidades](#52-unidades)
   - 5.3 [Gastos Fijos](#53-gastos-fijos)
   - 5.4 [Gastos del Período](#54-gastos-del-período)
   - 5.5 [Liquidación](#55-liquidación)
   - 5.6 [Enviar Mails](#56-enviar-mails)
   - 5.7 [Caja](#57-caja)
   - 5.8 [Cuenta Corriente](#58-cuenta-corriente)
   - 5.9 [Backup](#59-backup)
6. [Preguntas frecuentes](#6-preguntas-frecuentes)

---

## 1. Requisitos

| Componente | Versión mínima |
|---|---|
| XAMPP (Windows) | 8.x recomendado |
| PHP | 8.0 o superior (usa `match` y `str_contains`) |
| MySQL | 5.7 o superior |
| Navegador | Chrome, Firefox, Edge (versión actual) |

El sistema corre **localmente** en la misma PC donde está instalado XAMPP. No requiere conexión a internet para funcionar, salvo para el envío de mails.

---

## 2. Instalación paso a paso

### 2.1 Copiar los archivos

Copiá la carpeta `edificio` dentro de:
```
C:\xampp\htdocs\
```
Resultado esperado: `C:\xampp\htdocs\edificio\`

> PHPMailer ya viene incluido en `vendor/`: no hay que descargar ni instalar
> nada. El proyecto no usa Composer.

### 2.2 Crear el usuario de MySQL

Por seguridad, el sistema usa un usuario propio en lugar de `root`.

1. Abrí el Panel de Control de XAMPP e iniciá **Apache** y **MySQL**
2. Entrá a `http://localhost/phpmyadmin`
3. En la barra superior hacé clic en **SQL** y ejecutá, cambiando la contraseña:

```sql
CREATE USER 'edificio'@'localhost' IDENTIFIED BY 'tu_contraseña_aqui';
GRANT ALL PRIVILEGES ON edificio.* TO 'edificio'@'localhost';
FLUSH PRIVILEGES;
```

> El `GRANT` nombra la base `edificio` antes de que exista, y está bien así:
> MySQL acepta dar permisos sobre una base que todavía no se creó. Si vas a usar
> otro nombre de base, cambialo también acá.

### 2.3 Ejecutar el instalador

1. Abrí `http://localhost/edificio/instalar.php`
2. Completá los datos de MySQL: host, nombre de la base, y el usuario y la
   contraseña del paso anterior
3. Si ya tenés la cuenta de Gmail con su contraseña de aplicación (sección 2.6),
   completá también esos campos; si no, dejalos vacíos y cargalos después
4. Apretá **Instalar**

El instalador crea la base si no existe, arma todas las tablas y escribe el
`.env` con las credenciales. Si la base ya existe la reutiliza y aplica solo lo
que falte: **no borra datos**.

> **Cuando termina, borrá `instalar.php`.** Mientras exista un `.env` el
> instalador se niega a correr, pero un script que crea bases y escribe
> credenciales no debería quedar accesible.

No hay instalación manual. El esquema no existe como archivo `.sql`: lo
construye `includes/migraciones.php` aplicando versiones en orden, y es lo único
que sabe cómo tiene que quedar la base. Un volcado suelto se desactualiza y deja
la base a medias, que es justamente lo que el motor de migraciones evita.

### 2.4 Crear el primer usuario

Entrá a `http://localhost/edificio`. La primera vez el sistema no tiene usuarios
y muestra una pantalla para crear el primero, que queda como **administrador**.

Esa pantalla se cierra sola en cuanto existe un usuario, así que no se puede
usar dos veces para entrar sin permiso.

Después, desde **Sistema → Usuarios**, un administrador puede dar de alta más
gente con dos roles:

- **admin** — hace todo, incluidas la configuración, los usuarios y el backup
- **consulta** — solo mira; no puede modificar nada ni entrar a configuración

### 2.5 Revisar el `.env`

El instalador ya lo escribió. Vive en la raíz de `edificio/` y se ve así:

```ini
# Base de datos
DB_HOST=localhost
DB_USER=edificio                      # usuario que creaste en 2.2
DB_PASS="tu_contraseña_aqui"          # contraseña de ese usuario
DB_NAME=edificio

# Cuenta de Gmail para envío de mails
MAIL_USER=tu_cuenta@gmail.com
MAIL_PASS="xxxx xxxx xxxx xxxx"       # contraseña de aplicación (ver 2.6)
MAIL_FROM=tu_cuenta@gmail.com
MAIL_FROM_NAME="Administración Edificio"
```

Si una contraseña tiene espacios, comillas o barras invertidas, dejala entre
comillas dobles como en el ejemplo.

> **Importante:** el `.env` guarda contraseñas. Por eso queda fuera del
> repositorio (está en `.gitignore`) y el `.htaccess` bloquea su lectura por web.
> Si reinstalás en otra máquina hay que volver a correr el instalador, o copiar
> el `.env` a mano.

### 2.6 Configurar contraseña de aplicación de Gmail

Gmail requiere una contraseña especial para que aplicaciones externas puedan enviar correos.

1. Entrá a tu cuenta de Google: https://myaccount.google.com
2. Ir a **Seguridad** → activar **Verificación en dos pasos** si no está activa
3. Volver a **Seguridad** → buscar **Contraseñas de aplicaciones**
4. En "Seleccionar aplicación" elegí **Otra** y escribí `Edificio`
5. Google genera una clave de 16 caracteres (formato `xxxx xxxx xxxx xxxx`)
6. Copiá esa clave en el campo `MAIL_PASS` del `.env`

> **Nota:** Esta clave solo se muestra una vez. Guardala en un lugar seguro.

### 2.7 Verificar la instalación

Abrí el navegador y entrá a:
```
http://localhost/edificio
```

Si todo está bien vas a ver la pantalla de ingreso, o la de crear el primer
usuario si todavía no lo hiciste. Una vez adentro, el Dashboard muestra las
estadísticas en cero hasta que cargues unidades y gastos.

Para probar el correo, con sesión de administrador abrí
`http://localhost/edificio/testmail.php`: manda un mail de prueba a la misma
cuenta configurada e informa qué falló si no sale. A quien no sea administrador
le responde 401.

---

## 3. Configuración inicial del sistema

Antes de usar el sistema por primera vez, hay que cargar la estructura del edificio.

### 3.1 Cargar las unidades

1. Ir a **Unidades** en el menú lateral
2. Hacé clic en **+ Nueva unidad** para cada departamento/unidad del edificio
3. Completar:
   - **Nombre**: identificación de la unidad (ej: `Piso 1°`, `PB`, `Cochera`)
   - **Propietario / Inquilino**: nombre completo
   - **Email**: dirección para recibir las liquidaciones
   - **Coeficiente**: porcentaje fiscal de la unidad (ej: `0.1500` para 15%)
   - **Usa ascensor**: Sí/No (afecta el reparto del gasto de ascensor)

> **Importante:** La suma de todos los coeficientes debe ser exactamente **1.0000**.
> El sistema lo indica con un badge verde (✓) o rojo (⚠) en la parte superior de la tabla.
> No se puede calcular la liquidación hasta que la suma sea exactamente 1.0000.

### 3.2 Cargar los gastos fijos

Los gastos fijos son los que se cobran todos los meses (seguros, luz, limpieza, administración, etc.).

1. Ir a **Gastos Fijos** en el menú lateral
2. Hacé clic en **+ Nuevo gasto fijo** para cada gasto recurrente
3. Completar:
   - **Concepto**: nombre descriptivo (ej: `EDEA`, `Seguro Edificio`, `Limpieza`)
   - **Importe mensual**: monto en pesos
   - **División**: cómo se distribuye entre las unidades:
     - **Por coeficiente**: cada unidad paga proporcionalmente a su coeficiente
     - **Partes iguales**: todas las unidades pagan lo mismo
   - **Aplica a**: qué unidades incluyen este gasto (por ejemplo, el ascensor solo aplica a las unidades que lo usan)

Con las unidades y los gastos fijos cargados, el sistema ya está listo para operar.

---

## 4. Operación mensual

Este es el flujo típico que se repite cada mes:

```
┌─────────────────────────────────────────────────────────────┐
│  1. (Opcional) Cargar gastos esporádicos del mes            │
│  2. Calcular la liquidación                                 │
│  3. Enviar liquidaciones por mail a cada propietario        │
│  4. Registrar los pagos a medida que ingresan               │
│  5. Controlar saldos en Cuenta Corriente                    │
└─────────────────────────────────────────────────────────────┘
```

### Paso 1 — Gastos esporádicos (si los hay)

Si ese mes hubo gastos extraordinarios (reparación, plomero, pintura, etc.):

1. Ir a **Gastos del Período**
2. Seleccionar el año y mes correspondiente → **Cargar**
3. Clic en **+ Agregar gasto**
4. Completar concepto, importe, división y unidades afectadas

> Si ya existe una liquidación para ese período y agregás un gasto, el sistema la borrará automáticamente para forzar el recálculo.

### Paso 2 — Calcular la liquidación

1. Ir a **Liquidación**
2. Seleccionar el año y mes
3. Clic en **⚙ Calcular liquidación**

El sistema genera automáticamente:
- El detalle de cuánto paga cada unidad, ítem por ítem
- El saldo arrastrado del mes anterior (si alguna unidad quedó con deuda)
- El registro en **Cuenta Corriente**

### Paso 3 — Enviar mails

1. Ir a **Enviar Mails**
2. Seleccionar el período → **Cargar**
3. Para cada unidad, clic en **📤 Enviar**
4. Revisar el asunto y el cuerpo del mail (son editables antes de enviar)
5. Confirmar el envío

Las unidades que ya recibieron el mail aparecen con el badge **Enviado** en verde.

### Paso 4 — Registrar pagos

Cada vez que un propietario paga:

1. Ir a **Caja**
2. Clic en **+ Nuevo movimiento**
3. Completar:
   - **Fecha**: fecha en que se recibió el pago
   - **Tipo**: Ingreso
   - **Concepto**: ej: `Pago expensas Mayo`
   - **Importe**: monto recibido
   - **Unidad**: seleccionar la unidad que pagó
   - **Período**: el mes al que corresponde el pago
4. Guardar

El sistema actualiza automáticamente el campo **Pagado** en la Cuenta Corriente de esa unidad para ese período.

### Paso 5 — Controlar saldos

1. Ir a **Cuenta Corriente**
2. Seleccionar el período → **Cargar**

Se muestra una tabla con el estado de cada unidad:

| Columna | Descripción |
|---|---|
| Saldo anterior | Deuda arrastrada del mes anterior |
| Deuda del mes | Lo que le corresponde pagar este período |
| Pagado | Lo que efectivamente pagó |
| Saldo final | Deuda pendiente (calculado automáticamente) |
| Estado | **Al día** (verde) o **Debe $X** (rojo) |

El saldo pendiente se arrastra automáticamente al siguiente período cuando se calcula la próxima liquidación.

---

## 5. Módulos del sistema

### 5.1 Dashboard

Pantalla de inicio. Muestra un resumen rápido del edificio:

- **Unidades registradas**: cantidad total de unidades
- **Gastos fijos activos**: cantidad de gastos fijos configurados
- **Suma de coeficientes**: debe mostrar exactamente `1.0000`
- Tabla con todas las unidades, sus propietarios y coeficientes visualizados como barra

---

### 5.2 Unidades

Gestión de los departamentos y locales del edificio.

**Acciones disponibles:**
- Agregar nueva unidad (botón **+ Nueva unidad**)
- Editar unidad existente (ícono ✏)
- Eliminar unidad (ícono ✕)

**Campos:**

| Campo | Descripción |
|---|---|
| Nombre | Identificador de la unidad |
| Propietario | Nombre del propietario o inquilino |
| Email | Dirección para recibir liquidaciones |
| Coeficiente | Porcentaje fiscal (6 decimales, ej: 0.150000) |
| Usa ascensor | Determina si se incluye en el gasto de ascensor |

> El badge **Σ = X.XXXX** en la esquina superior derecha muestra la suma actual de coeficientes. Debe ser verde para poder calcular liquidaciones.

---

### 5.3 Gastos Fijos

Gastos que se repiten todos los meses (seguros, servicios, personal, administración).

**Acciones disponibles:**
- Agregar nuevo gasto (botón **+ Nuevo gasto fijo**)
- Editar gasto existente (ícono ✏)
- Eliminar gasto (ícono ✕ — baja lógica, no se pierde historial)

**Tipos de división:**

- **Por coeficiente**: cada unidad paga en proporción a su participación. Si solo aplica a algunas unidades, el coeficiente se recalcula sobre ese subgrupo.
- **Partes iguales**: el importe total se divide en partes iguales entre las unidades afectadas.

---

### 5.4 Gastos del Período

Gastos que ocurren una sola vez en un mes específico (reparaciones, compras, honorarios extras, etc.).

1. Seleccionar **año** y **mes** → clic en **Cargar**
2. Se muestra la lista de gastos ya cargados para ese período
3. Agregar nuevos con **+ Agregar gasto**

> Si ya existe una liquidación calculada para ese período, aparece un aviso en amarillo. Agregar o eliminar gastos la borrará y habrá que recalcular.

---

### 5.5 Liquidación

Cálculo de las expensas de un período.

**Proceso:**
1. Seleccionar año y mes
2. Clic en **⚙ Calcular liquidación**

El sistema:
- Toma todos los gastos fijos activos
- Suma los gastos esporádicos del período
- Aplica el criterio de división (coeficiente o partes iguales)
- Suma el saldo arrastrado del mes anterior de cada unidad
- Genera el detalle línea por línea para cada unidad

**Resultado:** tarjetas individuales por unidad con el desglose completo y el total a pagar.

Para **borrar** una liquidación y recalcularla: botón **✕ Borrar** (solo si es necesario corregir algo).

---

### 5.6 Enviar Mails

Envío de las liquidaciones calculadas por correo electrónico.

1. Seleccionar el período → **Cargar**
2. Se muestra la tabla con todas las unidades, su email, total y estado del mail
3. Clic en **📤 Enviar** para abrir la vista previa del mail
4. El asunto y el cuerpo son **editables** antes de enviar
5. Clic en **📤 Enviar mail** para confirmar

**Estados:**
- **Pendiente** (amarillo): el mail todavía no fue enviado
- **Enviado** (verde): el mail fue enviado exitosamente

> Las unidades sin email registrado muestran el botón deshabilitado. Registrar el email desde **Unidades**.

---

### 5.7 Caja

Registro de todos los movimientos de dinero del edificio: pagos recibidos de propietarios, gastos pagados a proveedores, compras, etc.

**Filtros disponibles:**
- Por tipo (todos / solo ingresos / solo egresos)
- Por año
- Por mes

**Tarjetas de resumen** (se actualizan según los filtros):
- Saldo actual del fondo (calculado sobre todos los movimientos, sin filtro)
- Ingresos del período filtrado
- Egresos del período filtrado

**Registrar un movimiento:**
1. Clic en **+ Nuevo movimiento**
2. Completar fecha, tipo, concepto e importe
3. Si es un **ingreso de una unidad**, seleccionar la unidad y el período al que corresponde el pago — esto actualiza la Cuenta Corriente automáticamente
4. El campo **Notas** es opcional

**Exportar a Excel:** botón **📊 Exportar** en la esquina superior derecha.

> Los movimientos se muestran en páginas de 50. Usar los botones de paginación para navegar.

---

### 5.8 Cuenta Corriente

Seguimiento de la deuda de cada unidad a lo largo del tiempo.

**Vista por período:**
- Seleccionar año y mes → **Cargar**
- Muestra todas las unidades con su estado para ese mes

**Vista por unidad:**
- Seleccionar una unidad del desplegable
- Muestra el historial completo de todos los períodos
- Botón **📊 Exportar** para descargar el historial en Excel

**Columnas:**

| Columna | Descripción |
|---|---|
| Saldo anterior | Deuda arrastrada del período previo |
| Deuda del mes | Total liquidado para ese período |
| Pagado | Suma de ingresos de caja imputados a esa unidad y período |
| Saldo final | Calculado automáticamente: anterior + deuda − pagado |
| Estado | Al día / Debe $X |

> El saldo final es una columna **calculada por la base de datos** en tiempo real. Se actualiza sola cada vez que se registra un pago en Caja.

---

### 5.9 Backup

Exportación e importación completa de la base de datos.

#### Exportar (hacer un backup)

1. Ir a **Sistema → Backup**
2. Clic en **⬇ Descargar backup (.sql)**
3. Se descarga un archivo con nombre `edificio_backup_YYYYMMDD_HHMMSS.sql`

El archivo incluye la estructura y todos los datos de las tablas. Se puede abrir con cualquier editor de texto para verificar su contenido.

**Recomendación:** hacer un backup antes de cada liquidación mensual y guardar los archivos en una carpeta fuera del servidor (ej: Google Drive, pendrive).

#### Restaurar (importar un backup)

> ⚠ **Esta operación reemplaza toda la base de datos actual. Los datos existentes se perderán.**

1. Ir a **Sistema → Backup**
2. Arrastrá el archivo `.sql` a la zona de carga, o hacé clic en **Seleccionar archivo .sql**
3. Verificar que el nombre del archivo sea correcto
4. Clic en **⬆ Restaurar backup**
5. Confirmar la operación en el cuadro de diálogo

El sistema ejecuta todas las sentencias del archivo y muestra un mensaje de confirmación con la cantidad de operaciones realizadas.

---

## 6. Preguntas frecuentes

**¿Qué pasa si la suma de coeficientes no da 1.0000?**
El sistema no permite calcular la liquidación. El badge en la pantalla de Unidades aparece en rojo. Revisá que la suma de todos los coeficientes cargados sea exactamente 1.0000 (tolerancia de ±0.001).

---

**¿Puedo corregir un pago mal registrado en Caja?**
Sí. Eliminá el movimiento con el ícono ✕ y volvé a cargarlo con los datos correctos. El sistema recalcula automáticamente el campo Pagado en la Cuenta Corriente.

---

**¿Qué pasa si un propietario paga en cuotas?**
Registrá cada pago por separado en Caja, siempre vinculando la unidad y el período correspondiente. El sistema suma todos los ingresos imputados a esa unidad/período y actualiza el saldo.

---

**¿El saldo del mes anterior se arrastra solo?**
Sí. Al calcular la liquidación de un nuevo período, el sistema toma el `saldo_final` del último período registrado en Cuenta Corriente para cada unidad y lo suma al total del mes nuevo.

---

**¿Puedo recalcular una liquidación ya enviada?**
Sí. Ir a **Liquidación**, seleccionar el período y hacer clic en **✕ Borrar**. Luego recalcular. Ten en cuenta que el estado de "mail enviado" se pierde y habría que reenviar los mails.

---

**¿El sistema funciona sin internet?**
Sí, para todo excepto el envío de mails (que requiere conexión a Gmail SMTP) y la carga de las fuentes tipográficas de Google (solo afecta la apariencia visual).

---

**¿Cómo migro el sistema a otra PC?**
1. Hacer un backup desde **Sistema → Backup**
2. Instalar XAMPP en la nueva PC y seguir los pasos del [README](README.md)
3. Restaurar el backup desde **Sistema → Backup** en la nueva instalación

---

*Última actualización: octubre 2026*
