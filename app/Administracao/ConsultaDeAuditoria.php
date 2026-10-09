<?php

namespace App\Administracao;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Filtros da trilha de auditoria, compartilhados pela tela e pela exportação para que as duas mostrem exatamente o mesmo recorte.
 */
class ConsultaDeAuditoria
{
    /**
     * @param  array{q?: string|null, grupo?: string|null, evento?: string|null, de?: string|null, ate?: string|null}  $filtros
     * @return Builder<AuditLog>
     */
    public static function aplicar(array $filtros): Builder
    {
        $consulta = AuditLog::query()->with('user:id,name,email');

        $busca = trim((string) ($filtros['q'] ?? ''));

        if ($busca !== '') {
            $termo = '%'.addcslashes($busca, '%_\\').'%';

            $consulta->where(fn (Builder $interna) => $interna
                ->where('ip', 'like', $termo)
                ->orWhere('dados', 'like', $termo)
                ->orWhereHas('user', fn (Builder $usuario) => $usuario->where('name', 'like', $termo)->orWhere('email', 'like', $termo)));
        }

        $evento = (string) ($filtros['evento'] ?? '');
        $grupo = (string) ($filtros['grupo'] ?? '');

        if ($evento !== '') {
            $consulta->where('evento', $evento);
        } elseif ($grupo !== '' && isset(RotulosDeAuditoria::GRUPOS[$grupo])) {
            $consulta->whereIn('evento', RotulosDeAuditoria::eventosDoGrupo($grupo));
        }

        if ($de = self::data($filtros['de'] ?? null)) {
            $consulta->where('created_at', '>=', $de->startOfDay());
        }

        if ($ate = self::data($filtros['ate'] ?? null)) {
            $consulta->where('created_at', '<=', $ate->endOfDay());
        }

        return $consulta->latest('created_at')->latest('id');
    }

    private static function data(?string $valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $valor, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
