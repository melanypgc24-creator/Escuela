# Escuela de Jiu Jitsu

Sitio web y API administrativa de la escuela, organizados en carpetas de primer nivel.

## Estructura

```text
config/        Configuración de la base de datos y sesiones
controllers/   Controladores HTTP
core/          Router y respuestas JSON
middleware/    Autenticación y protección CSRF
models/        Consultas a la base de datos
routes/        Rutas de la API
views/         Vistas HTML del login y panel
css/           Estilos del sitio
imagenes/      Imágenes del sitio
includes/      Componentes compartidos de la página
conexion.php   Conexión PDO reutilizable
config/conexion.php Conexión PDO de la aplicación
includes/auth_check.php Protección de sesión y roles
register.php   Registro público como alumno
api.php        Punto de entrada de la API
login.php      Inicio de sesión web
dashboard_admin.php Panel protegido para administrar usuarios y roles
registrar_pago.php Registro de pagos para administradores
panel_maestro.php Panel básico de maestro
panel_alumno.php Panel básico de alumno
dashboard.php  Panel protegido
logout.php     Cierre de sesión
bootstrap.php  Carga de clases y configuración inicial
index.php      Página principal del sitio
```

## Instalación local con XAMPP

1. Inicia Apache y MySQL. La base seleccionada debe contener `escuela_usuarios` con `id_usuario`, `correo`, `password`, `rol`, `id_alumno` e `id_profesor`, además de las tablas relacionadas.
2. Usa PHP 8 o posterior y habilita `pdo_mysql`.
3. Configura las variables de entorno `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASSWORD`. Sin variables, la conexión local usa `127.0.0.1`, `3306`, `escuela`, `root` y contraseña vacía; cambia `DB_NAME` si tu base tiene otro nombre.
4. El registro está en `http://localhost/Escuela/register.php` y solicita solo correo, contraseña y confirmación de contraseña. Crea una cuenta con rol fijo `alumno` y `id_alumno`/`id_profesor` en `NULL`; los datos personales del alumno se podrán vincular después desde el panel. El login en `http://localhost/Escuela/login.php` valida contraseñas con `password_verify()` y redirige según el rol.
5. El panel `dashboard_admin.php` solo permite el acceso al rol `administrador`. Desde ahí se listan los usuarios y se asignan roles; no se puede quitar el rol al último administrador. El módulo `registrar_pago.php` también está restringido al administrador y solo permite pagos para alumnos activos.
   Para generar un hash ejecuta `php -r "echo password_hash('TU_CONTRASENA_SEGURA', PASSWORD_DEFAULT), PHP_EOL;"` y usa el resultado en esta inserción, sustituyendo el correo y el hash:

   ```sql
   INSERT INTO escuela_usuarios (correo, `password`, rol, id_alumno, id_profesor)
   VALUES ('admin@tu-escuela.test', 'PEGA_AQUI_EL_HASH', 'administrador', NULL, NULL);
   ```
   Verifica también que `correo` tenga un índice `UNIQUE` y que `password` pueda guardar el hash completo (se recomienda `VARCHAR(255)`).
6. La página principal está en `http://localhost/Escuela/`. La API está disponible en `http://localhost/Escuela/api.php?route=/login`; sus endpoints requieren una base de datos compatible.

## API

Todas las respuestas son JSON. Para iniciar sesión:

```http
POST /Escuela/api.php?route=/login
Content-Type: application/json

{"email":"admin@escuela.test","password":"..."}
```

El inicio devuelve una cookie de sesión y `csrf_token`. Envíalo como `X-CSRF-Token` en las solicitudes autenticadas que modifican datos.

| Método | Ruta (`route`) | Acción |
|---|---|---|
| POST | `/login` | Iniciar sesión |
| POST | `/logout` | Cerrar sesión |
| GET, POST | `/students` | Listar y crear alumnos |
| GET, PUT, DELETE | `/students/{id}` | Consultar, actualizar y eliminar alumno |
| GET, POST | `/memberships` | Listar y crear membresías |
| GET, POST | `/payments` | Listar y registrar pagos |
| GET | `/debts` | Consultar adeudos pendientes |
| GET, POST | `/attendance` | Listar y registrar asistencias |
| GET | `/dashboard` | Resumen administrativo |

El CRUD de alumnos usa `first_name`, `last_name`, `email`, `phone` (opcional), `birth_date` (opcional, `YYYY-MM-DD`) y `status` (`active` o `inactive`). Las rutas de gestión requieren sesión de administrador; `/logout` acepta cualquier sesión autenticada y `/login` es público. Las operaciones que modifican datos también validan CSRF.

## Buenas prácticas

- Consultas PDO parametrizadas y conexión `utf8mb4`.
- Contraseñas con `password_hash()`/`password_verify()` y sesiones con cookie HttpOnly/SameSite.
- Validación de datos y control de acceso en el servidor.
- Credenciales configuradas mediante variables de entorno.
- En producción, usa HTTPS, desactiva `display_errors`, limita los permisos de MySQL y realiza respaldos periódicos.
