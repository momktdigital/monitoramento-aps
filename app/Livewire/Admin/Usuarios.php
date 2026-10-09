<?php

namespace App\Livewire\Admin;

use App\Administracao\GeradorDeSenha;
use App\Administracao\OperacaoNaoPermitida;
use App\Administracao\RotulosDeAuditoria;
use App\Administracao\ServicoDeUsuarios;
use App\Enums\Perfil;
use App\Livewire\Concerns\ConfirmaSenhaDoAdministrador;
use App\Models\AuditLog;
use App\Models\Municipio;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::app')]
#[Title('Usuários — Administração')]
class Usuarios extends Component
{
    use ConfirmaSenhaDoAdministrador, WithPagination;

    private const ACOES_SENSIVEIS = ['senha', 'dois-fatores', 'sessoes', 'desativar', 'excluir'];

    #[Url(as: 'q', except: '')]
    public string $busca = '';

    #[Url(except: '')]
    public string $perfil = '';

    #[Url(except: '')]
    public string $situacao = '';

    /** @var 'formulario'|'detalhes'|'confirmar'|'senha-gerada'|null */
    #[Locked]
    public ?string $janela = null;

    #[Locked]
    public ?int $usuarioId = null;

    /** @var 'senha'|'dois-fatores'|'sessoes'|'desativar'|'excluir'|null */
    #[Locked]
    public ?string $acao = null;

    public string $nome = '';

    public string $email = '';

    public string $perfilDoFormulario = 'leitura';

    public ?int $municipioId = null;

    /** @var 'gerar'|'definir' */
    public string $modoDeSenha = 'gerar';

    public string $senha = '';

    /** Senha temporária recém-gerada: só existe enquanto a janela que a mostra está aberta. */
    #[Locked]
    public ?string $senhaGerada = null;

    #[Locked]
    public ?string $contextoDaSenha = null;

    public function mount(): void
    {
        Gate::authorize('administrar');
    }

    public function hydrate(): void
    {
        Gate::authorize('administrar');
    }

    public function updating(string $campo): void
    {
        if (in_array($campo, ['busca', 'perfil', 'situacao'], true)) {
            $this->resetPage();
        }
    }

    public function novo(): void
    {
        $this->reiniciarFormulario();
        $this->janela = 'formulario';
    }

    public function editar(int $id): void
    {
        $usuario = User::findOrFail($id);

        $this->reiniciarFormulario();
        $this->usuarioId = $usuario->id;
        $this->nome = $usuario->name;
        $this->email = $usuario->email;
        $this->perfilDoFormulario = $usuario->perfil->value;
        $this->municipioId = $usuario->municipio_id;
        $this->janela = 'formulario';
    }

    public function detalhes(int $id): void
    {
        $this->resetErrorBag();
        $this->usuarioId = User::findOrFail($id)->id;
        $this->janela = 'detalhes';
    }

    public function fechar(): void
    {
        $this->janela = null;
        $this->usuarioId = null;
        $this->acao = null;
        $this->senhaGerada = null;
        $this->contextoDaSenha = null;
        $this->senhaAtual = '';
        $this->senha = '';
        $this->resetErrorBag();
    }

    public function gerarSenhaTemporaria(): void
    {
        $this->senha = GeradorDeSenha::temporaria();
    }

    /**
     * Cria a conta ou grava as alterações do formulário.
     */
    public function salvar(ServicoDeUsuarios $usuarios): void
    {
        $existente = $this->usuarioId !== null ? User::findOrFail($this->usuarioId) : null;

        $dados = $this->validate([
            'nome' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'perfilDoFormulario' => ['required', Rule::enum(Perfil::class)],
            'municipioId' => ['nullable', Rule::exists('municipios', 'id')],
            'senha' => [Rule::requiredIf($existente === null && $this->modoDeSenha === 'definir'), 'nullable', 'string', Password::default()],
        ], attributes: ['nome' => 'nome', 'email' => 'e-mail', 'perfilDoFormulario' => 'perfil', 'municipioId' => 'município', 'senha' => 'senha']);

        if ($existente !== null && $existente->perfil->value !== $dados['perfilDoFormulario'] && ! $this->confirmarSenhaAtual()) {
            return;
        }

        try {
            if ($existente === null) {
                $resultado = $usuarios->criar(Auth::user(), [
                    'name' => $dados['nome'],
                    'email' => $dados['email'],
                    'perfil' => $dados['perfilDoFormulario'],
                    'municipio_id' => $dados['municipioId'],
                ], $this->modoDeSenha === 'definir' ? $dados['senha'] : null);
            } else {
                $usuarios->atualizar(Auth::user(), $existente, [
                    'name' => $dados['nome'],
                    'email' => $dados['email'],
                    'perfil' => $dados['perfilDoFormulario'],
                    'municipio_id' => $dados['municipioId'],
                ]);
            }
        } catch (OperacaoNaoPermitida $erro) {
            $this->addError('formulario', $erro->getMessage());

            return;
        }

        if ($existente !== null) {
            $this->fechar();
            session()->now('sucesso', 'Alterações salvas.');

            return;
        }

        $criado = $resultado['usuario'];

        if ($resultado['senha'] === null) {
            $this->fechar();
            session()->now('sucesso', "Conta de {$criado->name} criada. Ela precisará trocar a senha no primeiro acesso.");

            return;
        }

        $this->mostrarSenha($criado, $resultado['senha'], "Conta de {$criado->name} criada.");
    }

    public function alternarSituacao(int $id, ServicoDeUsuarios $usuarios): void
    {
        $usuario = User::findOrFail($id);

        if ($usuario->ativo) {
            $this->pedirConfirmacao('desativar', $id);

            return;
        }

        try {
            $usuarios->atualizar(Auth::user(), $usuario, ['ativo' => true]);
        } catch (OperacaoNaoPermitida $erro) {
            session()->now('erro', $erro->getMessage());

            return;
        }

        session()->now('sucesso', "Conta de {$usuario->name} reativada.");
    }

    /**
     * @param  'senha'|'dois-fatores'|'sessoes'|'desativar'|'excluir'  $acao
     */
    public function pedirConfirmacao(string $acao, int $id): void
    {
        abort_unless(in_array($acao, self::ACOES_SENSIVEIS, true), 422);

        $this->usuarioId = User::findOrFail($id)->id;
        $this->acao = $acao;
        $this->senhaAtual = '';
        $this->resetErrorBag();
        $this->janela = 'confirmar';
    }

    /**
     * Executa a ação sensível escolhida depois de conferir a senha do administrador.
     */
    public function confirmar(ServicoDeUsuarios $usuarios): void
    {
        abort_unless(in_array($this->acao, self::ACOES_SENSIVEIS, true) && $this->usuarioId !== null, 422);

        $alvo = User::findOrFail($this->usuarioId);

        if (! $this->confirmarSenhaAtual()) {
            return;
        }

        try {
            switch ($this->acao) {
                case 'senha':
                    $this->mostrarSenha($alvo, $usuarios->redefinirSenha(Auth::user(), $alvo), "Senha de {$alvo->name} redefinida.");

                    return;
                case 'dois-fatores':
                    $usuarios->redefinirDoisFatores(Auth::user(), $alvo);
                    $mensagem = "Verificação em duas etapas de {$alvo->name} removida e sessões encerradas.";
                    break;
                case 'sessoes':
                    $quantidade = $usuarios->encerrarSessoes(Auth::user(), $alvo);
                    $mensagem = $quantidade === 0 ? "{$alvo->name} não tinha sessões abertas." : "Sessões de {$alvo->name} encerradas ({$quantidade}).";
                    break;
                case 'desativar':
                    $usuarios->atualizar(Auth::user(), $alvo, ['ativo' => false]);
                    $mensagem = "Conta de {$alvo->name} desativada e sessões encerradas.";
                    break;
                default:
                    $usuarios->excluir(Auth::user(), $alvo);
                    $mensagem = "Conta de {$alvo->name} excluída.";
            }
        } catch (OperacaoNaoPermitida $erro) {
            $this->addError('senhaAtual', $erro->getMessage());

            return;
        }

        $this->fechar();
        session()->now('sucesso', $mensagem);
    }

    public function render(): View
    {
        $filtro = User::query()
            ->when($this->busca !== '', function ($consulta): void {
                $termo = '%'.addcslashes(trim($this->busca), '%_\\').'%';
                $consulta->where(fn ($interna) => $interna->where('name', 'like', $termo)->orWhere('email', 'like', $termo));
            })
            ->when(Perfil::tryFrom($this->perfil) !== null, fn ($consulta) => $consulta->where('perfil', $this->perfil))
            ->when($this->situacao === 'ativos', fn ($consulta) => $consulta->where('ativo', true))
            ->when($this->situacao === 'inativos', fn ($consulta) => $consulta->where('ativo', false))
            ->when($this->situacao === 'pendentes', fn ($consulta) => $consulta->where('deve_alterar_senha', true));

        $selecionado = $this->usuarioId !== null ? User::with(['criadoPor:id,name', 'municipio:id,nome,uf'])->find($this->usuarioId) : null;

        return view('livewire.admin.usuarios', [
            'usuarios' => $filtro->with('municipio:id,nome,uf')->orderBy('name')->paginate(15),
            'totais' => [
                'todos' => User::count(),
                'ativos' => User::where('ativo', true)->count(),
                'administradores' => User::where('perfil', Perfil::Admin->value)->where('ativo', true)->count(),
                'pendentes' => User::where('deve_alterar_senha', true)->count(),
            ],
            'perfis' => Perfil::cases(),
            'municipios' => in_array($this->janela, ['formulario'], true) ? Municipio::query()->ativo()->orderBy('nome')->get(['id', 'nome', 'uf']) : collect(),
            'selecionado' => $selecionado,
            'sessoesAbertas' => $this->janela === 'detalhes' && $selecionado ? app(ServicoDeUsuarios::class)->sessoesAtivas($selecionado) : 0,
            'historico' => $this->janela === 'detalhes' && $selecionado ? $this->historicoDe($selecionado) : collect(),
            'editandoPerfil' => $selecionado !== null && $this->janela === 'formulario' && $selecionado->perfil->value !== $this->perfilDoFormulario,
        ]);
    }

    /**
     * Últimos eventos de auditoria em que o usuário é autor ou alvo.
     *
     * @return Collection<int, array{quando: Carbon, texto: string}>
     */
    private function historicoDe(User $usuario)
    {
        return AuditLog::query()
            ->where(fn ($consulta) => $consulta->where('user_id', $usuario->id)->orWhere('dados->alvo_id', $usuario->id))
            ->latest('created_at')->latest('id')->limit(8)->get()
            ->map(fn (AuditLog $registro) => ['quando' => $registro->created_at, 'texto' => RotulosDeAuditoria::rotulo($registro->evento).(($resumo = RotulosDeAuditoria::resumo($registro)) !== '' ? ' ('.$resumo.')' : '')]);
    }

    private function mostrarSenha(User $usuario, string $senha, string $mensagem): void
    {
        $this->usuarioId = $usuario->id;
        $this->senhaGerada = $senha;
        $this->contextoDaSenha = $mensagem;
        $this->acao = null;
        $this->senhaAtual = '';
        $this->janela = 'senha-gerada';
    }

    private function reiniciarFormulario(): void
    {
        $this->reset(['usuarioId', 'nome', 'email', 'municipioId', 'modoDeSenha', 'senha', 'senhaAtual', 'acao', 'senhaGerada']);
        $this->perfilDoFormulario = Perfil::Leitura->value;
        $this->resetErrorBag();
    }
}
