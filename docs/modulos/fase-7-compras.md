# Fase 7 — Compras, proveedores y cuentas por pagar

Estado: terminada el 2026-11-01. Fase anterior: [fase-6-clientes.md](fase-6-clientes.md).

## Qué cubre

- **Proveedores:** nombre, NIT (único), NRC, contacto, teléfono, correo, dirección, plazo de crédito en días y notas. La ficha de cada proveedor muestra:
  - lo que se le debe y lo vencido;
  - sus compras y pagos;
  - los productos que se le compran.
- **Órdenes de compra (`OC-000001`):** proveedor, fecha, entrega esperada, productos con unidad de compra, cantidad y costo estimado.
  - Estados: borrador → enviada → recibida en parte → recibida, o cancelada.
  - Se editan solo en borrador o enviadas; se cancelan solo sin recepciones.
  - Una orden recibida en parte se puede «cerrar sin recibir el resto».
  - PDF tamaño carta para mandar al proveedor.
- **Compras (`C-000001`, recepción de mercadería):** suelta o desde una orden, que precarga lo pendiente.
  - Datos del documento: tipo (factura, crédito fiscal, ticket, otro), número e IVA del documento.
  - **Unidad de compra:** se compra en la unidad del proveedor (quintal, arroba, docena, galón) y se convierte a la unidad del inventario (libra, unidad, litro) con los factores de la Fase 1. El costo por unidad de inventario sale de dividir el subtotal de la línea.
  - **Inventario:** entra con el movimiento `compra` y actualiza el costo promedio ponderado. Si el producto no lleva inventario, su costo pasa a ser el de la última compra.
  - **Sin duplicados:** el mismo documento (proveedor + tipo + número) no se registra dos veces, y la compra es idempotente por `uuid`.
- **Contado o crédito:** toda compra genera su cuenta por pagar, que vence según el plazo del proveedor (editable). La de contado se paga en el acto.
- **Pagos a proveedores (`PP-000001`):**
  - **Desde la caja:** sale de la gaveta del turno abierto como movimiento `pago_proveedor`. Aparece en el cuadre como egreso y no puede superar el efectivo disponible.
  - **Fuera de caja:** banco, cheque u otra cuenta del negocio; no toca la caja.
  - Se aplica a una compra elegida o a las más antiguas primero.
- **Cuentas por pagar:** pendientes, vencidas y pagadas, con resumen (total, vencido, vence en 7 días) y el historial de pagos realizados.
- **Historial de costos por producto:** últimas compras con proveedor y costo por unidad de inventario (`GET /compras/historial-producto/{id}`).

## Reglas

| Situación | Qué hace |
|---|---|
| Anular una compra de contado | Saca del inventario lo que entró, anula la cuenta y anula también su pago (si salió de caja, el dinero vuelve a la gaveta) |
| Anular una compra al crédito con pagos | Se rechaza: primero se anulan los pagos |
| Anular una compra que vino de una orden | La orden recupera lo pendiente (vuelve a «recibida en parte» o «enviada») |
| Anular un pago desde caja | Solo si su turno sigue abierto |
| Unidad de compra de otro tipo (quintal para un producto por unidad) | Se rechaza con el motivo |
| Producto entero (sin fracciones) con una conversión que no da exacta | Se rechaza |
| Pago mayor a lo que se debe | Se rechaza |

**Limitación:** al anular una compra, el costo promedio no se recalcula hacia atrás; el inventario sí sale.

## Backend

- **Migración `2026_11_01_500001`:**
  - campos nuevos en `proveedores` (incluido `saldo`, que se mantiene en las mismas transacciones);
  - `ordenes_compra` y su detalle, `compras` y su detalle, `cuentas_pagar`, `pagos_proveedor` y su detalle;
  - tipo `pago_proveedor` en `movimientos_caja`.
- **Servicios:**
  - `CompraService`: líneas con conversión, órdenes, registro y anulación de compras;
  - `CuentaPagarService`: pagos, aplicación a cuentas y anulación.
- **Controladores** en `Api/Compras`: `ProveedorApiController`, `OrdenCompraApiController` y `CompraApiController` (compras, catálogo para compras, cuentas por pagar y pagos).
- **Rutas:**

| Ruta | Permiso |
|---|---|
| `GET /compras/proveedores[/{id}]` | `proveedores.gestionar`, `compras.ver`, `compras.gestionar` o `cuentas_pagar.gestionar` |
| `POST/PUT /compras/proveedores` | `proveedores.gestionar` |
| `GET /compras`, `/compras/{id}`, `/compras/catalogo`, `/compras/ordenes[/{id}][/pdf]`, `/compras/historial-producto/{id}` | `compras.ver` o `compras.gestionar` |
| `POST /compras`, `/compras/{id}/anular`, `POST/PUT /compras/ordenes`, `/compras/ordenes/{id}/{enviar\|cancelar\|cerrar}` | `compras.gestionar` (el pago de una compra de contado va incluido) |
| `GET /cuentas-pagar`, `/cuentas-pagar/pagos`, `POST /cuentas-pagar/proveedores/{id}/pagos`, `/cuentas-pagar/pagos/{id}/anular` | `cuentas_pagar.gestionar` |

- **Rutas viejas eliminadas:** `/admin/proveedores/*` y sus métodos en `ConfiguracionApiController`.

## Frontend

- **Páginas** en `pages/Compras/`:
  - `Compras` (listado con filtros, detalle y anulación);
  - `CompraNueva` (registro, también desde `?orden=` y `?proveedor=`);
  - `OrdenesCompra`;
  - `Proveedores` y `ProveedorDetalle`;
  - `CuentasPagar`.
- **Componentes** en `components/compras/`:
  - `LineasCompra` (productos con unidad de compra y conversión en vivo, por ejemplo «= 200 lb · $0.4500/lb»);
  - `ProveedorFormModal`;
  - `PagoProveedorModal`.
- **Menú:** grupo «Compras» con Compras, Órdenes de compra, Proveedores y Cuentas por pagar. Se quitó «Proveedores» de Configuración junto con su página, sus ventanas, su hook y sus funciones en `services/api.ts`.
- **Utilidad nueva:** `fechaCorta()` en `utils/formato.ts`.

## Pruebas

**Backend:** `php artisan test` corre 149 pruebas (712 aserciones). [ComprasTest.php](../../tests/Feature/ComprasTest.php) agrega 14, que cubren:
- proveedores;
- conversión de quintal a libras con costo promedio, y de docena a unidades;
- documento duplicado e idempotencia;
- contado desde caja y su límite de efectivo;
- anulación con su pago de contado, y rechazo de la anulación con pagos;
- orden recibida en partes, anulación de una recepción y cierre;
- PDF de la orden;
- pagos a la cuenta más antigua y a una compra elegida, con anulación;
- cuentas por pagar e historial de costos;
- permisos.

**Navegador:**
1. Se creó el proveedor «Molinos de El Salvador» (plazo de 30 días).
2. Compra C-000001 al crédito de 2 quintales de café molido a $45: entraron 200 lb a $0.45/lb, con vencimiento el 01/12/2026.
3. Pago PP-000001 de $40 por transferencia (fuera de caja): el saldo bajó a $50.
4. Las pantallas de órdenes y de cuentas por pagar muestran los datos.

Esta prueba reveló un detalle, ya corregido: el detalle de la compra no mostraba la unidad del inventario («200» en lugar de «200 lb»).

## Limitaciones conocidas

- **Costo promedio al anular:** no se recalcula hacia atrás.
- **IVA:** el de los créditos fiscales se guarda por documento, pero aún no hay libro de compras para la declaración. Entra en Reportes (Fase 10).
- **Pagos:** no hay pagos parciales a varias compras elegidas a mano en un solo pago (se elige una o las más antiguas).
- **Saldo a favor:** no se manejan notas de crédito ni devoluciones al proveedor.
