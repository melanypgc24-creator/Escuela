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
js/            Scripts del navegador (validación de formularios y menú lateral de los paneles)
imagenes/      Imágenes del sitio
includes/      Componentes compartidos de la página
config/conexion.php Conexión PDO de la aplicación (única)
includes/auth_check.php Protección de sesión y roles
includes/panel_shell.php Barra superior y menú lateral compartidos (administración y secretaría)
register.php   Registro público como alumno
api.php        Punto de entrada de la API
login.php      Inicio de sesión web
dashboard_admin.php Panel protegido para administrar usuarios y roles
registrar_pago.php Caja de pagos (efectivo/tarjeta simulada) para recepción y administración
panel_maestro.php Panel básico de maestro
panel_alumno.php Panel del alumno (inicio, clases, historial, notificaciones y perfil)
dashboard.php  Panel protegido
logout.php     Cierre de sesión
bootstrap.php  Carga de clases y configuración inicial
index.php      Página principal del sitio
```

## Base de datos: una sola ruta

- Instalación nueva: importa `database/schema.sql` y luego ejecuta `php migrate.php init` y `up` (ver más abajo).
- Base existente: respalda y ejecuta `php migrate.php up` (guía de actualización más abajo).
- La conexión PDO vive solo en `config/conexion.php`.

## Instalación local con XAMPP

1. Inicia Apache y MySQL. La base seleccionada debe contener `usuarios` con `id_usuario`, `correo`, `password`, `rol`, `id_alumno` e `id_profesor`, además de las tablas relacionadas.
2. Usa PHP 8 o posterior y habilita `pdo_mysql`.
3. Configura las variables de entorno `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASSWORD`. Sin variables, la conexión local usa `127.0.0.1`, `3306`, `escuela`, `root` y contraseña vacía; cambia `DB_NAME` si tu base tiene otro nombre.
4. El registro está en `http://localhost/Escuela/register.php` y solicita nombre, apellidos, teléfono, fecha de nacimiento, correo, contraseña y confirmación. Acepta cualquier correo con formato válido (no solo institucional). Al crear la cuenta pública, registra automáticamente la ficha del alumno y la vincula al usuario con rol `alumno`. Al registrar correctamente, la sesión se inicia automáticamente y redirige directo al panel de alumno. El login en `http://localhost/Escuela/login.php` valida contraseñas con `password_verify()` y redirige según el rol.
5. Las vistas de rol están separadas por permisos:
   - `dashboard_admin.php`: gestión de usuarios/roles y eliminación de cuentas.
   - `dashboard_director.php`: indicadores y reportes financieros por periodo.
   - `panel_recepcionista.php`: caja integrada para cobrar en **efectivo** (calcula el cambio) o con **tarjeta simulada** (valida formato, Luhn, vencimiento y CVV; no hace ningún cobro real ni guarda el número completo ni el CVV; `4242 4242 4242 4242` se aprueba y una tarjeta que termine en `0002` se rechaza). También muestra alumnos con adeudo y los pagos del día con su folio (`TC-AAAAMMDD-######`).
   - Cargos: si un alumno no tiene categoría capturada, `MonthlyChargeModel` la deduce de su fecha de nacimiento (menor de 18 = infantil, si no adulta; constante `ADULT_AGE`). Los cargos pendientes con fecha de vencimiento pasada se marcan `vencido` automáticamente al abrir la caja y al generar cargos. Para que existan fechas de vencimiento, configura `dia_vencimiento` en la configuración de mensualidades.
   - `panel_maestro.php`: clases asignadas, alumnos y asistencia reciente.
   - `panel_alumno.php`: perfil, historial de pagos, adeudos, asistencia y notificaciones.
6. El panel `dashboard_admin.php` solo permite el acceso al rol `administrador`. Desde ahí se listan las cuentas por rol (alumnos, instructores, secretaría, directores y administradores), se crean cuentas de personal, se editan sus datos, se cambia el rol, se activa o desactiva una cuenta, se **restablece la contraseña** de un usuario que la olvidó y se pueden eliminar cuentas. No se puede eliminar ni quitar el rol a tu propia cuenta ni al último administrador activo. Eliminar una cuenta borra solo el acceso: la ficha de alumno o instructor y su historial se conservan. Al eliminar una cuenta **los ID de `usuarios` no se modifican**: los identificadores son estables y pueden quedar huecos en la numeración; ninguna pantalla renumera ni reinicia `AUTO_INCREMENT`. Las sesiones abiertas se validan también por correo. El módulo `registrar_pago.php` también está restringido al administrador y solo permite pagos para alumnos activos.
   Para generar un hash ejecuta `php -r "echo password_hash('TU_CONTRASENA_SEGURA', PASSWORD_DEFAULT), PHP_EOL;"` y usa el resultado en esta inserción, sustituyendo el correo y el hash:

   ```sql
   INSERT INTO usuarios (id_usuario, correo, `password`, rol, id_alumno, id_profesor)
   VALUES (1, 'admin@tu-escuela.test', 'PEGA_AQUI_EL_HASH', 'administrador', NULL, NULL);
   ```
   Si `id_usuario` no tiene `AUTO_INCREMENT`, incluye explícitamente un valor libre para `id_usuario`.
   Verifica también que `correo` tenga un índice `UNIQUE` y que `password` pueda guardar el hash completo (se recomienda `VARCHAR(255)`).
7. La página principal está en `http://localhost/Escuela/`. La API está disponible en `http://localhost/Escuela/api.php?route=/login`; sus endpoints requieren una base de datos compatible.
8. El registro de asistencia valida adeudos activos del alumno cuando existen tablas de cobranza (`cargos_mensuales`, `aplicaciones_pagos`, `pagos`). Si hay saldo pendiente o vencido, bloquea la asistencia.

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

## Sistema de migraciones propio (PDO)

Este proyecto no usa Yii ni `php yii migrate`. Las migraciones se ejecutan con:

```bash
php migrate.php status
php migrate.php init --target-db=<db> --env-id=<env> --confirm-write=YES
php migrate.php up --target-db=<db> --env-id=<env> --confirm-write=YES
php migrate.php history
```

- Los archivos de migración van en `database/migrations/*.php`.
- El historial se registra en la tabla `migraciones` y un journal de recuperación en `migraciones_estado`.
- Cada migración se ejecuta una sola vez por `id`.
- Si una migración ya ejecutada cambia su contenido, el runner se detiene para evitar drift. Los finales de línea (LF/CRLF) no cuentan como cambio.
- `status` y `history` son de solo lectura; si no existe la tabla `migraciones`, informan que el sistema no está inicializado.
- La creación de la tabla de control solo se permite con `init`.
- Para `init`/`up`/`recover` debes autorizar explícitamente la base con variables de entorno:
  - `MIGRATIONS_ALLOWED_DATABASES` (lista de bases autorizadas),
  - `MIGRATIONS_BLOCKED_DATABASES` (lista de bases bloqueadas; `escuela` siempre está protegida),
  - `MIGRATIONS_ENV_ID` (token de entorno que debe coincidir con `--env-id`),
  - `MIGRATIONS_UNLOCK_PROTECTED_DATABASES` (opcional): libera una base protegida como `escuela`, y exige además `--backup-confirmed=YES`.
- Los identificadores (`id_usuario`, `id_alumno`, `id_profesor`, ...) nunca se renumeran: ninguna migración ni pantalla los modifica.

Formato de archivo de migración:

```php
return [
  'id' => 'AAAAMMDD_HHMMSS_descripcion',
  'description' => 'Descripción',
  'transactional' => false,
  // true solo si TODAS las sentencias son idempotentes (CREATE ... IF NOT EXISTS, ADD COLUMN IF NOT EXISTS, ...).
  'retry_safe' => false,
  'statements' => [
    'ALTER TABLE ...',
  ],
];
```

### Nota importante sobre MariaDB y transacciones

MariaDB aplica *commit implícito* en sentencias DDL como `ALTER TABLE`, `CREATE TABLE`, `DROP TABLE`, `TRUNCATE`, etc.
Por eso:

- No todas las migraciones pueden revertirse con `ROLLBACK`.
- Para DDL, el runner ejecuta en modo no transaccional y se detiene ante errores.
- Si una migración falla o se interrumpe, queda en estado `failed`/`interrupted` y `up` **no la repite automáticamente**.
- Solo las migraciones marcadas `retry_safe` (hoy 003, 005, 006, 007, 008 y 009) pueden liberarse con `php migrate.php recover --migration=<id> ...` y repetirse con `up`. Las demás requieren restaurar el respaldo.
- Usa respaldos y entornos aislados antes de ejecutar migraciones en ambientes compartidos.

### Claves foráneas y columnas generadas (MariaDB 10.11)

MariaDB rechaza (error 1901) `ON UPDATE CASCADE` sobre una columna que es base de una columna generada persistente. Por eso `pagos.id_sesion_privada` y `clases_alumnos.id_alumno` usan `ON UPDATE RESTRICT` (y `ON DELETE RESTRICT`): no se permiten registros huérfanos ni cambiar un identificador referenciado. Las FK hacia `usuarios` siguen con `ON UPDATE CASCADE`.

### Actualizar una instalación existente sin perder datos

Esta guía es para quien ya tiene una base con alumnos, profesores, pagos y su cuenta de administrador. **No importes `escuela.sql` ni crees otro administrador**: las migraciones solo agregan estructura y conservan tus registros. Requiere MariaDB (no MySQL), PHP 8 y los comandos desde la carpeta del proyecto. Ejemplo para XAMPP en Windows (PowerShell), con la base llamada `escuela`:

1. **Respaldo obligatorio** (comprueba que el archivo no esté vacío):

   ```powershell
   C:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction --routines escuela > respaldo_escuela_AAAAMMDD.sql
   ```

2. **Actualiza el código** (`git pull`). Si tienes cambios locales, guárdalos antes con `git stash` o un commit.
3. **Define las variables de entorno** de esa sesión de PowerShell (ajusta usuario y contraseña; `DB_NAME` debe ser tu base):

   ```powershell
   $env:DB_NAME='escuela'; $env:DB_USER='root'; $env:DB_PASSWORD=''
   $env:MIGRATIONS_ALLOWED_DATABASES='escuela'
   $env:MIGRATIONS_UNLOCK_PROTECTED_DATABASES='escuela'   # solo si tu base se llama "escuela"
   $env:MIGRATIONS_ENV_ID='actualizacion-2026-11'
   ```

4. **Revisa el estado** (solo lectura). En una base sin migrar verás `Sistema de migraciones no inicializado` y las 6 migraciones `pending`:

   ```powershell
   php migrate.php status
   ```

5. **Inicializa y migra**:

   ```powershell
   php migrate.php init --target-db=escuela --env-id=actualizacion-2026-11 --confirm-write=YES --backup-confirmed=YES
   php migrate.php up   --target-db=escuela --env-id=actualizacion-2026-11 --confirm-write=YES --backup-confirmed=YES
   ```

6. **Verifica**: `php migrate.php status` debe mostrar las 9 migraciones como `executed`. Puedes ejecutar `up` otra vez: responde `No hay migraciones pendientes`. Inicia sesión con tu cuenta de administrador de siempre; tus IDs no cambian, con una excepción: la migración 007 reubica al profesor con ID 0 a un ID nuevo (y reapunta sus clases y su cuenta), porque 0 es un valor frágil en MySQL/MariaDB. Esa misma migración corrige la cuenta de secretaría de ejemplo: `sevcretaria@localhost.com` pasa a `secretaria@localhost.com` con rol `recepcionista`, y su ficha de alumno queda inactiva.

**Si algo falla:**

- No repitas `up` a ciegas. Lee el mensaje y ejecuta `php migrate.php status`.
- Estado `failed`, `interrupted` o `recovery_required` en la **003** o la **005** (marcadas `retry_safe`): libera y repite.

  ```powershell
  php migrate.php recover --migration=20261118_003_clases_privadas_notificaciones --target-db=escuela --env-id=actualizacion-2026-11 --confirm-write=YES --backup-confirmed=YES
  php migrate.php up --target-db=escuela --env-id=actualizacion-2026-11 --confirm-write=YES --backup-confirmed=YES
  ```

- Un fallo en 001, 002 o 004: no uses `recover`. Restaura el respaldo y avisa al equipo con el mensaje completo.
- `checksum_mismatch`: alguien modificó una migración ya ejecutada. Revierte el archivo con `git checkout -- database/migrations`.
- Si ya habías ejecutado la versión anterior de las migraciones 003 o 005 con éxito (por ejemplo en MariaDB 10.4), no hay que hacer nada: se reconocen como ya ejecutadas.

## Cuentas, roles y panel del alumno

- **Registro público (`register.php`)**: solo crea cuentas de **alumno** (con su ficha en `alumnos`). El rol nunca se toma de la solicitud y se rechaza cualquier intento de enviarlo.
- **Cuentas de maestro, recepción, dirección y administración**: las crea un administrador desde `dashboard_admin.php` ("Crear cuenta de personal"). El maestro se vincula con `profesor` (`usuarios.id_profesor`); el resto no necesita ficha de alumno ni cinturón.
- **Administración de cuentas**: el administrador consulta, edita, cambia el rol y activa/desactiva cuentas. Las cuentas no se eliminan; cambiar de rol conserva el ID, los vínculos y el historial. Una cuenta desactivada no puede iniciar sesión (web ni API) y su sesión abierta deja de ser válida.
- **Migración `20261120_006_cuentas_y_perfiles`**: agrega `usuarios.activo` y datos personales opcionales (`nombre`, `apellidos`, `telefono`, `fecha_nacimiento`) para el personal sin ficha. Es incremental y repetible. Hasta aplicarla, el panel funciona pero "Activar/Desactivar" queda deshabilitado. Los roles `director` y `recepcionista` requieren la migración 001.
- **Panel del alumno (`panel_alumno.php?seccion=...`)**: Inicio, Mi perfil, Mis clases, Asistencias, Pagos, Adeudos y Notificaciones. Si una instalación aún no tiene alguna tabla, la sección se muestra vacía con un mensaje amable; los errores reales se registran en el log de PHP/Apache.

## Cargos mensuales y transferencias

Los pagos son **mensuales**, como una suscripción de gimnasio: cada mes se crea un cargo por alumno activo y se cobra en la caja.

- **Cargos (administrador)**: `generar_cargos.php` ("Cargos mensuales" en el menú, y el botón "Generar cargos del mes" del dashboard). Ahí el administrador:
  - genera los cargos de **todos los alumnos activos** de un periodo, eligiendo la **fecha de vencimiento** al crearlos;
  - crea un **cargo individual** para un alumno (periodo y vencimiento a elegir);
  - revisa los cargos de cada periodo (pendientes, vencidos, pagados) y **cambia el vencimiento** de un cargo que aún se debe;
  - puede marcar "Usar este día del mes como predeterminado" para que el script automático use ese día.
  Repetir la generación de un periodo es seguro: nunca duplica cargos.
- **Cargos automáticos cada mes**: `generar_cargos_cli.php --actor=<id_usuario>` (opcional `--periodo=AAAA-MM` y `--vencimiento=AAAA-MM-DD`). En XAMPP/Windows prográmalo con el Programador de tareas (mensual) usando `C:\xampp\php\php.exe C:\xampp\htdocs\Escuela\generar_cargos_cli.php --actor=1`.
- **Transferencias**: la caja (secretaría en `panel_recepcionista.php` y administración en `registrar_pago.php`) acepta ahora **Efectivo, Transferencia y Tarjeta**. Para transferencias se captura clave de rastreo/referencia, banco de origen y fecha. La referencia no puede repetirse; se guarda en la bitácora (`auditoria_eventos`) y se muestra en las tablas de pagos. No requiere migraciones nuevas.
- **Panel de secretaría**: usa el mismo diseño (barra superior, menú lateral y tarjetas) que el panel de administración, mediante `includes/panel_shell.php`.
