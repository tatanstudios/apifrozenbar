# Notificaciones

Avisos guardados por usuario (tabla `notificaciones`). Reemplazan a la campana que calculaba las alertas de stock en vivo: ahora el número de la campana es lo que **ese usuario no ha visto**, y se puede marcar como visto o borrar.

## Cuándo se crea un aviso

- **stock_bajo**: un producto *entra* en alerta (llega a su mínimo o se agota). Mientras siga bajo no se repite; si se repone y vuelve a bajar, se avisa otra vez.
- **stock_negativo**: un producto pasa de 0 o más a negativo.
- Subir el stock mínimo de un producto también puede crear el aviso.
- Si el usuario ya tiene un aviso sin ver del mismo producto, se actualiza en vez de duplicarlo.

Lo dispara `InventarioService::aplicarMovimiento` (ventas, compras, producción, entradas y ajustes) llamando a `NotificacionService::revisarStock`. Lo reciben los usuarios activos con `inventario.ver` o `productos.gestionar`, y el admin.

`php artisan notificaciones:sincronizar-stock` crea avisos para los productos que ya están en alerta y que el usuario nunca recibió (se usó al instalar).

## Endpoints (solo sesión: cada usuario ve las suyas)

| Ruta | Qué hace |
|---|---|
| `GET /notificaciones/resumen` | Cantidad sin ver y las 8 más recientes (campana) |
| `GET /notificaciones?estado=no_leidas\|leidas&page=` | Listado paginado; incluye `sigue_en_alerta` del producto hoy |
| `POST /notificaciones/leer-todas` | Marca todas como vistas |
| `PATCH /notificaciones/{id}/leer` | Marca vista (`leida=false` la regresa a sin ver) |
| `DELETE /notificaciones/{id}` | Borra una |
| `DELETE /notificaciones?solo=leidas` | Borra las vistas (sin `solo`, todas) |

## Pantallas (web)

- `components/header/NotificacionesBell.tsx`: campana con contador, "Marcar todas como vistas" y "Ver todas".
- `pages/Notificaciones/Notificaciones.tsx` (`/notificaciones`): filtros Todas / Sin ver / Vistas, marcar, borrar una, borrar vistas o todas.

## Tablet (appfrozenbar)

- `app/inicio.tsx`: botón "Avisos" con el número sin ver (se actualiza al volver a inicio y cada 2 minutos).
- `app/notificaciones.tsx`: lo mismo que la página web; tocar un aviso lo marca visto y abre Inventario.
- `lib/operaciones.ts` tiene los servicios; `lib/api.ts` ahora admite `patch`.
