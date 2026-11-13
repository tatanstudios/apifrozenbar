<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Administrador;
use App\Models\Merma;
use App\Models\MovimientoInventario;
use App\Models\Produccion;
use App\Models\ProduccionConsumo;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\RecetaIngrediente;
use App\Models\UnidadesMedida;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Recetas, producción diaria y mermas.
 * La producción descuenta los insumos (consumo_produccion) y suma el producto
 * terminado (produccion) a su costo real, todo en una sola transacción.
 */
class ProduccionService
{
    // ── Recetas ───────────────────────────────────────────────

    /**
     * Cantidad de cada insumo para una tanda de la receta, en la unidad del
     * insumo y con su desperdicio esperado, y su costo con el promedio vigente.
     */
    public static function costear(Receta $receta): array
    {
        $receta->loadMissing(['ingredientes.producto.unidadMedida', 'ingredientes.unidad', 'producto.unidadMedida']);

        $lineas = $receta->ingredientes->map(function (RecetaIngrediente $i) {
            $insumo = $i->producto;
            $base = $insumo->unidadMedida;
            $cantidad = $base ? $i->unidad->convertirA((float) $i->cantidad, $base) : (float) $i->cantidad;
            $conMerma = round($cantidad * (1 + (float) $i->merma_porcentaje / 100), 4);
            $costo = round($conMerma * (float) $insumo->costo_unitario, 4);

            return [
                'id'               => $i->id,
                'producto_id'      => $insumo->id,
                'producto'         => $insumo->nombre,
                'unidad_medida_id' => $i->unidad_medida_id,
                'unidad'           => $i->unidad->abreviatura ?? $i->unidad->nombre,
                'cantidad'         => (float) $i->cantidad,
                'merma_porcentaje' => (float) $i->merma_porcentaje,
                'cantidad_base'    => $conMerma,                 // en la unidad del insumo
                'unidad_base'      => $base?->abreviatura,
                'costo_unitario'   => (float) $insumo->costo_unitario,
                'costo'            => $costo,
                'controla_stock'   => $insumo->controla_stock,
            ];
        })->all();

        $total = round(collect($lineas)->sum('costo'), 4);
        $rendimiento = (float) $receta->rendimiento;

        return [
            'ingredientes'   => $lineas,
            'costo_total'    => round($total, 2),
            'costo_unitario' => $rendimiento > 0 ? round($total / $rendimiento, 4) : 0,
        ];
    }

    /**
     * Crea o edita una receta. Si la receta ya se usó en producciones, editarla
     * crea una versión nueva (la anterior queda en el historial, inactiva).
     */
    public static function guardarReceta(array $datos, Administrador $usuario, ?Receta $receta = null): Receta
    {
        $producto = Producto::find($datos['producto_id']);
        if (!$producto || !$producto->activo) {
            throw new NegocioException('El producto no existe o está desactivado');
        }
        if ((float) $datos['rendimiento'] <= 0) {
            throw new NegocioException('El rendimiento debe ser mayor a 0');
        }
        // Lo que lleva existencia se produce (frijoles cocidos); lo que no, se descuenta al venderlo (limonada, servicios)
        $uso = $producto->controla_stock ? 'produccion' : 'venta';

        $insumos = Producto::with('unidadMedida')->whereIn('id', collect($datos['ingredientes'])->pluck('producto_id'))->get()->keyBy('id');
        $unidades = UnidadesMedida::whereIn('id', collect($datos['ingredientes'])->pluck('unidad_medida_id'))->get()->keyBy('id');

        foreach ($datos['ingredientes'] as $ing) {
            $insumo = $insumos->get($ing['producto_id']);
            $unidad = $unidades->get($ing['unidad_medida_id']);
            if (!$insumo || !$insumo->activo) {
                throw new NegocioException('Uno de los ingredientes no existe o está desactivado');
            }
            if ($insumo->id === $producto->id) {
                throw new NegocioException('Un producto no puede ser ingrediente de su propia receta');
            }
            // Un ingrediente sin existencia propia no tiene nada que descontar
            if (!$insumo->controla_stock) {
                throw new NegocioException($uso === 'venta'
                    ? "{$insumo->nombre} no lleva inventario: no se puede descontar al vender {$producto->nombre}"
                    : "{$insumo->nombre} no lleva inventario: no se puede descontar al producir {$producto->nombre}");
            }
            if (!$unidad) {
                throw new NegocioException("Indica la unidad de {$insumo->nombre}");
            }
            if ($insumo->unidadMedida) {
                $unidad->convertirA(1, $insumo->unidadMedida); // valida que se pueda convertir
            }
        }

        return DB::transaction(function () use ($datos, $usuario, $receta, $producto, $uso) {
            $campos = [
                'producto_id' => $producto->id,
                'nombre'      => $datos['nombre'] ?? $producto->nombre,
                'rendimiento' => round((float) $datos['rendimiento'], 4),
                'uso'         => $uso,
                'notas'       => $datos['notas'] ?? null,
            ];

            if ($receta) {
                $receta = Receta::whereKey($receta->id)->lockForUpdate()->first();
                if ($receta->producto_id !== $producto->id) {
                    throw new NegocioException('No se puede cambiar el producto de una receta: crea una nueva');
                }

                if ($receta->producciones()->exists()) {
                    // Ya se usó: se conserva tal cual y se crea la versión siguiente
                    $receta->update(['activa' => false]);
                    $siguiente = (int) Receta::where('producto_id', $producto->id)->max('version') + 1;
                    $receta = Receta::create([...$campos, 'version' => $siguiente, 'activa' => true, 'usuario_id' => $usuario->id]);
                } else {
                    $receta->update($campos);
                    $receta->ingredientes()->delete();
                }
            } else {
                if (Receta::where('producto_id', $producto->id)->where('activa', true)->exists()) {
                    throw new NegocioException("{$producto->nombre} ya tiene una receta activa: edítala para cambiarla");
                }
                $version = (int) Receta::where('producto_id', $producto->id)->max('version') + 1;
                $receta = Receta::create([...$campos, 'version' => $version, 'activa' => true, 'usuario_id' => $usuario->id]);
            }

            foreach ($datos['ingredientes'] as $ing) {
                RecetaIngrediente::create([
                    'receta_id'        => $receta->id,
                    'producto_id'      => $ing['producto_id'],
                    'unidad_medida_id' => $ing['unidad_medida_id'],
                    'cantidad'         => round((float) $ing['cantidad'], 4),
                    'merma_porcentaje' => round((float) ($ing['merma_porcentaje'] ?? 0), 2),
                ]);
            }

            AuditoriaService::registrar('produccion', 'guardar_receta', $receta, null,
                ['producto' => $producto->nombre, 'version' => $receta->version, 'ingredientes' => count($datos['ingredientes'])],
                "Receta de {$producto->nombre}");

            return $receta;
        });
    }

    /**
     * Insumos que salen al vender $cantidad del producto de una receta de venta,
     * en la unidad de cada insumo y con su desperdicio esperado. Ordenados por
     * producto para bloquear el inventario siempre en el mismo orden.
     *
     * @return array [['producto_id' => int, 'cantidad' => float], ...]
     */
    /**
     * Cuántas unidades de un plato con receta de venta alcanzan con lo que hay de sus
     * insumos (menos lo apartado en cuentas abiertas): lo limita el insumo que se acaba
     * primero. null si la receta no lleva insumos con inventario.
     * @param array<int, float> $stock    [insumo_id => existencia]
     * @param array<int, float> $apartado [insumo_id => cantidad]
     */
    public static function alcanza(Receta $receta, array $stock, array $apartado = []): ?float
    {
        $alcanza = null;
        // Un insumo con «Permitir vender sin existencia» (Coca-Cola) no limita: se descuenta aunque quede en negativo
        $consumo = self::consumoDeVenta($receta, 1); // carga los ingredientes con su producto
        $sinLimite = $receta->ingredientes->filter(fn (RecetaIngrediente $i) => $i->producto?->permite_sin_existencia)
            ->pluck('producto_id')->flip();
        foreach ($consumo as $i) {
            if ($sinLimite->has($i['producto_id'])) {
                continue;
            }
            $hay = max(0, (float) ($stock[$i['producto_id']] ?? 0) - (float) ($apartado[$i['producto_id']] ?? 0));
            $n = floor($hay / $i['cantidad'] + 0.000001);
            $alcanza = $alcanza === null ? $n : min($alcanza, $n);
        }
        return $alcanza;
    }

    /** Existencia de los insumos de varias recetas, para alcanza(). @return array<int, float> */
    public static function stockDeInsumos($recetas): array
    {
        $ids = collect($recetas)->flatMap(fn (Receta $r) => $r->ingredientes->pluck('producto_id'))->unique()->values();
        return $ids->isEmpty() ? [] : \App\Models\Inventario::whereIn('producto_id', $ids)->pluck('stock_actual', 'producto_id')
            ->map(fn ($v) => (float) $v)->all();
    }

    public static function consumoDeVenta(Receta $receta, float $cantidad): array
    {
        $receta->loadMissing(['ingredientes.producto.unidadMedida', 'ingredientes.unidad']);
        $rendimiento = (float) $receta->rendimiento;

        // Se multiplica antes de redondear: 2 ml por plato x 10 platos no debe perder decimales
        return $receta->ingredientes
            ->filter(fn (RecetaIngrediente $i) => $i->producto->controla_stock)
            ->groupBy('producto_id')
            ->map(fn ($grupo, $id) => [
                'producto_id' => (int) $id,
                'cantidad'    => round($grupo->sum(function (RecetaIngrediente $i) {
                    $base = $i->producto->unidadMedida;
                    $cantidad = $base ? $i->unidad->convertirA((float) $i->cantidad, $base) : (float) $i->cantidad;
                    return $cantidad * (1 + (float) $i->merma_porcentaje / 100);
                }) / $rendimiento * $cantidad, 4),
            ])
            ->filter(fn ($i) => $i['cantidad'] > 0)
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * Lo que lleva UNA unidad vendida, por insumo y en la unidad del inventario
     * (sin merma): Huevos Divorciados → 2 u Huevo, 2 u Tostada. Es lo que el
     * mesero puede quitar o aumentar ("solo 1 huevo") sin cambiar el precio.
     * @return array [['producto_id', 'nombre', 'base', 'unidad'], ...]
     */
    public static function ingredientesPorUnidad(Receta $receta): array
    {
        $receta->loadMissing(['ingredientes.producto.unidadMedida', 'ingredientes.unidad']);
        $rendimiento = (float) $receta->rendimiento ?: 1;

        return $receta->ingredientes
            ->filter(fn (RecetaIngrediente $i) => $i->producto->controla_stock)
            ->groupBy('producto_id')
            ->map(function ($grupo, $id) use ($rendimiento) {
                $producto = $grupo->first()->producto;
                // Se muestra en la unidad en que se escribió la receta (10 g de azúcar, no 0.022 lb),
                // que es como lo mide quien prepara. Si el insumo aparece con unidades distintas,
                // se usa la del inventario. Los ajustes se guardan como proporción de esta base,
                // así que el descuento de inventario no depende de la unidad que se muestre.
                $unidadReceta = $grupo->pluck('unidad')->filter()->unique('id');
                $destino = $unidadReceta->count() === 1 ? $unidadReceta->first() : $producto->unidadMedida;
                $base = $grupo->sum(fn (RecetaIngrediente $i) => $destino && $i->unidad
                    ? $i->unidad->convertirA((float) $i->cantidad, $destino) : (float) $i->cantidad);

                return [
                    'producto_id' => (int) $id,
                    'nombre'      => $producto->nombre,
                    'base'        => round($base / $rendimiento, 4),
                    'unidad'      => $destino?->abreviatura,
                ];
            })
            ->filter(fn ($i) => $i['base'] > 0)
            ->sortBy('nombre')
            ->values()
            ->all();
    }

    // Activa una versión (la que estaba activa del mismo producto se desactiva) o la desactiva
    public static function activarReceta(Receta $receta, bool $activa): Receta
    {
        return DB::transaction(function () use ($receta, $activa) {
            if ($activa) {
                Receta::where('producto_id', $receta->producto_id)->where('id', '!=', $receta->id)->update(['activa' => false]);
            }
            $receta->update(['activa' => $activa]);

            return $receta;
        });
    }

    // ── Producción ────────────────────────────────────────────

    /**
     * Registra una producción. El consumo teórico sale de la receta para la
     * cantidad planificada; el real puede corregirse por insumo.
     *
     * $datos: uuid, receta_id, fecha, cantidad_producida, cantidad_planificada?, observaciones?,
     *         consumos? [['producto_id', 'cantidad_real'], ...]
     */
    public static function producir(array $datos, Administrador $usuario): Produccion
    {
        if ($existente = Produccion::where('uuid', $datos['uuid'])->first()) {
            return $existente;
        }

        $receta = Receta::with(['producto', 'ingredientes.producto.unidadMedida', 'ingredientes.unidad'])->find($datos['receta_id']);
        if (!$receta) {
            throw new NegocioException('La receta no existe');
        }
        if (!$receta->activa) {
            throw new NegocioException('Esa versión de la receta ya no está activa');
        }
        if ($receta->esDeVenta()) {
            throw new NegocioException("La receta de {$receta->producto->nombre} se descuenta al vender: no se produce");
        }

        $fecha = Carbon::parse($datos['fecha'] ?? today());
        if ($fecha->gt(today())) {
            throw new NegocioException('La fecha de producción no puede ser futura');
        }

        $producto = $receta->producto;
        $producida = round((float) $datos['cantidad_producida'], 4);
        $planificada = round((float) ($datos['cantidad_planificada'] ?? $producida), 4);
        if ($producida <= 0 || $planificada <= 0) {
            throw new NegocioException('Las cantidades deben ser mayores a 0');
        }
        if (!$producto->permite_fraccion && (floor($producida) != $producida)) {
            throw new NegocioException("{$producto->nombre} se produce en unidades enteras");
        }

        $tandas = $planificada / (float) $receta->rendimiento;
        $reales = collect($datos['consumos'] ?? [])->keyBy('producto_id');
        $costeo = collect(self::costear($receta)['ingredientes']);

        try {
            return DB::transaction(function () use ($datos, $usuario, $receta, $producto, $fecha, $producida, $planificada, $tandas, $reales, $costeo) {
                $numero = CorrelativoService::siguiente('produccion', 'PR');

                $produccion = Produccion::create([
                    'uuid'                 => $datos['uuid'],
                    'numero'               => $numero,
                    'fecha'                => $fecha->toDateString(),
                    'receta_id'            => $receta->id,
                    'producto_id'          => $producto->id,
                    'cantidad_planificada' => $planificada,
                    'cantidad_producida'   => $producida,
                    'costo_total'          => 0,
                    'costo_unitario'       => 0,
                    'estado'               => 'registrada',
                    'observaciones'        => $datos['observaciones'] ?? null,
                    'usuario_id'           => $usuario->id,
                ]);

                // Un insumo puede repetirse en la receta: se consume una sola vez por producto
                $porInsumo = $costeo->groupBy('producto_id');
                $costoTotal = 0.0;

                foreach ($porInsumo as $insumoId => $lineas) {
                    $teorica = round($lineas->sum('cantidad_base') * $tandas, 4);
                    $real = $reales->has($insumoId) ? round((float) $reales[$insumoId]['cantidad_real'], 4) : $teorica;
                    if ($real < 0) {
                        throw new NegocioException('El consumo real no puede ser negativo');
                    }

                    $insumo = $lineas->first();
                    $costo = (float) $insumo['costo_unitario'];

                    if ($real > 0 && $insumo['controla_stock']) {
                        $mov = InventarioService::aplicarMovimiento(
                            productoId:     $insumoId,
                            tipo:           'consumo_produccion',
                            cantidad:       $real,
                            motivo:         "Producción {$numero} de {$producto->nombre}",
                            referenciaTipo: 'produccion',
                            referenciaId:   $produccion->id,
                            usuarioId:      $usuario->id,
                        );
                        $costo = (float) $mov->costo_unitario;
                    }

                    ProduccionConsumo::create([
                        'produccion_id'    => $produccion->id,
                        'producto_id'      => $insumoId,
                        'cantidad_teorica' => $teorica,
                        'cantidad_real'    => $real,
                        'costo_unitario'   => $costo,
                        'costo_total'      => round($real * $costo, 4),
                    ]);
                    $costoTotal += $real * $costo;
                }

                // Insumos que se usaron aunque no están en la receta
                foreach ($reales->except($porInsumo->keys()->all()) as $insumoId => $extra) {
                    $insumo = Producto::find($insumoId);
                    $real = round((float) $extra['cantidad_real'], 4);
                    if (!$insumo || $real <= 0) {
                        continue;
                    }
                    $costo = (float) $insumo->costo_unitario;
                    if ($insumo->controla_stock) {
                        $costo = (float) InventarioService::aplicarMovimiento(
                            productoId: $insumo->id, tipo: 'consumo_produccion', cantidad: $real,
                            motivo: "Producción {$numero} de {$producto->nombre} (fuera de receta)",
                            referenciaTipo: 'produccion', referenciaId: $produccion->id, usuarioId: $usuario->id,
                        )->costo_unitario;
                    }
                    ProduccionConsumo::create([
                        'produccion_id' => $produccion->id, 'producto_id' => $insumo->id, 'cantidad_teorica' => 0,
                        'cantidad_real' => $real, 'costo_unitario' => $costo, 'costo_total' => round($real * $costo, 4),
                    ]);
                    $costoTotal += $real * $costo;
                }

                $costoUnitario = round($costoTotal / $producida, 4);

                if ($producto->controla_stock) {
                    InventarioService::aplicarMovimiento(
                        productoId:     $producto->id,
                        tipo:           'produccion',
                        cantidad:       $producida,
                        motivo:         "Producción {$numero}",
                        referenciaTipo: 'produccion',
                        referenciaId:   $produccion->id,
                        usuarioId:      $usuario->id,
                        costoUnitario:  $costoUnitario,
                    );
                } else {
                    $producto->update(['costo_unitario' => $costoUnitario]);
                }

                $produccion->update(['costo_total' => round($costoTotal, 4), 'costo_unitario' => $costoUnitario]);

                AuditoriaService::registrar('produccion', 'producir', $produccion, null, [
                    'producto'    => $producto->nombre,
                    'planificada' => $planificada,
                    'producida'   => $producida,
                    'costo_total' => round($costoTotal, 2),
                ], "Producción {$numero}");

                return $produccion;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($existente = Produccion::where('uuid', $datos['uuid'])->first()) {
                return $existente;
            }
            throw $e;
        }
    }

    /**
     * Anula una producción: saca el producto terminado y devuelve los insumos a
     * su costo de consumo.
     */
    public static function anularProduccion(Produccion $produccion, string $motivo, Administrador $usuario): Produccion
    {
        return DB::transaction(function () use ($produccion, $motivo, $usuario) {
            $produccion = Produccion::whereKey($produccion->id)->lockForUpdate()->first();
            if ($produccion->estado === 'anulada') {
                throw new NegocioException('La producción ya está anulada');
            }

            $movimientos = MovimientoInventario::where('referencia_tipo', 'produccion')->where('referencia_id', $produccion->id)->get();

            foreach ($movimientos->where('tipo', 'produccion') as $m) {
                InventarioService::aplicarMovimiento(
                    productoId: $m->producto_id, tipo: 'salida', cantidad: (float) $m->cantidad,
                    motivo: "Anulación de producción {$produccion->numero}: {$motivo}",
                    referenciaTipo: 'produccion', referenciaId: $produccion->id, usuarioId: $usuario->id,
                );
            }
            foreach ($movimientos->where('tipo', 'consumo_produccion') as $m) {
                InventarioService::aplicarMovimiento(
                    productoId: $m->producto_id, tipo: 'anulacion', cantidad: (float) $m->cantidad,
                    motivo: "Anulación de producción {$produccion->numero}: {$motivo}",
                    referenciaTipo: 'produccion', referenciaId: $produccion->id, usuarioId: $usuario->id,
                    costoUnitario: (float) $m->costo_unitario,
                );
            }

            $produccion->update([
                'estado'           => 'anulada',
                'anulada_por'      => $usuario->id,
                'anulada_at'       => now(),
                'motivo_anulacion' => $motivo,
            ]);

            AuditoriaService::registrar('produccion', 'anular_produccion', $produccion,
                ['estado' => 'registrada'], ['estado' => 'anulada'], $motivo);

            return $produccion;
        });
    }

    // ── Mermas ────────────────────────────────────────────────

    /**
     * Registra un lote de mermas (por ejemplo, el producto que no se vendió al
     * cerrar el día). Cada línea es idempotente por su uuid.
     *
     * @param array $items [['uuid', 'producto_id', 'cantidad', 'motivo', 'detalle'?], ...]
     */
    public static function registrarMermas(string $fecha, array $items, Administrador $usuario): array
    {
        $fecha = Carbon::parse($fecha);
        if ($fecha->gt(today())) {
            throw new NegocioException('La fecha de la merma no puede ser futura');
        }

        return DB::transaction(function () use ($fecha, $items, $usuario) {
            $registradas = [];

            foreach ($items as $item) {
                if ($existente = Merma::where('uuid', $item['uuid'])->first()) {
                    $registradas[] = $existente;
                    continue;
                }

                $cantidad = round((float) $item['cantidad'], 4);
                if ($cantidad <= 0) {
                    continue;
                }

                $producto = Producto::find($item['producto_id']);
                if (!$producto) {
                    throw new NegocioException('Uno de los productos no existe');
                }

                $merma = Merma::create([
                    'uuid'           => $item['uuid'],
                    'fecha'          => $fecha->toDateString(),
                    'producto_id'    => $producto->id,
                    'cantidad'       => $cantidad,
                    'motivo'         => $item['motivo'],
                    'detalle'        => $item['detalle'] ?? null,
                    'costo_unitario' => (float) $producto->costo_unitario,
                    'costo_total'    => round($cantidad * (float) $producto->costo_unitario, 4),
                    'usuario_id'     => $usuario->id,
                ]);

                if ($producto->controla_stock) {
                    $mov = InventarioService::aplicarMovimiento(
                        productoId:     $producto->id,
                        tipo:           'merma',
                        cantidad:       $cantidad,
                        motivo:         'Merma: ' . Merma::MOTIVOS[$item['motivo']] . (!empty($item['detalle']) ? " ({$item['detalle']})" : ''),
                        referenciaTipo: 'merma',
                        referenciaId:   $merma->id,
                        usuarioId:      $usuario->id,
                    );
                    $merma->update([
                        'costo_unitario' => (float) $mov->costo_unitario,
                        'costo_total'    => round($cantidad * (float) $mov->costo_unitario, 4),
                    ]);
                }

                $registradas[] = $merma;
            }

            if ($registradas) {
                AuditoriaService::registrar('produccion', 'mermas', null, null, [
                    'fecha'  => $fecha->toDateString(),
                    'lineas' => count($registradas),
                    'costo'  => round(collect($registradas)->sum('costo_total'), 2),
                ], 'Registro de mermas');
            }

            return $registradas;
        });
    }

    public static function anularMerma(Merma $merma, string $motivo, Administrador $usuario): Merma
    {
        return DB::transaction(function () use ($merma, $motivo, $usuario) {
            $merma = Merma::whereKey($merma->id)->lockForUpdate()->first();
            if ($merma->anulada) {
                throw new NegocioException('La merma ya está anulada');
            }

            if ($merma->producto->controla_stock) {
                InventarioService::aplicarMovimiento(
                    productoId: $merma->producto_id, tipo: 'anulacion', cantidad: (float) $merma->cantidad,
                    motivo: "Anulación de merma: {$motivo}", referenciaTipo: 'merma', referenciaId: $merma->id,
                    usuarioId: $usuario->id, costoUnitario: (float) $merma->costo_unitario,
                );
            }

            $merma->update(['anulada' => true, 'anulada_por' => $usuario->id, 'anulada_at' => now(), 'motivo_anulacion' => $motivo]);
            AuditoriaService::registrar('produccion', 'anular_merma', $merma, ['anulada' => false], ['anulada' => true], $motivo);

            return $merma;
        });
    }

    // ── Resumen ───────────────────────────────────────────────

    /**
     * Producción, rendimiento real frente al planificado, diferencias de
     * consumo por insumo y mermas del periodo.
     */
    public static function resumen(Carbon $desde, Carbon $hasta): array
    {
        $producciones = Produccion::with('producto:id,nombre,unidad_medida_id', 'producto.unidadMedida:id,abreviatura')
            ->where('estado', 'registrada')->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->get();

        $porProducto = $producciones->groupBy('producto_id')->map(function ($grupo) {
            $planificada = (float) $grupo->sum('cantidad_planificada');
            $producida = (float) $grupo->sum('cantidad_producida');
            $costo = (float) $grupo->sum('costo_total');
            return [
                'producto_id'    => $grupo->first()->producto_id,
                'producto'       => $grupo->first()->producto?->nombre,
                'unidad'         => $grupo->first()->producto?->unidadMedida?->abreviatura,
                'producciones'   => $grupo->count(),
                'planificada'    => round($planificada, 4),
                'producida'      => round($producida, 4),
                'rendimiento'    => $planificada > 0 ? round($producida / $planificada * 100, 1) : null,
                'costo_total'    => round($costo, 2),
                'costo_unitario' => $producida > 0 ? round($costo / $producida, 4) : 0,
            ];
        })->sortByDesc('producida')->values();

        $consumos = ProduccionConsumo::with('producto:id,nombre,unidad_medida_id', 'producto.unidadMedida:id,abreviatura')
            ->whereIn('produccion_id', $producciones->pluck('id'))->get()
            ->groupBy('producto_id')->map(function ($grupo) {
                $teorica = (float) $grupo->sum('cantidad_teorica');
                $real = (float) $grupo->sum('cantidad_real');
                return [
                    'producto_id' => $grupo->first()->producto_id,
                    'producto'    => $grupo->first()->producto?->nombre,
                    'unidad'      => $grupo->first()->producto?->unidadMedida?->abreviatura,
                    'teorica'     => round($teorica, 4),
                    'real'        => round($real, 4),
                    'diferencia'  => round($real - $teorica, 4),
                    'costo'       => round((float) $grupo->sum('costo_total'), 2),
                ];
            })->sortByDesc(fn ($c) => abs($c['diferencia']))->values();

        $mermas = Merma::with('producto:id,nombre')
            ->where('anulada', false)->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->get();

        return [
            'desde'        => $desde->toDateString(),
            'hasta'        => $hasta->toDateString(),
            'totales'      => [
                'producciones'  => $producciones->count(),
                'costo'         => round((float) $producciones->sum('costo_total'), 2),
                'mermas'        => $mermas->count(),
                'costo_mermas'  => round((float) $mermas->sum('costo_total'), 2),
            ],
            'por_producto' => $porProducto->all(),
            'consumos'     => $consumos->all(),
            'mermas'       => [
                'por_motivo'   => $mermas->groupBy('motivo')->map(fn ($g, $motivo) => [
                    'motivo' => $motivo, 'texto' => Merma::MOTIVOS[$motivo], 'cantidad' => $g->count(), 'costo' => round((float) $g->sum('costo_total'), 2),
                ])->values()->all(),
                'por_producto' => $mermas->groupBy('producto_id')->map(fn ($g) => [
                    'producto_id' => $g->first()->producto_id, 'producto' => $g->first()->producto?->nombre,
                    'cantidad' => round((float) $g->sum('cantidad'), 4), 'costo' => round((float) $g->sum('costo_total'), 2),
                ])->sortByDesc('costo')->values()->all(),
            ],
            'por_dia'      => $producciones->groupBy(fn ($p) => $p->fecha->toDateString())->map(fn ($g, $dia) => [
                'fecha' => $dia, 'producciones' => $g->count(), 'costo' => round((float) $g->sum('costo_total'), 2),
                'mermas' => round((float) $mermas->filter(fn ($m) => $m->fecha->toDateString() === $dia)->sum('costo_total'), 2),
            ])->sortKeys()->values()->all(),
        ];
    }
}
