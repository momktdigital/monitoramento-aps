<?php

namespace App\Integrations\Conectores;

use App\Integrations\ClienteHttp;
use App\Integrations\ContextoDeIngestao;
use App\Integrations\ResultadoDoTeste;
use App\Models\Integracao;
use App\Support\Numero;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * População (estimativas anuais), estrutura etária e densidade (Censo 2022) via API SIDRA do IBGE.
 */
class IbgeConector extends ConectorBase
{
    private const GRUPOS_60_OU_MAIS = [93095, 93096, 93097, 93098, 49108, 49109, 60040, 60041, 6653];

    private const ANOS_DE_ESTIMATIVA = 6;

    public function fonte(): string
    {
        return 'ibge';
    }

    public function nome(): string
    {
        return 'IBGE (SIDRA)';
    }

    public function descricao(): string
    {
        return 'População estimada, estrutura por idade e densidade demográfica dos municípios. Dados públicos, sem chave.';
    }

    public function indicadores(): array
    {
        return ['populacao_total', 'pct_menores_5_anos', 'pct_60_anos_ou_mais', 'densidade_demografica'];
    }

    /**
     * Os dados do IBGE são anuais e guardados como AAAA12: exibe só o ano para não parecer uma data futura.
     */
    public function rotuloDaCompetencia(?int $competencia): string
    {
        return $competencia === null ? '—' : (string) intdiv($competencia, 100);
    }

    public function testarConexao(Integracao $integracao): ResultadoDoTeste
    {
        try {
            $linhas = $this->consultar('t/6579/n6/3306107/v/9324/p/last%201');
        } catch (ConnectionException|RequestException|RuntimeException $e) {
            return ResultadoDoTeste::falha('Não foi possível consultar o SIDRA agora: '.$e->getMessage());
        }

        return count($linhas) > 0
            ? ResultadoDoTeste::sucesso('O SIDRA respondeu normalmente.')
            : ResultadoDoTeste::falha('O SIDRA respondeu, mas sem dados para o município de teste.');
    }

    public function executar(ContextoDeIngestao $contexto): void
    {
        $territorio = 'n6/in%20n3%20'.implode(',', $contexto->codigosDeUf());
        $idsDosMunicipios = $contexto->municipios()->pluck('id')->flip();

        $contexto->etapa('Consultando o IBGE (população, idade e densidade)', 3);

        $this->carregarPopulacao($contexto, $territorio, $idsDosMunicipios);
        $contexto->avancar();
        $contexto->pausar();

        $this->carregarEstruturaEtaria($contexto, $territorio, $idsDosMunicipios);
        $contexto->avancar();
        $contexto->pausar();

        $this->carregarDensidade($contexto, $territorio, $idsDosMunicipios);
        $contexto->avancar();
    }

    /**
     * @param  Collection<int, int>  $idsDosMunicipios
     */
    private function carregarPopulacao(ContextoDeIngestao $contexto, string $territorio, $idsDosMunicipios): void
    {
        $linhas = $this->consultar("t/6579/{$territorio}/v/9324/p/last%20".self::ANOS_DE_ESTIMATIVA);

        foreach ($linhas as $linha) {
            $municipio = (int) $linha['D1C'];
            $valor = Numero::analisar($linha['V']);

            if ($valor === null || ! $idsDosMunicipios->has($municipio)) {
                continue;
            }

            $contexto->gravar('populacao_total', $municipio, ((int) $linha['D3C']) * 100 + 12, $valor);
        }
    }

    /**
     * @param  Collection<int, int>  $idsDosMunicipios
     */
    private function carregarEstruturaEtaria(ContextoDeIngestao $contexto, string $territorio, $idsDosMunicipios): void
    {
        $grupos = implode(',', [100362, 93070, ...self::GRUPOS_60_OU_MAIS]);
        $linhas = $this->consultar("t/9514/{$territorio}/v/93/p/2022/c2/6794/c287/{$grupos}/c286/113635");

        $porMunicipio = [];

        foreach ($linhas as $linha) {
            $valor = Numero::analisar($linha['V']);

            if ($valor !== null) {
                $porMunicipio[(int) $linha['D1C']][(int) $linha['D5C']] = $valor;
            }
        }

        foreach ($porMunicipio as $municipio => $faixas) {
            $total = $faixas[100362] ?? null;

            if (! $idsDosMunicipios->has($municipio) || ! $total) {
                continue;
            }

            $contexto->gravar('populacao_total', $municipio, 202212, $total);

            $ate4 = $faixas[93070] ?? null;
            $idosos = array_sum(array_intersect_key($faixas, array_flip(self::GRUPOS_60_OU_MAIS)));

            if ($ate4 !== null) {
                $contexto->gravar('pct_menores_5_anos', $municipio, 202212, $ate4 / $total * 100, $ate4, $total);
            }

            $contexto->gravar('pct_60_anos_ou_mais', $municipio, 202212, $idosos / $total * 100, $idosos, $total);
        }
    }

    /**
     * @param  Collection<int, int>  $idsDosMunicipios
     */
    private function carregarDensidade(ContextoDeIngestao $contexto, string $territorio, $idsDosMunicipios): void
    {
        foreach ($this->consultar("t/4714/{$territorio}/v/614/p/2022") as $linha) {
            $municipio = (int) $linha['D1C'];
            $valor = Numero::analisar($linha['V']);

            if ($valor !== null && $idsDosMunicipios->has($municipio)) {
                $contexto->gravar('densidade_demografica', $municipio, 202212, $valor);
            }
        }
    }

    /**
     * Consulta o SIDRA e devolve as linhas de dados (sem a linha de cabeçalho).
     *
     * @return list<array<string, string>>
     *
     * @throws RequestException|ConnectionException|RuntimeException
     */
    private function consultar(string $caminho): array
    {
        $resposta = ClienteHttp::fonte('ibge_sidra')->get("values/{$caminho}?formato=json")->throw();
        $dados = $resposta->json();

        if (! is_array($dados) || ! array_is_list($dados)) {
            throw new RuntimeException('Resposta inesperada do SIDRA: '.mb_substr(trim($resposta->body()), 0, 200));
        }

        array_shift($dados);

        return $dados;
    }
}
