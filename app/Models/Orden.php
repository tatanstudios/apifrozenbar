<?php

namespace App\Models;

use App\Models\Concerns\RegistraTurno;
use Illuminate\Database\Eloquent\Model;

class Orden extends Model
{
    use RegistraTurno;

    protected $table = 'ordenes';

    protected $fillable = [
        'uuid',
        'numero',
        'terminal_id',
        'estado',
        'comensales',
        'cliente_id',
        'usuario_id',
        'notas',
        'abierta_at',
        'cerrada_at',
        'cancelada_por',
        'motivo_cancelacion',
    ];

    protected $casts = [
        'comensales' => 'integer',
        'abierta_at' => 'datetime',
        'cerrada_at' => 'datetime',
    ];

    public function terminal()
    {
        return $this->belongsTo(Terminal::class);
    }

    public function tickets()
    {
        return $this->hasMany(OrdenTicket::class)->orderBy('posicion');
    }

    public function items()
    {
        return $this->hasMany(OrdenItem::class);
    }

    public function comandas()
    {
        return $this->hasMany(Comanda::class);
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }

    public function ventas()
    {
        return $this->hasMany(Venta::class);
    }
}
