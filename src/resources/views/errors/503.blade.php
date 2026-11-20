@extends('layouts.simple')

@section('titulo', 'Servicio no disponible')
@section('titulo_i18n', 'e503_t')

@section('contenido')
    @php
        $reqId = $request_id ?? (request()?->attributes?->get('_request_id') ?? 'N/A');
        $tiempoMs = $tiempo_respuesta_ms ?? null;
        $motivo = $motivo_caida ?? 'El servidor activó contención controlada por alta carga o reconexión con la base de datos. Tus datos están seguros; vuelve a intentarlo en unos segundos.';
        $codigoFallo = $codigo_fallo ?? 'SERVICE_CONTAINED_OVERLOAD';
        $cuello = $cuello_de_botella ?? 'postgresql_lock_for_update';
    @endphp
    <p class="codigo">503</p>
    <h1 data-i18n="e503_h1">Servicio temporalmente en contención</h1>
    <p data-i18n="e503_p">{{ $motivo }}</p>
    <div style="margin:1.25rem auto;padding:0.9rem 1rem;border-radius:10px;background:rgba(15,23,42,0.06);border:1px solid rgba(15,23,42,0.12);text-align:left;font-size:0.85rem;line-height:1.5;max-width:460px;">
        <div><strong>Código de fallo:</strong> <code>{{ $codigoFallo }}</code></div>
        <div><strong>Cuello de botella:</strong> <code>{{ $cuello }}</code></div>
        <div><strong>Request ID:</strong> <code>{{ $reqId }}</code></div>
        @if($tiempoMs !== null)
            <div><strong>Tiempo de respuesta:</strong> {{ number_format((float) $tiempoMs, 2) }} ms</div>
        @endif
    </div>
    <a class="boton" href="{{ url('/') }}" data-i18n="e404_btn">Volver al inicio</a>
@endsection
