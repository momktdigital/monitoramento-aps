<?php

namespace App\Administracao;

use App\Models\AuditLog;
use Illuminate\Support\Arr;

/**
 * Nome em português e grupo de cada evento da trilha de auditoria. Evento desconhecido aparece com o nome técnico.
 */
class RotulosDeAuditoria
{
    public const GRUPOS = [
        'acesso' => 'Acesso',
        'conta' => 'Conta própria',
        'usuarios' => 'Usuários',
        'integracoes' => 'Integrações',
        'dados' => 'Dados e indicadores',
        'sistema' => 'Sistema',
    ];

    /**
     * @return array<string, array{rotulo: string, grupo: string}>
     */
    public static function todos(): array
    {
        return [
            'login' => ['rotulo' => 'Entrou no sistema', 'grupo' => 'acesso'],
            'logout' => ['rotulo' => 'Saiu do sistema', 'grupo' => 'acesso'],
            'login_falhou' => ['rotulo' => 'Tentativa de acesso recusada', 'grupo' => 'acesso'],
            'login_bloqueado' => ['rotulo' => 'Acesso bloqueado por excesso de tentativas', 'grupo' => 'acesso'],
            'dois_fatores_falhou' => ['rotulo' => 'Código de verificação recusado', 'grupo' => 'acesso'],
            'dois_fatores_iniciado' => ['rotulo' => 'Iniciou a verificação em duas etapas', 'grupo' => 'conta'],
            'dois_fatores_ativado' => ['rotulo' => 'Ativou a verificação em duas etapas', 'grupo' => 'conta'],
            'dois_fatores_desativado' => ['rotulo' => 'Desativou a verificação em duas etapas', 'grupo' => 'conta'],
            'codigos_recuperacao_gerados' => ['rotulo' => 'Gerou novos códigos de recuperação', 'grupo' => 'conta'],
            'senha_alterada' => ['rotulo' => 'Alterou a própria senha', 'grupo' => 'conta'],

            'usuario_criado' => ['rotulo' => 'Criou um usuário', 'grupo' => 'usuarios'],
            'usuario_atualizado' => ['rotulo' => 'Alterou dados de um usuário', 'grupo' => 'usuarios'],
            'usuario_desativado' => ['rotulo' => 'Desativou um usuário', 'grupo' => 'usuarios'],
            'usuario_ativado' => ['rotulo' => 'Reativou um usuário', 'grupo' => 'usuarios'],
            'usuario_senha_redefinida' => ['rotulo' => 'Redefiniu a senha de um usuário', 'grupo' => 'usuarios'],
            'usuario_dois_fatores_redefinido' => ['rotulo' => 'Redefiniu a verificação em duas etapas de um usuário', 'grupo' => 'usuarios'],
            'usuario_sessoes_encerradas' => ['rotulo' => 'Encerrou as sessões de um usuário', 'grupo' => 'usuarios'],
            'usuario_excluido' => ['rotulo' => 'Excluiu um usuário', 'grupo' => 'usuarios'],

            'integracao_configurada' => ['rotulo' => 'Configurou uma integração', 'grupo' => 'integracoes'],
            'integracao_pausada' => ['rotulo' => 'Pausou uma integração', 'grupo' => 'integracoes'],
            'integracao_retomada' => ['rotulo' => 'Retomou uma integração', 'grupo' => 'integracoes'],
            'integracao_testada' => ['rotulo' => 'Testou a conexão de uma integração', 'grupo' => 'integracoes'],
            'integracao_execucao_forcada' => ['rotulo' => 'Forçou a atualização de uma integração', 'grupo' => 'integracoes'],
            'integracao_executada' => ['rotulo' => 'Integração executada', 'grupo' => 'integracoes'],

            'municipio_ativo_alterado' => ['rotulo' => 'Ativou ou desativou um município', 'grupo' => 'dados'],
            'municipio_piloto_alterado' => ['rotulo' => 'Marcou ou desmarcou um município como piloto', 'grupo' => 'dados'],
            'municipios_piloto_em_lote' => ['rotulo' => 'Alterou os municípios piloto em lote', 'grupo' => 'dados'],
            'municipios_sincronizacao_pedida' => ['rotulo' => 'Pediu a sincronização de municípios com o IBGE', 'grupo' => 'dados'],
            'indicador_atualizado' => ['rotulo' => 'Editou um indicador', 'grupo' => 'dados'],
            'indicador_ativo_alterado' => ['rotulo' => 'Ativou ou desativou um indicador', 'grupo' => 'dados'],
            'indicador_visivel_alterado' => ['rotulo' => 'Mostrou ou ocultou um indicador', 'grupo' => 'dados'],
            'indicador_textos_restaurados' => ['rotulo' => 'Restaurou os textos de um indicador', 'grupo' => 'dados'],
            'metodologia_pesos_alterados' => ['rotulo' => 'Ajustou os pesos da metodologia', 'grupo' => 'dados'],
            'metodologia_arquivo_restaurado' => ['rotulo' => 'Voltou à metodologia do arquivo de configuração', 'grupo' => 'dados'],
            'indices_recalculados' => ['rotulo' => 'Recalculou os índices', 'grupo' => 'dados'],

            'sistema_cache_renovado' => ['rotulo' => 'Renovou o cache dos visuais', 'grupo' => 'sistema'],
            'sistema_benchmarks_recalculados' => ['rotulo' => 'Recalculou as medianas de comparação', 'grupo' => 'sistema'],
            'sistema_fila_reenviada' => ['rotulo' => 'Reenviou um trabalho que falhou', 'grupo' => 'sistema'],
            'sistema_fila_descartada' => ['rotulo' => 'Descartou trabalhos que falharam', 'grupo' => 'sistema'],
            'auditoria_exportada' => ['rotulo' => 'Exportou a auditoria', 'grupo' => 'sistema'],
        ];
    }

    public static function rotulo(string $evento): string
    {
        return self::todos()[$evento]['rotulo'] ?? $evento;
    }

    public static function grupo(string $evento): string
    {
        return self::GRUPOS[self::todos()[$evento]['grupo'] ?? ''] ?? 'Outros';
    }

    /**
     * Eventos de um grupo (chave do grupo), para o filtro da tela.
     *
     * @return list<string>
     */
    public static function eventosDoGrupo(string $grupo): array
    {
        return array_keys(array_filter(self::todos(), fn (array $e): bool => $e['grupo'] === $grupo));
    }

    /**
     * Frase curta com o que o registro traz de mais útil (e-mail, fonte, indicador, quantidade...).
     */
    public static function resumo(AuditLog $registro): string
    {
        $dados = (array) $registro->dados;
        $partes = [];

        foreach (['email' => 'e-mail', 'fonte' => 'fonte', 'codigo' => 'indicador', 'nome' => 'nome', 'perfil' => 'perfil', 'versao' => 'versão', 'quantidade' => 'quantidade', 'alvo_id' => 'conta'] as $chave => $rotulo) {
            if (isset($dados[$chave]) && is_scalar($dados[$chave])) {
                $partes[] = $rotulo.': '.($chave === 'alvo_id' ? '#'.$dados[$chave] : $dados[$chave]);
            }
        }

        if (isset($dados['alteracoes']) && is_array($dados['alteracoes'])) {
            $partes[] = 'campos: '.implode(', ', array_keys($dados['alteracoes']));
        }

        return implode(' · ', Arr::flatten($partes));
    }
}
