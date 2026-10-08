<?php

namespace App\Integrations\Conectores;

use App\Integrations\ClienteHttp;
use App\Integrations\ContextoDeIngestao;
use App\Integrations\ResultadoDoTeste;
use App\Models\Integracao;
use App\Models\Municipio;
use App\Support\Numero;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * Coberturas de APS, ESF, ACS e saúde bucal e número de equipes, a partir da API que alimenta os
 * relatórios públicos do e-Gestor APS (Ministério da Saúde). A API não é documentada oficialmente:
 * por isso as respostas são validadas e qualquer mudança de formato vira erro explícito.
 */
class EGestorConector extends ConectorBase
{
    private const PESSOAS_POR_EQUIPE_ESF = 3500;

    /** O e-Gestor publica com vários meses de atraso, e uma consulta cobre um intervalo inteiro sem custo extra. */
    protected int $mesesDeAtualizacao = 6;

    public function fonte(): string
    {
        return 'egestor';
    }

    public function nome(): string
    {
        return 'e-Gestor APS (Ministério da Saúde)';
    }

    public function descricao(): string
    {
        return 'Cobertura da Atenção Primária, da Saúde da Família, de agentes comunitários e de saúde bucal, além do número de equipes. Dados públicos, sem chave.';
    }

    public function indicadores(): array
    {
        return ['cobertura_aps', 'cobertura_esf', 'cobertura_acs', 'cobertura_saude_bucal', 'equipes_esf', 'equipes_esf_por_10mil'];
    }

    public function testarConexao(Integracao $integracao): ResultadoDoTeste
    {
        // O e-Gestor publica com defasagem de meses: uma janela de 12 meses sempre alcança competências já publicadas.
        try {
            $resposta = $this->consultar('cobertura/aps', 330610, (int) now()->startOfMonth()->subMonthsNoOverflow(12)->format('Ym'), (int) now()->format('Ym'));
        } catch (ConnectionException|RequestException|RuntimeException $e) {
            return ResultadoDoTeste::falha('Não foi possível consultar o e-Gestor APS agora: '.$e->getMessage());
        }

        $competencias = collect($resposta)->map(fn (array $linha): ?int => Numero::competencia((string) ($linha['nuComp'] ?? '')))->filter();

        return $competencias->isEmpty()
            ? ResultadoDoTeste::falha('O e-Gestor APS respondeu, mas sem dados nos últimos 12 meses para o município de teste. Pode ser uma indisponibilidade temporária da fonte.')
            : ResultadoDoTeste::sucesso('O e-Gestor APS respondeu normalmente (dados até '.$this->rotuloDaCompetencia($competencias->max()).').');
    }

    public function executar(ContextoDeIngestao $contexto): void
    {
        $competencias = $contexto->competencias();
        $inicio = $competencias[0];
        $fim = $competencias[array_key_last($competencias)];

        $municipios = $contexto->municipios();
        $falhas = 0;

        $contexto->etapa('Coberturas e equipes por município', $municipios->count());

        foreach ($municipios as $municipio) {
            try {
                $this->carregarMunicipio($contexto, $municipio, $inicio, $fim);
            } catch (ConnectionException|RequestException|RuntimeException $e) {
                $falhas++;
                $contexto->aviso("{$municipio->nome}: {$e->getMessage()}");
            }

            $contexto->avancar();
        }

        if ($municipios->isNotEmpty() && $falhas === $municipios->count()) {
            throw new RuntimeException('O e-Gestor APS não respondeu para nenhum município. Verifique a disponibilidade do serviço.');
        }
    }

    private function carregarMunicipio(ContextoDeIngestao $contexto, Municipio $municipio, int $inicio, int $fim): void
    {
        foreach ($this->consultar('cobertura/aps', $municipio->codigo6, $inicio, $fim) as $linha) {
            $competencia = Numero::competencia((string) ($linha['nuComp'] ?? ''));
            $populacao = Numero::analisar($linha['qtPopulacao'] ?? null);
            $equipesEsf = Numero::analisar($linha['qtEsf'] ?? null);

            if ($competencia === null || ! $populacao) {
                continue;
            }

            $capacidade = Numero::analisar($linha['qtCapacidadeEquipe'] ?? null);
            $cobertura = Numero::analisar($linha['qtCobertura'] ?? null);

            if ($cobertura !== null) {
                $contexto->gravar('cobertura_aps', $municipio->id, $competencia, $cobertura, $capacidade, $populacao);
            }

            if ($equipesEsf !== null) {
                $capacidadeEsf = $equipesEsf * self::PESSOAS_POR_EQUIPE_ESF;

                $contexto->gravar('cobertura_esf', $municipio->id, $competencia, $capacidadeEsf / $populacao * 100, $capacidadeEsf, $populacao);
                $contexto->gravar('equipes_esf', $municipio->id, $competencia, $equipesEsf);
                $contexto->gravar('equipes_esf_por_10mil', $municipio->id, $competencia, $equipesEsf / $populacao * 10000, $equipesEsf, $populacao);
            }
        }

        $contexto->pausar();

        foreach ($this->consultar('cobertura/acs', $municipio->codigo6, $inicio, $fim) as $linha) {
            $competencia = Numero::competencia((string) ($linha['nuComp'] ?? ''));
            $percentual = Numero::analisar($linha['pcCoberturaAcsAb'] ?? null);

            if ($competencia !== null && $percentual !== null) {
                $contexto->gravar(
                    'cobertura_acs',
                    $municipio->id,
                    $competencia,
                    $percentual,
                    Numero::analisar($linha['qtCoberturaAcsAb'] ?? null),
                    Numero::analisar($linha['qtPopulacao'] ?? null),
                );
            }
        }

        $contexto->pausar();

        foreach ($this->consultar('cobertura/sb/v2', $municipio->codigo6, $inicio, $fim) as $linha) {
            $competencia = Numero::competencia((string) ($linha['nuCompetencia'] ?? ''));
            $percentual = Numero::analisar($linha['pcCoberturaSbAps'] ?? null);

            if ($competencia !== null && $percentual !== null) {
                $contexto->gravar(
                    'cobertura_saude_bucal',
                    $municipio->id,
                    $competencia,
                    $percentual,
                    Numero::analisar($linha['qtParametroCadastroEquipeSbAps'] ?? null),
                    Numero::analisar($linha['qtPopulacao'] ?? null),
                );
            }
        }

        $contexto->pausar();
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RequestException|ConnectionException|RuntimeException
     */
    private function consultar(string $caminho, int $codigoDoMunicipio, int $inicio, int $fim): array
    {
        $resposta = ClienteHttp::fonte('egestor')->get($caminho, [
            'unidadeGeografica' => 'MUNICIPIO',
            'coMunicipio' => $codigoDoMunicipio,
            'nuCompInicio' => $inicio,
            'nuCompFim' => $fim,
        ])->throw();

        $dados = $resposta->json();

        if (! is_array($dados) || ($dados !== [] && ! array_is_list($dados))) {
            throw new RuntimeException("Formato inesperado na resposta de {$caminho}. O e-Gestor pode ter mudado a API.");
        }

        return $dados;
    }
}
