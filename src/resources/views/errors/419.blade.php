@extends('layouts.simple')

@section('titulo', 'Sesión expirada')
@section('titulo_i18n', 'e419_t')

@section('contenido')
    <p class="codigo">419</p>
    <h1 data-i18n="e419_h1">Tu sesión ha expirado</h1>
    <p data-i18n="e419_p">Por seguridad, la sesión se cerró tras un periodo de inactividad. Recarga e inicia sesión nuevamente.</p>
    <a class="boton" href="{{ url('/') }}" data-i18n="e404_btn">Volver al inicio</a>
@endsection
