<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Impresora extends Model
{
    use SoftDeletes;

    protected $table = 'impresoras';

    protected $fillable = [
        'alias',
        'conexion',
        'ip_impresora',
        'puerto',
        'nombre_windows',
        'ip_servidor',
        'tipo_impresion',
        'ancho_caracteres',
        'uso',
        'predeterminada',
        'cortar_papel',
        'cash_drawer',
        'activo',
    ];

    protected $casts = [
        'activo'           => 'boolean',
        'predeterminada'   => 'boolean',
        'cortar_papel'     => 'boolean',
        'puerto'           => 'integer',
        'ancho_caracteres' => 'integer',
    ];

    public function imprimeTickets(): bool
    {
        return in_array($this->uso, ['ticket', 'ambos'], true);
    }

    public function imprimeComandas(): bool
    {
        return in_array($this->uso, ['comanda', 'ambos'], true);
    }
}
