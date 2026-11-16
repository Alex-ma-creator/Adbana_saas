<?php

namespace App\Providers;

use App\Support\RestauranteContexto;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(RestauranteContexto::class);
    }

    public function boot(): void
    {
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('app.url'), '/').'/restablecer-password?'.http_build_query(['token' => $token, 'email' => $user->email]));
        if ($this->app->environment('local')) {
            DB::listen(function (QueryExecuted $query) {
                if (! app()->bound('request')) {
                    return;
                }
                $attributes = app('request')->attributes;
                if (! $attributes->get('_timing_enabled')) {
                    return;
                }
                $attributes->set('_timing_sql_ms', $attributes->get('_timing_sql_ms', 0) + $query->time);
                $attributes->set('_timing_sql_count', $attributes->get('_timing_sql_count', 0) + 1);
            });
        }
        RateLimiter::for('login', function (Request $request) {
            $limite = app()->environment('testing')
                ? (int) env('LIMITE_LOGIN_POR_MINUTO', 5)
                : (int) config('adbana.limite_login');
            return $limite <= 0
                ? Limit::none()
                : Limit::perMinute($limite)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });
        RateLimiter::for('seguimiento-publico', fn (Request $request) => [
            Limit::perMinute(12)->by('seguimiento:'.hash('sha256', (string) $request->query('token'))),
            Limit::perMinute(1200)->by('seguimiento-ip:'.$request->ip()),
        ]);
        RateLimiter::for('pedidos-publicos', function (Request $request) {
            $limite = app()->environment('testing')
                ? (int) env('LIMITE_PEDIDOS_POR_MINUTO', 10)
                : (int) config('adbana.limite_pedidos');
            if ($limite <= 0) {
                return Limit::none();
            }
            $cliente = (string) $request->header('X-Adbana-Cliente', '');
            $cliente = Str::isUuid($cliente) ? $cliente : $request->ip();
            $slug = (string) $request->route('slug');

            $limiteIp = (int) config('adbana.limite_pedidos_ip', 300);
            $limiteRestaurante = (int) config('adbana.limite_pedidos_restaurante', 1200);

            $limits = [
                Limit::perMinute($limite)->by('cliente:'.$slug.':'.$request->ip().':'.$cliente),
            ];
            if ($limiteIp > 0) {
                $limits[] = Limit::perMinute($limiteIp)->by('ip:'.$request->ip());
            }
            if ($limiteRestaurante > 0) {
                $limits[] = Limit::perMinute($limiteRestaurante)->by('restaurante:'.$slug);
            }

            return $limits;
        });
    }
}
