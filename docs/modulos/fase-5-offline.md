# Fase 5 — Funcionamiento sin conexión

Estado: terminada el 2026-11-01. Fase anterior: [fase-4-impresoras.md](fase-4-impresoras.md).

## Qué cubre

En el negocio el servidor (Laragon) está en la red local. «Sin conexión» aquí significa **que el POS no llega al servidor**: el servidor está apagado, el Wi-Fi se cayó o el router se reinició. La pérdida de internet no afecta mientras el servidor esté en el local.

Mientras el POS no llega al servidor:

- **Seguir vendiendo:** el POS usa la última copia del catálogo, los precios, la configuración, los métodos de pago y el turno abierto, guardada en el dispositivo (IndexedDB).
- **Guardar la venta:** cada venta queda en el dispositivo con su `uuid` y un número provisional (`OFF-<caja>-000001`). El ticket se imprime desde el navegador con la leyenda «Emitido sin conexión».
- **Existencias:** el stock local se descuenta para que el catálogo refleje lo vendido.
- **Sincronización automática:**
  - Cuando el POS no llega al servidor, pregunta cada 15 segundos si ya volvió.
  - Al volver, envía las ventas pendientes en lotes de 50.
  - Mientras queden ventas sin enviar, reintenta cada minuto.
  - Al terminar avisa cuántas se sincronizaron y cuántas tienen observaciones.
- **Sin duplicados:** el servidor reconoce el `uuid`. Una venta enviada dos veces, o una que llegó al servidor justo cuando se cortó la respuesta, no se registra dos veces.
- **Estado visible:** un indicador en el POS y en el encabezado del panel muestra «En línea», «Sin conexión · N pendientes», «Sincronizando…» o «N ventas sin sincronizar». Al tocarlo se ven las ventas pendientes, se puede reimprimir cada ticket y sincronizar a mano.
- **Cierre bloqueado:** no se puede cerrar el turno mientras el dispositivo tenga ventas sin sincronizar, porque faltarían en el cuadre.

## Reglas de conflictos al sincronizar

`VentaService::registrar($datos, $usuario, offline: true)` parte de que **la venta ya ocurrió**: no la rechaza por lo que cambió después, la acepta y anota la diferencia en `ventas.conflictos`. Cada venta con observaciones queda además en auditoría (`venta_offline_conflicto`).

| Situación | Qué hace |
|---|---|
| El precio cambió mientras no había conexión | Respeta el precio cobrado y anota «se cobró a $X y el precio actual es $Y» |
| No había stock suficiente y no se permite stock negativo | La acepta igual (el stock queda negativo y aparece en las alertas) y lo anota |
| El producto se desactivó o se eliminó después | La acepta con los datos guardados al vender y lo anota |
| Descuento mayor al máximo | El PIN se pide en el dispositivo y lo verifica el servidor al sincronizar. Si no es válido, la venta se acepta y se anota «sin autorización válida» |
| El turno en que se vendió ya se cerró | Se registra en el turno abierto actual de esa caja y se anota |
| No hay ningún turno abierto en la caja | La venta **queda pendiente** en el dispositivo hasta que se abra uno |
| Datos inválidos (pago incompleto, fecha futura o de hace más de 30 días) | Queda con error, visible en el indicador. Las demás ventas del lote se guardan igual |

Además:

- La hora de la venta es la del dispositivo: llega en UTC y se guarda en la hora local del negocio. Se guarda también `sincronizada_at`, con origen `offline`.
- El número definitivo (`T-…`) lo asigna el servidor al sincronizar. El provisional queda guardado en `numero_provisional`.

## Backend

- **Migración `2026_11_01_300001`:** `ventas.numero_provisional`, `ventas.conflictos` (json) e índice por `origen`.
- **`GET /pos/ping`:** sirve para saber si hay conexión. Requiere `pos.vender`.
- **`POST /pos/sync {ventas: [...]}`:** recibe hasta 50 ventas por lote, cada una en su propia transacción. Requiere `pos.vender` y acepta 60 peticiones por minuto. Devuelve el resultado de cada venta:
  - `creada`, con su número y sus observaciones;
  - `ya_existia`;
  - `error`, con el motivo.
- **`InventarioService::aplicarMovimiento(..., forzarNegativo: true)`:** permite stock negativo solo para estas ventas.

## Frontend

- **`utils/offlineDb.ts`:** IndexedDB sin librerías, con dos almacenes: `cola` (ventas pendientes) y `cache` (catálogo, configuración, catálogos y turno de caja).
- **`context/ConexionContext.tsx`:** estado de la conexión, cola, sincronización automática y eventos `online`/`offline` del navegador.
- **`utils/ventaOffline.ts`:** arma la venta y su ticket con el mismo cálculo que el servidor (incluido el cambio) y lleva la secuencia provisional del dispositivo.
- **`usePosHook`:**
  - Si sabe que no hay conexión, vende directo en modo local.
  - Si el servidor no responde en 10 segundos, guarda la venta localmente con el mismo `uuid`.
  - Las precuentas siguen necesitando conexión.
- **`CajaContext` y POS:** guardan su copia al cargar y la usan cuando no hay respuesta del servidor.
- **`components/pos/EstadoConexion.tsx`:** el indicador y el panel de ventas pendientes.
- **`public/sw.js`:** service worker escrito a mano (sin `vite-plugin-pwa`). Guarda la aplicación para poder **abrirla** sin conexión.
  - Solo se registra en producción y en un contexto seguro (https o localhost); es una restricción de los navegadores.
  - En `http://192.168.x.x` no se registra. El POS sigue vendiendo sin conexión mientras la página esté abierta; lo que no puede es recargarse sin el servidor.

## Pruebas

**Backend:** `php artisan test` corre 115 pruebas (493 aserciones). [SyncTest.php](../../tests/Feature/SyncTest.php) agrega 12:
- **Registro:** hora y número, conversión de la hora UTC, reenvío sin duplicados.
- **Conflictos:** precio cambiado, sin stock, producto desactivado.
- **Turnos:** turno cerrado que pasa al abierto; sin turno abierto la venta queda pendiente y el resto del lote se guarda.
- **Descuentos:** sin PIN válido y con PIN válido.
- **Lote y permisos:** datos inválidos que no detienen el lote, permisos de sync y ping.

**Navegador:**
1. Con el POS abierto se apagó el servidor de la API.
2. Venta de 3 lattes helados: a los 10 segundos se guardó como OFF-1-000001, con su ticket y el indicador «Sin conexión · 1 pendiente».
3. Segunda venta: instantánea (OFF-1-000002).
4. Recarga de la página sin API: el POS se reconstruyó desde el dispositivo, con el stock local en 84.
5. Al encender la API se sincronizaron solas como T-000006 y T-000007, en el turno #2, sin observaciones y con el stock correcto.

Esta prueba reveló un error, ya corregido y con su prueba: la hora del dispositivo llegaba en UTC y se guardaba sin convertir. Las dos ventas de prueba se corrigieron en `frozenbar`.

## Limitaciones conocidas

- **Iniciar sesión:** requiere el servidor. Un usuario ya conectado sigue trabajando sin conexión.
- **Precuentas, anulaciones, devoluciones e impresión térmica:** requieren el servidor. La impresión del navegador funciona sin conexión.
- **Abrir la app sin servidor:** solo funciona por https o localhost. Para recargar el POS sin servidor en las tabletas, conviene servir el frontend por https en la red local (por ejemplo, con un certificado de Laragon).
- **Service worker:** no se probó en el navegador porque solo se activa en el build de producción. El resto del flujo sin conexión sí se probó.
- **Varios dispositivos:** cada tableta guarda su propia cola. Si dos venden sin conexión el último café helado, ambas ventas se aceptan y el stock queda en negativo con la observación.
