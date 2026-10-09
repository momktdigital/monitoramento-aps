<?php

namespace App\Integrations\Conectores;

use App\Enums\Frequencia;
use App\Integrations\CampoDeConfiguracao;
use App\Integrations\ContextoDeIngestao;
use App\Integrations\Contracts\ConectorDeFonte;
use App\Support\Competencia;

abstract class ConectorBase implements ConectorDeFonte
{
    /**
     * Meses de histórico buscados na primeira carga (o padrão pode ser ajustado por fonte).
     */
    protected int $mesesDeHistoricoPadrao = 36;

    /**
     * Meses reconsultados nas atualizações de rotina (ver `ConectorDeFonte::mesesDeAtualizacao`).
     */
    protected int $mesesDeAtualizacao = 3;

    protected Frequencia $frequenciaPadrao = Frequencia::Diaria;

    /**
     * @return list<CampoDeConfiguracao>
     */
    protected function camposDaFonte(): array
    {
        return [];
    }

    public function campos(): array
    {
        return [
            ...$this->camposDaFonte(),
            new CampoDeConfiguracao(
                nome: 'meses_historico',
                rotulo: 'Meses de histórico na primeira carga',
                tipo: 'numero',
                padrao: $this->mesesDeHistoricoPadrao,
                ajuda: 'Quantos meses retroativos buscar na primeira vez. Depois, apenas os meses recentes são atualizados.',
                minimo: 1,
                maximo: ContextoDeIngestao::MAXIMO_DE_MESES,
            ),
        ];
    }

    public function frequenciaPadrao(): Frequencia
    {
        return $this->frequenciaPadrao;
    }

    public function mesesDeAtualizacao(): int
    {
        return $this->mesesDeAtualizacao;
    }

    public function rotuloDaCompetencia(?int $competencia): string
    {
        return Competencia::rotulo($competencia);
    }

    public function exigeConfiguracao(): bool
    {
        return collect($this->campos())->contains(fn (CampoDeConfiguracao $campo): bool => $campo->obrigatorio);
    }
}
