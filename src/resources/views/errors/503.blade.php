@extends('layouts.simple')

@section('titulo', 'Servicio no disponible')
@section('titulo_i18n', 'e503_t')

@section('contenido')
    <p class="codigo">503</p>
    <h1 data-i18n="e503_h1">Servicio temporalmente en mantenimiento</h1>
    <p data-i18n="e503_p">El servidor o la base de datos se está reconectando. Tus datos están seguros; vuelve a intentarlo en unos segundos.</p>
    <a class="boton" href="{{ url('/') }}" data-i18n="e404_btn">Volver al inicio</a>
@endsection
