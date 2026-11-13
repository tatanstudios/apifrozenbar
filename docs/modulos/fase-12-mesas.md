# Fase 12 — Mesas, cuentas separadas y comandas

Estado: terminada el 2026-11-01. Fase anterior: [fase-11-seguridad.md](fase-11-seguridad.md).

> **Cambio del 2026-11-02:** se quitó la pantalla de cocina y barra (`/cocina`). Cada área trabaja con su ticket impreso. Desde la mesa se reimprime la comanda o se marca entregada (`POST /mesas/comandas/{id}/imprimir` y `/entregar`, permiso `pos.vender`). Se eliminaron el permiso `cocina.ver` y el rol `cocina`. Lo que sigue describe la fase como se entregó; las partes de la pantalla de cocina ya no aplican.

Punto de venta por terminales, al estilo de KOLO. Decisiones del dueño:
- extras como **modificadores con precio**;
- comandas con **pantalla de cocina y barra, además de impresión**;
- **todo por terminales**: «CAJA 1» es una terminal del mapa, junto a las mesas. Lo de llevar se vende en la venta rápida (2026-11-05 se quitó el tipo «para llevar»).

## Qué cubre

- **Mapa del punto de venta (`/pos`):**
  - Tarjetas: cada terminal aparece como tarjeta, en cian si está libre y en rosa si está ocupada. Las ocupadas muestran sus cuentas, la hora de ingreso, el tiempo transcurrido (corre en vivo), lo que falta cobrar, «N listos para servir» y «N por enviar».
  - Navegación: filtro por zona (Mostrador, Salón, Terraza…), búsqueda por mesa, cuenta o mesero, y conteo de mesas ocupadas.
  - Actualización y menú: se refresca cada 15 segundos. Un botón flotante lleva a la venta rápida, la pantalla de cocina, el historial, la configuración y la caja.
  - Caja de mostrador: la terminal de tipo «caja» abre la **venta rápida** de siempre (`/pos/mostrador`), que conserva el funcionamiento sin conexión.
- **Terminal de mesa (`/pos/terminal/{id}`):**
  - **Abrir:** se toca la mesa libre y «Abrir mesa». Ya no se pregunta cuántas personas (2026-11-05); la columna `comensales` queda sin usar.
  - **Productos:** búsqueda, categorías y cuadrícula. Si el producto tiene extras, se abre la ventana de modificadores (opciones obligatorias o no, una o varias, cantidad y nota para cocina).
  - **Cuenta activa (panel derecho):** pestañas por cuenta y cada línea con su estado (Por enviar, Enviado, Preparando, Listo, Entregado, Pagado). Lo pendiente permite cambiar la cantidad o quitarlo; lo ya enviado se cancela con motivo y PIN.
  - **Acciones del panel:**
    - «Enviar a cocina (N)», con una comanda por área;
    - «Marcar entregados» para lo seleccionado;
    - «Precuenta», que se imprime desde el navegador y no es comprobante de pago;
    - «Cobrar», la cuenta activa o solo los productos seleccionados.
  - **Volver a pedir:** se agregan productos a la misma orden en cualquier momento, y salen en una comanda nueva.
  - **Cuentas separadas:**
    - Cada cuenta es una tarjeta con sus productos y su total.
    - Se pueden añadir y renombrar cuentas (por ejemplo, «Luz»), y mover productos seleccionados a otra cuenta o a una nueva.
    - Una línea se puede separar («2 frappés → 1 y 1»).
    - Cada cuenta tiene su propio botón Cobrar y su precuenta.
  - **Comandas:** una tarjeta por área (cocina en rosa, barra en cian) con número, hora, tiempo y productos con sus extras y notas. Se pueden marcar como entregadas y reimprimir.
  - **Encabezado:** cliente de la mesa (para crédito o historial), «Cambiar de mesa» y «Limpiar terminal», que libera la mesa; si queda algo sin cobrar, pide motivo y PIN.
  - **Al cobrar:** se registra la venta con su ticket. Cuando ya no queda nada por cobrar, la mesa queda libre sola.
- **Pantalla de cocina y barra (`/cocina`):**
  - Comandas: activas, de la más antigua a la más nueva, filtradas por área (el área elegida se recuerda en el dispositivo).
  - Avance: botones Iniciar, Terminada (los productos pasan a «Listo» y la mesa lo ve), Entregada, y Deshacer o Regresar para corregir.
  - Urgencia: el borde se pone ámbar a los 10 minutos y rojo a los 20.
  - Aviso: suena una alerta cuando llega una comanda nueva. La pantalla se refresca cada 8 segundos y tiene vista de «Entregadas» (últimas 6 horas) y reimpresión.
- **Configuración (`/admin/mesas`):**
  - Terminales: nombre, tipo (mesa o caja), zona, capacidad, orden, caja y estado.
  - Áreas de preparación: nombre, color, impresora y en qué área se prepara cada categoría (obligatorio desde el 2026-11-02).
  - Grupos de modificadores: obligatorio, varias opciones y activo, con sus opciones y precio extra, y los productos que los usan.

## Reglas

| Situación | Qué hace |
|---|---|
| Producto con extras | El precio de la línea es el precio base más los extras; el ticket dice «Frappuccino (Deslactosada, Caramelo)» |
| Falta un grupo obligatorio, o hay dos opciones en un grupo de una sola | Se rechaza |
| Extra que no es del producto | Se rechaza |
| Enviar | Una comanda por área, con el mismo número; lo que no tiene área se marca entregado |
| Quitar algo no enviado | Se borra |
| Quitar algo enviado | Se cancela con motivo y autorización; si la comanda queda vacía, también se cancela; queda en auditoría |
| Agregar a una cuenta pagada | Se rechaza: se agrega en otra cuenta |
| Cobrar | Usa la misma lógica de venta que la caja de mostrador: inventario, caja, cliente, crédito y PIN. El reintento con el mismo `uuid` no duplica |
| Todo cobrado | La orden se cierra y la mesa queda libre |
| Anular una venta de mesa | Sus productos vuelven a quedar por cobrar; si la mesa sigue libre, la orden se reabre |
| Dos dispositivos abren la misma mesa | Se usa la misma orden (una sola orden abierta por terminal, garantizado por la base de datos) |
| Cambiar de mesa a una ocupada | Se rechaza |

## Backend

- **Migración `2026_11_01_900001`:**
  - tablas nuevas: `areas_preparacion`, `terminales`, `grupos_modificadores`, `modificadores`, `producto_grupo_modificador`, `ordenes`, `ordenes_tickets`, `comandas` y `ordenes_items`;
  - columnas nuevas: `categorias.area_preparacion_id`, `areas_preparacion.impresora_id` y `ventas.orden_id`;
  - datos iniciales: las áreas Cocina y Barra y las terminales CAJA 1 y MESA 1 a 6.
- **`OrdenService`:** abrir, agregar con modificadores, actualizar, quitar o cancelar, enviar, avanzar comanda, entregar, cuentas (crear, renombrar, mover, dividir, eliminar), cobrar, cerrar, trasladar y la reacción al anular una venta.
- **Cambios en otros servicios:**
  - `VentaService` acepta, solo desde el servidor, el precio y la descripción de la línea de una orden y la liga con `orden_id`. El cliente no puede enviar esas claves.
  - `ImpresionService::imprimirComanda` envía cada área a su impresora.
- **Controladores** en `Api/Mesas`: `MesaApiController`, `CocinaApiController` y `ConfigMesasApiController`.
- **Permisos nuevos:**
  - `cocina.ver`: pantalla de cocina (lo tienen el cajero y el rol nuevo `cocina`);
  - `mesas.configurar`: solo el administrador.
- **Rutas:** las de la terminal usan `pos.vender`; las de cocina, `cocina.ver` o `pos.vender`.

## Frontend

- **Páginas:** `pages/Mesas/MapaMesas.tsx`, `TerminalMesa.tsx`, `Cocina.tsx` y `pages/Configuracion/Mesas/MesasConfig.tsx`.
- **Componentes:** `components/mesas/` (`ModificadoresModal`, `PrecuentaImprimible`, `IconoMesa`).
- **Servicios y tipos:** `services/mesas.ts` y `types/mesas.ts`.
- **Rutas:**
  - `/pos` ahora es el mapa y la venta rápida pasó a `/pos/mostrador`, que tiene un botón «Mesas».
  - Los enlaces existentes se actualizaron (pedidos especiales, historial de ventas).
  - En el menú: «Mesas (punto de venta)», «Venta rápida», «Pantalla de cocina» y «Mesas y cocina» en Configuración.

## Pruebas

**Backend:** `php artisan test` corre 194 pruebas (1129 aserciones). [MesasTest.php](../../tests/Feature/MesasTest.php) agrega 11, que cubren:
- apertura y mapa;
- validación y precio de los modificadores;
- comandas por área y lo que se entrega directo;
- avance en la pantalla de cocina;
- cuentas separadas con mover, separar y cobrar por cuenta hasta liberar la mesa (con inventario y caja);
- cobro de productos elegidos y anulación que reabre la mesa;
- idempotencia del cobro;
- cancelación con PIN;
- liberar con pendientes;
- traslado;
- configuración y permisos.

**Navegador:**
1. Se abrió MESA 2.
2. Se agregaron un Frappuccino con leche deslactosada y caramelo ($3.90), un sándwich (cocina) y una botella de agua (se entrega directo).
3. Al enviar, salió la comanda #000001 en Cocina y Barra.
4. En la pantalla de cocina se marcó el sándwich como terminado, y el mapa mostró «1 listo para servir».
5. Se separó el frappé en una segunda cuenta y se cobró cada cuenta: T-000010 por $4.15 y T-000011 por $3.90 con cambio de $1.10. La mesa quedó libre.

**Corrección encontrada en la prueba:** las tarjetas de cocina cambiaban de lugar al actualizar si se enviaron en el mismo segundo. Se ordenan además por id.

**Datos de demostración en `frozenbar`:**
- categorías Bebidas (Barra) y Comida (Cocina);
- productos Frappuccino, Americano y Sándwich de pollo;
- grupos de modificadores Leche y Saborizante.

## Ajustes pedidos por el cliente (2026-11-01)

**Un ticket impreso por área.**
- **Por qué:** el negocio trabaja con una sola tablet y entrega papel a la cocina y a la barra.
- **Al enviar:** cada área sale en su propio ticket, con el nombre del área en grande («COCINA», «BARRA»), la mesa, el número de comanda, la hora, el mesero y solo los productos de esa área con sus extras y notas.
- **Impresora de cada ticket**, en este orden:
  1. la impresora del área (`areas_preparacion.impresora_id`, en Configuración › Mesas y cocina › Áreas);
  2. la impresora de comandas predeterminada;
  3. la de tickets.
- **Respaldo:** si no hay térmica o falla, se imprime desde el navegador, un ticket por página (`utils/imprimirComandas.ts`). Lo mismo al reimprimir desde la terminal.
- **Prueba:** `MesasTest::test_cada_area_imprime_su_propio_ticket_aunque_haya_una_sola_impresora`.

**Todo sale en comanda (2026-11-02).**
- Ya no existe «Se entrega directo». Cada categoría debe tener un área (Configuración › Mesas y cocina › Áreas exige elegirla). Si el postre de vitrina lo despacha otra persona, se crea un área para ella.
- Lo que no tiene área (producto sin categoría o categoría sin área) sale igual, en una **comanda general** con el encabezado «COMANDA», en la impresora de comandas o, si no hay, en la de tickets.
- Vale para mesas, venta rápida de mostrador y precuentas.
- El formulario del producto muestra a qué comanda va («Comanda: Barra») o avisa que saldrá en la general.
- **Mostrador sin térmica:** si no se imprimieron todas las comandas (sin impresora, falla, o venta sin conexión), salen desde el navegador, una página por área. El catálogo del POS trae el área de cada producto para eso.
- **Pruebas:** `MesasTest::test_enviar_crea_una_comanda_por_area_y_lo_sin_area_va_en_la_general` e `ImpresionTest::test_venta_de_mostrador_sin_area_sale_en_comanda_general`.

**App de meseros para la tablet (2026-11-02).**
- Proyecto aparte: `C:\laragon\www\frozenbar\appfrozenbar` (Expo SDK 58, React Native). Ver su `README.md`.
- Usa las mismas rutas de `/mesas/*`, `/caja/*` e `/impresion/*`.
- Ruta nueva para la app: `POST /mesas/ordenes/{orden}/precuenta {ticket_id?, caja_id?}` (permiso `pos.vender`). Imprime la precuenta en la térmica de la caja, porque la tablet no imprime desde el navegador. Prueba: `MesasTest::test_precuenta_se_imprime_en_la_termica`.

**Responsable obligatorio al abrir y cerrar (2026-11-02).**
- Abrir y cerrar turno exigen elegir al empleado responsable (`responsable_id`), en la web y en la app de la tablet.
- Si el elegido no es el usuario de la sesión, se pide su PIN y tiene que ser el suyo («Ese PIN no es de Ana»). Quien no tiene PIN no se puede elegir.
- `GET /caja/responsables?accion=abrir|cerrar` lista a los empleados activos con permiso de abrir o cerrar caja.
- Prueba: `CajaTest::test_en_tablet_compartida_quien_abre_y_cierra_se_identifica_con_pin`.

**Quién hizo cada turno.**
- **Abrir y cerrar con PIN:** en la apertura y en el cierre aparece «Lo abre» o «Lo cierra» con el usuario de la sesión. Con «¿Es otra persona?» se escribe el PIN de quien atiende y el turno queda a su nombre (`pin` opcional en `POST /caja/turnos/abrir` y `/cerrar`). El PIN debe ser de alguien con permiso de abrir o cerrar caja.
- **Dónde se ve el nombre:**
  - el encabezado dice «Turno de {nombre}»;
  - el historial de cierres tiene las columnas Abrió y Cerró, con el nombre destacado, y filtro por cajero;
  - el detalle del turno indica quién abrió y quién cerró.
- **Prueba:** `CajaTest::test_en_tablet_compartida_quien_abre_y_cierra_se_identifica_con_pin`.

## Pedidos de mostrador por cobrar (2026-11-05)

En la venta rápida de la tablet, **«Enviar comanda»** manda el pedido a cocina o barra sin cobrarlo, a nombre del cliente («Juan», «#3»). Se cobra después desde **«Por cobrar»**.

- **Cómo se guarda:** cada pedido es una cuenta (`ordenes_tickets`) con el nombre del cliente dentro de la orden abierta de la terminal tipo `caja` de esa caja. Así se reusa todo lo de las mesas: comandas por área, cancelación con aviso CANCELADO y PIN, extras, ingredientes, existencia apartada y cobro mixto. No hubo cambios de base de datos. La orden se cierra sola al cobrar el último pedido; el siguiente abre otra.
- **Rutas** (`pos.vender`), en `Api/Pos/MostradorApiController`:
  - `GET /mostrador/pedidos?caja_id=`: pedidos con algo por cobrar, más antiguos primero, y `mesas_por_cobrar` (mesas con algo por cobrar).
  - `POST /mostrador/pedidos {uuid, caja_id, nombre, items}`: crea la cuenta, agrega los productos y envía la comanda. Es idempotente por `uuid`: un reintento no duplica la comanda.
  - `POST /mostrador/pedidos/{ticket}/items {items}`: agrega algo más al pedido y lo envía.
  - El cobro usa `POST /mesas/ordenes/{orden}/cobrar` con `ticket_id`; la cancelación usa `POST /mesas/items/{item}/quitar`.
- **Comanda impresa:** el título lleva el nombre del cliente («Juan · #000123») y la línea «Mostrador · se cobra al entregar». En una mesa con varias cuentas sale además «Cuenta: …».
- **La caja de mostrador** sigue sin poder abrirse desde el mapa de mesas (`OrdenService::abrir` con `$mostrador = true` solo desde este controlador).
- **Cierre de turno (tablet):** antes de pedir el conteo avisa si quedan pedidos de mostrador o mesas sin cobrar.
- **Web (2026-11-05):** el POS web tiene lo mismo que la tablet: extras al tocar el producto y al editar una línea (✎), «Ingredientes», bloqueo de agotados, «Enviar comanda» y «Por cobrar». Si la térmica no imprime, las comandas salen desde el navegador (`impresion.comandas`).
- **Cobrar una parte:** en «Por cobrar» (web y tablet) se marcan productos y se cobran solo esos (`item_ids`); quedan «Pagado» y el resto sigue pendiente.
- **Precuentas:** guardan solo producto y cantidad; una línea con extras, ingredientes o nota se envía con «Enviar comanda».
- **Venta sin conexión (web):** `POST /pos/sync` acepta `modificadores`, `ajustes` y `notas`; el precio esperado incluye los extras. Si al sincronizar un extra ya no existe, la venta entra sin él y queda un conflicto.

## Limitaciones conocidas

- **Sin conexión:** las mesas necesitan el servidor (la venta rápida de mostrador sigue funcionando sin conexión).
- **Cobro de mesa:** no ofrece descuento general (sí en la venta rápida).
- **Inventario:** el producto se descuenta al cobrar, no al enviar a cocina.
- **Venta rápida:** no tiene modificadores; se agregan solo en las mesas.
- **Actualización:** la pantalla de cocina y el mapa se actualizan por consulta periódica (8 y 15 segundos), no al instante.

## Existencia en las tarjetas y permiso para verla (2026-11-06)

- **Pantalla de mesa (web y tablet):** cada producto con inventario propio muestra su **disponible** abajo a la derecha, junto al precio. El disponible es la existencia menos lo apartado en otras cuentas abiertas. Sale en gris, en ámbar en la web o rojo en la tablet si está bajo su mínimo, y en rojo si no queda. Los platos que salen de su receta no muestran número.
- **API:** `GET /mesas/catalogo` agrega `bajo_minimo` y `unidad` a cada producto (ya traía `stock` y `disponible`).
- **Permiso nuevo `pos.ver_existencias`** («Ver la existencia en las tarjetas de productos»), en el módulo Punto de venta:
  - Sin él, la venta rápida y la mesa (web y tablet) ocultan la cantidad, pero siguen mostrando «Agotado».
  - El rol mesero lo trae de entrada; se quita en Roles y permisos. El admin siempre lo tiene.

**Lo que se entrega directo ya no imprime comanda (2026-11-07).** Reemplaza «Todo sale en comanda» (2026-11-02).
- Una categoría puede quedar **sin área** («Sin comanda (se entrega directo)» en Configuración › Mesas y cocina › Áreas, web y tablet). `PUT /mesas/config/categorias` acepta `area_id: null` (la clave tiene que venir).
- **Venta rápida:** lo que no tiene área no sale en comanda; solo el ticket de venta (`ImpresionService::comandas`, y `comandasDeMostrador` en el navegador).
- **Mesas y pedidos de mostrador:** al tocar Enviar, lo que no tiene área queda **entregado** sin comanda (`OrdenService::enviar`). Si solo había eso, la respuesta dice «Productos entregados (no pasan por cocina)». Cancelarlo después sigue pidiendo motivo y autorización, pero no imprime aviso de cancelación.
- **Pedidos especiales:** siguen imprimiendo todo, porque son encargos a producción; lo que no tiene área sale en la comanda general.
- El formulario del producto dice «Sin comanda: se entrega directo» en lugar de avisar de la comanda general.
- **Pruebas:** `MesasTest::test_enviar_crea_una_comanda_por_area_y_lo_sin_area_se_entrega_directo` e `ImpresionTest::test_venta_de_mostrador_sin_area_solo_imprime_el_ticket`.
