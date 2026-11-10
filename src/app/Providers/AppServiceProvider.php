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
            return Limit::perMinute((int) config('adbana.limite_login'))->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });
        RateLimiter::for('seguimiento-publico', fn (Request $request) => [Limit::perMinute(12)->by('seguimiento:'.hash('sha256', (string) $request->query('token'))), Limit::perMinute(1200)->by('seguimiento-ip:'.$request->ip())]);
        RateLimiter::for('pedidos-publicos', function (Request $request) {
            $cliente = (string) $request->header('X-Adbana-Cliente', '');
            $cliente = Str::isUuid($cliente) ? $cliente : $request->ip();
            $slug = (string) $request->route('slug');

            return [Limit::perMinute((int) config('adbana.limite_pedidos'))->by('cliente:'.$slug.':'.$request->ip().':'.$cliente), Limit::perMinute((int) config('adbana.limite_pedidos_ip'))->by('ip:'.$request->ip()), Limit::perMinute((int) config('adbana.limite_pedidos_restaurante'))->by('restaurante:'.$slug)];
        });
    }
}
