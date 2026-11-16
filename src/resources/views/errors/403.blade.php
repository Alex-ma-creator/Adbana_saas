@extends('layouts.simple')

@section('titulo', 'Acceso denegado')
@section('titulo_i18n', 'e403_t')

@section('contenido')
    <p class="codigo">403</p>
    <h1 data-i18n="e403_h1">No tienes permiso para acceder</h1>
    <p data-i18n="e403_p">Tu cuenta o rol actual no cuenta con autorización para ver esta sección o realizar esta operación.</p>
    <a class="boton" href="{{ url('/') }}" data-i18n="e404_btn">Volver al inicio</a>
@endsection
