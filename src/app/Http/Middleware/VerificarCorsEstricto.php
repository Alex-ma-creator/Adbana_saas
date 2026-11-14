<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerificarCorsEstricto
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/carta/*/productos/*/modelo')) {
            if ($request->isMethod('OPTIONS')) {
                return response('', 204)
                    ->header('Access-Control-Allow-Origin', '*')
                    ->header('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS')
                    ->header('Access-Control-Allow-Headers', '*')
                    ->header('Access-Control-Max-Age', '86400');
            }

            $response = $next($request);
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS');

            return $response;
        }

        $origin = $request->headers->get('Origin');

        if ($origin !== null && trim($origin) !== '' && $request->is('api', 'api/*')) {
            $origenNormalizado = rtrim(trim($origin), '/');
            $permitidos = array_map(
                static fn (string $o): string => rtrim(trim($o), '/'),
                (array) config('cors.allowed_origins', [])
            );

            $schemeAndHttpHost = rtrim($request->getSchemeAndHttpHost(), '/');
            if ($schemeAndHttpHost !== '') {
                $permitidos[] = $schemeAndHttpHost;
            }
            if ($appUrl = rtrim((string) config('app.url'), '/')) {
                $permitidos[] = $appUrl;
            }
            if ($appDomain = env('APP_DOMAIN')) {
                $permitidos[] = 'https://' . rtrim($appDomain, '/');
                $permitidos[] = 'http://' . rtrim($appDomain, '/');
            }
            $permitidos = array_values(array_unique(array_filter($permitidos)));

            if (!in_array($origenNormalizado, $permitidos, true)) {
                return response()->json([
                    'message' => 'Origen no autorizado por política CORS.',
                    'origin' => $origenNormalizado,
                ], 403);
            }

            if ($request->isMethod('OPTIONS')) {
                return response('', 204)
                    ->header('Access-Control-Allow-Origin', $origenNormalizado)
                    ->header('Access-Control-Allow-Methods', implode(', ', config('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])))
                    ->header('Access-Control-Allow-Headers', implode(', ', config('cors.allowed_headers', ['Content-Type', 'Authorization', 'Accept', 'Origin'])))
                    ->header('Access-Control-Allow-Credentials', 'true')
                    ->header('Access-Control-Max-Age', (string) config('cors.max_age', 86400));
            }

            /** @var Response $response */
            $response = $next($request);
            $response->headers->set('Access-Control-Allow-Origin', $origenNormalizado);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Vary', 'Origin');

            return $response;
        }

        return $next($request);
    }
}
