<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Abono extends Model
{
    protected $table = 'abonos';

    protected $fillable = [
        'uuid',
        'numero',
        'cliente_id',
        'turno_id',
        'metodo_pago_id',
        'monto',
        'referencia',
        'usuario_id',
        'anulado',
        'anulado_por',
        'anulado_at',
        'motivo_anulacion',
    ];

    protected $casts = [
        'monto'      => 'decimal:2',
        'anulado'    => 'boolean',
        'anulado_at' => 'datetime',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function detalles()
    {
        return $this->hasMany(AbonoDetalle::class);
    }

    public function metodoPago()
    {
        return $this->belongsTo(MetodoPago::class);
    }

    public function turno()
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }
}
