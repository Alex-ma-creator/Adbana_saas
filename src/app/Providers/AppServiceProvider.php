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
            return Limit::perMinute((int) config('adbana.limite_login'))->by(
                strtolower((string) $request->input('email')) . '|' . $request->ip()
            );
        });

        RateLimiter::for('pedidos-publicos', function (Request $request) {
            return Limit::perMinute((int) config('adbana.limite_pedidos'))->by($request->ip());
        });
    }
}