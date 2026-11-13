# Fase 2 — Caja y turnos

Estado: terminada el 2026-10-31. Fase anterior: [fase-1-inventario.md](fase-1-inventario.md).

## Base de datos (migraciones nuevas)

| Tabla | Para qué |
|---|---|
| `metodos_pago` | Efectivo, tarjeta, transferencia, crédito u otro. Indica si el dinero entra o sale de la gaveta (`afecta_efectivo`) y si se pide número de referencia |
| `cajas` | Cajas físicas de el negocio (no son sucursales), con su impresora de tickets |
| `turnos_caja` | Apertura, fondo inicial y, al cerrar, una foto del turno: totales, efectivo esperado y contado, diferencia, y el resumen por método y por empleado |
| `movimientos_caja` | Cada entrada o salida de dinero: venta, ingreso, egreso, devolución, abono o gasto. Lleva `uuid` único y no se borra, se anula con motivo |
| `arqueos_caja` | Conteos de la gaveta, parciales o de cierre, con el detalle por denominación |
| `configuracion_negocio.cierre_ciego` | Si está activo, el cajero cuenta sin ver cuánto debería haber |

**Un solo turno abierto por caja,** garantizado en la base de datos: la columna generada `caja_abierta_id` (igual a `caja_id` solo mientras el turno está abierto) tiene índice único. Aunque dos tabletas intenten abrir a la vez, MySQL no deja.

**Datos iniciales:** `CajaSeeder` crea la «Caja principal» y los métodos Efectivo, Tarjeta, Transferencia (pide referencia) y Crédito. Crédito queda **inactivo** hasta el módulo de Clientes.

**Denominaciones:** están en `config/caja.php`. Son los billetes de $100, $50, $20, $10, $5 y $1, y las monedas de 25¢, 10¢, 5¢ y 1¢.

## Reglas de negocio

Todas las operaciones están en `App\Services\CajaService`.

- **Abrir turno:** bloquea la caja. El fondo puede indicarse directo o contarse por denominaciones; si se cuenta, el total lo calcula el servidor (en centavos, sin errores de redondeo).
- **`registrarMovimiento()`:**
  - Bloquea el turno y exige que esté abierto.
  - Es idempotente por `uuid`: un reintento devuelve el movimiento existente sin duplicarlo.
  - Exige la referencia cuando el método la pide.
  - No permite sacar más efectivo del que debería haber en la gaveta.
  - Es el mismo método que usarán ventas, devoluciones, abonos y gastos (Fases 3, 6 y 9).
- **Anular:** solo los ingresos y egresos manuales, con motivo. Los movimientos de una venta se revertirán desde la venta. No se puede anular un ingreso si eso deja la gaveta en negativo.
- **Efectivo esperado:** fondo inicial más el neto de los métodos que mueven la gaveta. La tarjeta y la transferencia no cuentan.
- **Arqueo parcial:** registra el conteo y la diferencia sin cerrar el turno.
- **Cierre:**
  - Crea el arqueo de cierre y calcula la diferencia (contado − esperado).
  - Guarda la foto del turno, que no cambia aunque luego se modifique un método de pago.
  - Después del cierre se rechaza cualquier movimiento nuevo.
- **Reabrir:** requiere el permiso nuevo `caja.reabrir` (solo administrador) y un motivo, que queda en auditoría. No se puede si la caja ya tiene otro turno abierto.
- **Cierre ciego:** quien no tiene `caja.historial` no ve el efectivo esperado mientras el turno está abierto. Al cerrar se le muestra el resultado.
- **Turno ajeno (2026-11-04):** cada quien vende y mueve dinero solo en su propio turno (`CajaService::exigirTurnoPropio`).
  - Si un mesero cerró sesión sin cerrar su turno, el siguiente no puede vender, cobrar abonos o anticipos, registrar ingresos, egresos, gastos, pagos a proveedores ni devoluciones en ese turno. Solo puede hacer arqueo y **cerrarlo** (el cierre queda a su nombre con la diferencia que salga) y luego abrir el suyo, sin cerrar su sesión.
  - El admin puede operar en cualquier turno (ayuda en horas pico).
  - Las ventas sin conexión que se sincronizan después no se rechazan: ya ocurrieron.
  - `GET /caja/turno-actual` devuelve `turno_ajeno` y `turno.usuario_apertura_id`; la tablet y la web muestran el aviso rojo y bloquean la venta.
  - Al **salir** con su turno abierto, la tablet ofrece "Cerrar mi turno" o "Salir sin cerrar"; la web pide confirmar y si no, lleva al cierre.

**Auditoría:** se registran la apertura, cada ingreso y egreso, las anulaciones, los arqueos, el cierre, la reapertura y los cambios a cajas y métodos de pago.

## Endpoints

| Método y ruta | Permiso |
|---|---|
| `GET /caja/catalogos` (cajas activas, métodos activos, denominaciones) | Cualquier usuario autenticado |
| `GET /caja/turno-actual?caja_id=` | `caja.*` o `pos.vender` |
| `POST /caja/turnos/abrir` | `caja.abrir` |
| `POST /caja/turnos/{turno}/movimientos` (ingreso o egreso, con `uuid`) | `caja.movimientos` |
| `POST /caja/movimientos/{movimiento}/anular` | `caja.movimientos` |
| `POST /caja/turnos/{turno}/arqueos` | `caja.arqueo` |
| `POST /caja/turnos/{turno}/cerrar` | `caja.cerrar` |
| `POST /caja/turnos/{turno}/reabrir` | `caja.reabrir` |
| `GET /caja/turnos?desde=&hasta=&caja_id=&estado=&page=` (historial con faltantes y sobrantes) | `caja.historial` |
| `GET /caja/turnos/{turno}` (reporte) | `caja.historial`, o haber abierto o cerrado ese turno |
| `GET/POST/PUT /caja/cajas` y `/caja/metodos-pago` | `configuracion.gestionar` |

Las validaciones de configuración:
- No se puede desactivar el último método de efectivo.
- No se puede desactivar una caja con turno abierto.

## Frontend

**`CajaContext`** guarda la caja de este dispositivo, los catálogos y el turno actual. Lo usan la pantalla de caja, el encabezado y, más adelante, el POS. Cada dispositivo recuerda su caja; si solo hay una caja activa, se elige sola.

**Caja → Turno actual** (`/caja`):
- **Elegir caja:** si hay más de una, se elige cuál usa este dispositivo.
- **Abrir turno:** el fondo se escribe directo o se cuentan los billetes.
- **Turno abierto:**
  - Botones grandes para tableta: Ingreso, Egreso, Arqueo, Corte X y Cerrar turno.
  - Tarjetas de fondo, ventas, ingresos, egresos y efectivo en caja.
  - Movimientos con opción de anular.
  - Totales por forma de pago y ventas por empleado.
  - Lista de arqueos.

**Cierre** (`/caja/cierre`):
- Se cuentan billetes y monedas con botones − y +.
- La pantalla compara contra lo esperado mientras se cuenta (salvo en cierre ciego) y pide confirmación.
- Al final muestra el resultado (cuadra, faltante o sobrante) y el reporte para imprimir.

**Historial de cierres** (`/caja/historial`) y **reporte de turno** (`/caja/turnos/:id`):
- El historial muestra los totales del período, con faltantes y sobrantes.
- El reporte se imprime y, con permiso, permite reabrir el turno.

**Configuración → Cajas y formas de pago** (`/admin/cajas/index`). En **Configuración → Datos del negocio** está el interruptor de cierre ciego.

**Encabezado:** indica si este dispositivo tiene turno abierto y lleva a `/caja`. Reemplaza el botón que antes solo escribía en la consola.

**Impresión desde el navegador:** `TurnoReporte` tiene formato de ticket de 80 mm. En `index.css`, `@media print` imprime solo `.area-impresion`. Es el respaldo hasta configurar las impresoras térmicas (Fase 4) y para cuando no haya servidor (Fase 5).

**Componentes reutilizables nuevos:**
- `form/QtyStepper.tsx`: cantidad con − y + táctiles. Lo usará el carrito del POS.
- `caja/ContadorEfectivo.tsx`: pasa a dos columnas según el ancho de su contenedor (`@container`), así cabe en ventanas y en páginas angostas.
- `caja/ConteoModal.tsx`
- `caja/MovimientoCajaModal.tsx`
- `caja/TurnoReporte.tsx`
- `modal/MotivoModal.tsx`
- `header/EstadoTurnoBoton.tsx`

**`utils/cajaDispositivo.ts`** genera el `uuid` de cada movimiento. Funciona también desde `http://192.168.x.x`, donde el navegador no permite `crypto.randomUUID`.

**Corrección:** `dinero()` muestra los negativos como «−$12.50» en vez de «$-12.50».

## Pruebas

`php artisan test` corre 70 pruebas (274 aserciones). [CajaTest.php](../../tests/Feature/CajaTest.php) agrega 20:

- **Apertura:** fondo por denominaciones, doble apertura, índice único en MySQL, permisos por rol, denominaciones inválidas.
- **Movimientos:** efectivo esperado, egreso mayor a lo disponible, referencia obligatoria, idempotencia por `uuid`, anulación con motivo, movimientos de venta que no se anulan desde caja.
- **Resumen:** por método y ventas por empleado.
- **Arqueo y cierre:** arqueo parcial; cierre con su foto, el bloqueo posterior y una caja libre para el siguiente turno; cierre ciego.
- **Reabrir, historial y reporte:** reapertura, historial con totales, acceso al reporte propio y ajeno.
- **Configuración:** último efectivo, caja con turno abierto, catálogos.

En el frontend, `npm run build` compila sin errores. En el navegador se probó el ciclo completo:

1. Abrir turno contando $46 en billetes (2×$20, 1×$5, 4×25¢).
2. Intentar un egreso de $60: rechazado por falta de efectivo.
3. Registrar un egreso de $12.50: el efectivo en caja queda en $33.50.
4. Cerrar contando $33: faltante de $0.50, con el reporte de 80 mm y el detalle del conteo.
5. Ver el historial con el faltante y la configuración de cajas y formas de pago.

## Pendiente o a considerar

- **Ventas:** se registrarán como movimientos `venta` desde el POS (Fase 3). La estructura de ventas por empleado ya está lista y probada.
- **Impresión térmica:** la impresión directa a la impresora y la apertura del cajón llegan en la Fase 4. Hoy se imprime desde el navegador.
- **Offline:** bloquear el cierre mientras el dispositivo tenga ventas sin sincronizar se hará en la Fase 5.
- **Datos de prueba:** quedó en `frozenbar` el turno #1 de la prueba (cerrado, con su egreso y su arqueo).

## Abrir el cajón sin venta y ventana de cambio en la tablet (2026-11-06)

- **Al cobrar:** el cajón se abre solo, con el ticket de la venta, cuando hubo un pago con un método que «mueve la gaveta» (hoy, solo Efectivo). Con tarjeta, transferencia, crédito, reimpresiones o consumo interno no se abre. El sistema no sabe cuándo se cierra el cajón: no tiene sensor.
- **Abrir cajón sin venta:** `POST /impresion/cajon {caja_id, motivo}`.
  - **Permiso:** el nuevo `caja.abrir_cajon`. Quien no lo tiene recibe 403 SIN_PERMISO; la tablet y la web piden el PIN del administrador y repiten la petición.
  - **Turno:** exige un turno abierto en esa caja.
  - **Registro:** queda en auditoría (`caja/abrir_cajon`) con el motivo, quién lo abrió, quién autorizó y el turno.
  - **Rol mesero:** no trae el permiso de entrada (abre con PIN); se le da en Roles y permisos.
  - **Tablet:** botón «Abrir cajón» en Caja y turno, con su ventana de motivo.
- **Venta cobrada (tablet):** `components/ventaCobrada.tsx`.
  - Después de cobrar en Venta rápida, en una mesa o en un pedido de mostrador, muestra el cambio en grande, el total y lo que pagó el cliente (efectivo recibido, tarjeta…).
  - Solo se cierra con «Listo»: un toque fuera no la cierra. También tiene «Imprimir otra vez».
  - Si la mesa quedó libre, vuelve al mapa al tocar «Listo».
  - La web ya tenía su ventana «Venta realizada», que también se cierra a mano.
