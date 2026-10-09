<?php

namespace App\Integrations\Conectores;

use App\Integrations\ConfiguracaoInvalida;
use App\Integrations\ContextoDeIngestao;
use App\Integrations\Datasus\AgregadorDeInternacoes;
use App\Integrations\Datasus\Contratos\BaixadorDeArquivos;
use App\Integrations\Datasus\Contratos\LeitorDeRegistros;
use App\Integrations\Datasus\NomesDeArquivo;
use App\Models\ValorIndicador;
use Carbon\Carbon;
use Throwable;

/**
 * Internações por condições sensíveis à Atenção Primária (SIH/SUS).
 *
 * Cada arquivo mensal vira duas contagens por município (sensíveis e total sem obstétricas), gravadas
 * como indicadores auxiliares. As taxas exibidas ao usuário somam 12 meses dessas contagens: assim,
 * os arquivos antigos nunca precisam ser baixados de novo.
 */
class DatasusSihConector extends ConectorDatasusBase
{
    /** Meses somados nas taxas acumuladas (a competência e os 11 anteriores). */
    private const MESES_ACUMULADOS = 12;

    protected int $mesesDeHistoricoPadrao = 24;

    /** O SIH é publicado com 1 a 2 meses de atraso e os meses recentes são revisados. */
    protected int $mesesDeAtualizacao = 4;

    public function __construct(BaixadorDeArquivos $baixador, LeitorDeRegistros $leitor, private readonly AgregadorDeInternacoes $agregador)
    {
        parent::__construct($baixador, $leitor);
    }

    public function fonte(): string
    {
        return 'datasus_sih';
    }

    public function nome(): string
    {
        return 'DATASUS · Internações (SIH/SUS)';
    }

    public function descricao(): string
    {
        return 'Internações por condições sensíveis à Atenção Primária, a partir dos arquivos mensais do SIH/SUS no FTP do DATASUS. Os arquivos são lidos e descartados: só os totais por município ficam no banco. Dados públicos, sem chave.';
    }

    public function indicadores(): array
    {
        return ['icsap_taxa', 'icsap_percentual', 'icsap_internacoes_mes', 'internacoes_clinicas_mes'];
    }

    protected function arquivoDeTeste(): string
    {
        $ultimoAno = (int) now()->subYear()->format('Y');

        return NomesDeArquivo::sih($this->primeiraUf(), $ultimoAno, 12);
    }

    public function executar(ContextoDeIngestao $contexto): void
    {
        if (($problema = $this->leitor->disponivel()) !== null) {
            throw new ConfiguracaoInvalida($problema);
        }

        $this->limparTemporariosAntigos();

        $alvo = $contexto->competencias();
        $arquivos = $this->competenciasEntre($this->somarMeses($alvo[0], -(self::MESES_ACUMULADOS - 1)), $alvo[array_key_last($alvo)]);
        $ufs = $contexto->ufs();
        $porCodigo = $contexto->municipiosPorCodigoDeSeisDigitos();

        $contexto->etapa('Lendo os arquivos de internações do SIH/SUS', count($ufs) * count($arquivos));

        $naoPublicados = [];
        $erro = null;

        try {
            foreach ($ufs as $uf) {
                $idsDaUf = $contexto->municipios()->where('uf', $uf)->pluck('id')->all();

                foreach ($arquivos as $competencia) {
                    $contexto->avancar();

                    $remoto = NomesDeArquivo::sih($uf, intdiv($competencia, 100), $competencia % 100);
                    $assinatura = $this->baixador->assinatura($remoto);

                    if ($assinatura === null) {
                        $naoPublicados[] = $competencia;

                        continue;
                    }

                    $chave = "datasus.sih.{$uf}.{$competencia}";

                    if ($this->jaProcessado($chave, $assinatura, $contexto)) {
                        continue;
                    }

                    $contagens = $this->lerArquivo($remoto, AgregadorDeInternacoes::CAMPOS, fn (iterable $registros): array => $this->agregador->agregar($registros, $porCodigo));

                    foreach ($idsDaUf as $municipio) {
                        $doMes = $contagens[$municipio][$competencia] ?? ['icsap' => 0, 'total' => 0];

                        $contexto->gravar('icsap_internacoes_mes', $municipio, $competencia, (float) $doMes['icsap']);
                        $contexto->gravar('internacoes_clinicas_mes', $municipio, $competencia, (float) $doMes['total']);
                    }

                    $contexto->persistirPendentes();
                    $this->lembrar($chave, $assinatura);
                }
            }
        } catch (Throwable $e) {
            $erro = $e;
        }

        // Mesmo que a leitura tenha falhado no meio, o que já foi lido vira taxas: o erro continua sendo informado, mas nada se perde.
        try {
            $contexto->persistirPendentes();

            if ($erro === null) {
                $this->avisarLacunas($contexto, $naoPublicados, $arquivos);
            }

            $this->calcularAcumulados($contexto, $alvo);
        } catch (Throwable $e) {
            $erro ??= $e;
        }

        if ($erro !== null) {
            throw $erro;
        }
    }

    /**
     * Meses ainda não publicados no fim da série são normais (atraso do SIH). Só um mês faltando no
     * meio, com meses posteriores publicados, merece aviso.
     *
     * @param  list<int>  $naoPublicados
     * @param  list<int>  $arquivos
     */
    private function avisarLacunas(ContextoDeIngestao $contexto, array $naoPublicados, array $arquivos): void
    {
        $publicados = array_diff($arquivos, $naoPublicados);

        if ($publicados === []) {
            $contexto->aviso('Nenhum arquivo do SIH/SUS foi encontrado para o período. Confira a organização das pastas do FTP do DATASUS.');

            return;
        }

        $ultimoPublicado = max($publicados);
        $lacunas = array_filter($naoPublicados, fn (int $competencia): bool => $competencia < $ultimoPublicado);

        if ($lacunas !== []) {
            $contexto->aviso('Arquivos do SIH/SUS ausentes no meio do período: '.implode(', ', array_map(fn (int $c): string => substr((string) $c, 4, 2).'/'.substr((string) $c, 0, 4), $lacunas)).'. As taxas desses meses não puderam ser calculadas.');
        }
    }

    /**
     * Soma, para cada competência-alvo, os 12 meses até ela (só quando os 12 existem para o município).
     *
     * @param  list<int>  $alvo
     */
    private function calcularAcumulados(ContextoDeIngestao $contexto, array $alvo): void
    {
        $idIcsap = $contexto->idDoIndicador('icsap_internacoes_mes');
        $idTotal = $contexto->idDoIndicador('internacoes_clinicas_mes');

        if ($idIcsap === null || $idTotal === null) {
            return;
        }

        $inicio = $this->somarMeses($alvo[0], -(self::MESES_ACUMULADOS - 1));
        $municipios = $contexto->municipios()->pluck('id')->all();

        $mensal = [];

        ValorIndicador::query()
            ->whereIn('indicador_id', [$idIcsap, $idTotal])
            ->whereIn('municipio_id', $municipios)
            ->whereBetween('competencia', [$inicio, $alvo[array_key_last($alvo)]])
            ->whereNotNull('valor')
            ->get(['municipio_id', 'indicador_id', 'competencia', 'valor'])
            ->each(function (ValorIndicador $linha) use (&$mensal, $idIcsap): void {
                $mensal[$linha->municipio_id][$linha->competencia][$linha->indicador_id === $idIcsap ? 'icsap' : 'total'] = $linha->valor;
            });

        $semPopulacao = [];

        foreach ($alvo as $competencia) {
            $janela = $this->competenciasEntre($this->somarMeses($competencia, -(self::MESES_ACUMULADOS - 1)), $competencia);

            foreach ($contexto->municipios() as $municipio) {
                $somaIcsap = 0.0;
                $somaTotal = 0.0;

                foreach ($janela as $mes) {
                    $contagem = $mensal[$municipio->id][$mes] ?? null;

                    if ($contagem === null || ! isset($contagem['icsap'], $contagem['total'])) {
                        continue 2;
                    }

                    $somaIcsap += $contagem['icsap'];
                    $somaTotal += $contagem['total'];
                }

                $populacao = $contexto->populacao()->para($municipio->id, $competencia);

                if ($populacao) {
                    $contexto->gravar('icsap_taxa', $municipio->id, $competencia, $somaIcsap / $populacao * 10000, $somaIcsap, $populacao);
                } else {
                    $semPopulacao[$municipio->id] = $municipio->nome;
                }

                if ($somaTotal > 0) {
                    $contexto->gravar('icsap_percentual', $municipio->id, $competencia, $somaIcsap / $somaTotal * 100, $somaIcsap, $somaTotal);
                }
            }
        }

        if ($semPopulacao !== []) {
            $contexto->aviso('Sem população de referência (execute antes a integração do IBGE) para: '.implode(', ', array_slice($semPopulacao, 0, 8)).'.');
        }
    }

    /**
     * @return list<int> competências AAAAMM de $de até $ate, inclusive
     */
    private function competenciasEntre(int $de, int $ate): array
    {
        $lista = [];
        $mes = Carbon::createFromFormat('!Ym', (string) $de)->startOfMonth();
        $fim = Carbon::createFromFormat('!Ym', (string) $ate)->startOfMonth();

        while ($mes->lessThanOrEqualTo($fim)) {
            $lista[] = (int) $mes->format('Ym');
            $mes = $mes->addMonthNoOverflow();
        }

        return $lista;
    }

    private function somarMeses(int $competencia, int $meses): int
    {
        return (int) Carbon::createFromFormat('!Ym', (string) $competencia)->startOfMonth()->addMonthsNoOverflow($meses)->format('Ym');
    }

    private function primeiraUf(): string
    {
        $uf = config('aps.ufs')[0] ?? 'RJ';

        return strtoupper((string) $uf);
    }
}
