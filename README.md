# Administración de Edificio — Guía de instalación

> ## ⚠️ Pensado para uso local
>
> Todos los endpoints de `api/` exigen sesión iniciada, pero el sistema está
> pensado para correr en `localhost` detrás de XAMPP. Si lo ponés en un hosting
> accesible desde internet, antes revisá como mínimo: servirlo por **HTTPS**
> (las contraseñas viajan en texto plano sobre HTTP), y que el `.htaccess` esté
> siendo respetado por el servidor.

> **Nota sobre las imágenes:** ni los favicons, ni los iconos de la PWA, ni la
> foto que se carga desde **Sistema → Configuración** están en el repositorio:
> son fotos del edificio real e identifican la propiedad. Si clonás el proyecto
> vas a ver errores 404 por los favicons; no afectan el funcionamiento.

## 1. Copiar el proyecto

Copiá la carpeta `edificio` completa a:
```
C:\xampp\htdocs\edificio\
```

## 2. Verificar la estructura

PHPMailer ya viene incluido en `vendor/`, no hay que descargar nada.
La estructura debe quedar así:
```
edificio/
├── index.html
├── .env                  ← credenciales (lo creás vos, no se sube)
├── .env.example          ← plantilla del .env
├── .htaccess             ← bloquea el acceso web al .env, a .git y a los .sql
├── instalar.php          ← crea la base y escribe el .env (borralo al terminar)
├── testmail.php          ← prueba de configuración SMTP
├── css/
│   └── style.css
├── api/
│   ├── unidades.php
│   ├── gastos_fijos.php
│   ├── gastos_esporadicos.php
│   ├── liquidacion.php
│   ├── liquidacion_extraordinaria.php
│   ├── enviar_mail.php
│   ├── plantilla_mail.php
│   ├── caja.php
│   ├── cuenta_corriente.php
│   ├── exportar_caja.php
│   ├── exportar_cuenta_corriente.php
│   ├── backup.php
│   ├── auth.php          ← login, logout y cambio de contraseña
│   ├── usuarios.php      ← alta y gestión de usuarios (solo admin)
│   └── edificio.php      ← datos y foto del edificio
├── uploads/              ← imágenes subidas (no se publican)
├── includes/
│   ├── config.php        ← lee el .env, no hay que editarlo
│   ├── auth.php          ← sesión y protección de endpoints
│   └── migraciones.php   ← esquema de la base, versionado
└── vendor/               ← PHPMailer 7.0.2 vendorizado (ver vendor/README.md)
    └── phpmailer/
        ├── PHPMailer.php
        ├── SMTP.php
        └── Exception.php
```

> El proyecto **no usa Composer**: `vendor/` viene incluido, así que no hay
> ningún paso de instalación de dependencias.

## 3. Instalar

1. Abrí XAMPP y arrancá Apache y MySQL
2. Entrá a **http://localhost/edificio/instalar.php**
3. Completá los datos de MySQL (host, nombre de la base, usuario y contraseña) y,
   si querés, los del correo
4. Apretá *Instalar*

El instalador crea la base si no existe, aplica las migraciones y escribe el
`.env` por vos. Si la base ya existe, la reutiliza y solo aplica lo que falte:
**no borra datos**.

Cuando termina, borrá `instalar.php` del servidor. Mientras exista un `.env` el
instalador se niega a correr, pero lo prolijo es que un script que crea bases y
escribe credenciales no quede accesible.

> No hay forma manual: el esquema ya no existe como volcado `.sql`. Lo construye
> `includes/migraciones.php`, que lo aplica por versiones y es lo único que sabe
> cómo tiene que quedar la base. El instalador es la única vía.

La base queda vacía. Cargá las unidades y gastos fijos desde la app.

## 4. Configurar credenciales

Las credenciales viven en un archivo `.env` en la raíz del proyecto, que **no**
se sube al repositorio. Copiá la plantilla y completala:

```bash
copy .env.example .env
```

Después abrí `.env` y poné tus valores reales:
```ini
DB_HOST=localhost
DB_USER=tu_usuario_mysql
DB_PASS="tu_password_mysql"
DB_NAME=edificio

MAIL_USER=tu_email@gmail.com
MAIL_PASS="xxxx xxxx xxxx xxxx"
MAIL_FROM=tu_email@gmail.com
MAIL_FROM_NAME="Administración del Edificio"
```

Si un valor tiene espacios o caracteres especiales, encerralo entre comillas
dobles. `includes/config.php` lee este archivo y no hay que editarlo.

## 5. Primer ingreso

Abrí `http://localhost/edificio/`. Como todavía no hay ningún usuario, la app te
muestra la pantalla de **alta inicial**: cargás tu nombre, un usuario y una
contraseña (mínimo 8 caracteres) y entrás directo.

Ese primer usuario queda como **administrador**, y la vía de alta se cierra apenas
existe, así que nadie puede crearse una cuenta desde afuera. Después podés cambiar
tu contraseña desde **Sistema → Mi Cuenta**.

### Configuración del edificio

En **Sistema → Configuración** (solo administradores) cargás el nombre, la
dirección, el CUIT del consorcio, los datos del administrador y una foto. El
nombre y la foto se muestran en la barra lateral y en el título de la pestaña.

Estos datos viven en la base, no en los archivos: por eso el código del sistema
sirve para cualquier edificio y no revela cuál administrás si compartís el
repositorio.

La foto se reduce a 1200 px y se vuelve a codificar a JPEG al subirla. Eso no es
solo por peso: **descarta los metadatos EXIF**, que en una foto sacada con celular
incluyen las coordenadas GPS del lugar donde se tomó. Solo se aceptan JPG, PNG y
WEBP, validando el tipo real del archivo y no su extensión, y el directorio
`uploads/` tiene el motor PHP apagado por `.htaccess`.

### Roles

Desde **Sistema → Usuarios** (visible solo para administradores) podés dar de alta
a más gente. Hay dos roles:

| | Administrador | Consulta |
|---|---|---|
| Ver unidades, caja, liquidaciones, cuenta corriente | Sí | Sí |
| Exportar los CSV de caja y cuenta corriente | Sí | Sí |
| Cargar, modificar y borrar cualquier cosa | Sí | **No** |
| Emitir liquidaciones y enviar mails | Sí | **No** |
| Exportar o restaurar la base completa | Sí | **No** |
| Gestionar usuarios | Sí | **No** |

El control lo hace el servidor, no la pantalla: un usuario de consulta recibe un
error 403 aunque llame a la API por fuera de la interfaz.

Los usuarios no se borran, se **dan de baja**: los movimientos de caja y las
liquidaciones guardan quién los cargó, y borrar la fila dejaría esos registros
sin autor. Tampoco podés quitarte el rol de administrador ni darte de baja a vos
mismo, para que la instalación no quede sin nadie que pueda entrar a gestionarla.

Si te olvidás la contraseña, no hay recuperación por mail: se resetea borrando la
fila de la tabla `usuarios` desde phpMyAdmin, lo que vuelve a habilitar la
pantalla de alta inicial.

**Protecciones incluidas:** las contraseñas se guardan con `password_hash()`
(bcrypt, nunca en texto plano), la cookie de sesión es `HttpOnly` + `SameSite=Strict`,
la sesión se cierra sola tras 8 horas de inactividad, y la cuenta se bloquea 15
minutos después de 5 intentos fallidos seguidos.

## 6. Contraseña de aplicación de Gmail

Para que Gmail permita el envío desde PHP:

1. Entrá a https://myaccount.google.com
2. Seguridad → Verificación en dos pasos (activala si no está)
3. Seguridad → Contraseñas de aplicaciones
4. Seleccioná "Otra (nombre personalizado)" → escribí "Edificio"
5. Google te da una clave de 16 caracteres → copiala en `MAIL_PASS` del `.env`

## 7. Usar el sistema

Abrí el navegador y entrá a:
```
http://localhost/edificio
```

## 8. Backup y restauración

Desde la sección **Sistema → Backup** de la app podés:

- **Exportar** toda la base de datos a un archivo `.sql` con un clic.
- **Restaurar** subiendo un archivo `.sql` previamente exportado. Esta operación reemplaza todos los datos actuales.

¡Listo!
