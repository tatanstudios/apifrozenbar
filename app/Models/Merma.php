<?php

namespace App\Models;

use App\Models\Concerns\RegistraTurno;
use Illuminate\Database\Eloquent\Model;

class Merma extends Model
{
    use RegistraTurno;

    const MOTIVOS = [
        'vencido' => 'Vencido / no se vendió',
        'quemado' => 'Quemado',
        'danado'  => 'Dañado',
        'otro'    => 'Otro',
    ];

    protected $table = 'mermas';

    protected $fillable = [
        'uuid',
        'fecha',
        'producto_id',
        'cantidad',
        'motivo',
        'detalle',
        'costo_unitario',
        'costo_total',
        'anulada',
        'usuario_id',
        'anulada_por',
        'anulada_at',
        'motivo_anulacion',
    ];

    protected $casts = [
        'fecha' => 'date',
        'cantidad' => 'decimal:4',
        'costo_unitario' => 'decimal:4',
        'costo_total' => 'decimal:4',
        'anulada' => 'boolean',
        'anulada_at' => 'datetime',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }
}
