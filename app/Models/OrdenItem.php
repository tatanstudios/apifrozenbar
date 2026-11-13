<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenItem extends Model
{
    protected $table = 'ordenes_items';

    protected $fillable = [
        'orden_id',
        'ticket_id',
        'producto_id',
        'nombre_producto',
        'cantidad',
        'precio_base',
        'modificadores',
        'ajustes',
        'precio_unitario',
        'notas',
        'estado',
        'area_id',
        'comanda_id',
        'venta_id',
        'usuario_id',
        'cancelado_por',
        'motivo_cancelacion',
    ];

    protected $casts = [
        'cantidad' => 'decimal:4',
        'precio_base' => 'decimal:2',
        'modificadores' => 'array',
        'ajustes' => 'array',
        'precio_unitario' => 'decimal:2',
    ];

    public function orden()
    {
        return $this->belongsTo(Orden::class);
    }

    public function ticket()
    {
        return $this->belongsTo(OrdenTicket::class, 'ticket_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function area()
    {
        return $this->belongsTo(AreaPreparacion::class, 'area_id');
    }

    public function comanda()
    {
        return $this->belongsTo(Comanda::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }

    // Lo que va al ticket y la comanda: «Frappuccino (caramelo, deslactosada)», «Desayuno (sin Huevo)»
    public function descripcion(): string
    {
        $extras = collect(Modificador::textos($this->modificadores))
            ->merge($this->textoAjustes())->filter()->join(', ');
        return $this->nombre_producto . ($extras ? " ({$extras})" : '');
    }

    /** «Sin Huevo», «Huevo: 1 (lleva 2)», «Frijol: 300 g (lleva 150 g)» */
    public function textoAjustes(): array
    {
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');

        return collect($this->ajustes ?? [])->map(function ($a) use ($num) {
            if ((float) $a['cantidad'] <= 0) {
                return "sin {$a['nombre']}";
            }
            $u = !empty($a['unidad']) && $a['unidad'] !== 'u' ? " {$a['unidad']}" : '';
            return "{$a['nombre']}: {$num($a['cantidad'])}{$u} (lleva {$num($a['base'])}{$u})";
        })->all();
    }

    /** producto_id => cuánto de lo normal sale (0 = nada, 0.5 = la mitad, 2 = doble) */
    public function factoresAjuste(): array
    {
        return collect($this->ajustes ?? [])->filter(fn ($a) => (float) $a['base'] > 0)
            ->mapWithKeys(fn ($a) => [(int) $a['producto_id'] => round((float) $a['cantidad'] / (float) $a['base'], 6)])->all();
    }

    public function subtotal(): float
    {
        return round((float) $this->cantidad * (float) $this->precio_unitario, 2);
    }
}
