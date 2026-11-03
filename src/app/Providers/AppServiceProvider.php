<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(\App\Support\RestauranteContexto::class);
    }

    public function boot(): void
    {
        if ($this->app->environment('local')) {
            \Illuminate\Support\Facades\DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) {
                if (! app()->bound('request')) return;
                $attributes = app('request')->attributes;
                if (! $attributes->get('_timing_enabled')) return;
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
                : Limit::perMinute($limite)->by(strtolower((string) $request->input('email')) . '|' . $request->ip());
        });

        RateLimiter::for('pedidos-publicos', function (Request $request) {
            $limite = app()->environment('testing')
                ? (int) env('LIMITE_PEDIDOS_POR_MINUTO', 10)
                : (int) config('adbana.limite_pedidos');
            return $limite <= 0
                ? Limit::none()
                : Limit::perMinute($limite)->by($request->ip());
        });
    }
}