<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovimientoCaja extends Model
{
    protected $table = 'movimientos_caja';

    // Sentido del dinero según el tipo (el monto siempre es positivo)
    const TIPOS_ENTRADA = ['venta', 'ingreso', 'abono', 'anticipo'];
    const TIPOS_SALIDA  = ['egreso', 'devolucion', 'gasto', 'pago_proveedor'];

    const TIPOS = [
        'venta'      => 'Venta',
        'ingreso'    => 'Ingreso',
        'egreso'     => 'Egreso',
        'devolucion' => 'Devolución',
        'abono'      => 'Abono de cliente',
        'anticipo'   => 'Anticipo de pedido',
        'pago_proveedor' => 'Pago a proveedor',
        'gasto'      => 'Gasto',
    ];

    // Tipos que se registran a mano desde la pantalla de caja
    const TIPOS_MANUALES = ['ingreso', 'egreso'];

    protected $fillable = [
        'uuid',
        'turno_id',
        'tipo',
        'metodo_pago_id',
        'monto',
        'concepto',
        'referencia',
        'documento_tipo',
        'documento_id',
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

    public function turno()
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_id');
    }

    public function metodoPago()
    {
        return $this->belongsTo(MetodoPago::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }

    public function anuladoPor()
    {
        return $this->belongsTo(Administrador::class, 'anulado_por');
    }

    public function esEntrada(): bool
    {
        return in_array($this->tipo, self::TIPOS_ENTRADA, true);
    }

    // Monto con signo: positivo si entra, negativo si sale
    public function montoConSigno(): float
    {
        return $this->esEntrada() ? (float) $this->monto : -(float) $this->monto;
    }
}
