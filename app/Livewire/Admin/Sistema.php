<?php

namespace App\Livewire\Admin;

use App\Administracao\Fila;
use App\Administracao\VerificacaoDeSaude;
use App\Administracao\VerificadorDeSaude;
use App\Domain\Indicators\CalculadorDeBenchmarks;
use App\Support\Auditoria;
use App\Support\VersaoDosDados;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('Sistema — Administração')]
class Sistema extends Component
{
    private const LIMITE_DO_REGISTRO_DE_ERROS = 8;

    private const BYTES_LIDOS_DO_LOG = 262144;

    public function mount(): void
    {
        Gate::authorize('administrar');
    }

    public function hydrate(): void
    {
        Gate::authorize('administrar');
    }

    /**
     * Só refaz a leitura: a tela recalcula as verificações a cada renderização.
     */
    public function verificarDeNovo(): void
    {
        session()->now('sucesso', 'Verificações refeitas às '.now()->format('H:i:s').'.');
    }

    public function reenviarFalha(string $id, Fila $fila): void
    {
        if (! $fila->reenviar($id)) {
            session()->now('aviso', 'Esse trabalho não está mais na lista de falhas.');

            return;
        }

        Auditoria::registrar('sistema_fila_reenviada', ['codigo' => $id]);
        session()->now('sucesso', 'Trabalho devolvido à fila. Ele roda quando o processador de filas estiver ligado.');
    }

    public function descartarFalha(string $id, Fila $fila): void
    {
        if ($fila->descartar($id)) {
            Auditoria::registrar('sistema_fila_descartada', ['codigo' => $id, 'quantidade' => 1]);
            session()->now('sucesso', 'Trabalho descartado.');
        }
    }

    public function descartarTodasAsFalhas(Fila $fila): void
    {
        $quantidade = $fila->descartarTodas();

        Auditoria::registrar('sistema_fila_descartada', ['quantidade' => $quantidade]);
        session()->now('sucesso', "{$quantidade} trabalho(s) descartado(s).");
    }

    /**
     * Os visuais guardam resultados prontos em cache; renovar força todos a serem refeitos na próxima visita.
     */
    public function renovarCache(): void
    {
        VersaoDosDados::renovar();

        Auditoria::registrar('sistema_cache_renovado');
        session()->now('sucesso', 'Cache dos visuais renovado: os números serão refeitos na próxima abertura de cada painel.');
    }

    /**
     * Refaz as medianas e percentis usados nas comparações (e nos textos de análise) a partir dos valores gravados.
     */
    public function recalcularBenchmarks(CalculadorDeBenchmarks $benchmarks): void
    {
        $chave = 'admin-recalcular-benchmarks';

        if (RateLimiter::tooManyAttempts($chave, 6)) {
            session()->now('aviso', 'Esse recálculo foi pedido várias vezes na última hora. Aguarde um pouco.');

            return;
        }

        RateLimiter::hit($chave, 3600);

        $linhas = $benchmarks->recalcularTudo();
        VersaoDosDados::renovar();

        Auditoria::registrar('sistema_benchmarks_recalculados', ['quantidade' => $linhas]);
        session()->now('sucesso', "Medianas de comparação refeitas ({$linhas} linhas).");
    }

    public function render(Fila $fila, VerificadorDeSaude $verificador): View
    {
        $verificacoes = $verificador->executar();

        return view('livewire.admin.sistema', [
            'grupos' => collect($verificacoes)->groupBy(fn (VerificacaoDeSaude $verificacao) => $verificacao->grupo),
            'resumo' => $verificador->resumo($verificacoes),
            'fila' => [
                'usaBanco' => $fila->usaBanco(),
                'pendentes' => $fila->pendentes(),
                'espera' => $fila->esperaMaisLongaEmSegundos(),
                'falhas' => $fila->falhas(30),
                'totalDeFalhas' => $fila->totalDeFalhas(),
            ],
            'informacoes' => $this->informacoes(),
            'erros' => $this->ultimosErros(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function informacoes(): array
    {
        return [
            'Aplicação' => config('app.name').' · '.app()->environment(),
            'Laravel / PHP' => app()->version().' / '.PHP_VERSION,
            'Banco de dados' => (string) config('database.default'),
            'Cache' => (string) config('cache.default'),
            'Fila' => (string) config('queue.default'),
            'Sessões' => (string) config('session.driver').' · expiram em '.config('session.lifetime').' min',
            'Fuso horário' => (string) config('app.timezone').' · agora: '.now()->format('d/m/Y H:i'),
            'Versão dos dados (cache)' => (string) VersaoDosDados::atual(),
        ];
    }

    /**
     * Últimas linhas de erro do registro do Laravel (só o cabeçalho de cada uma, sem a pilha de chamadas).
     *
     * @return list<string>
     */
    private function ultimosErros(): array
    {
        $arquivo = storage_path('logs/laravel.log');

        if (! is_file($arquivo) || ! is_readable($arquivo)) {
            return [];
        }

        $tamanho = filesize($arquivo);
        $manipulador = fopen($arquivo, 'r');
        fseek($manipulador, max(0, $tamanho - self::BYTES_LIDOS_DO_LOG));
        $texto = (string) stream_get_contents($manipulador);
        fclose($manipulador);

        preg_match_all('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] [\w.-]+\.(?:ERROR|CRITICAL|ALERT|EMERGENCY): (.{1,220})/m', $texto, $encontrados, PREG_SET_ORDER);

        return array_map(
            fn (array $linha): string => $linha[1].' — '.trim($linha[2]),
            array_slice(array_reverse($encontrados), 0, self::LIMITE_DO_REGISTRO_DE_ERROS),
        );
    }
}
