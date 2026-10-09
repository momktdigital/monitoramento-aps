<?php

namespace App\Models;

use Database\Factories\MetodologiaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Versão da metodologia dos índices. O conteúdo padrão vem de `config/indices.php`; mudou o arquivo, nasce uma nova versão.
 */
#[Fillable(['versao', 'hash', 'origem', 'ativa', 'configuracao'])]
class Metodologia extends Model
{
    public const ORIGEM_ARQUIVO = 'arquivo';

    public const ORIGEM_PAINEL = 'painel';

    /** @use HasFactory<MetodologiaFactory> */
    use HasFactory;

    protected $table = 'metodologias';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'versao' => 'integer',
            'ativa' => 'boolean',
            'configuracao' => 'array',
        ];
    }

    /**
     * @param  Builder<Metodologia>  $query
     */
    public function scopeAtiva(Builder $query): void
    {
        $query->where('ativa', true);
    }

    /**
     * Identificador do conteúdo: a mesma configuração sempre gera o mesmo hash, independentemente da ordem das chaves.
     *
     * @param  array<string, mixed>  $configuracao
     */
    public static function hashDe(array $configuracao): string
    {
        return sha1(json_encode(self::ordenar($configuracao), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Garante que a versão ativa corresponde à configuração informada (por padrão, `config/indices.php`).
     * Devolve a versão ativa existente se nada mudou, ou cria e ativa uma nova.
     *
     * Sem configuração informada, uma versão ajustada pelo administrador no painel continua valendo: o arquivo
     * só volta a mandar quando o administrador pedir (`voltarAoArquivo`).
     *
     * @param  array<string, mixed>|null  $configuracao
     */
    public static function sincronizar(?array $configuracao = null, string $origem = self::ORIGEM_ARQUIVO): self
    {
        if ($configuracao === null) {
            $atual = self::query()->ativa()->first();

            if ($atual?->origem === self::ORIGEM_PAINEL) {
                return $atual;
            }
        }

        $configuracao ??= (array) config('indices');
        $hash = self::hashDe($configuracao);

        return DB::transaction(function () use ($configuracao, $hash, $origem): self {
            $ativa = self::query()->ativa()->first();

            if ($ativa !== null && $ativa->hash === $hash) {
                return $ativa;
            }

            self::query()->update(['ativa' => false]);

            $existente = self::query()->where('hash', $hash)->first();

            if ($existente !== null) {
                $existente->update(['ativa' => true]);

                return $existente;
            }

            return self::create([
                'versao' => ((int) self::query()->max('versao')) + 1,
                'hash' => $hash,
                'origem' => $origem,
                'ativa' => true,
                'configuracao' => $configuracao,
            ]);
        });
    }

    /**
     * Ativa uma configuração montada no painel de administração (pesos ajustados), criando a versão se for nova.
     *
     * @param  array<string, mixed>  $configuracao
     */
    public static function ativarDoPainel(array $configuracao): self
    {
        return self::sincronizar($configuracao, self::ORIGEM_PAINEL);
    }

    /**
     * Abandona os ajustes do painel e volta a seguir o conteúdo de `config/indices.php`.
     */
    public static function voltarAoArquivo(): self
    {
        self::query()->where('origem', self::ORIGEM_PAINEL)->update(['ativa' => false]);

        return self::sincronizar();
    }

    public function veioDoPainel(): bool
    {
        return $this->origem === self::ORIGEM_PAINEL;
    }

    /**
     * @param  array<array-key, mixed>  $valor
     * @return array<array-key, mixed>
     */
    private static function ordenar(array $valor): array
    {
        foreach ($valor as $chave => $item) {
            if (is_array($item)) {
                $valor[$chave] = self::ordenar($item);
            }
        }

        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return $valor;
    }
}
