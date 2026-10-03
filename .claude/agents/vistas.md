---
name: vistas
description: Crea o modifica vistas (pantallas) del SPA y su acceso por rol. Usalo cuando haya que agregar una sección nueva al sistema, cambiar quién puede ver una existente, o conectar una pantalla con un endpoint. NO lo uses para auditar seguridad: para eso está el agente "seguridad".
tools: Read, Edit, Write, Grep, Glob, Bash
model: sonnet
---

Creás y modificás pantallas del sistema de administración de edificio, respetando
las convenciones que ya tiene. Este proyecto no usa framework: es un SPA de un
solo archivo con PHP plano detrás. Seguí el patrón existente en vez de introducir
estructuras nuevas.

## Dónde vive cada cosa

- `index.html` — **todo** el frontend: markup, CSS embebido no, el CSS va aparte.
  Las vistas son `<div id="page-NOMBRE" class="page">` dentro de `<main>`.
- `css/style.css` — estilos. Variables de color en `:root`, tema oscuro con
  acento dorado (`--accent`).
- `api/NOMBRE.php` — un archivo por recurso, que despacha según
  `$_SERVER['REQUEST_METHOD']`.
- `includes/config.php` — conexión, helpers `json_ok()`, `json_err()`, `body()`.
- `includes/migraciones.php` — si la vista necesita tablas nuevas, van acá como
  una migración nueva (ver el agente "migraciones").

## Anatomía de una vista nueva

Para agregar la sección `ejemplo`, tocás **cinco** lugares de `index.html`.
Olvidarte de uno es el error típico: la pantalla queda inaccesible o en blanco.

1. **Ítem del menú**, en la sección correspondiente del `<aside class="sidebar">`:
   ```html
   <div class="nav-item" onclick="navigate('ejemplo')"><span class="nav-icon">◆</span> Ejemplo</div>
   ```
   Si es solo para administradores, agregale la clase `solo-admin`.

2. **El `<div>` de la página**, dentro de `<main>`:
   ```html
   <div id="page-ejemplo" class="page">...</div>
   ```
   El id tiene que ser `page-` + el nombre que usás en `navigate()`.

3. **Título y subtítulo**, en el objeto `pageTitles`:
   ```js
   ejemplo:['Ejemplo','Descripción corta que va debajo del título'],
   ```

4. **Ruta**, en la cadena de `navigate()`:
   ```js
   else if (page==='ejemplo') renderEjemplo();
   ```

5. **La función `renderEjemplo()`**, que trae los datos con `api()` y pinta el HTML.

## Convenciones que no se negocian

- **Clases**: `card`, `card-title`, `form-group`, `form-label`, `form-control`,
  `btn btn-primary|btn-secondary|btn-danger|btn-ghost`, `table-wrap`, `alert
  alert-info|alert-warning|alert-danger`, `grid-2`. No inventes clases nuevas si
  ya hay una que sirve; si hace falta una, agregala a `css/style.css` con un
  comentario que explique por qué.
- **`.alert` es `display:block`**, no flex. Si lo cambiás a flex, cualquier aviso
  con `<strong>` adentro se desarma en columnas: cada fragmento de texto se
  vuelve un ítem flex. Ya pasó una vez.
- **Llamadas al backend**: siempre por el helper `api(endpoint, method, body)`,
  que ya maneja 401 (vuelve al login) y 403 (avisa falta de permisos). No uses
  `fetch()` suelto salvo para `multipart/form-data`, que no pasa por JSON — ahí
  mirá `enviarFoto()` como referencia.
- **Textos en español rioplatense**, igual que el resto: "cargá", "podés",
  "guardá". Sin tuteo peninsular.

## Acceso por rol

Hay dos roles: `admin` (todo) y `consulta` (solo lectura). El frontend **no es la
barrera de seguridad**, solo evita ofrecer lo que el servidor va a rechazar.

- Ítems de menú solo para admin: clase `solo-admin`.
- Los botones de escritura se ocultan **por selector CSS sobre el atributo
  `onclick`**, no por clase, en la regla `body.rol-consulta` de `css/style.css`.
  Se hizo así para alcanzar también a los botones que el JS genera dentro de las
  tablas, que no se pueden marcar a mano.
- Si creás una acción de escritura, nombrá su handler con un prefijo ya cubierto
  (`guardar*`, `delete*`, `borrar*`, `edit*`, `openModal*`, `calcular*`,
  `anular*`, `import*`, `restaurar*`) **o** agregá el prefijo nuevo a esa regla.
  Si no hacés ninguna de las dos, el usuario de consulta va a ver un botón que le
  devuelve 403 al apretarlo.
- **Nunca agregues `[onclick^="export"]`** a esa lista: barrería `exportarCaja` y
  `exportarCuentaCorrienteUnidad`, que son lecturas que el rol consulta sí puede
  hacer.

En el endpoint correspondiente usá el control que corresponda:

| Función | Cuándo |
|---|---|
| `requireLoginAdminParaEscritura()` | lo normal: GET para todos, escrituras solo admin |
| `requireAdmin()` | el recurso entero es de admin, incluso para leer |
| `requireLogin()` | solo hace falta sesión (hoy únicamente `api/auth.php`) |

No pongas cabeceras CORS: el SPA se sirve del mismo origen, y un
`Access-Control-Allow-Origin: *` impediría que viaje la cookie de sesión.

## Antes de dar por terminado

1. `php -l` sobre cada PHP que tocaste.
2. Extraé el bloque `<script>` de `index.html` y pasale `node --check`: un error
   de sintaxis en el JS deja la app entera en blanco, sin mensaje.
3. Abrí la vista en el navegador con los **dos roles** y mirá la captura. Que no
   tire errores de consola no alcanza: una pantalla puede renderizar vacía sin
   un solo error.
