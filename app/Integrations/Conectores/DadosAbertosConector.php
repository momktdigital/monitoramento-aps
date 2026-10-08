<?php

namespace App\Integrations\Conectores;

use App\Integrations\ClienteHttp;
use App\Integrations\ContextoDeIngestao;
use App\Integrations\ResultadoDoTeste;
use App\Models\Integracao;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * API de Dados Abertos do Ministério da Saúde. Nesta versão: Unidades Básicas de Saúde ativas no CNES.
 * A API entrega a situação atual (não há histórico), então cada execução grava a competência do mês corrente.
 */
class DadosAbertosConector extends ConectorBase
{
    /** Tipos de unidade do CNES: 1 = Posto de Saúde; 2 = Centro de Saúde/Unidade Básica. */
    private const TIPOS_DE_UNIDADE_BASICA = [1, 2];

    private const ITENS_POR_PAGINA = 20;

    private const MAXIMO_DE_PAGINAS = 3000;

    public function fonte(): string
    {
        return 'dados_abertos';
    }

    public function nome(): string
    {
        return 'Dados Abertos do SUS (CNES)';
    }

    public function descricao(): string
    {
        return 'Unidades Básicas de Saúde cadastradas e ativas no CNES. A fonte informa só a situação atual: o histórico se forma a cada atualização. Dados públicos, sem chave.';
    }

    public function indicadores(): array
    {
        return ['ubs_por_10mil'];
    }

    public function testarConexao(Integracao $integracao): ResultadoDoTeste
    {
        try {
            $resposta = ClienteHttp::fonte('dados_abertos')->get('cnes/tipounidades')->throw();
        } catch (ConnectionException|RequestException $e) {
            return ResultadoDoTeste::falha('Não foi possível consultar a API de Dados Abertos agora: '.$e->getMessage());
        }

        return is_array($resposta->json('tipos_unidade'))
            ? ResultadoDoTeste::sucesso('A API de Dados Abertos respondeu normalmente.')
            : ResultadoDoTeste::falha('A API respondeu em formato inesperado.');
    }

    public function executar(ContextoDeIngestao $contexto): void
    {
        $contagem = [];

        $contexto->etapa('Listando unidades básicas no CNES (páginas lidas)');

        foreach ($contexto->codigosDeUf() as $codigoDaUf) {
            foreach (self::TIPOS_DE_UNIDADE_BASICA as $tipo) {
                foreach ($this->unidades($contexto, $codigoDaUf, $tipo) as $codigoDoMunicipio) {
                    $contagem[$codigoDoMunicipio] = ($contagem[$codigoDoMunicipio] ?? 0) + 1;
                }
            }
        }

        $competencia = $contexto->competenciaAtual();

        $contexto->etapa('Calculando indicadores por município', $contexto->municipios()->count());

        foreach ($contexto->municipios() as $municipio) {
            $contexto->avancar();

            $populacao = $contexto->populacao()->para($municipio->id, $competencia);

            if (! $populacao) {
                $contexto->aviso("{$municipio->nome}: sem população de referência (execute antes a integração do IBGE).");

                continue;
            }

            $quantidade = $contagem[$municipio->codigo6] ?? 0;

            $contexto->gravar('ubs_por_10mil', $municipio->id, $competencia, $quantidade / $populacao * 10000, $quantidade, $populacao);
        }
    }

    /**
     * Código IBGE (6 dígitos) do município de cada unidade ativa do tipo informado.
     *
     * @return \Generator<int, int>
     */
    private function unidades(ContextoDeIngestao $contexto, int $codigoDaUf, int $tipo): \Generator
    {
        for ($pagina = 0; $pagina < self::MAXIMO_DE_PAGINAS; $pagina++) {
            $resposta = ClienteHttp::fonte('dados_abertos')->get('cnes/estabelecimentos', [
                'codigo_tipo_unidade' => $tipo,
                'codigo_uf' => $codigoDaUf,
                'status' => 1,
                'limit' => self::ITENS_POR_PAGINA,
                'offset' => $pagina * self::ITENS_POR_PAGINA,
            ])->throw();

            $itens = $resposta->json('estabelecimentos');

            if (! is_array($itens)) {
                throw new RuntimeException('Resposta inesperada da API de estabelecimentos do CNES.');
            }

            $contexto->avancar();

            foreach ($itens as $item) {
                if (isset($item['codigo_municipio'])) {
                    yield (int) $item['codigo_municipio'];
                }
            }

            if (count($itens) < self::ITENS_POR_PAGINA) {
                return;
            }

            $contexto->pausar();
        }

        throw new RuntimeException('Limite de páginas excedido ao listar estabelecimentos do CNES.');
    }
}
