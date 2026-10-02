# Administración de Edificio — Guía de instalación

> ## ⚠️ Pensado para uso local
>
> Todos los endpoints de `api/` exigen sesión iniciada, pero el sistema está
> pensado para correr en `localhost` detrás de XAMPP. Si lo ponés en un hosting
> accesible desde internet, antes revisá como mínimo: servirlo por **HTTPS**
> (las contraseñas viajan en texto plano sobre HTTP), y que el `.htaccess` esté
> siendo respetado por el servidor.

> **Nota sobre los iconos:** los favicons y los iconos de la PWA no están en el
> repositorio porque son fotos del edificio real. Si clonás el proyecto vas a ver
> errores 404 por esos archivos; no afectan el funcionamiento. Poné los tuyos con
> los nombres que lista `.gitignore`.

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
├── .htaccess             ← bloquea el acceso web al .env y a los .sql
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
│   └── auth.php          ← login, logout y cambio de contraseña
├── includes/
│   ├── config.php        ← lee el .env, no hay que editarlo
│   ├── auth.php          ← sesión y protección de endpoints
│   └── migraciones.php   ← esquema de la base, versionado
├── Deploy base de datos/
│   ├── database.sql
│   └── migracion_liquidacion_extraordinaria.sql
└── vendor/               ← PHPMailer 7.0.2 vendorizado (ver vendor/README.md)
    └── phpmailer/
        ├── PHPMailer.php
        ├── SMTP.php
        └── Exception.php
```

> El proyecto **no usa Composer**: `vendor/` viene incluido, así que no hay
> ningún paso de instalación de dependencias.

## 3. Crear la base de datos

1. Abrí XAMPP y arrancá Apache y MySQL
2. Entrá a http://localhost/phpmyadmin
3. Hacé clic en "SQL" (barra superior)
4. Copiá y pegá el contenido de `Deploy base de datos/database.sql`
5. Ejecutá

La base se crea vacía. Cargá las unidades y gastos fijos desde la app.

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

Esa vía de alta queda cerrada apenas existe el primer usuario, así que nadie más
puede crearse una cuenta desde afuera. Después podés cambiar tu contraseña desde
**Sistema → Mi Cuenta**.

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
