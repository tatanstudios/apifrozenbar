<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionNegocio extends Model
{
    protected $table = 'configuracion_negocio';

    protected $fillable = [
        'nombre',
        'razon_social',
        'nit',
        'nrc',
        'actividad_economica',
        'tipo_establecimiento',
        'direccion',
        'telefono',
        'correo',
        'logo',
        'moneda',
        'simbolo_moneda',
        'permitir_stock_negativo',
        'descuento_max_sin_autorizacion',
        'cierre_ciego',
        'aviso_cambio',
        'ticket_por_comprar',
        'mensaje_ticket',
    ];

    protected $casts = [
        'permitir_stock_negativo'        => 'boolean',
        'descuento_max_sin_autorizacion' => 'decimal:2',
        'cierre_ciego'                   => 'boolean',
        'aviso_cambio'                   => 'boolean',
        'ticket_por_comprar'             => 'boolean',
    ];

    /**
     * Devuelve la única fila de configuración, creándola con valores por defecto si no existe.
     */
    public static function actual(): self
    {
        return static::query()->first() ?? static::create(['nombre' => 'The Frozen Bar']);
    }
}
