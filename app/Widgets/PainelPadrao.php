<?php

namespace App\Widgets;

use App\Enums\TipoDeVisual;
use App\Models\PainelWidget;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Painel que todo usuário recebe na primeira vez: um retrato da estrutura da APS (coberturas, equipes,
 * população) em linguagem acessível. Indicadores ainda sem coleta ativa são simplesmente omitidos.
 */
class PainelPadrao
{
    /** @var list<array{0: TipoDeVisual, 1: string}> */
    private const VISUAIS = [
        [TipoDeVisual::Destaque, 'cobertura_esf'],
        [TipoDeVisual::Destaque, 'cobertura_aps'],
        [TipoDeVisual::Destaque, 'populacao_total'],
        [TipoDeVisual::Evolucao, 'cobertura_esf'],
        [TipoDeVisual::Destaque, 'equipes_esf'],
        [TipoDeVisual::Ranking, 'cobertura_esf'],
        [TipoDeVisual::Destaque, 'pct_60_anos_ou_mais'],
    ];

    public function __construct(private readonly CatalogoDeVisuais $catalogo) {}

    /**
     * Cria o painel padrão se o usuário ainda nunca teve painel. Retorna true se criou.
     */
    public function garantirPara(User $usuario): bool
    {
        if ($usuario->painel_personalizado) {
            return false;
        }

        $this->aplicar($usuario);

        return true;
    }

    /**
     * Descarta o painel atual e volta ao padrão.
     */
    public function restaurar(User $usuario): void
    {
        $this->aplicar($usuario);
    }

    private function aplicar(User $usuario): void
    {
        DB::transaction(function () use ($usuario): void {
            $usuario->widgets()->delete();

            $posicao = 0;

            foreach (self::VISUAIS as [$tipo, $codigo]) {
                if ($this->catalogo->indicador($codigo) === null) {
                    continue;
                }

                PainelWidget::create([
                    'user_id' => $usuario->id,
                    'tipo' => $tipo,
                    'indicador' => $codigo,
                    'posicao' => $posicao++,
                    'largura' => $tipo->larguraInicial(),
                ]);
            }

            $usuario->forceFill(['painel_personalizado' => true])->save();
        });
    }
}
