<?php

namespace App\Integrations\Conectores;

use App\Integrations\CampoDeConfiguracao;
use App\Integrations\ConfiguracaoInvalida;
use App\Integrations\ContextoDeIngestao;
use App\Integrations\Datasus\AgregadorDeNascimentos;
use App\Integrations\Datasus\AgregadorDeObitos;
use App\Integrations\Datasus\Contratos\BaixadorDeArquivos;
use App\Integrations\Datasus\Contratos\LeitorDeRegistros;
use App\Integrations\Datasus\NomesDeArquivo;

/**
 * Mortalidade infantil (SIM + SINASC) e, do SINASC, pré-natal com 7 ou mais consultas e baixo peso ao nascer.
 * Os dados são anuais, finais e publicados com cerca de um ano e meio de atraso: a competência é AAAA12.
 */
class DatasusSimSinascConector extends ConectorDatasusBase
{
    private const ANOS_DE_HISTORICO_PADRAO = 5;

    public function __construct(
        BaixadorDeArquivos $baixador,
        LeitorDeRegistros $leitor,
        private readonly AgregadorDeNascimentos $nascimentos,
        private readonly AgregadorDeObitos $obitos,
    ) {
        parent::__construct($baixador, $leitor);
    }

    public function fonte(): string
    {
        return 'datasus_sim_sinasc';
    }

    public function nome(): string
    {
        return 'DATASUS · Mortalidade e Nascimentos (SIM/SINASC)';
    }

    public function descricao(): string
    {
        return 'Mortalidade infantil, pré-natal (7 ou mais consultas) e baixo peso ao nascer, a partir dos arquivos anuais do SIM e do SINASC no FTP do DATASUS. Os dados finais saem com cerca de um ano e meio de atraso. Dados públicos, sem chave.';
    }

    public function indicadores(): array
    {
        return ['mortalidade_infantil', 'pre_natal_7_consultas', 'baixo_peso_nascer'];
    }

    public function campos(): array
    {
        return [
            new CampoDeConfiguracao(
                nome: 'anos_historico',
                rotulo: 'Anos de histórico',
                tipo: 'numero',
                padrao: self::ANOS_DE_HISTORICO_PADRAO,
                ajuda: 'Quantos anos recentes verificar. Arquivos que não mudaram desde a última leitura são pulados.',
                minimo: 1,
                maximo: 15,
            ),
        ];
    }

    public function exigeConfiguracao(): bool
    {
        return false;
    }

    protected function arquivoDeTeste(): string
    {
        return NomesDeArquivo::sinasc(strtoupper((string) (config('aps.ufs')[0] ?? 'RJ')), (int) now()->subYears(3)->format('Y'));
    }

    public function executar(ContextoDeIngestao $contexto): void
    {
        if (($problema = $this->leitor->disponivel()) !== null) {
            throw new ConfiguracaoInvalida($problema);
        }

        $this->limparTemporariosAntigos();

        $anoAtual = (int) $contexto->agora->format('Y');
        $quantidade = $contexto->ehReprocessamento()
            ? (int) ceil($contexto->meses() / 12)
            : (int) $contexto->integracao->valorDeConfig('anos_historico', self::ANOS_DE_HISTORICO_PADRAO);
        $anos = range($anoAtual - max(1, $quantidade) + 1, $anoAtual);
        $ufs = $contexto->ufs();
        $porCodigo = $contexto->municipiosPorCodigoDeSeisDigitos();

        $contexto->etapa('Lendo arquivos de nascimentos e óbitos (SINASC e SIM)', count($ufs) * count($anos) * 2);

        foreach ($ufs as $uf) {
            $idsDaUf = $contexto->municipios()->where('uf', $uf)->pluck('id')->all();

            foreach ($anos as $ano) {
                $remotoNascimentos = NomesDeArquivo::sinasc($uf, $ano);
                $remotoObitos = NomesDeArquivo::sim($uf, $ano);
                $assinaturaNascimentos = $this->baixador->assinatura($remotoNascimentos);
                $assinaturaObitos = $this->baixador->assinatura($remotoObitos);
                $contexto->avancar(2);

                if ($assinaturaNascimentos === null) {
                    continue;
                }

                $chaveNascimentos = "datasus.sinasc.{$uf}.{$ano}";
                $chaveObitos = "datasus.sim.{$uf}.{$ano}";

                if ($this->jaProcessado($chaveNascimentos, $assinaturaNascimentos, $contexto)
                    && ($assinaturaObitos === null || $this->jaProcessado($chaveObitos, $assinaturaObitos, $contexto))) {
                    continue;
                }

                $nascimentos = $this->lerArquivo($remotoNascimentos, AgregadorDeNascimentos::CAMPOS, fn (iterable $registros): array => $this->nascimentos->agregar($registros, $porCodigo));
                $obitos = $assinaturaObitos === null
                    ? null
                    : $this->lerArquivo($remotoObitos, AgregadorDeObitos::CAMPOS, fn (iterable $registros): array => $this->obitos->agregar($registros, $porCodigo));

                $this->gravarDoAno($contexto, $ano, $idsDaUf, $nascimentos, $obitos);

                $this->lembrar($chaveNascimentos, $assinaturaNascimentos);

                if ($assinaturaObitos !== null) {
                    $this->lembrar($chaveObitos, $assinaturaObitos);
                }
            }
        }
    }

    /**
     * @param  list<int>  $municipios
     * @param  array<int, array{nascidos: int, consultas_informadas: int, consultas_7_ou_mais: int, peso_informado: int, baixo_peso: int}>  $nascimentos
     * @param  array<int, int>|null  $obitos  óbitos de menores de 1 ano por município; null se o arquivo do SIM do ano ainda não saiu
     */
    private function gravarDoAno(ContextoDeIngestao $contexto, int $ano, array $municipios, array $nascimentos, ?array $obitos): void
    {
        $competencia = $ano * 100 + 12;

        foreach ($municipios as $municipio) {
            $nascidos = $nascimentos[$municipio] ?? null;

            if ($nascidos === null || $nascidos['nascidos'] === 0) {
                continue;
            }

            if ($nascidos['consultas_informadas'] > 0) {
                $contexto->gravar('pre_natal_7_consultas', $municipio, $competencia, $nascidos['consultas_7_ou_mais'] / $nascidos['consultas_informadas'] * 100, $nascidos['consultas_7_ou_mais'], $nascidos['consultas_informadas']);
            }

            if ($nascidos['peso_informado'] > 0) {
                $contexto->gravar('baixo_peso_nascer', $municipio, $competencia, $nascidos['baixo_peso'] / $nascidos['peso_informado'] * 100, $nascidos['baixo_peso'], $nascidos['peso_informado']);
            }

            if ($obitos !== null) {
                $mortes = $obitos[$municipio] ?? 0;

                $contexto->gravar('mortalidade_infantil', $municipio, $competencia, $mortes / $nascidos['nascidos'] * 1000, $mortes, $nascidos['nascidos']);
            }
        }
    }
}
