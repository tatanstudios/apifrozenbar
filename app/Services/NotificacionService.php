<?php

namespace App\Services;

use App\Models\Administrador;
use App\Models\Inventario;
use App\Models\Notificacion;
use App\Models\Producto;
use Illuminate\Support\Collection;

/**
 * Notificaciones guardadas por usuario. Hoy solo avisa de stock: se crea una
 * cuando un producto ENTRA en alerta (no en cada venta mientras sigue bajo),
 * así el usuario puede marcarla como vista o borrarla y no vuelve a aparecer
 * hasta que el producto se reponga y vuelva a bajar.
 */
class NotificacionService
{
    public const PERMISOS_STOCK = ['inventario.ver', 'productos.gestionar'];

    /**
     * Revisa el cambio de stock de un producto y avisa si acaba de entrar en
     * alerta o si acaba de quedar en negativo.
     */
    public static function revisarStock(
        Producto $producto,
        float $stockAnterior,
        float $stockActual,
        float $stockMinimo,
        ?float $minimoAnterior = null, // cuando lo que cambió fue el mínimo
    ): void {
        $antes = InventarioService::enAlerta($stockAnterior, $minimoAnterior ?? $stockMinimo);
        $ahora = InventarioService::enAlerta($stockActual, $stockMinimo);

        $tipo = match (true) {
            $stockActual < 0 && $stockAnterior >= 0 => 'stock_negativo',
            $ahora && !$antes                       => 'stock_bajo',
            default                                 => null,
        };

        if ($tipo === null) {
            return;
        }

        [$titulo, $mensaje] = self::textoStock($producto, $tipo, $stockActual, $stockMinimo);

        foreach (self::destinatariosStock() as $usuario) {
            // Si ya tiene un aviso sin leer de este producto, se actualiza en vez de repetirlo
            $existente = Notificacion::where('usuario_id', $usuario->id)
                ->where('producto_id', $producto->id)
                ->whereIn('tipo', ['stock_bajo', 'stock_negativo'])
                ->noLeidas()
                ->first();

            if ($existente) {
                $existente->fill(['tipo' => $tipo, 'titulo' => $titulo, 'mensaje' => $mensaje]);
                $existente->created_at = now();
                $existente->save();
                continue;
            }

            Notificacion::create([
                'usuario_id'  => $usuario->id,
                'tipo'        => $tipo,
                'titulo'      => $titulo,
                'mensaje'     => $mensaje,
                'producto_id' => $producto->id,
                'enlace'      => '/admin/inventario',
            ]);
        }
    }

    /**
     * Crea un aviso para cada producto que ya está en alerta y que el usuario
     * no tiene sin leer. Sirve para arrancar (alertas que existían antes de las
     * notificaciones) y para quien recién recibe el permiso.
     *
     * @return int notificaciones creadas
     */
    public static function sincronizarStock(): int
    {
        $creadas = 0;
        $productos = Producto::with(['inventario', 'unidadMedida'])
            ->whereIn('id', collect(InventarioService::productosEnAlerta())->pluck('producto_id'))
            ->get();

        foreach ($productos as $producto) {
            $stock  = (float) $producto->inventario->stock_actual;
            $minimo = (float) $producto->inventario->stock_minimo;

            foreach (self::destinatariosStock() as $usuario) {
                $tiene = Notificacion::where('usuario_id', $usuario->id)
                    ->where('producto_id', $producto->id)
                    ->whereIn('tipo', ['stock_bajo', 'stock_negativo'])
                    ->exists();
                if ($tiene) {
                    continue;
                }

                $tipo = $stock < 0 ? 'stock_negativo' : 'stock_bajo';
                [$titulo, $mensaje] = self::textoStock($producto, $tipo, $stock, $minimo);

                Notificacion::create([
                    'usuario_id'  => $usuario->id,
                    'tipo'        => $tipo,
                    'titulo'      => $titulo,
                    'mensaje'     => $mensaje,
                    'producto_id' => $producto->id,
                    'enlace'      => '/admin/inventario',
                ]);
                $creadas++;
            }
        }

        return $creadas;
    }

    /**
     * Usuarios activos que pueden ver inventario (el admin siempre).
     */
    private static function destinatariosStock(): Collection
    {
        // once(): una consulta por petición aunque una venta mueva muchos productos
        return once(fn () => Administrador::where('estado', true)->get()
            ->filter(fn (Administrador $u) => $u->hasRole('admin')
                || collect(self::PERMISOS_STOCK)->contains(fn ($p) => self::tienePermiso($u, $p)))
            ->values());
    }

    private static function tienePermiso(Administrador $usuario, string $permiso): bool
    {
        try {
            return $usuario->hasPermissionTo($permiso, 'api');
        } catch (\Throwable) {
            return false; // el permiso no existe en esta base
        }
    }

    /** @return array{0: string, 1: string} título y mensaje del aviso */
    private static function textoStock(Producto $producto, string $tipo, float $stock, float $minimo): array
    {
        $unidad = $producto->unidadMedida?->abreviatura;
        $cantidad = self::formato($stock) . ($unidad ? " {$unidad}" : '');

        return match (true) {
            $tipo === 'stock_negativo' => ["{$producto->nombre} quedó en negativo", "Existencia: {$cantidad}. Registra la entrada o ajusta el conteo."],
            $stock == 0                => ["{$producto->nombre} se agotó", 'Mínimo: ' . self::formato($minimo) . ($unidad ? " {$unidad}" : '') . '.'],
            default                    => ["{$producto->nombre} llegó a su mínimo", "Quedan {$cantidad} (mínimo " . self::formato($minimo) . ').'],
        };
    }

    private static function formato(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 4, '.', ''), '0'), '.');
    }
}
