<?php

namespace App\Integrations\Contracts;

use App\Enums\Frequencia;
use App\Integrations\CampoDeConfiguracao;
use App\Integrations\ContextoDeIngestao;
use App\Integrations\ResultadoDoTeste;
use App\Models\Integracao;

interface ConectorDeFonte
{
    /**
     * Identificador estável da fonte (ex.: "ibge"). Também é a chave da tabela `integracoes`.
     */
    public function fonte(): string;

    public function nome(): string;

    public function descricao(): string;

    /**
     * Códigos dos indicadores que este conector alimenta.
     *
     * @return list<string>
     */
    public function indicadores(): array;

    /**
     * Parâmetros configuráveis na tela de Integrações (chaves de API, meses de histórico etc.).
     *
     * @return list<CampoDeConfiguracao>
     */
    public function campos(): array;

    /**
     * A integração só deve ficar ativa por padrão quando não precisa de configuração obrigatória.
     */
    public function exigeConfiguracao(): bool;

    /**
     * Quantos meses recentes reconsultar nas atualizações de rotina (depois da primeira carga). Deve ser maior
     * que a defasagem de publicação da fonte, senão a rotina nunca alcança os dados novos.
     */
    public function mesesDeAtualizacao(): int;

    /**
     * Texto exibido para a competência mais recente desta fonte (ex.: "jul/2026", ou só "2026" em dados anuais).
     */
    public function rotuloDaCompetencia(?int $competencia): string;

    /**
     * Frequência com que a integração nasce configurada (o administrador pode mudá-la na tela).
     */
    public function frequenciaPadrao(): Frequencia;

    public function testarConexao(Integracao $integracao): ResultadoDoTeste;

    /**
     * Busca os dados na fonte e grava com `$contexto->gravar()`. Lança exceção em falhas fatais;
     * problemas pontuais (um município indisponível) viram `$contexto->aviso()`.
     */
    public function executar(ContextoDeIngestao $contexto): void;
}
