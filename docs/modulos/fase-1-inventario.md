# Fase 1 — Inventario

Estado: terminada el 2026-10-31. Fase anterior: [fase-0-fundamentos.md](fase-0-fundamentos.md).

## Base de datos (migraciones nuevas)

| Migración | Cambios |
|---|---|
| `2026_10_31_230001_add_campos_inventario_pos_to_productos_table` | `precio_venta`, `clase`, `se_vende`, `controla_stock`, `permite_fraccion`, `codigo_barras` (único), `orden_pos`, `color_pos`. `costo_unitario` pasa a `decimal(12,4)` |
| `2026_10_31_230002_add_conversion_to_unidades_medida_table` | `abreviatura`, `tipo` (`unidad`, `masa`, `volumen`), `factor_base` |
| `2026_10_31_230003_add_costos_tipos_to_movimientos_inventario_table` | Tipos nuevos (`compra`, `merma`, `devolucion`, `anulacion`, `consumo_produccion`), `costo_unitario`, `costo_total` e índices para el kardex |

Las tres se probaron también hacia atrás (`migrate:rollback --step=3`).

**Clases de producto:**
- **Producto terminado:** pan, pasteles.
- **Materia prima:** harina, azúcar, levadura.
- **Reventa:** bebidas, golosinas.
- **Empaque:** bolsas, cajas.

Al elegir la clase, el formulario sugiere si el producto se vende y si admite decimales.

La columna `tipo` (`bien`/`servicio`) se conserva por compatibilidad: ahora se deriva sola. Un producto que lleva inventario es `bien`; uno que no, es `servicio`.

**Unidades de medida.** `UnidadesMedidaSeeder` siembra las unidades con su equivalencia a la unidad base de su tipo (gramos para peso, mililitros para volumen, unidades para el resto): unidad, docena, g, kg, oz, lb, arroba, quintal, ml, l y galón.

Si una unidad con el mismo nombre ya existía, el seeder respeta su conversión. Solo la completa si todavía tiene los valores por defecto (tipo `unidad`, factor 1).

`UnidadesMedida::convertirA($cantidad, $destino)` convierte entre unidades del mismo tipo. Por ejemplo, 1 quintal equivale a 100 lb. Lo usarán Recetas y Compras.

## Reglas de negocio

**`InventarioService::aplicarMovimiento()`** sigue siendo el único punto de entrada al stock. Ahora también hace esto:

- **Bloqueo:** bloquea primero el producto y después su fila de inventario, siempre en ese orden, para evitar bloqueos cruzados.
- **Productos sin inventario:** rechaza los productos que no llevan inventario (`controla_stock = false`).
- **Cantidades enteras:** rechaza cantidades con decimales si el producto no admite fracción.
- **Costo promedio ponderado:** en los movimientos que suman y traen costo, `nuevo = (stock × costo actual + cantidad × costo nuevo) / (stock + cantidad)`. Un stock negativo no aporta al promedio, porque no tenía costo real.
- **Costo del movimiento:** guarda `costo_unitario` y `costo_total` en cada movimiento. Las salidas y los ajustes se valoran al costo promedio vigente.

**Registro de inventario** (`POST /inventario/entradas/registrar`):

- Cada línea acepta `costo_unitario` (opcional; solo cuenta en entradas y producción).
- En los ajustes la cantidad es lo contado y puede ser 0. El motivo es obligatorio.
- Se rechazan las fechas futuras (zona horaria `America/El_Salvador`), los productos repetidos y las cantidades en 0 (salvo en ajustes).
- El permiso (`inventario.ajustar` o `inventario.registrar`) se revisa antes de validar los datos.

**Productos:**

- **Precios y costos:** cambiar el precio o el costo de un producto existente requiere `productos.precios`. El encargado de inventario puede editar todo lo demás. Al crear un producto sí se fija el precio inicial.
- **Auditoría:** cada cambio de precio queda en auditoría como `cambio_precio`, con el antes y el después.
- **Stock mínimo:** se guarda desde el mismo formulario del producto.

**Alertas de stock:** un producto entra en alerta si tiene mínimo configurado (mayor que 0) y su existencia llegó a él o bajó, o si su existencia es negativa. Los productos sin mínimo no generan alerta.

## Endpoints nuevos y cambiados

| Método y ruta | Permiso | Descripción |
|---|---|---|
| `GET /inventario/stock?clase=&categoria_id=&estado=alerta\|sin_stock&buscar=` | `inventario.ver` o `inventario.registrar` | Solo productos activos que llevan inventario. Agrega unidad, costo, precio, valor y categorías |
| `PUT /inventario/stock-minimo/{producto}` | `inventario.ajustar` o `productos.gestionar` | Cambia el stock mínimo (queda en auditoría) |
| `GET /inventario/alertas` | `inventario.ver` o `productos.gestionar` | Total y hasta 50 productos en alerta |
| `GET /inventario/kardex/{producto}?desde=&hasta=&page=&per_page=` | `inventario.kardex` | Saldo inicial, movimientos en orden cronológico con saldo y costo, totales del período |
| `GET /inventario/movimientos?producto_id=&tipo=&usuario_id=&desde=&hasta=&page=` | `inventario.ver` | Historial general paginado, del más reciente al más antiguo |
| `POST /admin/unidadmedida/nuevo` y `PUT /admin/unidadmedida/actualizar/{id}` | `catalogos.gestionar` | Aceptan `abreviatura`, `tipo` y `factor_base` (opcionales; si no se envían, no cambian) |
| `POST /productos/registro` y `POST /productos/actualizar/{id}` | `productos.gestionar` | Campos nuevos; `clase`, `se_vende`, `controla_stock`, `permite_fraccion` y `precio_venta` son requeridos |

Por defecto, el kardex y el historial muestran los últimos 30 días.

## Frontend

**Producto (nuevo y editar).** Las dos pantallas usan un formulario compartido, [ProductoFormulario.tsx](../../../reactfrozenbar/src/components/productos/ProductoFormulario.tsx), con la lógica en `utils/productoForm.ts`. Incluye:

- Clase y unidad.
- Interruptores: se vende, lleva inventario, admite decimales.
- Costo, precio y margen calculado.
- Stock mínimo. Al editar, también la existencia actual y un enlace al kardex.
- Color y orden del botón en el POS, con vista previa.

Si el usuario no tiene `productos.precios`, los campos de precio y costo quedan bloqueados. También se corrigió la URL de la imagen en la edición, que no cargaba.

**Catálogo:** columnas de clase, costo, precio y existencia; filtro por clase; búsqueda por código de barras. Los botones de crear y editar solo aparecen con `productos.gestionar`.

**Inventario** (`/admin/inventario`):
- Tarjetas de resumen: productos, en alerta (al hacer clic filtra) y valor del inventario al costo.
- Filtros por clase, categoría y estado.
- Stock mínimo editable desde la tabla.
- Enlace al kardex de cada producto.

**Nuevo registro:**
- Solo ofrece los tipos que el usuario puede registrar.
- Costo opcional por línea en entradas y producción.
- Muestra la existencia y el costo promedio del producto elegido.
- No acepta decimales en productos por unidad.
- La fecha por defecto es la local. Antes usaba UTC, y de noche daba el día siguiente.

**Conteo físico** (`/admin/inventario/conteo`):
- Se anota lo contado y la pantalla muestra la diferencia en cantidad y su valor, faltante o sobrante.
- «Copiar existencias» llena lo visible con el stock del sistema, para corregir solo lo distinto.
- Antes de aplicar pide confirmación. Solo se envían los productos con diferencia.

**Kardex** (`/admin/inventario/kardex/:id`): rango de fechas con atajos, tarjetas de saldo inicial, entradas, salidas y saldo final, y tabla con documento, usuario, saldo y costo por movimiento.

**Movimientos** (`/admin/inventario/movimientos`): historial con filtros por fechas, tipo y producto.

**Unidades de medida:** ventana nueva con abreviatura, tipo y equivalencia («1 lb = 453.59237 g»).

**Encabezado:** una campana muestra cuántos productos están en alerta y la lista al abrirla. Se revisa cada 5 minutos y al entrar a inventario.

**Componentes reutilizables nuevos:**
- `common/DateRangeFilter.tsx`
- `common/Paginacion.tsx`
- `modal/UnidadMedidaModal.tsx`
- `header/AlertasStockBell.tsx`
- `utils/formato.ts`: dinero, cantidad, fecha local, nombres de clases y tipos, y `mensajeError`.
- `services/inventario.ts`: funciones sin `token`; lo agrega el interceptor.

## Pruebas

`php artisan test` corre 50 pruebas (178 aserciones). [InventarioFase1Test.php](../../tests/Feature/InventarioFase1Test.php) agrega 22:

- **Costo:** costo promedio con y sin costo, con stock negativo, y salidas valoradas al promedio.
- **Ajustes y cantidades:** ajuste a 0, motivo obligatorio, cantidad 0 rechazada, fecha futura, enteros frente a decimales, producto sin inventario.
- **Stock y alertas:** quién entra en alerta, filtros del stock, permiso del stock mínimo.
- **Kardex e historial:** saldo inicial, totales y saldos del kardex, permiso del kardex, filtros del historial.
- **Productos:** permiso de precios con su auditoría, producto sin inventario guardado como servicio, código de barras único, formato del color.
- **Unidades:** conversiones, tipos incompatibles, alta de unidades con y sin conversión.

En el frontend, `npm run build` compila sin errores. En el navegador se probó el ciclo completo:

1. Crear «Pan francés» con precio, costo, mínimo y color.
2. Ver la alerta en la campana.
3. Registrar una entrada de 120 con costo.
4. Ver el inventario valorizado (120 × $0.06 = $7.20) y la campana limpia.
5. Hacer un conteo de 112: faltante de 8, valorado en $0.48.
6. Ver el kardex: saldo inicial 0, +120, −8, saldo final 112.
7. Revisar el historial de movimientos y editar una unidad.

## Pendiente o a considerar

- **Mermas:** las mermas con motivo y la producción con descuento de materias primas se harán en la Fase 8. Por ahora el registro de producción solo suma el producto terminado.
- **Compras:** el costo de compra se registrará desde el módulo de Compras (Fase 7). Hoy se puede cargar en una entrada manual.
- **Exportar:** exportar el kardex y el inventario a Excel y PDF queda para Reportes (Fase 10).
