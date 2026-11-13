# Fase 13 — Recetas de venta, servicios, consumo interno y lista de compras del turno

Estado: terminada el 2026-11-02. Pedida por el cliente después del plan. Fase anterior: [fase-12-mesas.md](fase-12-mesas.md).

## Qué cubre

- **Recetas de venta.**
  - **Cuándo aplica:** el producto **no lleva inventario propio** (limonada, desayuno, combo de cumpleaños). Su receta se descuenta al venderlo.
  - **Recetas de producción:** si el producto sí lleva inventario (frijoles cocidos, tapioca cocida), su receta se usa al producir, como antes.
  - **Tipo de receta:** lo decide el sistema al guardarla (`recetas.uso`); nadie lo elige a mano.
  - **Al vender:** cada insumo sale con el movimiento `venta`, a su costo promedio. El motivo dice qué se vendió («Venta T-000013: Limonada»). El costo de la línea es la suma de los insumos.
  - **Rendimiento:** dice para cuántas unidades vendidas alcanzan los ingredientes (normalmente 1).
  - **Anular la venta** devuelve los insumos. En una **devolución** no regresan, porque ya se usaron.
- **Servicios con insumos.** Usan el mismo mecanismo. «Combo de cumpleaños» a $3.00 es un producto sin inventario cuya receta lleva frappé y vela.
- **Consumo interno** (`productos.consumo_interno`):
  - **Para qué es:** la cena típica del personal y las cortesías.
  - **Precio:** siempre $0.00, aunque se mande otro precio. No pide pago y no entra a la caja.
  - **Inventario:** descuenta su receta con el movimiento propio `consumo_interno`.
  - **Separación:** la línea de venta guarda la marca (`ventas_detalle.consumo_interno`), así el consumo del turno separa lo vendido de lo consumido.
- **Unidades:**
  - Se agregó **Porción**.
  - Frijol crudo en libras → frijol cocido en porciones: es una receta de producción que rinde 20 porciones por libra. Producir 80 descuenta 4 lb.
- **Bajas manuales de insumos de receta.** Hay casos como «ya se acabó el aceite»:
  - **Qué se controla:** una **salida**, o un **ajuste por conteo** que baja la existencia, de un producto que es ingrediente de una receta activa.
  - **Qué se pide:** el permiso nuevo `inventario.autorizar_baja` o el PIN de quien lo tenga.
  - **Registro:** queda en `entradas_inventario.autorizado_por` y en la auditoría.
  - **Sin autorización:** no se piden las subidas ni lo que no está en recetas.
- **Lista de compras del turno:**
  - **Al cerrar:** el resumen del turno guarda `por_comprar`, con los productos en su mínimo o por debajo, y `consumo`, con lo que salió del inventario con las ventas del turno.
  - **Qué no sale:** lo que está arriba del mínimo.
  - **Ticket:** el ticket de cierre ESC/POS y el reporte impreso desde el navegador agregan la sección «POR COMPRAR».
  - **Al abrir:** sin turno abierto, `GET /caja/turno-actual` devuelve quién cerró el turno anterior y la lista en vivo, para que quien recibe la caja haga la compra.

## Unidades: llevar el inventario en la unidad más pequeña

Un insumo que se gasta en mililitros o gramos debe llevar su inventario en mililitros o gramos, aunque se compre por galón o quintal. Las compras convierten la unidad del proveedor a la del inventario (Fase 7).

La existencia se guarda con 4 decimales. En galones, 0.0001 gal son 0.38 ml, así que 2 ml por plato perderían precisión. En mililitros no se pierde nada.

## Reglas

| Situación | Qué hace |
|---|---|
| Receta de venta con un ingrediente sin inventario | Se rechaza |
| Producir con una receta de venta | Se rechaza |
| Insumo insuficiente al vender y no se permite stock negativo | Se rechaza la venta completa |
| Venta sin conexión con insumo insuficiente | Se acepta y se anota el conflicto (como antes) |
| Insumo que solo admite enteros (huevo) y la receta da fracción | Se descuenta la fracción (la cantidad la calcula la receta) |
| Consumo interno con precio | Se guarda en $0.00 |

## Backend

- **Migración `2026_11_02_000001`:**
  - `recetas.uso`, `productos.consumo_interno`, `ventas_detalle.consumo_interno` y `entradas_inventario.autorizado_por`;
  - el tipo `consumo_interno` en `movimientos_inventario`;
  - la unidad Porción.
- **`VentaService`:** `recetasDeVenta()` y `descontarInventario()`. La anulación revierte `venta` y `consumo_interno`.
- **`ProduccionService`:** asigna `uso` al guardar la receta; `consumoDeVenta()` multiplica antes de redondear; `producir()` rechaza las recetas de venta.
- **`InventarioService`:**
  - `aplicarMovimiento(calculado:)` admite fracción en cantidades que salen de una receta;
  - `bajasDeInsumos()` detecta lo que necesita autorización;
  - `productosEnAlerta()` lo comparten la campana de alertas y la caja.
- **`CajaService`:** `consumoDelTurno()`; `resumen()` agrega `consumo` y `por_comprar`, y el cierre guarda una foto de los dos.
- **Permiso nuevo:** `inventario.autorizar_baja`. Lo tiene el administrador; se asigna a otros roles en la matriz de permisos.

## Frontend

- **Producto:**
  - interruptor «Consumo interno», que fija el precio en $0.00;
  - nota que explica la receta de venta cuando el producto no lleva inventario.
- **Recetas:**
  - indica si la receta es de producción o se descuenta al vender, y lo explica en el editor;
  - en las recetas de venta solo ofrece insumos con inventario.
- **Producción:** no lista las recetas de venta.
- **Inventario:** el registro manual y el conteo físico piden el PIN (`useAutorizacion`) al bajar un insumo de receta.
- **Caja:**
  - el componente `PorComprar` aparece en la apertura (con quién cerró), en el turno abierto y en el cierre;
  - el componente `ConsumoTurno` muestra lo que salió del inventario;
  - el reporte impreso agrega «POR COMPRAR».
- **POS:** los productos de consumo interno muestran «Consumo interno» en lugar del precio.

## Pruebas

**Backend:** [RecetasVentaTest.php](../../tests/Feature/RecetasVentaTest.php) agrega 9 pruebas, que cubren:
- los insumos que salen al vender y el costo de la venta;
- los mililitros que salen de un insumo comprado por galón;
- el servicio con insumos y su anulación;
- la venta rechazada por insumo insuficiente;
- los ingredientes sin inventario;
- el consumo interno sin caja;
- la baja manual con PIN;
- la lista de compras del cierre y de la apertura;
- las 4 lb de frijol que rinden 80 porciones.

La suite completa tiene 206 pruebas.

**Navegador:** venta de una limonada de prueba. El limón bajó de 12 a 8, se vio en «Salió del inventario» y en «Por comprar», y la receta aparece marcada «se descuenta al vender».

## Pendiente

- Los **modificadores** de la Fase 12 («extra queso») todavía no descuentan insumos. Se puede agregar una receta por modificador con el mismo mecanismo.
- **Mínimo sugerido** a partir del consumo promedio por recetas (propuesta al cliente; hoy el mínimo se pone a mano).

## Platos sin ingredientes suficientes no se agregan (2026-11-06)

- `ProduccionService::alcanza()` calcula cuántas unidades de un plato con receta de venta alcanzan con la existencia de sus insumos, menos lo apartado en cuentas abiertas. El que se acaba primero manda.
- `GET /pos/catalogo` y `GET /mesas/catalogo` traen `alcanza` por producto (`null` si no tiene receta con insumos con inventario).
- **Sin stock negativo** (Datos del negocio), la venta rápida y la mesa, en la web y en la tablet:
  - marcan «Agotado» el plato que no alcanza y no lo dejan agregar («No alcanzan los ingredientes para Americano»);
  - no dejan subir la cantidad por encima de lo que alcanza.
- Los extras agotados («Con leche») ya se marcaban así. El servidor lo sigue revisando al cobrar o al agregar a la mesa, porque dos platos pueden compartir un insumo.
- Prueba: `RecetasVentaTest::test_el_catalogo_dice_cuantos_platos_alcanzan_con_los_insumos`.
