<?php

namespace App\Console\Commands;

use App\Integrations\DadosAbertos\RegioesDeSaude;
use App\Integrations\Ibge\Localidades;
use App\Models\Municipio;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;

#[Signature('aps:sincronizar-municipios {--uf=* : Siglas dos estados (padrão: config aps.ufs)}')]
#[Description('Carrega/atualiza municípios (IBGE) e regiões de saúde. Não altera o marcador de piloto de municípios já existentes')]
class SincronizarMunicipios extends Command
{
    public function handle(Localidades $localidades, RegioesDeSaude $regioes): int
    {
        $ufs = array_map('strtoupper', $this->option('uf') ?: config('aps.ufs'));
        $piloto = array_map('intval', config('aps.municipios_piloto'));
        $total = 0;

        foreach ($ufs as $uf) {
            try {
                $municipios = $localidades->municipiosDaUf($uf);
            } catch (RequestException $e) {
                $this->components->error("Não foi possível obter os municípios de {$uf} no IBGE: ".$e->getMessage());

                return self::FAILURE;
            }

            try {
                $regioesDeSaude = $regioes->porUf($uf);
            } catch (RequestException $e) {
                $regioesDeSaude = [];
                $this->components->warn("Regiões de saúde de {$uf} indisponíveis agora ({$e->getMessage()}). Os demais dados foram carregados.");
            }

            $agora = now();
            $linhas = array_map(function (array $municipio) use ($uf, $regioesDeSaude, $piloto, $agora): array {
                $codigo6 = intdiv($municipio['id'], 10);

                return [
                    'id' => $municipio['id'],
                    'codigo6' => $codigo6,
                    'nome' => $municipio['nome'],
                    'nome_busca' => Municipio::normalizarNome($municipio['nome']),
                    'uf' => $uf,
                    'codigo_uf' => intdiv($municipio['id'], 100000),
                    'regiao_imediata_codigo' => $municipio['regiao_imediata_codigo'],
                    'regiao_imediata_nome' => $municipio['regiao_imediata_nome'],
                    'regiao_saude_codigo' => $regioesDeSaude[$codigo6]['regiao_saude_codigo'] ?? null,
                    'regiao_saude_nome' => $regioesDeSaude[$codigo6]['regiao_saude_nome'] ?? null,
                    'macrorregiao_saude_codigo' => $regioesDeSaude[$codigo6]['macrorregiao_saude_codigo'] ?? null,
                    'macrorregiao_saude_nome' => $regioesDeSaude[$codigo6]['macrorregiao_saude_nome'] ?? null,
                    'piloto' => in_array($municipio['id'], $piloto, true),
                    'ativo' => true,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }, $municipios);

            $colunasAtualizaveis = ['codigo6', 'nome', 'nome_busca', 'uf', 'codigo_uf', 'regiao_imediata_codigo', 'regiao_imediata_nome', 'updated_at'];

            if ($regioesDeSaude !== []) {
                array_push($colunasAtualizaveis, 'regiao_saude_codigo', 'regiao_saude_nome', 'macrorregiao_saude_codigo', 'macrorregiao_saude_nome');
            }

            foreach (array_chunk($linhas, 500) as $lote) {
                Municipio::upsert($lote, ['id'], $colunasAtualizaveis);
            }

            $total += count($linhas);
            $this->components->info(count($linhas)." municípios de {$uf} sincronizados.");
        }

        $this->components->twoColumnDetail('Total', (string) $total);
        $this->components->twoColumnDetail('Marcados como piloto', (string) Municipio::piloto()->count());

        return self::SUCCESS;
    }
}
