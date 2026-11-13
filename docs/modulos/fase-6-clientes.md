# Fase 6 — Clientes, cuentas por cobrar y pedidos especiales

Estado: terminada el 2026-11-01. Fase anterior: [fase-5-offline.md](fase-5-offline.md).

## Qué cubre

- **Clientes:** nombre, DUI/NIT (único), teléfono, correo, dirección, notas, límite de crédito, plazo en días y estado. Búsqueda por nombre, documento o teléfono. Ficha con saldo, crédito disponible, vencido, compras e historial.
- **Venta al crédito desde el POS:** se asigna el cliente a la venta y se cobra con el método «Crédito», solo o combinado con efectivo, tarjeta o transferencia.
  - Requiere cliente con límite mayor a 0 y conexión con el servidor.
  - Si el crédito supera lo disponible, se pide el PIN de quien tenga `clientes.credito_exceder` (queda en auditoría como `credito_sobre_limite`).
  - Crea una cuenta por cobrar con vencimiento = fecha de venta + plazo del cliente, y suma el saldo del cliente.
  - El ticket muestra el cliente, el vencimiento y una línea para la firma.
- **Abonos:** entran a la caja del turno abierto (tipo `abono`) y se aplican a las cuentas pendientes de la más antigua a la más reciente. Idempotentes por `uuid`, numerados `AB-000001`. No pueden superar el saldo.
- **Anular abono:** solo del turno abierto y con autorización (`pos.anular`). El dinero sale de la caja y las cuentas recuperan su saldo.
- **Estado de cuenta:** saldo anterior, ventas al crédito, abonos y devoluciones del periodo con saldo corrido, y facturas pendientes. En pantalla y en PDF (dompdf, tamaño carta).
- **Cartera (cuentas por cobrar):** todas las cuentas pendientes, vencidas o pagadas, con antigüedad por tramos (por vencer, 1–30, 31–60, 61–90, más de 90 días).
- **Pedidos especiales (encargos):** nombre y teléfono (o cliente registrado), fecha y hora de entrega, productos con detalle por línea (sabor, dedicatoria) y observaciones.
  - Anticipo al crear o después: entra a caja con el tipo `anticipo` y no puede superar el total.
  - Al entregar se abre en el POS (`/pos?pedido=ID`) y solo se cobra lo pendiente; la venta guarda `anticipo_aplicado`.
  - Al cancelar se elige si se devuelve el anticipo (sale de la caja como `devolucion`) o se retiene.
  - Comprobante de 80 mm para el cliente, impreso desde el navegador.

## Reglas

| Situación | Qué hace |
|---|---|
| Anular una venta al crédito sin abonos | Se anula la cuenta y se descuenta del saldo del cliente |
| Anular una venta al crédito con abonos | Se rechaza: primero se anulan los abonos o se registra una devolución |
| Devolución de una venta al crédito | Puede descontarse de la cuenta («Descontar de su cuenta») en lugar de devolver dinero; no puede superar lo que se debe de esa venta |
| Venta al crédito sincronizada sin conexión | El POS no la permite sin conexión; si llega por sincronización sobre el límite, se acepta y se anota en `conflictos` |
| Editar un pedido especial | Solo pendiente; el total no puede quedar por debajo del anticipo recibido |
| Anular la venta de un pedido especial | El pedido vuelve a quedar pendiente con su anticipo |
| Crédito en el cuadre de caja | Aparece por método (Crédito) dentro de las ventas, pero no suma al efectivo esperado |

## Backend

- **Migraciones:**
  - `2026_11_01_400001`: tabla `clientes`; llaves foráneas de `ventas.cliente_id` y `pedidos.cliente_id`; `ventas.anticipo_aplicado`; `pedidos.telefono`.
  - `2026_11_01_400002`: `cuentas_cobrar` (una por venta), `abonos` y `abonos_detalle`.
  - `2026_11_01_400003`: tipo `anticipo` en `movimientos_caja` y activa el método Crédito.
- **Servicios:**
  - `CuentaCobrarService`: autorización de crédito, cuenta desde venta, anulación, devolución a cuenta, abonos, estado de cuenta.
  - `PedidoEspecialService`: guardar, anticipos y cancelación.
  - Cambios en `VentaService` (crédito, cliente, anticipo), `DevolucionService` (reembolso a cuenta), `PedidoService` (las precuentas ya no tocan pedidos especiales) e `ImpresionService` (cliente, anticipo y firma en el ticket térmico).
- **Rutas:**

| Ruta | Permiso |
|---|---|
| `GET /clientes`, `GET /clientes/{id}` | `clientes.ver` o `pos.vender` |
| `POST /clientes`, `PUT /clientes/{id}` | `clientes.gestionar` (el límite de crédito solo lo asigna quien tiene `cuentas_cobrar.gestionar`) |
| `GET /cuentas-cobrar`, `GET /clientes/{id}/estado-cuenta[/pdf]`, `POST /clientes/{id}/abonos`, `POST /clientes/abonos/{id}/anular` | `cuentas_cobrar.gestionar` |
| `/pedidos-especiales` (listar, ver, crear, editar, anticipos, cancelar) | `pedidos.gestionar` |

- **Permiso nuevo:** `clientes.credito_exceder` (no lo tiene el rol cajero).
- **`GET /pos/ventas`** acepta `cliente_id` para el historial de compras.
- **Dependencia nueva:** `barryvdh/laravel-dompdf`.

## Frontend

- **Páginas:**
  - `pages/Clientes/Clientes.tsx` (listado);
  - `pages/Clientes/ClienteDetalle.tsx` (ficha con pestañas por cobrar, abonos, compras y estado de cuenta);
  - `pages/Clientes/CuentasCobrar.tsx` (cartera);
  - `pages/Pedidos/PedidosEspeciales.tsx`.
- **Componentes** en `components/clientes/`:
  - `ClienteFormModal`;
  - `ClienteBuscador` (búsqueda con alta rápida);
  - `PagoCajaModal` (abonos y anticipos);
  - `PedidoEspecialModal`.
- **POS:**
  - «Asignar cliente» en el carrito.
  - `CobroModal` muestra Crédito solo con cliente con crédito y conexión, con el disponible y aviso si se excede.
  - El carrito de un pedido especial muestra el anticipo y lo que falta.
- **`DevolucionModal`:** ofrece «Descontar de su cuenta» en ventas al crédito con saldo.
- **Menú:** grupo «Clientes» con Clientes, Cuentas por cobrar y Pedidos especiales.

## Pruebas

**Backend:** `php artisan test` corre 135 pruebas (614 aserciones). [ClientesTest.php](../../tests/Feature/ClientesTest.php) agrega 19, que cubren:
- clientes y permisos;
- crédito: venta, pago mixto, límite con PIN, sincronización sin conexión;
- anulación y devolución de ventas al crédito;
- abonos: aplicación a la cuenta más antigua, idempotencia, límites y anulación;
- estado de cuenta con PDF, y cartera con antigüedad;
- pedidos especiales: anticipo, entrega, validaciones y cancelación.

**Navegador:**
1. Se creó el cliente «Tienda Don Pepe» (límite $20, plazo de 15 días).
2. Venta T-000008 de $6.00 al crédito desde el POS. El ticket mostró el cliente, el vencimiento (16/11/2026) y la firma.
3. Abono AB-000001 de $2.50 en efectivo: el saldo bajó a $3.50.
4. El estado de cuenta en pantalla y en PDF muestra los saldos correctos.
5. Pedido especial PE-000001 de $15 con anticipo de $5. Se entregó desde el POS cobrando $10 (T-000009). El ticket muestra «Anticipo recibido».

## Limitaciones conocidas

- **Sin conexión:** no se buscan clientes ni se vende al crédito. Un cliente ya asignado sí se envía con la venta para su historial.
- **Abonos:** solo se anulan en el turno en que se recibieron.
- **Ventas al crédito anteriores:** no hay intereses ni recargos por mora.
- **Pedidos especiales:** no descuentan materia prima ni generan órdenes de producción. Eso llegará con Producción (Fase 8).
- **Ventanas sobre el encabezado:** en pantallas bajas, el título de algunas ventanas queda detrás del encabezado de la plantilla. Afecta a todas las ventanas del panel, no solo a las de esta fase.
