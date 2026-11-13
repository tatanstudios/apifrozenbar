# Fase 3 — Punto de venta

Estado: terminada el 2026-10-31. Fase anterior: [fase-2-caja.md](fase-2-caja.md).

## Base de datos

| Tablas | Para qué |
|---|---|
| `pedidos`, `pedidos_detalle` | Precuentas (ventas en espera). Ya incluyen los campos de los pedidos especiales (anticipo, fecha de entrega), que se usarán en la Fase 6 |
| `ventas`, `ventas_detalle`, `ventas_pagos` | Venta con `uuid` único y número `T-000001`. Cada línea guarda el producto tal como se vendió (nombre, precio, descuento y costo de ese momento). Cada pago guarda lo recibido y el cambio |
| `devoluciones`, `devoluciones_detalle` | Devoluciones totales o parciales, con número `DEV-000001` y la forma de reembolso |

## Reglas

Las reglas están en `VentaService`, `DevolucionService` y `PedidoService`.

- **Cálculo:** el servidor recalcula todo con `VentaService::calcular()`: descuentos por línea y general (en porcentaje o monto) e IVA según la configuración. El POS repite el mismo cálculo en `utils/carrito.ts` solo como vista previa.
- **Venta:** todo ocurre en una sola transacción: turno abierto, correlativo, salida de stock al costo promedio, pagos y movimientos de caja. Si algo falla, no queda nada a medias.
- **Duplicados:** la venta es idempotente por `uuid`. Un reintento devuelve la misma venta sin cobrarla dos veces.
- **Pagos:** se puede pagar en efectivo con cambio, con tarjeta o transferencia (no pueden superar el total) o combinando formas. El crédito queda bloqueado hasta la Fase 6.
- **Autorizaciones con PIN:** cuando hace falta, el backend responde 403 `REQUIERE_AUTORIZACION` y el POS pide el PIN de un encargado y reintenta. Se pide para:
  - descuentos mayores al máximo configurado;
  - anulaciones (`pos.anular`);
  - devoluciones (`pos.devolver`).

  Se permiten 5 intentos de PIN por minuto, y quien autorizó queda guardado en la venta y en la auditoría.
- **Anular:** solo ventas del turno abierto. Devuelve el stock, revierte los movimientos de caja y reabre la precuenta si la venta venía de una.
- **Devolver:** sirve para ventas de cualquier turno. El descuento general se reparte en proporción a cada línea y el reembolso sale del turno abierto. Lo dañado no regresa al inventario. Cuando se devuelve todo, la venta queda en estado «devuelta».
- **Precuentas:** guardar una precuenta no mueve stock ni caja. Al cobrarla, la venta se hace con los precios vigentes y la precuenta solo se puede cobrar una vez.

## Endpoints

| Ruta | Permiso |
|---|---|
| `GET /pos/catalogo` | `pos.vender` |
| `POST /pos/ventas` | `pos.vender` |
| `POST /pos/ventas/{id}/anular` | `pos.vender`, más `pos.anular` o el PIN de un encargado |
| `POST /pos/ventas/{id}/devoluciones` | `pos.vender`, más `pos.devolver` o el PIN de un encargado |
| `GET /pos/ventas`, `GET /pos/ventas/{id}` | `pos.historial` o `pos.vender` |
| `GET/POST /pos/pedidos`, `PUT /pos/pedidos/{id}`, `POST /pos/pedidos/{id}/cancelar` | `pos.precuentas` |

## Frontend

**`/pos`** es una pantalla completa pensada para tableta:

- **Catálogo:** categorías y búsqueda; el Enter o el lector de código de barras agregan el producto. Cada producto muestra su existencia.
- **Carrito:** se guarda en el dispositivo, así que sobrevive a una recarga. Las cantidades se cambian con − y +, y los productos por peso con teclado numérico.
- **Descuentos:** por línea o generales.
- **Precuentas:** se dejan en espera y se retoman después.
- **Cobro:** teclado numérico, billetes rápidos y pagos combinados.
- **Al cobrar:** pantalla grande con el cambio y el ticket de 80 mm.
- **Sin turno abierto:** el POS no deja vender y lleva a la caja.

**`/ventas`:** historial por fecha o por turno, con reimpresión, anulación y devolución.

**Componentes nuevos:**
- `pos/NumericKeypad`
- `pos/PinModal`
- `pos/CobroModal`
- `pos/CarritoPanel`
- `pos/CantidadModal`
- `pos/DescuentoModal`
- `pos/PrecuentasModal`
- `pos/DevolucionModal`
- `pos/TicketVenta`
- `hooks/pos/useAutorizacion`: resuelve el PIN de forma genérica para cualquier acción

## Pruebas

- **Backend:** `php artisan test` corre 89 pruebas (366 aserciones). [PosTest.php](../../tests/Feature/PosTest.php) agrega 19.
- **Frontend:** `npm run build` compila sin errores.
- **Navegador:** se probaron una venta con descuento y cobro en efectivo; un descuento mayor al máximo como cajero, con PIN incorrecto y luego correcto; una anulación con PIN y una devolución parcial con PIN. Después se comprobó en la base de datos: el stock y el efectivo esperado coinciden con el kardex y el resumen de caja.

## Pendiente

- **Pantallas sin probar en el navegador:** las precuentas (sí cubiertas por las pruebas del backend) y el teclado de cantidad para productos por peso.
- **Datos y PIN de prueba:** en `frozenbar` quedaron el turno #2 abierto, las ventas T-000001 y T-000002 (anulada) y la devolución DEV-000001. Para las pruebas se le asignó al administrador un PIN de desarrollo; el valor está en `AdminSeeder` y solo se usa en el entorno local.
