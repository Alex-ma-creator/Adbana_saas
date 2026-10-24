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
        //
    }

    public function boot(): void
    {
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