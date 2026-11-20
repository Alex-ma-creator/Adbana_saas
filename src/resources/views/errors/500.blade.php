@extends('layouts.simple')

@section('titulo', 'Error del servidor')
@section('titulo_i18n', 'e500_t')

@section('contenido')
    @php
        $reqId = $request_id ?? (request()?->attributes?->get('_request_id') ?? 'N/A');
        $tiempoMs = $tiempo_respuesta_ms ?? null;
        $motivo = $motivo_caida ?? 'Ocurrió un inconveniente interno contenido por el servidor. El incidente quedó registrado en los logs estructurados.';
        $codigoFallo = $codigo_fallo ?? 'INTERNAL_SERVER_ERROR';
    @endphp
    <p class="codigo">500</p>
    <h1 data-i18n="e500_h1">Ocurrió un problema en el servidor</h1>
    <p data-i18n="e500_p">{{ $motivo }}</p>
    <div style="margin:1.25rem auto;padding:0.9rem 1rem;border-radius:10px;background:rgba(15,23,42,0.06);border:1px solid rgba(15,23,42,0.12);text-align:left;font-size:0.85rem;line-height:1.5;max-width:460px;">
        <div><strong>Código de fallo:</strong> <code>{{ $codigoFallo }}</code></div>
        <div><strong>Request ID:</strong> <code>{{ $reqId }}</code></div>
        @if($tiempoMs !== null)
            <div><strong>Tiempo de respuesta:</strong> {{ number_format((float) $tiempoMs, 2) }} ms</div>
        @endif
    </div>
    <a class="boton" href="{{ url('/') }}" data-i18n="e404_btn">Volver al inicio</a>
@endsection
