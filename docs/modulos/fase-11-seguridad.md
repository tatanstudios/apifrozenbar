# Fase 11 — Usuarios, permisos y auditoría

Estado: terminada el 2026-11-01. Fase anterior: [fase-10-reportes.md](fase-10-reportes.md).

## Qué cubre

- **Matriz de permisos por rol (`/admin/roles/{id}/permisos`):**
  - Organización: los permisos se agrupan por módulo, en el orden del menú, con su descripción en español y su clave técnica.
  - Edición: se marcan o desmarcan uno por uno, o el módulo completo («Marcar todos» o «Quitar todos»). Hay buscador.
  - Guardado: antes de guardar se muestra qué se agrega y qué se quita; al guardar se reemplaza todo el conjunto en un solo paso.
  - Auditoría: el cambio queda registrado como `roles/permisos`, con lo agregado y lo quitado.
  - Rol admin: se muestra en solo lectura, porque tiene acceso total, incluso a permisos futuros.
  - Permisos fuera del catálogo: los que se crearon a mano aparecen en «Otros».
- **Usuarios:** el listado muestra si el usuario tiene PIN de autorización y su último acceso. La asignación y el retiro del PIN y la desactivación ya existían desde la Fase 0:
  - un usuario desactivado no inicia sesión y pierde sus sesiones abiertas;
  - el administrador principal no se puede desactivar;
  - no puede haber dos usuarios con el mismo PIN.
- **Visor de auditoría (`/admin/auditoria`):**
  - Filtros: rango de fechas (por defecto, los últimos 7 días), usuario, módulo, acción y búsqueda en la descripción.
  - Detalle: cada registro se despliega con el antes y el después lado a lado, con los datos que cambiaron resaltados, más la IP y el dispositivo.
  - Lo que registra: todo lo que el sistema audita desde la Fase 0, como inicios de sesión, anulaciones, devoluciones, descuentos autorizados, ajustes de inventario, cambios de precio, cierres y reaperturas de caja, compras, pagos, producción, mermas, gastos, exportaciones de reportes y cambios de usuarios y permisos.
- **Menú:** el grupo «Usuarios y seguridad» incluye Roles y permisos, Usuarios y Auditoría.

## Revisión final de permisos

**Backend:** la prueba `SeguridadTest::test_toda_ruta_de_la_api_pide_sesion_y_permiso` recorre todas las rutas de la API y falla si alguna:
- **no pide sesión,** salvo el inicio de sesión y la recuperación de contraseña (las cuatro tienen límite de intentos, y otra prueba lo verifica);
- **no pide un permiso específico,** salvo 6 rutas abiertas a cualquier usuario con sesión, a propósito:

| Ruta | Por qué no lleva permiso |
|---|---|
| `POST /logout`, `GET /me`, `GET /datos` | Sesión y perfil del propio usuario |
| `POST /autorizaciones/pin` | Verifica el PIN de quien autoriza; el permiso se revisa dentro |
| `GET /configuracion/negocio` | Lectura de moneda, IVA y datos del ticket (modificarlo sí pide permiso) |
| `GET /caja/catalogos` | Cajas y formas de pago para elegir la caja del dispositivo |

Cualquier ruta nueva sin permiso hace fallar la prueba.

**Frontend:** todas las pantallas están envueltas en `PermissionRoute`, salvo el inicio de sesión, la recuperación de contraseña, el 404 y el panel de inicio. El panel solo pide sus datos si el usuario tiene `dashboard.ver` o `reportes.ver`; si no, muestra el saludo y los accesos.

## Backend

- **`config/permisos.php`:** nuevo mapa `modulos` (prefijo del permiso → nombre del módulo) para agrupar la matriz.
- **`RolesApiController`:**
  - `matrizPermisos` (`GET /admin/roles/{id}/matriz`);
  - `guardarMatriz` (`PUT /admin/roles/{id}/permisos`), que valida que cada permiso exista, rechaza editar el rol admin y limpia la caché de permisos;
  - `tablaUsuarios` ahora incluye `tiene_pin` y `ultimo_acceso` (nunca el PIN).
- **`AuditoriaApiController`:** `GET /auditoria` (paginado) y `GET /auditoria/filtros`, con el permiso `auditoria.ver`.

## Frontend

- **`pages/RolesPermisos/RolesPermisos.tsx`:** reescrita como matriz. Se eliminaron `useRolesPermisosHook.ts` y `AgregarPermisoModal.tsx`, que solo usaba la pantalla anterior.
- **`pages/RolesPermisos/Auditoria.tsx`:** pantalla nueva.
- **`pages/RolesPermisos/Usuarios.tsx`:** columnas PIN y Último acceso.
- **`services/seguridad.ts`:** servicio nuevo de la matriz y la auditoría.

## Pruebas

**Backend:** `php artisan test` corre 183 pruebas (1026 aserciones). [SeguridadTest.php](../../tests/Feature/SeguridadTest.php) agrega 8, que cubren:
- la revisión de sesión y permiso en todas las rutas;
- el límite de intentos de las rutas públicas;
- la matriz agrupada y marcada;
- que guardar la matriz cambia lo que el rol puede hacer de inmediato y queda en auditoría;
- la validación de la matriz y la protección del rol admin;
- el listado de usuarios con PIN y último acceso (sin exponer el PIN);
- los filtros del visor y su permiso.

**Navegador:**
1. Matriz del rol cajero: se marcó «Registrar devoluciones» y se guardó.
2. El visor de auditoría mostró el cambio con «agregados: pos.devolver».
3. Después se devolvió el rol a sus permisos originales.
4. El listado de usuarios muestra el PIN y el último acceso.

## Limitaciones conocidas

- **Un rol por usuario:** cada usuario tiene un solo rol (así estaba el sistema); no hay permisos sueltos por usuario.
- **Retención de la auditoría:** no hay depuración automática; la tabla crece sin límite. Conviene archivarla cada año.
- **Sesiones abiertas:** un cambio de permisos se aplica en el servidor de inmediato, pero el menú del usuario conectado se actualiza al recargar la página.

## Explicación de cada permiso (2026-11-06)

- `config/permisos.php` tiene una sección nueva, `ayuda`, con la explicación larga de cada permiso: qué deja hacer, dónde (web o tablet), qué otros permisos usa y qué pasa sin él (por ejemplo, si se pide el PIN del administrador).
- `GET /admin/roles/{id}/matriz` manda `ayuda` en cada permiso.
- **Roles y permisos (web) y Ajustes → Usuarios (tablet):** cada permiso tiene un botón «?» que despliega la explicación debajo. En la web, el buscador también busca en ese texto.
- `SeguridadTest::test_cada_permiso_del_catalogo_tiene_su_explicacion` falla si se agrega un permiso al catálogo sin su explicación.
