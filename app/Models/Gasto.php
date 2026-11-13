<?php

namespace App\Models;

use App\Models\Concerns\RegistraTurno;
use Illuminate\Database\Eloquent\Model;

class Gasto extends Model
{
    use RegistraTurno;

    protected $table = 'gastos';

    protected $fillable = [
        'uuid',
        'numero',
        'fecha',
        'categoria_id',
        'proveedor_id',
        'descripcion',
        'monto',
        'origen',
        'turno_id',
        'metodo_pago_id',
        'referencia',
        'numero_documento',
        'comprobante',
        'usuario_id',
        'anulado',
        'anulado_por',
        'anulado_at',
        'motivo_anulacion',
    ];

    protected $casts = [
        'fecha'      => 'date',
        'monto'      => 'decimal:2',
        'anulado'    => 'boolean',
        'anulado_at' => 'datetime',
    ];

    public function categoria()
    {
        return $this->belongsTo(CategoriaGasto::class, 'categoria_id');
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

    public function anuladoPor()
    {
        return $this->belongsTo(Administrador::class, 'anulado_por');
    }
}
