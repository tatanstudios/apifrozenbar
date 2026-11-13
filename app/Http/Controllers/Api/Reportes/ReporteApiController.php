<?php

namespace App\Http\Controllers\Api\Reportes;

use App\Exports\ReporteExport;
use App\Http\Controllers\Controller;
use App\Models\ConfiguracionNegocio;
use App\Services\AuditoriaService;
use App\Services\ReportesService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReporteApiController extends Controller
{
    // GET /reportes: catálogo de reportes para la pantalla
    public function catalogo()
    {
        return response()->json([
            'success'  => 1,
            'reportes' => collect(ReportesService::REPORTES)->map(fn ($r, $tipo) => ['tipo' => $tipo, ...$r])->values(),
        ]);
    }

    private function generar(Request $request, string $tipo): array
    {
        $datos = validator($request->all(), [
            'desde'        => 'nullable|date',
            'hasta'        => 'nullable|date',
            'producto_id'  => 'nullable|integer',
            'proveedor_id' => 'nullable|integer',
            'categoria_id' => 'nullable|integer',
            'usuario_id'   => 'nullable|integer',
            'clase'        => 'nullable|in:producto_terminado,materia_prima,reventa,empaque',
            'motivo'       => 'nullable|in:vencido,quemado,danado,otro',
            'limite'       => 'nullable|integer|min:1|max:500',
        ])->validate();

        return ReportesService::generar($tipo, $datos);
    }

    // GET /reportes/{tipo}
    public function show(Request $request, string $tipo)
    {
        return response()->json(['success' => 1, 'reporte' => $this->generar($request, $tipo)]);
    }

    private function nombreArchivo(array $reporte, string $extension): string
    {
        return str($reporte['titulo'])->slug() . "-{$reporte['desde']}-{$reporte['hasta']}.{$extension}";
    }

    // GET /reportes/{tipo}/excel
    public function excel(Request $request, string $tipo)
    {
        $reporte = $this->generar($request, $tipo);
        AuditoriaService::registrar('reportes', 'exportar_excel', null, null, ['tipo' => $tipo, 'periodo' => $reporte['periodo']]);

        return Excel::download(new ReporteExport($reporte, ConfiguracionNegocio::actual()->nombre), $this->nombreArchivo($reporte, 'xlsx'));
    }

    // GET /reportes/{tipo}/pdf
    public function pdf(Request $request, string $tipo)
    {
        $reporte = $this->generar($request, $tipo);
        AuditoriaService::registrar('reportes', 'exportar_pdf', null, null, ['tipo' => $tipo, 'periodo' => $reporte['periodo']]);

        return Pdf::loadView('pdf.reporte', [
            'reporte'  => $reporte,
            'negocio'  => ConfiguracionNegocio::actual(),
            'usuario'  => $request->user()->nombre,
            'generado' => now(),
        ])
            ->setPaper('letter', count($reporte['columnas']) > 6 ? 'landscape' : 'portrait')
            ->setOption('isFontSubsettingEnabled', true)
            ->download($this->nombreArchivo($reporte, 'pdf'));
    }

    // GET /panel: indicadores del día para la pantalla de inicio
    public function panel(Request $request)
    {
        $request->validate([
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
        ]);

        return response()->json([
            'success' => 1,
            'panel'   => ReportesService::panel($request->user()->can('reportes.ver'), $request->desde, $request->hasta),
        ]);
    }
}
