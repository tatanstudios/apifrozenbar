<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TurnoCaja extends Model
{
    protected $table = 'turnos_caja';

    protected $fillable = [
        'caja_id',
        'usuario_apertura_id',
        'usuario_cierre_id',
        'estado',
        'abierto_at',
        'cerrado_at',
        'fondo_inicial',
        'total_ventas',
        'total_ingresos',
        'total_egresos',
        'efectivo_esperado',
        'efectivo_contado',
        'diferencia',
        'resumen',
        'observaciones_apertura',
        'observaciones_cierre',
    ];

    protected $casts = [
        'abierto_at'        => 'datetime',
        'cerrado_at'        => 'datetime',
        'fondo_inicial'     => 'decimal:2',
        'total_ventas'      => 'decimal:2',
        'total_ingresos'    => 'decimal:2',
        'total_egresos'     => 'decimal:2',
        'efectivo_esperado' => 'decimal:2',
        'efectivo_contado'  => 'decimal:2',
        'diferencia'        => 'decimal:2',
        'resumen'           => 'array',
    ];

    public function caja()
    {
        return $this->belongsTo(Caja::class);
    }

    public function usuarioApertura()
    {
        return $this->belongsTo(Administrador::class, 'usuario_apertura_id');
    }

    public function usuarioCierre()
    {
        return $this->belongsTo(Administrador::class, 'usuario_cierre_id');
    }

    public function movimientos()
    {
        return $this->hasMany(MovimientoCaja::class, 'turno_id');
    }

    public function arqueos()
    {
        return $this->hasMany(ArqueoCaja::class, 'turno_id');
    }

    /**
     * Turno en curso para un registro: el turno abierto por ese usuario o, si no abrió
     * ninguno (el administrador desde la web), el único turno abierto del negocio.
     */
    public static function idEnCurso(?int $usuarioId): ?int
    {
        $abiertos = self::where('estado', 'abierto')->orderBy('id')->get(['id', 'usuario_apertura_id']);
        $propio = $usuarioId ? $abiertos->firstWhere('usuario_apertura_id', $usuarioId) : null;

        return $propio?->id ?? ($abiertos->count() === 1 ? $abiertos->first()->id : null);
    }

    /** "#5 · abrió Ana 02/11 08:00 · cerró Luis 02/11 14:30" para listados y reportes. */
    public function etiqueta(): string
    {
        $this->loadMissing(['usuarioApertura:id,nombre', 'usuarioCierre:id,nombre']);

        return self::texto($this->id, $this->usuarioApertura?->nombre, $this->abierto_at, $this->usuarioCierre?->nombre, $this->cerrado_at);
    }

    public static function texto(?int $id, ?string $abrio, $abierto, ?string $cerro, $cerrado): ?string
    {
        if (!$id) {
            return null;
        }
        $fecha = fn ($f) => \Illuminate\Support\Carbon::parse($f)->format('d-m H:i');

        return "#{$id} · abrió {$abrio} {$fecha($abierto)}" . ($cerrado ? " · cerró {$cerro} {$fecha($cerrado)}" : ' · abierto');
    }

    public function estaAbierto(): bool
    {
        return $this->estado === 'abierto';
    }
}
