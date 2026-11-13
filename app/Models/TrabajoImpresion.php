<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrabajoImpresion extends Model
{
    protected $table = 'trabajos_impresion';

    const TIPOS = [
        'ticket'  => 'Ticket de venta',
        'comanda' => 'Comanda',
        'precuenta' => 'Precuenta',
        'cierre'  => 'Cierre de caja',
        'prueba'  => 'Prueba',
        'cajon'   => 'Apertura de cajón',
    ];

    protected $fillable = [
        'impresora_id',
        'tipo',
        'documento_tipo',
        'documento_id',
        'estado',
        'intentos',
        'error',
        'contenido',
        'usuario_id',
        'impreso_at',
    ];

    protected $casts = [
        'intentos'   => 'integer',
        'impreso_at' => 'datetime',
    ];

    protected $hidden = ['contenido'];

    public function impresora()
    {
        return $this->belongsTo(Impresora::class)->withTrashed();
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }
}
