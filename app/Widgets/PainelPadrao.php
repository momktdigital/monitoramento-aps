<?php

namespace App\Widgets;

use App\Enums\TipoDeVisual;
use App\Models\PainelArea;
use App\Models\PainelWidget;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Painel que todo usuário recebe na primeira vez: um conjunto de áreas (abas), cada uma com todos os visuais de um
 * assunto, definido em `config/painel.php`. Também restaura uma área ou o painel inteiro a qualquer momento.
 */
class PainelPadrao
{
    public function __construct(private readonly CatalogoDeVisuais $catalogo) {}

    /**
     * As áreas prontas que existem hoje, com os visuais de cada uma. Só entram indicadores ativos e visíveis; área
     * que ficaria vazia não existe; indicadores fora de qualquer área vão para "Outros indicadores".
     *
     * @return list<array{chave: string, nome: string, descricao: string, visuais: list<array{0: TipoDeVisual, 1: string}>}>
     */
    public function modelos(): array
    {
        $modelos = [];
        $usados = [];

        foreach ((array) config('painel.areas') as $chave => $area) {
            $visuais = [];

            foreach ($area['inicio'] ?? [] as [$tipo, $codigo]) {
                if ($this->catalogo->permite($tipo, $codigo)) {
                    $visuais[] = [TipoDeVisual::from($tipo), $codigo];
                }
            }

            foreach ($area['indicadores'] as $codigo) {
                $usados[$codigo] = true;
                array_push($visuais, ...$this->visuaisDoIndicador($codigo));
            }

            if ($visuais !== []) {
                $modelos[] = ['chave' => (string) $chave, 'nome' => $area['nome'], 'descricao' => $area['descricao'], 'visuais' => $visuais];
            }
        }

        $outros = [];

        foreach ($this->catalogo->indicadores() as $indicador) {
            if (! isset($usados[$indicador->codigo])) {
                array_push($outros, ...$this->visuaisDoIndicador($indicador->codigo));
            }
        }

        if ($outros !== []) {
            $modelos[] = ['chave' => config('painel.outros.chave'), 'nome' => config('painel.outros.nome'), 'descricao' => config('painel.outros.descricao'), 'visuais' => $outros];
        }

        return $modelos;
    }

    /**
     * @return array{chave: string, nome: string, descricao: string, visuais: list<array{0: TipoDeVisual, 1: string}>}|null
     */
    public function modelo(string $chave): ?array
    {
        foreach ($this->modelos() as $modelo) {
            if ($modelo['chave'] === $chave) {
                return $modelo;
            }
        }

        return null;
    }

    /**
     * Cria o painel padrão se o usuário ainda não tem nenhuma área. Retorna true se criou.
     */
    public function garantirPara(User $usuario): bool
    {
        if ($usuario->areas()->exists()) {
            return false;
        }

        $this->restaurar($usuario);

        return true;
    }

    /**
     * Descarta todas as áreas do usuário e volta ao painel padrão (a primeira área abre primeiro).
     */
    public function restaurar(User $usuario): void
    {
        DB::transaction(function () use ($usuario): void {
            $usuario->widgets()->delete();
            $usuario->areas()->delete();

            foreach ($this->modelos() as $posicao => $modelo) {
                $area = $usuario->areas()->create(['nome' => $modelo['nome'], 'posicao' => $posicao, 'padrao' => $posicao === 0, 'modelo' => $modelo['chave']]);

                $this->preencher($area, $modelo['visuais']);
            }

            // Sem nenhum indicador ativo ainda, o painel nasce com uma área vazia: ele nunca fica sem aba.
            if (! $usuario->areas()->exists()) {
                $usuario->areas()->create(['nome' => 'Meu painel', 'posicao' => 0, 'padrao' => true]);
            }

            $usuario->forceFill(['painel_personalizado' => true])->save();
        });
    }

    /**
     * Cria uma área nova no fim das abas, vazia ou com os visuais de uma área pronta.
     */
    public function criarArea(User $usuario, string $nome, ?string $modelo = null): PainelArea
    {
        return DB::transaction(function () use ($usuario, $nome, $modelo): PainelArea {
            $pronto = $modelo === null || $modelo === '' ? null : $this->modelo($modelo);

            $area = $usuario->areas()->create([
                'nome' => $nome,
                'posicao' => (int) $usuario->areas()->max('posicao') + 1,
                'padrao' => ! $usuario->areas()->exists(),
                'modelo' => $pronto['chave'] ?? null,
            ]);

            if ($pronto !== null) {
                $this->preencher($area, $pronto['visuais']);
            }

            return $area;
        });
    }

    /**
     * Devolve a uma área o conteúdo da área pronta de que ela nasceu. O nome e o lugar dela não mudam.
     * Retorna false se a área foi criada do zero ou se o modelo deixou de existir.
     */
    public function restaurarArea(PainelArea $area): bool
    {
        $modelo = $area->modelo === null ? null : $this->modelo($area->modelo);

        if ($modelo === null) {
            return false;
        }

        DB::transaction(function () use ($area, $modelo): void {
            $area->widgets()->delete();
            $this->preencher($area, $modelo['visuais']);
        });

        return true;
    }

    /**
     * @param  list<array{0: TipoDeVisual, 1: string}>  $visuais
     */
    private function preencher(PainelArea $area, array $visuais): void
    {
        foreach ($visuais as $posicao => [$tipo, $codigo]) {
            PainelWidget::create([
                'user_id' => $area->user_id,
                'area_id' => $area->id,
                'tipo' => $tipo,
                'indicador' => $codigo,
                'posicao' => $posicao,
                'largura' => $tipo->larguraInicial(),
            ]);
        }
    }

    /**
     * @return list<array{0: TipoDeVisual, 1: string}>
     */
    private function visuaisDoIndicador(string $codigo): array
    {
        $visuais = [];

        if ($this->catalogo->indicador($codigo) === null) {
            return $visuais;
        }

        foreach ((array) config('painel.visuais_por_indicador') as $tipo) {
            if ($this->catalogo->permite($tipo, $codigo)) {
                $visuais[] = [TipoDeVisual::from($tipo), $codigo];
            }
        }

        return $visuais;
    }
}
