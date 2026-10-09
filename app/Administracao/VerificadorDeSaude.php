<?php

namespace App\Administracao;

use App\Enums\Perfil;
use App\Enums\StatusIntegracao;
use App\Integrations\Datasus\Contratos\LeitorDeRegistros;
use App\Models\IndiceMunicipio;
use App\Models\Integracao;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Confere, de uma vez, se o sistema está configurado e funcionando como deveria: ambiente, banco, disco, fila,
 * agendador, integrações, dados e contas. Cada item sai como "ok", "atenção" ou "problema", com o que fazer.
 */
class VerificadorDeSaude
{
    public const CHAVE_DO_BATIMENTO = 'aps.agendador.batimento';

    public const CHAVE_DE_INDICES_DESATUALIZADOS = 'aps.indices_desatualizados';

    private const DIAS_SEM_ACESSO = 90;

    private const DIAS_COM_SENHA_TEMPORARIA = 7;

    public function __construct(private readonly Fila $fila) {}

    /**
     * Avisa que algo que entra no cálculo mudou (município ou indicador ligado/desligado) e os índices precisam ser recalculados.
     */
    public static function marcarIndicesDesatualizados(): void
    {
        Cache::forever(self::CHAVE_DE_INDICES_DESATUALIZADOS, now()->getTimestamp());
    }

    public static function indicesAtualizados(): void
    {
        Cache::forget(self::CHAVE_DE_INDICES_DESATUALIZADOS);
    }

    /**
     * @return list<VerificacaoDeSaude>
     */
    public function executar(): array
    {
        return [
            ...$this->ambiente(),
            ...$this->infraestrutura(),
            ...$this->integracoes(),
            ...$this->dados(),
            ...$this->contas(),
        ];
    }

    /**
     * @param  list<VerificacaoDeSaude>|null  $verificacoes
     * @return array{ok: int, atencao: int, erro: int}
     */
    public function resumo(?array $verificacoes = null): array
    {
        $resumo = [VerificacaoDeSaude::OK => 0, VerificacaoDeSaude::ATENCAO => 0, VerificacaoDeSaude::ERRO => 0];

        foreach ($verificacoes ?? $this->executar() as $verificacao) {
            $resumo[$verificacao->estado]++;
        }

        return $resumo;
    }

    /**
     * @return list<VerificacaoDeSaude>
     */
    private function ambiente(): array
    {
        $grupo = 'Ambiente e segurança';
        $producao = app()->isProduction();
        $itens = [];

        $itens[] = $producao
            ? VerificacaoDeSaude::ok($grupo, 'Ambiente', 'Produção.')
            : VerificacaoDeSaude::atencao($grupo, 'Ambiente', 'O sistema está rodando em "'.app()->environment().'", não em produção.', 'Em um servidor de verdade, defina APP_ENV=production no arquivo .env.');

        $itens[] = ! config('app.debug')
            ? VerificacaoDeSaude::ok($grupo, 'Modo de depuração', 'Desligado.')
            : ($producao
                ? VerificacaoDeSaude::erro($grupo, 'Modo de depuração', 'Ligado em produção: erros podem mostrar senhas e caminhos internos a qualquer visitante.', 'Defina APP_DEBUG=false no arquivo .env e rode php artisan config:cache.')
                : VerificacaoDeSaude::atencao($grupo, 'Modo de depuração', 'Ligado (aceitável só em desenvolvimento).', 'Antes de publicar, defina APP_DEBUG=false.'));

        $itens[] = request()->isSecure()
            ? VerificacaoDeSaude::ok($grupo, 'Conexão segura (HTTPS)', 'Você está acessando por HTTPS.')
            : ($producao
                ? VerificacaoDeSaude::erro($grupo, 'Conexão segura (HTTPS)', 'Você está acessando sem HTTPS: senhas e sessões trafegam sem proteção.', 'Ative HTTPS no servidor. Se há um proxy na frente, defina TRUSTED_PROXIES no .env.')
                : VerificacaoDeSaude::atencao($grupo, 'Conexão segura (HTTPS)', 'Acesso sem HTTPS (aceitável só em desenvolvimento).'));

        $itens[] = config('session.secure')
            ? VerificacaoDeSaude::ok($grupo, 'Cookie de sessão seguro', 'Só trafega por HTTPS.')
            : VerificacaoDeSaude::atencao($grupo, 'Cookie de sessão seguro', 'O cookie de sessão pode trafegar sem HTTPS.', 'Com HTTPS ativo, defina SESSION_SECURE_COOKIE=true no .env.');

        $itens[] = config('session.encrypt')
            ? VerificacaoDeSaude::ok($grupo, 'Sessão criptografada', 'Ligada.')
            : VerificacaoDeSaude::atencao($grupo, 'Sessão criptografada', 'Os dados da sessão ficam sem criptografia no banco.', 'Defina SESSION_ENCRYPT=true no .env.');

        $itens[] = app()->configurationIsCached()
            ? VerificacaoDeSaude::ok($grupo, 'Cache de configuração', 'Ativo.')
            : ($producao
                ? VerificacaoDeSaude::atencao($grupo, 'Cache de configuração', 'Desligado: cada requisição relê os arquivos de configuração.', 'Rode php artisan config:cache (e route:cache, view:cache).')
                : VerificacaoDeSaude::ok($grupo, 'Cache de configuração', 'Desligado (normal em desenvolvimento).'));

        return $itens;
    }

    /**
     * @return list<VerificacaoDeSaude>
     */
    private function infraestrutura(): array
    {
        $grupo = 'Infraestrutura';
        $itens = [];

        try {
            $inicio = microtime(true);
            DB::select('select 1');
            $milissegundos = (int) round((microtime(true) - $inicio) * 1000);

            $itens[] = $milissegundos < 500
                ? VerificacaoDeSaude::ok($grupo, 'Banco de dados', "Respondendo em {$milissegundos} ms.")
                : VerificacaoDeSaude::atencao($grupo, 'Banco de dados', "Resposta lenta: {$milissegundos} ms.", 'Verifique a carga do servidor de banco de dados.');
        } catch (Throwable $e) {
            $itens[] = VerificacaoDeSaude::erro($grupo, 'Banco de dados', 'Não foi possível consultar o banco: '.$e->getMessage());
        }

        $naoGravaveis = array_filter([storage_path('app'), storage_path('logs'), storage_path('framework'), base_path('bootstrap/cache')], fn (string $pasta): bool => ! is_writable($pasta));

        $itens[] = $naoGravaveis === []
            ? VerificacaoDeSaude::ok($grupo, 'Pastas de gravação', 'storage e bootstrap/cache estão com permissão de escrita.')
            : VerificacaoDeSaude::erro($grupo, 'Pastas de gravação', 'Sem permissão de escrita em: '.implode(', ', array_map(fn (string $p): string => str_replace(base_path().DIRECTORY_SEPARATOR, '', $p), $naoGravaveis)).'.', 'Dê permissão de escrita ao usuário do servidor web nessas pastas.');

        $livre = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());

        if ($livre !== false && $total) {
            $percentual = $livre / $total * 100;
            $texto = sprintf('%s livres de %s (%d%%).', self::bytes($livre), self::bytes($total), $percentual);

            $itens[] = $percentual < 10
                ? VerificacaoDeSaude::erro($grupo, 'Espaço em disco', $texto, 'Libere espaço: o sistema grava arquivos temporários nas cargas do DATASUS.')
                : ($percentual < 20 ? VerificacaoDeSaude::atencao($grupo, 'Espaço em disco', $texto, 'O espaço está ficando curto.') : VerificacaoDeSaude::ok($grupo, 'Espaço em disco', $texto));
        }

        $itens[] = $this->fila();
        $itens[] = $this->agendador();

        $problema = app(LeitorDeRegistros::class)->disponivel();

        $itens[] = $problema === null
            ? VerificacaoDeSaude::ok($grupo, 'Python (arquivos do DATASUS)', 'Disponível.')
            : VerificacaoDeSaude::atencao($grupo, 'Python (arquivos do DATASUS)', $problema, 'Sem Python 3 o DATASUS não é atualizado. Instale-o e, se preciso, informe o caminho em APS_PYTHON no .env.');

        return $itens;
    }

    private function fila(): VerificacaoDeSaude
    {
        $grupo = 'Infraestrutura';

        if (! $this->fila->usaBanco()) {
            return VerificacaoDeSaude::atencao($grupo, 'Processador de filas', 'A fila não usa o banco de dados ('.config('queue.default').'): não dá para medir daqui.');
        }

        $espera = $this->fila->esperaMaisLongaEmSegundos();
        $pendentes = $this->fila->pendentes();
        $falhas = $this->fila->totalDeFalhas();

        if ($espera !== null && $espera > 1800) {
            return VerificacaoDeSaude::erro($grupo, 'Processador de filas', "Há {$pendentes} trabalho(s) esperando há ".self::duracao($espera).'. O processador de filas parece desligado.', 'Inicie php artisan queue:work (e mantenha-o sempre rodando, por exemplo com Supervisor).');
        }

        if ($espera !== null && $espera > 300) {
            return VerificacaoDeSaude::atencao($grupo, 'Processador de filas', "Há {$pendentes} trabalho(s) esperando há ".self::duracao($espera).'.', 'Confira se php artisan queue:work está rodando.');
        }

        if ($falhas > 0) {
            return VerificacaoDeSaude::atencao($grupo, 'Processador de filas', "{$falhas} trabalho(s) falharam e aguardam decisão.", 'Veja a lista abaixo: reenvie ou descarte.');
        }

        return VerificacaoDeSaude::ok($grupo, 'Processador de filas', $pendentes === 0 ? 'Fila vazia, sem falhas.' : "{$pendentes} trabalho(s) na fila, andando normalmente.");
    }

    private function agendador(): VerificacaoDeSaude
    {
        $grupo = 'Infraestrutura';
        $ultimo = Cache::get(self::CHAVE_DO_BATIMENTO);

        if ($ultimo === null) {
            return VerificacaoDeSaude::atencao($grupo, 'Agendador de tarefas', 'Ainda não registrou nenhuma execução.', 'Agende php artisan schedule:run a cada minuto (cron no Linux, Agendador de Tarefas no Windows). Sem isso, as integrações não rodam sozinhas.');
        }

        $segundos = max(0, now()->getTimestamp() - (int) $ultimo);

        if ($segundos > 600) {
            return VerificacaoDeSaude::erro($grupo, 'Agendador de tarefas', 'Última execução há '.self::duracao($segundos).'. O agendador parou.', 'Confira o cron (ou Agendador de Tarefas) que chama php artisan schedule:run a cada minuto.');
        }

        return VerificacaoDeSaude::ok($grupo, 'Agendador de tarefas', 'Última execução há '.self::duracao($segundos).'.');
    }

    /**
     * @return list<VerificacaoDeSaude>
     */
    private function integracoes(): array
    {
        $grupo = 'Integrações';
        $itens = [];

        foreach (Integracao::query()->orderBy('id')->get() as $integracao) {
            $nome = $integracao->conector()->nome();

            $itens[] = match (true) {
                ! $integracao->ativa => VerificacaoDeSaude::ok($grupo, $nome, 'Pausada.'),
                $integracao->status === StatusIntegracao::Erro => VerificacaoDeSaude::erro($grupo, $nome, 'A última execução falhou: '.($integracao->ultimo_erro ?? 'sem detalhes').'.', 'Abra Integrações para testar a conexão e tentar de novo.'),
                $integracao->ultimo_sucesso_em === null => VerificacaoDeSaude::atencao($grupo, $nome, 'Ainda não foi executada com sucesso.', 'Abra Integrações e use "Atualizar agora".'),
                $integracao->estaTravada(now()) => VerificacaoDeSaude::erro($grupo, $nome, 'A execução parece travada.', 'Rode php artisan aps:destravar e tente de novo.'),
                $integracao->estaVencida(now()) => VerificacaoDeSaude::atencao($grupo, $nome, 'Atrasada: deveria ter rodado de novo (última com sucesso em '.$integracao->ultimo_sucesso_em->timezone(config('app.timezone'))->format('d/m/Y H:i').').', 'Confira o agendador e o processador de filas.'),
                default => VerificacaoDeSaude::ok($grupo, $nome, 'Atualizada em '.$integracao->ultimo_sucesso_em->timezone(config('app.timezone'))->format('d/m/Y H:i').'.'),
            };
        }

        return $itens;
    }

    /**
     * @return list<VerificacaoDeSaude>
     */
    private function dados(): array
    {
        $grupo = 'Dados e índices';
        $itens = [];

        $ultima = IndiceMunicipio::query()->max('competencia');

        $itens[] = $ultima === null
            ? VerificacaoDeSaude::atencao($grupo, 'Índices (INA, IDAPS, IPF)', 'Ainda não foram calculados.', 'Eles são calculados sozinhos depois das cargas. Se as integrações já rodaram, use Índices > Recalcular agora.')
            : VerificacaoDeSaude::ok($grupo, 'Índices (INA, IDAPS, IPF)', 'Calculados até '.substr((string) $ultima, 4, 2).'/'.substr((string) $ultima, 0, 4).' para '.IndiceMunicipio::query()->where('competencia', $ultima)->count().' municípios.');

        if (Cache::get(self::CHAVE_DE_INDICES_DESATUALIZADOS)) {
            $itens[] = VerificacaoDeSaude::atencao($grupo, 'Índices desatualizados', 'Municípios ou indicadores foram alterados depois do último cálculo.', 'Use Índices > Recalcular agora.');
        }

        return $itens;
    }

    /**
     * @return list<VerificacaoDeSaude>
     */
    private function contas(): array
    {
        $grupo = 'Contas e acessos';
        $itens = [];

        $administradores = User::query()->where('perfil', Perfil::Admin->value)->where('ativo', true)->get();

        $itens[] = match (true) {
            $administradores->isEmpty() => VerificacaoDeSaude::erro($grupo, 'Administradores', 'Não há nenhum administrador ativo.', 'Crie um com php artisan aps:criar-usuario --perfil=admin.'),
            $administradores->count() === 1 => VerificacaoDeSaude::atencao($grupo, 'Administradores', 'Há um único administrador ativo. Se ele perder o acesso, só o terminal resolve.', 'Crie um segundo administrador em Usuários.'),
            default => VerificacaoDeSaude::ok($grupo, 'Administradores', $administradores->count().' administradores ativos.'),
        };

        $semDoisFatores = $administradores->reject(fn (User $u): bool => $u->hasEnabledTwoFactorAuthentication());

        $itens[] = $semDoisFatores->isEmpty()
            ? VerificacaoDeSaude::ok($grupo, 'Verificação em duas etapas dos administradores', 'Todos ativaram.')
            : VerificacaoDeSaude::atencao($grupo, 'Verificação em duas etapas dos administradores', $semDoisFatores->count().' administrador(es) ainda não ativaram: '.$semDoisFatores->pluck('name')->take(5)->implode(', ').'.', 'Eles são obrigados a ativar no próximo acesso.');

        $temporarias = User::query()->where('ativo', true)->where('deve_alterar_senha', true)->where('created_at', '<', now()->subDays(self::DIAS_COM_SENHA_TEMPORARIA))->count();

        if ($temporarias > 0) {
            $itens[] = VerificacaoDeSaude::atencao($grupo, 'Senhas temporárias antigas', "{$temporarias} conta(s) criadas há mais de ".self::DIAS_COM_SENHA_TEMPORARIA.' dias ainda não trocaram a senha temporária.', 'Confirme se as pessoas receberam o acesso, ou desative as contas que não serão usadas.');
        }

        $inativas = User::query()
            ->where('ativo', true)
            ->where(fn ($consulta) => $consulta->where('ultimo_acesso_em', '<', now()->subDays(self::DIAS_SEM_ACESSO))->orWhere(fn ($c) => $c->whereNull('ultimo_acesso_em')->where('created_at', '<', now()->subDays(self::DIAS_SEM_ACESSO))))
            ->count();

        if ($inativas > 0) {
            $itens[] = VerificacaoDeSaude::atencao($grupo, 'Contas sem uso', "{$inativas} conta(s) ativas sem acesso há mais de ".self::DIAS_SEM_ACESSO.' dias.', 'Desative as contas que não são mais necessárias.');
        }

        return $itens;
    }

    public static function bytes(float|int $bytes): string
    {
        $unidades = ['B', 'KB', 'MB', 'GB', 'TB'];
        $indice = 0;

        while ($bytes >= 1024 && $indice < count($unidades) - 1) {
            $bytes /= 1024;
            $indice++;
        }

        return number_format($bytes, $indice === 0 ? 0 : 1, ',', '.').' '.$unidades[$indice];
    }

    public static function duracao(int $segundos): string
    {
        return match (true) {
            $segundos < 90 => "{$segundos} s",
            $segundos < 5400 => round($segundos / 60).' min',
            $segundos < 172800 => round($segundos / 3600).' h',
            default => round($segundos / 86400).' dias',
        };
    }
}
