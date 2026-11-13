# Fase 0 — Fundamentos

Estado: terminada el 2026-10-31.

## Qué cambió

### Seguridad

- **Todas las rutas de la API exigen sesión** (`auth:sanctum`) y, además, el permiso del módulo (`permission:` de Spatie). Antes, `/productos/*` e `/inventario/*` funcionaban sin iniciar sesión.
- **Usuarios desactivados.** No pueden iniciar sesión, y el middleware `usuario.activo` rechaza sus tokens vigentes en la siguiente petición. Al desactivar un usuario desde la pantalla de Usuarios se borran sus tokens.
- **Login.**
  - Mismo mensaje si falla el correo o la contraseña (`INVALID_CREDENTIALS`), para no revelar qué correos existen.
  - Máximo 5 intentos por minuto por correo + IP.
  - Se guarda `ultimo_acceso`.
  - La recuperación de contraseña está limitada a 10 peticiones por minuto.
- **Errores.** Los errores internos ya no se envían al cliente: se escriben en `storage/logs` y se responde un mensaje genérico. Las reglas de negocio no cumplidas lanzan `App\Exceptions\NegocioException`, que responde 422 con un mensaje pensado para el usuario.

### Permisos

- El catálogo está en `config/permisos.php` con formato `modulo.accion`, junto con los permisos de cada rol base.
- `RolesAndPermissionsSeeder` crea los permisos y los roles `cajero` y `encargado_inventario`. Solo **agrega** permisos: volver a correrlo no deshace los cambios hechos desde la pantalla de Roles.
- El rol `admin` pasa todas las verificaciones (`Gate::before` en `AppServiceProvider` y `hasPermission` en `AuthContext`), incluidos los permisos que se agreguen en el futuro.
- Se conservan los roles `admin` y `editor` y los permisos anteriores.

| Rol | Puede |
|---|---|
| `admin` | Todo |
| `cajero` | POS, caja, ver productos e inventario, clientes, abonos, pedidos, registrar gastos |
| `encargado_inventario` | Productos, catálogos, inventario (registrar, ajustar, kardex), proveedores, compras, producción y mermas, reportes |
| `editor` | Panel de inicio |

Los registros de inventario de tipo `ajuste` exigen `inventario.ajustar`. El resto exige `inventario.registrar`.

### Auditoría — tabla `auditorias`

`App\Services\AuditoriaService::registrar($modulo, $accion, $entidad, $antes, $despues, $descripcion)` guarda el usuario, la IP y el navegador, y quita las contraseñas y los PIN de los datos. Si falla, solo escribe en el log y no interrumpe la operación.

Ya se registra:

- Login (correcto y fallido) y logout.
- Alta y edición de usuarios.
- Permisos agregados o quitados a un rol, y roles eliminados.
- Productos creados y editados (con los datos de antes y después).
- Movimientos de inventario.
- Cambios a la configuración del negocio.
- Autorizaciones con PIN.

### Configuración del negocio — tabla `configuracion_negocio`

Una sola fila: datos fiscales, logo, moneda, IVA, si los precios incluyen IVA, si se permite stock negativo, el descuento máximo sin autorización y el mensaje del ticket.

- `GET /api/configuracion/negocio`: cualquier usuario autenticado (el POS la necesita para el ticket).
- `POST /api/configuracion/negocio`: requiere `configuracion.gestionar`. Es multipart por el logo.
- Pantalla: **Configuración → Datos del negocio** (`/admin/configuracion/negocio`).

### PIN de autorización

- `administradores.pin` se guarda con hash. Cada PIN tiene de 4 a 6 dígitos y no puede repetirse entre usuarios.
- Se asigna o se quita desde los formularios de crear y editar usuario.
- `POST /api/autorizaciones/pin` recibe `{ pin, permiso, motivo? }` y responde quién autorizó. Rechaza un PIN incorrecto, un usuario inactivo o un usuario sin el permiso. Está limitado a 10 intentos por minuto. Lo usará el POS para descuentos y anulaciones (en el frontend: `autorizarConPin()` en `services/configuracion.ts`).

### Correlativos — tabla `correlativos`

`CorrelativoService::siguiente('venta')` devuelve `000001`, `000002`, etc. (desde 2026-11-05 el ticket no lleva prefijo; las ventas anteriores conservan su T-). Bloquea la fila, así que dentro de una transacción no se repiten números entre cajas. Se siembran `venta` (sin prefijo), `devolucion` (DEV), `orden_compra` (OC), `compra` (C), `produccion` (PR) y `pedido` (P).

### Inventario

- `InventarioService::aplicarMovimiento()` es el **único punto de entrada al stock**. Bloquea la fila de inventario (`lockForUpdate`), respeta `permitir_stock_negativo` y deja el movimiento con su referencia. Ventas, compras y producción lo van a reutilizar.
- Al crear un producto se crea su fila de inventario en cero.
- Se corrigió la relación `usuario()` de entradas y movimientos (apuntaba a `User`, una tabla que no existe). Ahora el historial muestra quién registró cada movimiento.

### Frontend

- `npm run build` vuelve a compilar (se corrigieron los 12 errores de TypeScript).
- `services/api.ts` exporta `api` con dos interceptores:
  - Agrega el token si la llamada no trae uno. Las funciones nuevas ya no reciben `token`; las existentes siguen funcionando igual.
  - Ante un 401 limpia la sesión y vuelve al login con el aviso «Tu sesión expiró».
- `AuthContext` refresca roles y permisos en segundo plano al abrir la app. Si no hay conexión, conserva los datos guardados en lugar de cerrar la sesión.
- Cada ruta exige el mismo permiso que su API, y el menú lateral solo muestra lo que el usuario puede abrir.
- La ventana de crear usuario carga los roles desde la API (antes solo ofrecía `admin` y `editor`).
- Componentes reutilizables nuevos:
  - `components/common/PageHeader.tsx`
  - `components/common/StatusBadge.tsx`
  - `components/form/MoneyInput.tsx`
  - `components/form/FormField.tsx`

  `NumericKeypad`, `QtyStepper`, `PinModal` y `DateRangeFilter` se harán en las fases que los usan (Caja y POS), para construirlos junto con su caso real.

## Pruebas

Backend: `php artisan test` corre 28 pruebas sobre la base `frozenbar_test`, que hay que crear una vez:

```sql
CREATE DATABASE frozenbar_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

| Archivo | Cubre |
|---|---|
| `tests/Feature/LoginTest.php` | Credenciales, usuario inactivo, token revocado, límite de intentos |
| `tests/Feature/PermisosRutasTest.php` | 401 sin sesión, 403 por rol, acceso total del admin |
| `tests/Feature/InventarioTest.php` | Stock, usuario en el movimiento, stock negativo, permiso de ajuste, rollback |
| `tests/Feature/FundamentosTest.php` | Correlativos, configuración, PIN, desactivación de usuarios |

Frontend: `npm run build` sin errores. Se probó en el navegador, a tamaño de escritorio y de tablet:

- Login incorrecto y correcto.
- Guardar la configuración del negocio.
- Crear un cajero con PIN.
- El menú del cajero filtrado y el bloqueo de `/admin/usuarios`.
- La redirección al login con aviso cuando se revoca el token.

## Cómo probar en local

`.claude/launch.json` levanta la API (`php artisan serve`, puerto 8000) y Vite en modo `pruebas`, que lee `reactfrozenbar/.env.pruebas` y apunta a `127.0.0.1:8000`. El `.env` normal no se tocó.

En desarrollo, `AdminSeeder` crea un cajero de prueba (`cajero@thefrozenbar.test`). Su contraseña y su PIN están en el seeder.

## Pendiente o a considerar

- Antes de producción: `APP_DEBUG=false` en `.env`, y cambiar las contraseñas de los usuarios sembrados (`1234`).
- `/admin/enviar/correo/password` sigue indicando si el correo existe (lo usa el flujo de recuperación). Está limitado a 10 peticiones por minuto.
- ESLint marca errores en código anterior (`any` en `services/api.ts` y en los hooks). No bloquean el build. Se irán limpiando al tocar cada módulo.
