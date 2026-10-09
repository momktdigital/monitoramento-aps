<?php

namespace App\Livewire\Concerns;

use App\Models\Municipio;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

/**
 * Município em foco nas telas de análise. A escolha fica gravada no usuário e vale para todas as telas:
 * quem escolhe Valença no painel encontra Valença na matriz, no mapa e na comparação.
 */
trait EscolheMunicipio
{
    private const MUNICIPIO_PADRAO = 3306107;

    public int $municipioId = 0;

    protected function iniciarMunicipio(): void
    {
        $this->municipioId = $this->municipioInicial();
    }

    public function updatedMunicipioId(): void
    {
        $this->escolherMunicipio($this->municipioId);
    }

    /**
     * Troca o município em foco (rejeita códigos inexistentes ou inativos) e lembra a escolha.
     */
    public function selecionar(int $id): void
    {
        $this->escolherMunicipio($id);
    }

    /**
     * Clique em um município dentro de um gráfico (matriz, mapa ou ranking): ele passa a ser o município em foco.
     */
    #[On('municipio-escolhido')]
    public function municipioEscolhido(int $id): void
    {
        $this->escolherMunicipio($id);
    }

    private function escolherMunicipio(int $id): void
    {
        $municipio = Municipio::ativo()->find($id);

        if ($municipio === null) {
            $this->municipioId = $this->municipioInicial();

            return;
        }

        $this->municipioId = $municipio->id;
        Auth::user()->forceFill(['municipio_id' => $municipio->id])->save();
    }

    /**
     * Municípios do estado agrupados por região de saúde, para os seletores.
     *
     * @return Collection<string, Collection<int, Municipio>>
     */
    protected function municipiosAgrupados(): Collection
    {
        return Municipio::ativo()
            ->whereIn('uf', config('aps.ufs'))
            ->orderBy('regiao_saude_nome')
            ->orderBy('nome')
            ->get(['id', 'nome', 'piloto', 'regiao_saude_nome'])
            ->groupBy('regiao_saude_nome');
    }

    /**
     * Município aberto ao entrar: o último escolhido pelo usuário, senão o piloto de referência, senão o primeiro.
     */
    private function municipioInicial(): int
    {
        $escolhido = Auth::user()->municipio_id;

        if ($escolhido !== null && Municipio::ativo()->whereKey($escolhido)->exists()) {
            return $escolhido;
        }

        return Municipio::ativo()->whereKey(self::MUNICIPIO_PADRAO)->value('id')
            ?? Municipio::ativo()->piloto()->orderBy('nome')->value('id')
            ?? (int) Municipio::ativo()->orderBy('nome')->value('id');
    }
}
