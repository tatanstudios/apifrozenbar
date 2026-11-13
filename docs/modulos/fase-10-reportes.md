# Fase 10 — Reportes y panel de inicio

Estado: terminada el 2026-11-01. Fase anterior: [fase-9-gastos.md](fase-9-gastos.md).

## Qué cubre

### Reportes (15)

| Grupo | Reporte | Qué muestra |
|---|---|---|
| Ventas | Ventas por día | Tickets, ventas, descuentos, devoluciones, venta neta, IVA, neto sin IVA, costo, utilidad y margen |
| Ventas | Ventas por hora | Tickets y ventas por hora del día (para planear personal y preparación) |
| Ventas | Ventas por empleado | Tickets, ventas, ticket promedio, descuentos y anuladas |
| Ventas | Ventas por forma de pago | Cobrado, devoluciones y neto por método, con su % |
| Ventas | Productos más vendidos | Cantidad neta de devoluciones, ventas, costo, utilidad y margen; filtro por categoría |
| Ventas | Utilidad estimada | Por día: ventas sin IVA, costo de ventas, utilidad bruta, mermas, gastos y resultado |
| Caja | Cierres de caja | Turnos cerrados con fondo, ventas, ingresos, egresos, esperado, contado y diferencia |
| Compras y gastos | Libro de compras | Compras con NIT/NRC, documento, subtotal, IVA (crédito fiscal), total y saldo; filtro por proveedor |
| Compras y gastos | Gastos | Detalle por gasto; filtro por categoría |
| Inventario | Inventario valorizado | Existencia, costo promedio, valor al costo y a precio de venta; filtro por clase |
| Inventario | Kárdex de un producto | Entradas, salidas, saldo y costo de cada movimiento, con saldo inicial y final |
| Producción | Producción | Planificado, producido, rendimiento y costo |
| Producción | Mermas | Detalle con motivo y costo; filtro por motivo |
| Cartera | Cuentas por cobrar | Clientes con saldo, vencido y por vencer |
| Cartera | Cuentas por pagar | Proveedores con saldo, vencido y próximo vencimiento |

Cada reporte tiene filtro de fechas (máximo un año), indicadores, gráfica cuando aplica, tabla con totales y exportación a **Excel** y **PDF**.

### Cómo se calcula la utilidad

- **Ventas que cuentan:** las ventas anuladas no cuentan. Las ventas con devoluciones cuentan, y se les restan sus devoluciones el día en que se registran.
- **Base sin IVA:** la parte sin IVA de una devolución se calcula en la misma proporción que su venta.
- **Costo:** es el costo guardado en cada línea de venta al momento de vender. Lo devuelto en buen estado lo resta; lo dañado sigue contando como costo.
- **Utilidad estimada (resultado):** utilidad bruta menos mermas y gastos. Las compras no restan: son inventario y su costo se reconoce al vender, producir o mermar.

### Panel de inicio

- **Para todos (`dashboard.ver`):**
  - ventas del día con su variación frente a ayer, tickets, ticket promedio y turnos abiertos;
  - ventas por hora y lo más vendido hoy;
  - alertas: productos bajo el mínimo, pedidos especiales para hoy y pedidos atrasados.
- **Con `reportes.ver`:**
  - gráfica de los últimos 30 días (venta neta y utilidad);
  - ventas, compras, gastos y mermas del mes;
  - lo que se debe cobrar y lo que se debe pagar, con lo vencido o por vencer.
- **Actualización:** el panel se refresca solo cada 2 minutos.
- **Rediseño al estilo KOLO (2026-11-01):**
  - **Periodo:** arriba, Desde, Hasta y un selector de periodo (Hoy, Ayer, Esta semana, Este mes, Mes pasado, Últimos 30 días). Por defecto, este mes. `GET /panel?desde=&hasta=` devuelve el bloque `rango`.
  - **Totales del periodo:** total de ventas, total de gastos y utilidad bruta (venta sin IVA menos costo), con la utilidad después de gastos. Sin `reportes.ver` se muestran ventas, ticket promedio y ventas de hoy.
  - **Últimos pedidos:** los 10 más recientes, con código, cliente, fecha, forma de pago, estado y total. El botón de cada fila abre el ticket en el historial (`/ventas?venta=`), y «Ver todas las órdenes» abre el historial con el mismo rango.
  - **Productos más vendidos:** los 10 con más unidades, con su foto. «Ver todos los productos» abre el reporte con el mismo rango.
  - **Debajo:** cómo va el día, las gráficas y el resumen del mes.
  - **Plantilla:** se quitaron el margen y el ancho máximo del contenido, así que las páginas usan todo el ancho.

## Backend

- **`ReportesService`:** las consultas se agregan en la base de datos. Todos los reportes devuelven la misma forma (`columnas`, `filas`, `totales`, `indicadores`, `grafica`), así que la pantalla, Excel y PDF muestran exactamente lo mismo.
- **`App\Exports\ReporteExport`:** convierte cualquier reporte en una hoja de Excel con:
  - título, negocio y periodo arriba;
  - encabezado en negrita, inmovilizado al desplazarse;
  - formatos de moneda, costo, cantidad y porcentaje;
  - fila de totales.
- **Vista `pdf.reporte`:** genérica. Usa hoja horizontal cuando hay más de 6 columnas y pie con quién lo generó y cuándo.
- **Migración `2026_11_01_800001`:** índices `ventas(estado, fecha_venta)`, `devoluciones(created_at)` y `turnos_caja(estado, cerrado_at)`.
- **`config/cors.php` publicado:** expone `Content-Disposition` para que el navegador lea el nombre de los archivos descargados. El resto queda con los valores por defecto de Laravel.
- **Rutas:**

| Ruta | Permiso |
|---|---|
| `GET /panel` | `dashboard.ver` o `reportes.ver` (lo financiero solo con `reportes.ver`) |
| `GET /reportes`, `GET /reportes/{tipo}` | `reportes.ver` |
| `GET /reportes/{tipo}/excel`, `GET /reportes/{tipo}/pdf` | `reportes.exportar` (máximo 30 por minuto; cada exportación queda en auditoría) |

Por defecto solo el administrador exporta. El encargado de inventario ve reportes, pero no los exporta.

## Frontend

- **`pages/Reportes/Reportes.tsx`:** reportes agrupados a la izquierda; a la derecha, filtros propios de cada reporte, indicadores, gráfica (ApexCharts: barras, barras horizontales, líneas o dona), tabla con totales y botones Excel y PDF.
- **`pages/Dashboard/Home.tsx`:** el panel de inicio, con accesos al punto de venta, la caja y los reportes.
- **`services/reportes.ts`** y **`utils/reportes.ts`:** formato de los valores, igual al de Excel y PDF.
- **Menú:** entrada «Reportes».

## Pruebas

**Backend:** `php artisan test` corre 175 pruebas (989 aserciones). [ReportesTest.php](../../tests/Feature/ReportesTest.php) agrega 8. El día de prueba tiene una venta en efectivo con devolución, una venta con tarjeta, un gasto y una merma, con IVA del 13% incluido. Las pruebas comprueban:
- **Ventas por día:** cuadran al centavo (neto $20.34, sin IVA $18.00, costo $7.20, utilidad $10.80, margen 60%).
- **Resultado:** $4.60.
- **Por método, producto y empleado:** cuadran.
- **Ventas anuladas:** no cuentan.
- **Todos los reportes:** responden, y se validan el kárdex sin producto y el periodo mayor a un año.
- **Inventario valorizado:** cuadra.
- **Excel y PDF:** se descargan y quedan en auditoría.
- **Permisos:** el panel con y sin la parte financiera.

**Navegador:**
1. El panel muestra las ventas del día, la variación frente a ayer, ventas por hora y lo más vendido.
2. La pantalla de reportes muestra la gráfica y la tabla.
3. Los 15 reportes responden con los datos de `frozenbar`.
4. Las descargas de Excel y PDF llegan con su nombre de archivo.
5. Se revisó el contenido de los archivos.

La revisión de los archivos encontró dos detalles, ya corregidos:
- **Excel:** omitía la fila vacía bajo el título, lo que corría el formato del encabezado, y mostraba «3.000» en las cantidades.
- **PDF:** los encabezados numéricos no estaban alineados a la derecha.

## Limitaciones conocidas

- **Utilidad:** es una estimación de gestión, no contabilidad formal. No incluye depreciación, impuestos sobre la renta ni la variación del inventario de materia prima.
- **Gráficas en archivos:** no se incluyen en Excel ni en PDF.
- **Periodo:** máximo un año por consulta.

## Consumo interno aparte y simulación de un día (2026-11-06)

`tests/Feature/DiaCompletoTest.php` recorre un día entero por la API, como la tablet: apertura, venta rápida con cambio, frappé con tarjeta (anulado después con PIN), pedido de mostrador, mesa con comandas por área, cancelación parcial con PIN, cuentas separadas, cobro mixto, descuento autorizado, mesa con comanda anulada y liberada, devolución, cena del personal, gasto desde caja y cierre con faltante. Al final cruza el cierre de caja, los reportes y el inventario (existencias y último saldo del kárdex de cada producto). Todo cuadró salvo un punto, que se corrigió:

- **El consumo interno se contaba como venta en los reportes.** La cena del personal ($0.00) sumaba su costo al costo de ventas, contaba como ticket (bajaba el ticket promedio) y salía en «Productos más vendidos». Ahora:
  - **Ventas por día:** el costo y la utilidad son solo de lo vendido; la nueva columna «Consumo interno (costo)» lo muestra aparte; una venta que es puro consumo interno no cuenta como ticket.
  - **Utilidad estimada:** nueva columna «Consumo interno», que resta del resultado igual que las mermas y los gastos.
  - **Ventas por hora, Ventas por empleado, Productos más vendidos y panel de inicio:** no cuentan el consumo interno.
