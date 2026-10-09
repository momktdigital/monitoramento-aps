<?php

namespace App\Livewire\Admin;

use App\Administracao\VerificacaoDeSaude;
use App\Administracao\VerificadorDeSaude;
use App\Models\AuditLog;
use App\Models\Indicador;
use App\Models\IndiceMunicipio;
use App\Models\Municipio;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('Administração')]
class Inicio extends Component
{
    private const ROTAS_POR_GRUPO = [
        'Integrações' => 'admin.integracoes',
        'Contas e acessos' => 'admin.usuarios',
        'Dados e índices' => 'admin.indices',
    ];

    public function mount(): void
    {
        Gate::authorize('administrar');
    }

    public function hydrate(): void
    {
        Gate::authorize('administrar');
    }

    public function render(VerificadorDeSaude $verificador): View
    {
        $verificacoes = $verificador->executar();

        $pendencias = collect($verificacoes)
            ->reject(fn (VerificacaoDeSaude $verificacao) => $verificacao->estado === VerificacaoDeSaude::OK)
            ->sortBy(fn (VerificacaoDeSaude $verificacao) => $verificacao->estado === VerificacaoDeSaude::ERRO ? 0 : 1)
            ->values()
            ->map(fn (VerificacaoDeSaude $verificacao) => ['verificacao' => $verificacao, 'rota' => self::ROTAS_POR_GRUPO[$verificacao->grupo] ?? 'admin.sistema']);

        $ultimoMes = IndiceMunicipio::query()->max('competencia');

        return view('livewire.admin.inicio', [
            'resumo' => $verificador->resumo($verificacoes),
            'pendencias' => $pendencias,
            'numeros' => [
                'usuarios' => User::where('ativo', true)->count(),
                'municipios' => Municipio::where('ativo', true)->count(),
                'indicadores' => Indicador::where('ativo', true)->count(),
                'indices' => $ultimoMes === null ? 0 : IndiceMunicipio::where('competencia', $ultimoMes)->count(),
                'ultimoMes' => $ultimoMes === null ? null : substr((string) $ultimoMes, 4, 2).'/'.substr((string) $ultimoMes, 0, 4),
            ],
            'atividade' => AuditLog::query()->with('user:id,name')->latest('created_at')->latest('id')->limit(8)->get(),
        ]);
    }
}
