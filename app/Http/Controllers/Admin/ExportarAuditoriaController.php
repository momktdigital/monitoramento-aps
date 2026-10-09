<?php

namespace App\Http\Controllers\Admin;

use App\Administracao\ConsultaDeAuditoria;
use App\Administracao\RotulosDeAuditoria;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Baixa em CSV o recorte da auditoria que está na tela (mesmos filtros), limitado a um teto de linhas.
 */
class ExportarAuditoriaController extends Controller
{
    public const LIMITE_DE_LINHAS = 10000;

    public function __invoke(Request $request): StreamedResponse
    {
        Gate::authorize('administrar');

        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'grupo' => ['nullable', 'string', 'max:30'],
            'evento' => ['nullable', 'string', 'max:60'],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        Auditoria::registrar('auditoria_exportada', array_filter($filtros, fn ($valor) => $valor !== null && $valor !== ''));

        $nome = 'auditoria-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($filtros): void {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF"); // BOM: o Excel abre acentos corretamente
            fputcsv($saida, ['Data e hora', 'Evento', 'Código do evento', 'Usuário', 'E-mail', 'IP', 'Detalhes'], ';');

            ConsultaDeAuditoria::aplicar($filtros)->limit(self::LIMITE_DE_LINHAS)->lazy(500)->each(function (AuditLog $registro) use ($saida): void {
                fputcsv($saida, array_map(self::protegerPlanilha(...), [
                    $registro->created_at->format('d/m/Y H:i:s'),
                    RotulosDeAuditoria::rotulo($registro->evento),
                    $registro->evento,
                    $registro->user?->name ?? '',
                    $registro->user?->email ?? '',
                    (string) $registro->ip,
                    $registro->dados === null ? '' : json_encode($registro->dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]), ';');
            });

            fclose($saida);
        }, $nome, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Texto que começa com =, +, - ou @ vira fórmula no Excel; o apóstrofo força texto (injeção de fórmulas em CSV).
     */
    public static function protegerPlanilha(string $valor): string
    {
        return $valor !== '' && str_contains("=+-@\t\r", $valor[0]) ? "'".$valor : $valor;
    }
}
