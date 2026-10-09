<?php

namespace App\Providers;

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureTwoFactorSetup;
use App\Integrations\Datasus\Contratos\BaixadorDeArquivos;
use App\Integrations\Datasus\Contratos\LeitorDeRegistros;
use App\Integrations\Datasus\FtpDatasus;
use App\Integrations\Datasus\LeitorDeDbc;
use App\Integrations\Datasus\ListaIcsap;
use App\Listeners\AuditarAutenticacao;
use App\Models\User;
use App\Support\ProxiesConfiaveis;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BaixadorDeArquivos::class, FtpDatasus::class);
        $this->app->bind(LeitorDeRegistros::class, LeitorDeDbc::class);
        $this->app->singleton(ListaIcsap::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        ProxiesConfiaveis::aplicar(config('aps.proxies_confiaveis'));

        Model::shouldBeStrict(! $this->app->isProduction());
        DB::prohibitDestructiveCommands($this->app->isProduction());

        Password::defaults(function (): Password {
            $regra = Password::min(12)->letters()->mixedCase()->numbers()->symbols();

            return $this->app->isProduction() ? $regra->uncompromised() : $regra;
        });

        // Toda a área de administração (usuários, integrações, municípios, indicadores, metodologia, auditoria, sistema).
        Gate::define('administrar', fn (User $usuario): bool => $usuario->isAdmin() && $usuario->ativo);
        Gate::define('gerenciar-integracoes', fn (User $usuario): bool => Gate::forUser($usuario)->allows('administrar'));

        Event::subscribe(AuditarAutenticacao::class);

        Livewire::addPersistentMiddleware([EnsureTwoFactorSetup::class, EnsurePasswordIsChanged::class]);
    }
}
