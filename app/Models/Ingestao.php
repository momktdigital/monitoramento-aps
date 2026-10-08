<?php

namespace App\Models;

use App\Enums\OrigemExecucao;
use App\Enums\StatusIngestao;
use Carbon\CarbonInterface;
use Database\Factories\IngestaoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'integracao_id', 'user_id', 'origem', 'status', 'meses', 'iniciada_em', 'finalizada_em',
    'linhas', 'competencia_mais_recente', 'mensagem', 'avisos',
    'etapa', 'passos_total', 'passos_concluidos',
])]
class Ingestao extends Model
{
    /** @use HasFactory<IngestaoFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'ingestoes';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'linhas' => 0,
        'passos_concluidos' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origem' => OrigemExecucao::class,
            'status' => StatusIngestao::class,
            'iniciada_em' => 'datetime',
            'finalizada_em' => 'datetime',
            'linhas' => 'integer',
            'meses' => 'integer',
            'passos_total' => 'integer',
            'passos_concluidos' => 'integer',
            'competencia_mais_recente' => 'integer',
            'avisos' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Integracao, $this>
     */
    public function integracao(): BelongsTo
    {
        return $this->belongsTo(Integracao::class, 'integracao_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Fração concluída (0 a 1), ou null quando a etapa não tem total conhecido.
     */
    public function fracaoConcluida(): ?float
    {
        if (! $this->passos_total) {
            return null;
        }

        return min(1.0, $this->passos_concluidos / $this->passos_total);
    }

    /**
     * Segundos restantes estimados pelo ritmo até agora; null enquanto não há base para estimar.
     */
    public function segundosRestantes(CarbonInterface $agora): ?int
    {
        $fracao = $this->fracaoConcluida();
        $decorrido = (int) $this->iniciada_em->diffInSeconds($agora);

        if ($fracao === null || $fracao < 0.01 || $fracao >= 1.0 || $decorrido < 20) {
            return null;
        }

        return (int) round($decorrido * (1 - $fracao) / $fracao);
    }

    public function duracaoEmSegundos(): ?int
    {
        return $this->finalizada_em === null ? null : (int) $this->iniciada_em->diffInSeconds($this->finalizada_em);
    }
}
