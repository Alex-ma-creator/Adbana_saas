@extends('layouts.simple')

@section('titulo', 'Demasiadas peticiones')
@section('titulo_i18n', 'e429_t')

@section('contenido')
    <p class="codigo">429</p>
    <h1 data-i18n="e429_h1">Demasiadas solicitudes seguidas</h1>
    <p data-i18n="e429_p">Has alcanzado el límite de solicitudes por minuto. Espera unos segundos antes de volver a intentarlo.</p>
    <a class="boton" href="{{ url('/') }}" data-i18n="e404_btn">Volver al inicio</a>
@endsection
