# Fase 8 — Producción, recetas y mermas

Estado: terminada el 2026-11-01. Fase anterior: [fase-7-compras.md](fase-7-compras.md).

## Qué cubre

- **Recetas:**
  - **Contenido:** el producto que se produce, el rendimiento por tanda (en la unidad del producto), los ingredientes con su cantidad, unidad y % de merma esperada, y el procedimiento o notas.
  - **Unidades:** cada ingrediente puede ir en cualquier unidad del mismo tipo que su insumo (kilogramos de levadura que se lleva en gramos, por ejemplo).
  - **Costo:** se calcula con el costo promedio vigente de los insumos. Da el costo de la tanda, el costo por unidad y el margen sobre el precio de venta, y el editor lo recalcula mientras se escribe.
  - **Activa y versiones:** hay una sola receta activa por producto. Si una receta ya se usó en producciones, editarla crea la versión siguiente; la anterior queda en el historial y se puede volver a activar.
- **Producción (`PR-000001`):**
  - **Datos:** receta, fecha, cantidad planificada (que define las tandas) y cantidad realmente producida.
  - **Consumo:** el teórico sale de la receta; el real se puede corregir por insumo, y se aceptan insumos que no están en la receta.
  - **Una sola transacción:**
    1. Descuenta los insumos con el movimiento `consumo_produccion`, a su costo promedio.
    2. Suma el producto terminado con el movimiento `produccion`.
    3. Asigna como costo unitario el costo real del lote dividido entre lo producido, y lo promedia con la existencia.
  - **Indicadores:** rendimiento real frente al planificado (%), y consumo real frente al teórico por insumo.
  - **Anulación:** saca el producto terminado y devuelve los insumos a su costo de consumo.
- **Mermas y cierre del día:**
  - **Pantalla:** lista los productos de vitrina con existencia, para registrar en un solo paso lo que no se vendió. También se pueden agregar otros productos (materia prima, empaque).
  - **Motivos:** vencido o no se vendió, quemado, dañado u otro, con detalle opcional.
  - **Inventario:** salen con el movimiento `merma` a su costo promedio.
  - **Anulación:** la existencia vuelve al inventario.
- **Resumen de producción:** por periodo muestra:
  - totales de producción y de mermas, con el % de merma sobre lo producido;
  - por producto, lo planificado, lo producido, el rendimiento y el costo;
  - los desvíos de consumo por insumo;
  - las mermas por motivo y los totales por día.

## Reglas

| Situación | Qué hace |
|---|---|
| Unidad de un ingrediente de otro tipo (litros para la harina) | Se rechaza |
| El producto como ingrediente de su propia receta | Se rechaza |
| Segunda receta activa para el mismo producto | Se rechaza: se edita la existente |
| Insumos insuficientes y no se permite stock negativo | No se registra nada (toda la producción se revierte) |
| Producto que se produce en unidades enteras con una cantidad fraccionada | Se rechaza |
| Producción o merma reenviada con el mismo `uuid` | No se duplica |

## Backend

- **Migración `2026_11_01_600001`:** `recetas` (con columna generada para una activa por producto), `recetas_ingredientes`, `producciones`, `producciones_consumos` y `mermas`.
- **Servicio `ProduccionService`:** costeo, recetas con versiones, producción, anulación, mermas en lote y resumen.
- **Controlador `Api/Produccion/ProduccionApiController`.**
- **Rutas:**

| Ruta | Permiso |
|---|---|
| `GET /produccion/catalogo` | `recetas.gestionar`, `produccion.ver`, `produccion.registrar` o `mermas.registrar` |
| `GET /produccion/recetas[/{id}]` | `recetas.gestionar`, `produccion.ver` o `produccion.registrar` |
| `POST/PUT /produccion/recetas`, `/produccion/recetas/{id}/activar` | `recetas.gestionar` |
| `GET /produccion`, `/produccion/{id}`, `/produccion/resumen` | `produccion.ver` o `produccion.registrar` |
| `POST /produccion`, `/produccion/{id}/anular` | `produccion.registrar` |
| `GET /mermas` | `mermas.registrar` o `produccion.ver` |
| `POST /mermas`, `/mermas/{id}/anular` | `mermas.registrar` |

Los permisos ya existían en el catálogo desde la Fase 0 (los tiene el rol `encargado_inventario`).

## Frontend

- **Páginas** en `pages/Produccion/`:
  - `Recetas` (listado con costo y margen, detalle con versiones, editor con costeo en vivo);
  - `Produccion` (registro con consumo teórico y real y la existencia de cada insumo, más el listado del periodo);
  - `Mermas` (cierre del día e historial);
  - `ResumenProduccion`.
- **Menú:** grupo «Producción» con Producción del día, Recetas, Mermas y cierre del día, y Resumen de producción.

## Pruebas

**Backend:** `php artisan test` corre 159 pruebas (777 aserciones). [ProduccionTest.php](../../tests/Feature/ProduccionTest.php) agrega 10, que cubren:
- costo con conversión y merma;
- validaciones de la receta y una activa por producto;
- versión nueva al editar una receta usada, y volver a la anterior;
- descuento de insumos y suma del producto a su costo;
- consumo real distinto al teórico;
- producción sin insumos que no deja rastro;
- anulación e idempotencia;
- mermas del cierre del día con anulación;
- resumen y permisos.

**Navegador:**
1. Receta de Pan francés: 100 panes con 20 lb de harina (+5% de merma) y 200 g de levadura. El editor y el servidor coinciden: $13.24 por tanda, $0.1324 por pan y margen del 11.7% con precio de $0.15.
2. Producción PR-000001: se planificaron 200 panes y salieron 190 (95%). Consumió 42 lb de harina y 400 g de levadura, por $26.48 ($0.1394 por pan).
3. Cierre del día: merma de 12 panes vencidos ($1.67). La existencia bajó de 134 a 122.
4. El resumen muestra todo lo anterior, con mermas del 6.3% de lo producido.

## Limitaciones conocidas

- **Costo promedio al anular:** como en compras, no se recalcula hacia atrás.
- **Costo de la receta:** se calcula con el costo de hoy; no se guarda un historial de cómo cambió.
- **Pedidos especiales:** no generan órdenes de producción automáticamente.
- **Productos devueltos dañados (Fase 3):** no se registran como merma, porque nunca volvieron al inventario.
- **Exportar:** el resumen aún no se exporta a Excel o PDF; llega con Reportes (Fase 10).
