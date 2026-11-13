<?php

namespace App\Models;

use App\Models\Concerns\RegistraTurno;
use Illuminate\Database\Eloquent\Model;

class Compra extends Model
{
    use RegistraTurno;

    protected $table = 'compras';

    protected $fillable = [
        'uuid',
        'numero',
        'proveedor_id',
        'orden_compra_id',
        'fecha',
        'tipo_documento',
        'numero_documento',
        'condicion',
        'subtotal',
        'impuesto',
        'total',
        'estado',
        'observaciones',
        'usuario_id',
        'anulada_por',
        'anulada_at',
        'motivo_anulacion',
    ];

    protected $casts = [
        'fecha' => 'date',
        'subtotal' => 'decimal:2',
        'impuesto' => 'decimal:2',
        'total' => 'decimal:2',
        'anulada_at' => 'datetime',
    ];

    public function detalles()
    {
        return $this->hasMany(CompraDetalle::class);
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function orden()
    {
        return $this->belongsTo(OrdenCompra::class, 'orden_compra_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }

    public function anuladaPor()
    {
        return $this->belongsTo(Administrador::class, 'anulada_por');
    }

    public function cuentaPagar()
    {
        return $this->hasOne(CuentaPagar::class);
    }
}
