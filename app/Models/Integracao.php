<?php

namespace App\Models;

use App\Enums\Frequencia;
use App\Enums\StatusIntegracao;
use App\Integrations\Contracts\ConectorDeFonte;
use App\Integrations\RegistroDeConectores;
use Carbon\CarbonInterface;
use Database\Factories\IntegracaoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Date;

#[Fillable([
    'fonte', 'ativa', 'frequencia', 'horario', 'config', 'status',
    'ultima_execucao_em', 'ultimo_sucesso_em', 'ultima_competencia', 'ultimas_linhas', 'ultimo_erro',
])]
#[Hidden(['config'])]
class Integracao extends Model
{
    /** @use HasFactory<IntegracaoFactory> */
    use HasFactory;

    public const REPETICAO_APOS_ERRO_EM_HORAS = 6;

    protected $table = 'integracoes';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'ativa' => true,
        'frequencia' => 'diaria',
        'horario' => '03:00:00',
        'status' => 'nunca',
    ];

    /**
     * `config` guarda chaves de API e parâmetros: fica criptografada no banco e oculta em serializações.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ativa' => 'boolean',
            'frequencia' => Frequencia::class,
            'status' => StatusIntegracao::class,
            'config' => 'encrypted:array',
            'ultima_execucao_em' => 'datetime',
            'ultimo_sucesso_em' => 'datetime',
            'ultima_competencia' => 'integer',
            'ultimas_linhas' => 'integer',
        ];
    }

    public function conector(): ConectorDeFonte
    {
        return RegistroDeConectores::para($this->fonte);
    }

    /**
     * @return HasMany<Ingestao, $this>
     */
    public function ingestoes(): HasMany
    {
        return $this->hasMany(Ingestao::class, 'integracao_id');
    }

    /**
     * Valor de um parâmetro de configuração (inclusive segredos: use só no servidor, nunca na tela).
     */
    public function valorDeConfig(string $chave, mixed $padrao = null): mixed
    {
        return ($this->config ?? [])[$chave] ?? $padrao;
    }

    public function possuiConfig(string $chave): bool
    {
        $valor = $this->valorDeConfig($chave);

        return $valor !== null && $valor !== '';
    }

    /**
     * Horário agendado mais recente que já passou (ou null para execução apenas manual).
     */
    public function ultimoHorarioPrevisto(CarbonInterface $agora): ?CarbonInterface
    {
        if ($this->frequencia === Frequencia::Manual) {
            return null;
        }

        [$hora, $minuto] = array_map('intval', explode(':', (string) $this->horario));
        $agora = Date::instance($agora);

        $candidato = match ($this->frequencia) {
            Frequencia::Diaria => $agora->startOfDay()->setTime($hora, $minuto),
            Frequencia::Semanal => $agora->startOfWeek(CarbonInterface::MONDAY)->setTime($hora, $minuto),
            Frequencia::Mensal => $agora->startOfMonth()->addDay()->setTime($hora, $minuto),
        };

        if ($candidato->greaterThan($agora)) {
            $candidato = match ($this->frequencia) {
                Frequencia::Diaria => $candidato->subDay(),
                Frequencia::Semanal => $candidato->subWeek(),
                Frequencia::Mensal => $candidato->subMonthNoOverflow(),
            };
        }

        return $candidato;
    }

    public function proximoHorario(CarbonInterface $agora): ?CarbonInterface
    {
        $ultimo = $this->ultimoHorarioPrevisto($agora);

        if (! $this->ativa || $ultimo === null) {
            return null;
        }

        return match ($this->frequencia) {
            Frequencia::Diaria => $ultimo->addDay(),
            Frequencia::Semanal => $ultimo->addWeek(),
            Frequencia::Mensal => $ultimo->addMonthNoOverflow(),
        };
    }

    /**
     * Uma execução "na fila" ou "atualizando" sem sinal de vida há horas indica worker parado ou processo morto.
     */
    public function estaTravada(CarbonInterface $agora): bool
    {
        return $this->status->emAndamento()
            && $this->updated_at !== null
            && $this->updated_at->lessThan($agora->copy()->subHours(2));
    }

    /**
     * Deve rodar agora? Sim se o último horário previsto passou sem execução depois dele,
     * ou se a última tentativa falhou há tempo suficiente para tentar de novo.
     */
    public function estaVencida(CarbonInterface $agora): bool
    {
        if (! $this->ativa || ($this->status->emAndamento() && ! $this->estaTravada($agora))) {
            return false;
        }

        $previsto = $this->ultimoHorarioPrevisto($agora);

        if ($previsto === null) {
            return false;
        }

        if ($this->ultima_execucao_em === null || $this->ultima_execucao_em->lessThan($previsto)) {
            return true;
        }

        return $this->status === StatusIntegracao::Erro
            && $this->ultima_execucao_em->lessThanOrEqualTo($agora->copy()->subHours(self::REPETICAO_APOS_ERRO_EM_HORAS));
    }
}
