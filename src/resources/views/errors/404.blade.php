@extends('layouts.simple')

@section('titulo', 'Página no encontrada')

@section('contenido')
    <p class="codigo">404</p>
    <h1 data-i18n="e404_h1">No encontramos esta página</h1>
    <p data-i18n="e404_p">Puede que el enlace esté mal escrito o que la página ya no exista.</p>
    <a class="boton" href="/" data-i18n="e404_btn">Volver al inicio</a>
@endsection
