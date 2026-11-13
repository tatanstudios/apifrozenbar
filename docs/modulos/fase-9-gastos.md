# Fase 9 — Gastos

Estado: terminada el 2026-11-01. Fase anterior: [fase-8-produccion.md](fase-8-produccion.md).

## Qué cubre

- **Categorías de gasto:** vienen 10 creadas: Servicios básicos, Gas, Alquiler, Planilla, Mantenimiento, Limpieza, Transporte, Papelería, Impuestos y trámites, y Otros. Se pueden crear, renombrar y desactivar desde la web (pestaña Categorías) y desde la tableta (botón «Categorías» en Gastos). Requiere el permiso `gastos.categorias` (agregado el 2026-11-04; el admin lo activa por rol en Roles y permisos).
- **Gastos (`G-000001`):**
  - **Datos:** fecha, categoría, descripción, monto, forma de pago, referencia, proveedor (opcional) y número de factura o recibo.
  - **Comprobante:** se puede adjuntar una foto o un PDF de hasta 5 MB. En la tableta se puede tomar con la cámara.
  - **Desde la caja:** sale de la gaveta del turno abierto como movimiento `gasto`. Aparece en el cuadre dentro de los egresos y no puede superar el efectivo disponible.
  - **Fuera de caja:** banco, tarjeta del negocio u otra cuenta; no toca la caja. Si no hay turno abierto, solo se puede registrar así.
  - **Sin duplicados:** el registro es idempotente por `uuid`.
- **Anular un gasto:** requiere el permiso `pos.anular` o el PIN de quien lo tenga. Si salió de caja, su turno debe seguir abierto y el dinero vuelve a la gaveta.
- **Resumen por periodo:**
  - total, lo pagado desde la caja y lo pagado fuera de caja;
  - por categoría, con su porcentaje;
  - por mes y por forma de pago.
- **Atajo desde la caja:** el botón «Gasto» de la pantalla del turno abre el registro directamente.

## Comprobantes

- **Dónde se guardan:** en el disco privado de Laravel (`storage/app/private/comprobantes/gastos/AAAA-MM/`), no en `public`.
- **Cómo se ven:** solo se descargan con sesión y permiso (`GET /gastos/{id}/comprobante`); el frontend los abre en otra pestaña.
- **Si el registro falla:** el archivo subido se borra.

## Backend

- **Migración `2026_11_01_700001`:** `categorias_gasto` (con las 10 categorías iniciales) y `gastos`.
- **Modelos y servicio:** `CategoriaGasto`, `Gasto` y `GastoService` (registro, anulación y resumen).
- **Controlador:** `Api/Gastos/GastoApiController`.
- **Rutas:**

| Ruta | Permiso |
|---|---|
| `GET /gastos/categorias` | `gastos.ver`, `gastos.registrar` o `gastos.categorias` |
| `GET /gastos`, `GET /gastos/{id}/comprobante` | `gastos.ver` o `gastos.registrar` |
| `POST /gastos`, `POST /gastos/{id}/anular` | `gastos.registrar` (anular pide además autorización) |
| `GET /gastos/resumen` | `gastos.ver` |
| `POST/PUT /gastos/categorias` | `gastos.categorias` |

- **Cambio en Compras:** `GET /compras/proveedores` (solo el listado) también lo pueden usar quienes registran gastos, para asociarlos a un proveedor.

## Frontend

- **Página `pages/Gastos/Gastos.tsx`:** pestañas Gastos (listado con filtros y detalle), Resumen y Categorías. Resumen pide `gastos.ver`; Categorías pide `gastos.categorias`.
- **Componente `components/gastos/GastoModal.tsx`:** el registro, con categorías en botones grandes para pantallas táctiles.
- **Servicio `services/gastos.ts`:** el gasto se envía como `multipart/form-data` para poder adjuntar el comprobante.
- **Menú:** entrada «Gastos». En la pantalla de Caja hay un botón «Gasto».

## Pruebas

**Backend:** `php artisan test` corre 167 pruebas (821 aserciones). [GastosTest.php](../../tests/Feature/GastosTest.php) agrega 8, que cubren:
- el egreso en caja;
- los límites de efectivo y de turno;
- el gasto fuera de caja con comprobante, y el tipo de archivo;
- la idempotencia;
- la anulación con PIN;
- el resumen por categoría;
- las categorías y los permisos.

**Navegador:**
1. Desde el botón «Gasto» de la caja se registró G-000001: cilindro de gas por $18.50, en efectivo desde la caja.
2. El gasto aparece en los movimientos del turno.
3. El resumen muestra $18.50 en la categoría Gas, todo desde la caja.

## Limitaciones conocidas

- **Gastos fijos:** no hay gastos recurrentes (alquiler mensual automático).
- **Presupuesto:** no hay presupuesto por categoría.
- **Comprobante:** se adjunta solo al registrar; no se puede cambiar después.
- **Exportar:** el resumen se exporta a Excel o PDF con Reportes (Fase 10).
