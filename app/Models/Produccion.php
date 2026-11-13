<?php

namespace App\Models;

use App\Models\Concerns\RegistraTurno;
use Illuminate\Database\Eloquent\Model;

class Produccion extends Model
{
    use RegistraTurno;

    protected $table = 'producciones';

    protected $fillable = [
        'uuid',
        'numero',
        'fecha',
        'receta_id',
        'producto_id',
        'cantidad_planificada',
        'cantidad_producida',
        'costo_total',
        'costo_unitario',
        'estado',
        'observaciones',
        'usuario_id',
        'anulada_por',
        'anulada_at',
        'motivo_anulacion',
    ];

    protected $casts = [
        'fecha' => 'date',
        'cantidad_planificada' => 'decimal:4',
        'cantidad_producida' => 'decimal:4',
        'costo_total' => 'decimal:4',
        'costo_unitario' => 'decimal:4',
        'anulada_at' => 'datetime',
    ];

    public function receta()
    {
        return $this->belongsTo(Receta::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function consumos()
    {
        return $this->hasMany(ProduccionConsumo::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }

    public function anuladaPor()
    {
        return $this->belongsTo(Administrador::class, 'anulada_por');
    }
}
