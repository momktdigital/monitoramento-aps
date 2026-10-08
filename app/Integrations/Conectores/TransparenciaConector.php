<?php

namespace App\Integrations\Conectores;

use App\Integrations\CampoDeConfiguracao;
use App\Integrations\ClienteHttp;
use App\Integrations\ConfiguracaoInvalida;
use App\Integrations\ContextoDeIngestao;
use App\Integrations\ResultadoDoTeste;
use App\Models\Integracao;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Beneficiários do Novo Bolsa Família e do BPC por município (Portal da Transparência).
 * Exige a chave gratuita da API, informada na tela de Integrações.
 */
class TransparenciaConector extends ConectorBase
{
    private const MAXIMO_DE_PAGINAS = 30;

    /** Tamanho de página da API; uma página menor que isso é necessariamente a última. */
    private const ITENS_POR_PAGINA = 15;

    protected int $mesesDeHistoricoPadrao = 12;

    /** @var array<string, string> indicador => caminho da API */
    private const PROGRAMAS = [
        'pct_bolsa_familia' => 'novo-bolsa-familia-por-municipio',
        'pct_bpc' => 'bpc-por-municipio',
    ];

    public function fonte(): string
    {
        return 'transparencia';
    }

    public function nome(): string
    {
        return 'Portal da Transparência';
    }

    public function descricao(): string
    {
        return 'Beneficiários do Novo Bolsa Família e do BPC por município. Exige a chave gratuita de API do Portal da Transparência.';
    }

    public function indicadores(): array
    {
        return array_keys(self::PROGRAMAS);
    }

    protected function camposDaFonte(): array
    {
        return [
            new CampoDeConfiguracao(
                nome: 'chave_api',
                rotulo: 'Chave da API',
                secreto: true,
                obrigatorio: true,
                ajuda: 'Gerada gratuitamente em portaldatransparencia.gov.br/api-de-dados/cadastrar-email. Fica criptografada e não é exibida novamente.',
            ),
        ];
    }

    public function testarConexao(Integracao $integracao): ResultadoDoTeste
    {
        if (! $integracao->possuiConfig('chave_api')) {
            return ResultadoDoTeste::falha('A chave da API ainda não foi informada.');
        }

        try {
            $resposta = $this->cliente($integracao)->get('bpc-por-municipio', [
                'mesAno' => (int) now()->subMonths(3)->format('Ym'),
                'codigoIbge' => 3306107,
                'pagina' => 1,
            ]);
        } catch (ConnectionException $e) {
            return ResultadoDoTeste::falha('Não foi possível conectar ao Portal da Transparência: '.$e->getMessage());
        }

        return match (true) {
            in_array($resposta->status(), [401, 403], true) => ResultadoDoTeste::falha('O Portal da Transparência recusou a chave. Confira se ela foi copiada por inteiro.'),
            $resposta->status() === 429 => ResultadoDoTeste::falha('Limite de requisições por minuto atingido. Tente de novo em instantes.'),
            $resposta->successful() => ResultadoDoTeste::sucesso('Chave aceita pelo Portal da Transparência.'),
            default => ResultadoDoTeste::falha("O Portal respondeu com erro {$resposta->status()}."),
        };
    }

    public function executar(ContextoDeIngestao $contexto): void
    {
        $integracao = $contexto->integracao;

        if (! $integracao->possuiConfig('chave_api')) {
            throw new ConfiguracaoInvalida('A chave da API do Portal da Transparência não foi informada. Cadastre-a na tela de Integrações.');
        }

        $cliente = $this->cliente($integracao);
        $semPopulacao = [];
        $competencias = $contexto->competencias();

        $contexto->etapa('Beneficiários por município e mês', count($competencias) * $contexto->municipios()->count());

        foreach ($competencias as $competencia) {
            foreach ($contexto->municipios() as $municipio) {
                $contexto->avancar();

                $populacao = $contexto->populacao()->para($municipio->id, $competencia);

                if (! $populacao) {
                    $semPopulacao[$municipio->id] = $municipio->nome;

                    continue;
                }

                foreach (self::PROGRAMAS as $indicador => $caminho) {
                    $beneficiarios = $this->beneficiarios($cliente, $contexto, $caminho, $competencia, $municipio->id);

                    if ($beneficiarios !== null) {
                        $contexto->gravar($indicador, $municipio->id, $competencia, $beneficiarios / $populacao * 100, $beneficiarios, $populacao);
                    }
                }
            }
        }

        if ($semPopulacao !== []) {
            $contexto->aviso('Sem população de referência para: '.implode(', ', array_slice($semPopulacao, 0, 10)).'. Execute antes a integração do IBGE.');
        }
    }

    /**
     * Soma de beneficiários do município no mês, ou null quando a fonte não publicou o mês.
     */
    private function beneficiarios(PendingRequest $cliente, ContextoDeIngestao $contexto, string $caminho, int $competencia, int $codigoIbge): ?float
    {
        $total = 0;
        $encontrou = false;

        for ($pagina = 1; $pagina <= self::MAXIMO_DE_PAGINAS; $pagina++) {
            $itens = $this->buscarPagina($cliente, $caminho, [
                'mesAno' => $competencia,
                'codigoIbge' => $codigoIbge,
                'pagina' => $pagina,
            ]);

            // Toda requisição conta no limite por minuto da fonte, inclusive as que voltam vazias.
            $contexto->pausar((int) config('aps.transparencia.pausa_ms'));

            if ($itens === []) {
                break;
            }

            $encontrou = true;

            foreach ($itens as $item) {
                $total += (int) ($item['quantidadeBeneficiados'] ?? 0);
            }

            if (count($itens) < self::ITENS_POR_PAGINA) {
                break;
            }
        }

        return $encontrou ? (float) $total : null;
    }

    /**
     * @param  array<string, int>  $parametros
     * @return list<array<string, mixed>>
     */
    private function buscarPagina(PendingRequest $cliente, string $caminho, array $parametros): array
    {
        $tentativas = (int) config('aps.transparencia.tentativas_apos_limite');

        for ($tentativa = 1; $tentativa <= $tentativas; $tentativa++) {
            $resposta = $cliente->get($caminho, $parametros);

            if ($resposta->status() === 429) {
                $this->esperarLimite($resposta);

                continue;
            }

            if (in_array($resposta->status(), [401, 403], true)) {
                throw new ConfiguracaoInvalida('O Portal da Transparência recusou a chave da API. Atualize a chave na tela de Integrações.');
            }

            $resposta->throw();

            $dados = $resposta->json();

            if (! is_array($dados)) {
                throw new RuntimeException('Resposta inesperada do Portal da Transparência.');
            }

            return array_values($dados);
        }

        throw new RuntimeException('O Portal da Transparência manteve o limite de requisições por minuto. Tente novamente mais tarde.');
    }

    private function esperarLimite(Response $resposta): void
    {
        $espera = (int) ($resposta->header('Retry-After') ?: config('aps.transparencia.espera_apos_limite_s'));

        if ($espera > 0) {
            sleep(min($espera, 120));
        }
    }

    private function cliente(Integracao $integracao): PendingRequest
    {
        return ClienteHttp::fonte('transparencia')->withHeaders([
            'chave-api-dados' => (string) $integracao->valorDeConfig('chave_api'),
        ]);
    }
}
