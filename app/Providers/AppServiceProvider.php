<?php

namespace App\Providers;

use App\Dispositivos\RegistroDeDrivers;
use App\Models\User;
use App\Support\CondominioAtual;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CondominioAtual::class);
        $this->app->singleton(RegistroDeDrivers::class, fn () => new RegistroDeDrivers(config('portaria.drivers.reles', [])));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
    }

    /**
     * Super admin pode tudo, em qualquer condomínio.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(fn (User $usuario): ?bool => $usuario->isSuperAdmin() ? true : null);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Publicado com HTTPS (ex.: Cloudflare): gera todos os links em https.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
