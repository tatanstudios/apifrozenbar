<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaDetalle extends Model
{
    protected $table = 'ventas_detalle';

    protected $fillable = [
        'venta_id',
        'producto_id',
        'nombre_producto',
        'extras',
        'unidad',
        'cantidad',
        'precio_unitario',
        'descuento_tipo',
        'descuento_valor',
        'descuento',
        'subtotal',
        'costo_unitario',
        'consumo_interno',
        'cantidad_devuelta',
    ];

    protected $casts = [
        'extras'            => 'array',
        'cantidad'          => 'decimal:4',
        'precio_unitario'   => 'decimal:2',
        'descuento_valor'   => 'decimal:2',
        'descuento'         => 'decimal:2',
        'subtotal'          => 'decimal:2',
        'costo_unitario'    => 'decimal:4',
        'consumo_interno'   => 'boolean',
        'cantidad_devuelta' => 'decimal:4',
    ];

    public function venta()
    {
        return $this->belongsTo(Venta::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function cantidadDisponibleParaDevolver(): float
    {
        return round((float) $this->cantidad - (float) $this->cantidad_devuelta, 4);
    }

    /**
     * Para el ticket: [nombre del producto, extras uno por renglón].
     * Si la línea no guardó sus extras (ventas de mesa o anteriores) se separan del nombre
     * «Café americano (Con leche, Sin azúcar) · nota», solo cuando empieza con el nombre del producto.
     *
     * @return array{0: string, 1: string[]}
     */
    public function partesTicket(): array
    {
        $nombre = (string) $this->nombre_producto;
        $base = $this->producto?->nombre;
        $extras = array_values(array_filter($this->extras ?? [], fn ($e) => trim((string) $e) !== ''));
        if ($extras) {
            return [$base ?? $nombre, $extras];
        }
        if (!$base || $nombre === $base) {
            return [$nombre, []];
        }

        if (str_starts_with($nombre, $base . ' · ')) {
            return [$base, [mb_substr($nombre, mb_strlen($base) + 3)]];
        }
        if (!str_starts_with($nombre, $base . ' (')) {
            return [$nombre, []];
        }

        $resto = mb_substr($nombre, mb_strlen($base) + 2);
        $nota = null;
        $corte = mb_strrpos($resto, ') · ');
        if ($corte !== false) {
            $nota = trim(mb_substr($resto, $corte + 4));
            $resto = mb_substr($resto, 0, $corte);
        } elseif (str_ends_with($resto, ')')) {
            $resto = mb_substr($resto, 0, -1);
        } else {
            return [$nombre, []];
        }

        $partes = array_values(array_filter(array_map('trim', explode(', ', $resto)), fn ($e) => $e !== ''));
        if ($nota) {
            $partes[] = $nota;
        }

        return [$base, $partes];
    }
}
