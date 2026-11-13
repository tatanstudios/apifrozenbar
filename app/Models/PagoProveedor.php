<?php

namespace App\Models;

use App\Models\Concerns\RegistraTurno;
use Illuminate\Database\Eloquent\Model;

class PagoProveedor extends Model
{
    use RegistraTurno;

    protected $table = 'pagos_proveedor';

    protected $fillable = [
        'uuid',
        'numero',
        'proveedor_id',
        'fecha',
        'monto',
        'origen',
        'turno_id',
        'metodo_pago_id',
        'referencia',
        'usuario_id',
        'anulado',
        'anulado_por',
        'anulado_at',
        'motivo_anulacion',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
        'anulado' => 'boolean',
        'anulado_at' => 'datetime',
    ];

    public function detalles()
    {
        return $this->hasMany(PagoProveedorDetalle::class, 'pago_id');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
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
