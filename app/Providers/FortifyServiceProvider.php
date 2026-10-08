<?php

namespace App\Providers;

use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Hash descartável, usado para igualar o tempo de resposta quando o e-mail não existe.
     */
    private static function hashFicticio(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(32));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where('email', Str::lower((string) $request->input(Fortify::username())))->first();
            $password = (string) $request->input('password');

            if ($user === null) {
                Hash::check($password, self::hashFicticio());

                return null;
            }

            if (! Hash::check($password, $user->password) || ! $user->ativo) {
                return null;
            }

            return $user;
        });

        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::username())));

            $bloquear = function (Request $request, array $headers): never {
                event(new Lockout($request));

                throw ValidationException::withMessages([
                    Fortify::username() => trans('auth.throttle', ['seconds' => $headers['Retry-After'] ?? 60]),
                ])->status(429);
            };

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip())->response($bloquear),
                Limit::perHour(20)->by($email.'|'.$request->ip())->response($bloquear),
                Limit::perMinute(30)->by($request->ip())->response($bloquear),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->session()->get('login.id'));
        });
    }
}
