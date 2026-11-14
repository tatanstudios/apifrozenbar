<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\ConfiguracionNegocio;
use App\Models\EntradaInventario;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use Illuminate\Support\Facades\DB;
use App\Models\EntradaInventarioDetalle;

class InventarioService
{
    /**
     * Registra una entrada/salida/ajuste con su desglose de productos.
     *
     * @param string $tipo        entrada|salida|ajuste|produccion
     * @param string $fecha       Y-m-d
     * @param array  $items       [['producto_id' => x, 'cantidad' => y, 'costo_unitario' => z?], ...]
     *                            En "ajuste", cantidad es el stock contado (la cantidad final).
     * @param string|null $descripcion
     * @param int|null    $usuarioId
     */
    public static function registrarEntrada(
        string  $tipo,
        string  $fecha,
        array   $items,
        ?string $descripcion = null,
        ?int    $usuarioId   = null,
        ?int    $autorizadoPor = null
    ): EntradaInventario {
        return DB::transaction(function () use (
            $tipo, $fecha, $items, $descripcion, $usuarioId, $autorizadoPor
        ) {
            // 1) Crear cabecera
            $entrada = EntradaInventario::create([
                'fecha'          => $fecha,
                'tipo'           => $tipo,
                'descripcion'    => $descripcion,
                'usuario_id'     => $usuarioId,
                'autorizado_por' => $autorizadoPor,
            ]);

            // 2) Procesar cada producto del desglose
            foreach ($items as $item) {
                $costo = $item['costo_unitario'] ?? null;

                $movimiento = self::aplicarMovimiento(
                    productoId:     (int) $item['producto_id'],
                    tipo:           $tipo,
                    cantidad:       (float) $item['cantidad'],
                    motivo:         $descripcion,
                    referenciaTipo: 'entrada_inventario',
                    referenciaId:   $entrada->id,
                    usuarioId:      $usuarioId,
                    costoUnitario:  $costo === null || $costo === '' ? null : (float) $costo,
                );

                // Guardar detalle de la entrada
                EntradaInventarioDetalle::create([
                    'entrada_id'       => $entrada->id,
                    'producto_id'      => $movimiento->producto_id,
                    'cantidad'         => $movimiento->cantidad,
                    'stock_anterior'   => $movimiento->stock_anterior,
                    'stock_resultante' => $movimiento->stock_resultante,
                ]);
            }

            return $entrada;
        });
    }

    /**
     * Único punto de entrada para modificar el stock de un producto.
     * Bloquea el producto y su fila de inventario, calcula el nuevo stock, actualiza
     * el costo promedio si entra mercadería con costo y deja el movimiento en el
     * historial (kardex). Lo usan inventario, ventas, compras y producción.
     *
     * $costoUnitario solo se usa en movimientos que suman: recalcula el costo
     * promedio ponderado del producto. En los demás se usa el costo promedio vigente.
     *
     * Debe llamarse dentro de una transacción.
     */
    public static function aplicarMovimiento(
        int     $productoId,
        string  $tipo,
        float   $cantidad,
        ?string $motivo = null,
        ?string $referenciaTipo = null,
        ?int    $referenciaId = null,
        ?int    $usuarioId = null,
        ?float  $costoUnitario = null,
        bool    $forzarNegativo = false, // ventas sin conexión: ya ocurrieron, no se pueden rechazar
        bool    $calculado = false,      // cantidad que sale de una receta (2 ml de aceite, media docena): admite fracción
    ): MovimientoInventario {
        // Siempre en el mismo orden (producto → inventario) para no provocar bloqueos cruzados
        $producto = Producto::whereKey($productoId)->lockForUpdate()->first();

        if (!$producto) {
            throw new NegocioException("El producto #{$productoId} no existe");
        }

        if (!$producto->controla_stock) {
            throw new NegocioException("{$producto->nombre} no lleva control de inventario");
        }

        if (!$calculado && !$producto->permite_fraccion && floor($cantidad) != $cantidad) {
            throw new NegocioException("{$producto->nombre} solo admite cantidades enteras");
        }

        // Asegurar que exista la fila de inventario y bloquearla
        Inventario::firstOrCreate(
            ['producto_id' => $productoId],
            ['stock_actual' => 0, 'stock_minimo' => 0]
        );
        $inventario = Inventario::where('producto_id', $productoId)->lockForUpdate()->first();

        $stockAnterior = (float) $inventario->stock_actual;

        // Calcular nuevo stock según tipo
        $stockResultante = match (true) {
            in_array($tipo, MovimientoInventario::TIPOS_SUMAN, true)  => $stockAnterior + $cantidad,
            in_array($tipo, MovimientoInventario::TIPOS_RESTAN, true) => $stockAnterior - $cantidad,
            $tipo === 'ajuste'                                        => $cantidad,
            default => throw new NegocioException("Tipo de movimiento no válido: {$tipo}"),
        };

        // Un producto marcado «Permitir vender sin existencia» (Coca-Cola) puede quedar en negativo al
        // venderse aunque el negocio no lo permita; sus mermas, ajustes y salidas siguen bloqueando
        $vendeSinExistencia = $producto->permite_sin_existencia && in_array($tipo, ['venta', 'consumo_interno'], true);
        if ($stockResultante < 0 && !$forzarNegativo && !$vendeSinExistencia && !ConfiguracionNegocio::actual()->permitir_stock_negativo) {
            throw new NegocioException(
                "Stock insuficiente para {$producto->nombre}: disponible " . self::formato($stockAnterior)
                . ', solicitado ' . self::formato($cantidad)
            );
        }

        // Costo del movimiento y costo promedio ponderado
        $costoPromedio = (float) $producto->costo_unitario;
        $costoMovimiento = $costoPromedio;

        if ($costoUnitario !== null && in_array($tipo, MovimientoInventario::TIPOS_SUMAN, true) && $cantidad > 0) {
            // Un stock negativo no aporta costo: se promedia solo contra lo que realmente había
            $base = max($stockAnterior, 0);
            $producto->costo_unitario = round(
                (($base * $costoPromedio) + ($cantidad * $costoUnitario)) / ($base + $cantidad),
                4
            );
            $producto->save();
            $costoMovimiento = $costoUnitario;
        }

        // Actualizar stock
        $inventario->stock_actual = $stockResultante;
        $inventario->save();

        // Aviso si el producto acaba de llegar a su mínimo o de quedar en negativo
        NotificacionService::revisarStock($producto, $stockAnterior, $stockResultante, (float) $inventario->stock_minimo);

        // Registrar movimiento (historial completo)
        return MovimientoInventario::create([
            'producto_id'      => $productoId,
            'tipo'             => $tipo,
            'cantidad'         => $cantidad,
            'stock_anterior'   => $stockAnterior,
            'stock_resultante' => $stockResultante,
            'costo_unitario'   => $costoMovimiento,
            'costo_total'      => round(abs($stockResultante - $stockAnterior) * $costoMovimiento, 4),
            'motivo'           => $motivo,
            'referencia_id'    => $referenciaId,
            'referencia_tipo'  => $referenciaTipo,
            'usuario_id'       => $usuarioId,
        ]);
    }

    /**
     * Insumos de receta que una salida o un ajuste manual haría bajar. Esos solo
     * deben bajar con las ventas y la producción ("se acabó el aceite" no basta):
     * bajarlos a mano solo lo puede hacer quien tiene el permiso (el administrador).
     *
     * @return array nombres de los productos
     */
    public static function bajasDeInsumos(string $tipo, array $items): array
    {
        if (!in_array($tipo, ['salida', 'ajuste'], true)) {
            return [];
        }

        $ids = collect($items)->pluck('producto_id')->map(fn ($id) => (int) $id);
        $insumos = Producto::whereIn('id', $ids)
            ->whereHas('enRecetas', fn ($q) => $q->where('activa', true))
            ->with('inventario:producto_id,stock_actual')
            ->get()->keyBy('id');

        return collect($items)
            ->filter(function ($item) use ($tipo, $insumos) {
                $insumo = $insumos->get((int) $item['producto_id']);
                return $insumo && ($tipo === 'salida'
                    || (float) $item['cantidad'] < (float) ($insumo->inventario?->stock_actual ?? 0));
            })
            ->map(fn ($item) => $insumos->get((int) $item['producto_id'])->nombre)
            ->values()->all();
    }

    /**
     * Productos activos con existencia en su mínimo o por debajo (o en negativo),
     * primero los que están más lejos de su mínimo. Es también la lista de compras
     * del cierre de turno: lo que está arriba del mínimo no aparece.
     *
     * Los productos con «Permitir vender sin existencia» (Coca-Cola, golosinas) no entran: su
     * existencia no se sigue (quedan en negativo a propósito), igual que no generan avisos.
     */
    public static function productosEnAlerta(): array
    {
        return Producto::query()
            ->join('inventario', 'inventario.producto_id', '=', 'productos.id')
            ->leftJoin('unidades_medida', 'unidades_medida.id', '=', 'productos.unidad_medida_id')
            ->where('productos.activo', true)
            ->where('productos.controla_stock', true)
            ->where('productos.permite_sin_existencia', false)
            ->where(fn ($q) => $q->where('inventario.stock_actual', '<', 0)
                ->orWhere(fn ($w) => $w->where('inventario.stock_minimo', '>', 0)
                    ->whereColumn('inventario.stock_actual', '<=', 'inventario.stock_minimo')))
            ->orderByRaw('inventario.stock_actual - inventario.stock_minimo')
            ->get([
                'productos.id as producto_id',
                'productos.nombre as producto',
                'productos.clase',
                'inventario.stock_actual',
                'inventario.stock_minimo',
                'unidades_medida.abreviatura as unidad',
            ])
            ->map(fn ($p) => [
                'producto_id'  => $p->producto_id,
                'producto'     => $p->producto,
                'clase'        => $p->clase,
                'unidad'       => $p->unidad,
                'stock_actual' => (float) $p->stock_actual,
                'stock_minimo' => (float) $p->stock_minimo,
                // Lo que falta para volver al mínimo
                'faltante'     => round(max((float) $p->stock_minimo - (float) $p->stock_actual, 0), 4),
            ])
            ->all();
    }

    /**
     * Un producto está en alerta si tiene mínimo configurado y lo alcanzó, o si quedó en negativo.
     */
    public static function enAlerta(float $stockActual, float $stockMinimo): bool
    {
        return $stockActual < 0 || ($stockMinimo > 0 && $stockActual <= $stockMinimo);
    }

    private static function formato(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 4, '.', ''), '0'), '.');
    }
}
