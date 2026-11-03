@extends('layouts.simple')

@section('titulo', 'Error del servidor')
@section('titulo_i18n', 'e500_t')

@section('contenido')
    <p class="codigo">500</p>
    <h1 data-i18n="e500_h1">Ocurrió un problema en el servidor</h1>
    <p data-i18n="e500_p">Estamos procesando tu solicitud, pero surgió un inconveniente interno. Por favor intenta de nuevo en unos instantes.</p>
    <a class="boton" href="{{ url('/') }}" data-i18n="e404_btn">Volver al inicio</a>
@endsection
