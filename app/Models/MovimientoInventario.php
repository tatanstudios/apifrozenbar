<?php

namespace App\Models;

use App\Models\Concerns\RegistraTurno;
use Illuminate\Database\Eloquent\Model;

class MovimientoInventario extends Model
{
    use RegistraTurno;

    protected $table = 'movimientos_inventario';

    // Tipos que suman al stock y tipos que restan ("ajuste" fija una cantidad exacta)
    const TIPOS_SUMAN  = ['entrada', 'produccion', 'compra', 'devolucion', 'anulacion'];
    const TIPOS_RESTAN = ['salida', 'venta', 'merma', 'consumo_produccion', 'consumo_interno'];

    const TIPOS = [
        'entrada'            => 'Entrada',
        'salida'             => 'Salida',
        'ajuste'             => 'Ajuste',
        'produccion'         => 'Producción',
        'venta'              => 'Venta',
        'compra'             => 'Compra',
        'merma'              => 'Merma',
        'devolucion'         => 'Devolución',
        'anulacion'          => 'Anulación',
        'consumo_produccion' => 'Consumo de producción',
        'consumo_interno'    => 'Consumo interno',
    ];

    protected $fillable = [
        'producto_id',
        'tipo',
        'cantidad',
        'stock_anterior',
        'stock_resultante',
        'costo_unitario',
        'costo_total',
        'motivo',
        'referencia_id',
        'referencia_tipo',
        'usuario_id',
    ];

    protected $casts = [
        'cantidad'         => 'decimal:4',
        'stock_anterior'   => 'decimal:4',
        'stock_resultante' => 'decimal:4',
        'costo_unitario'   => 'decimal:4',
        'costo_total'      => 'decimal:4',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }

    // Cantidad con signo: positiva si entró, negativa si salió (sirve también para ajustes)
    public function diferencia(): float
    {
        return (float) $this->stock_resultante - (float) $this->stock_anterior;
    }

    // Texto del documento de origen para el kardex
    public function referenciaTexto(): ?string
    {
        if (!$this->referencia_tipo) {
            return null;
        }

        $nombre = match ($this->referencia_tipo) {
            'entrada_inventario' => 'Registro de inventario',
            'venta'              => 'Venta',
            'compra'             => 'Compra',
            'produccion'         => 'Producción',
            'devolucion'         => 'Devolución',
            'merma'              => 'Merma',
            default              => ucfirst(str_replace('_', ' ', $this->referencia_tipo)),
        };

        return $this->referencia_id ? "{$nombre} #{$this->referencia_id}" : $nombre;
    }
}
