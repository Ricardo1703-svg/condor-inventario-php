# Inventario · App 1 (stack clásico) — Cóndor Cloud

CRUD de productos en **PHP 8.3 nativo** (PDO, sin framework) con **MySQL 8.4** administrado.
Se despliega en la PaaS **Cóndor Cloud**: cada `git push` a `main` se construye y publica solo.

## Variables de entorno
El código **no contiene credenciales ni cadenas de conexión**. Las genera la plataforma y las
inyecta desde un Secret de Kubernetes; se consultan en el panel (*Mis aplicaciones → Credenciales*).

| Variable | Origen |
|---|---|
| `DB_HOST`, `DB_PORT` | Servicio de la base de datos administrada |
| `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Secret generado por Cóndor Cloud |
| `APP_ENV`, `PLATAFORMA` y las demás | Panel → *Editar* → Variables de entorno |

Si falta alguna, la app responde 503 y lo registra en el log, en lugar de usar valores por defecto.

## Estructura
- `src/index.php`: listado, búsqueda, alta, edición y baja (PRG + CSRF por cookie, sin sesión)
- `src/db.php`: conexión PDO y migración idempotente de la tabla `productos`
- `src/health.php`: sonda de readiness (responde 200 solo si la BD contesta)
- `Dockerfile`: `php:8.3-apache` + `pdo_mysql`
