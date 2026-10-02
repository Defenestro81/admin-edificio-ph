# Dependencias de terceros

Este proyecto **no usa Composer**. La única dependencia externa es PHPMailer y
está copiada a mano acá, por eso `vendor/` sí se sube al repositorio: al clonar
el proyecto la app funciona sin ningún paso de instalación extra.

## PHPMailer

| | |
|---|---|
| Versión | **7.0.2** |
| Origen | https://github.com/PHPMailer/PHPMailer |
| Licencia | LGPL-2.1 |

Archivos copiados desde `src/` del release:

- `phpmailer/PHPMailer.php`
- `phpmailer/SMTP.php`
- `phpmailer/Exception.php`

Los demás archivos de `src/` (`OAuth.php`, `OAuthTokenProvider.php`, `POP3.php`,
`DSNConfigurator.php`) no se incluyen porque el proyecto autentica con usuario y
contraseña de aplicación por SMTP, no con OAuth ni POP3.

### Cómo actualizar

1. Bajar el release nuevo de https://github.com/PHPMailer/PHPMailer/releases
2. Reemplazar los 3 archivos de arriba con los de `src/`
3. Actualizar el número de versión en esta tabla
4. Probar el envío con `testmail.php` antes de commitear

Quien consume la librería es [`api/enviar_mail.php`](../api/enviar_mail.php) (envío
de expensas) y [`testmail.php`](../testmail.php) (prueba de configuración SMTP).
