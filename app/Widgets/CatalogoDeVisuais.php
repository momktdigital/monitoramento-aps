<?php

namespace App\Widgets;

use App\Enums\Dimensao;
use App\Enums\TipoDeVisual;
use App\Models\Indicador;
use Illuminate\Support\Collection;

/**
 * Tudo o que pode ser adicionado ao painel: cada indicador ativo em cada tipo de visual.
 * É também a lista de permissões: tipo ou indicador fora daqui nunca chega ao painel de um usuário.
 */
class CatalogoDeVisuais
{
    /** @var Collection<int, Indicador>|null */
    private ?Collection $indicadores = null;

    /**
     * @return Collection<int, Indicador>
     */
    public function indicadores(): Collection
    {
        return $this->indicadores ??= Indicador::ativo()->visivel()->orderBy('ordem')->get()->keyBy('codigo')->values();
    }

    public function indicador(string $codigo): ?Indicador
    {
        return $this->indicadores()->firstWhere('codigo', $codigo);
    }

    public function permite(string $tipo, string $codigoDoIndicador): bool
    {
        $visual = TipoDeVisual::tryFrom($tipo);

        return $visual !== null && $this->indicador($codigoDoIndicador) !== null && $visual->aplicaA($codigoDoIndicador);
    }

    /**
     * Indicadores agrupados por dimensão, opcionalmente filtrados por parte do nome.
     *
     * @return array<string, Collection<int, Indicador>> rótulo da dimensão => indicadores
     */
    public function agrupados(?string $busca = null): array
    {
        $termo = $busca === null ? '' : mb_strtolower(trim($busca));

        $filtrados = $this->indicadores()->filter(fn (Indicador $indicador): bool => $termo === ''
            || str_contains(mb_strtolower($indicador->nome), $termo));

        $grupos = [];

        foreach (Dimensao::cases() as $dimensao) {
            $doGrupo = $filtrados->filter(fn (Indicador $indicador): bool => $indicador->dimensao === $dimensao)->values();

            if ($doGrupo->isNotEmpty()) {
                $grupos[$dimensao->rotulo()] = $doGrupo;
            }
        }

        return $grupos;
    }
}
