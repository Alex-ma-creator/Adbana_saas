@extends('layouts.simple')

@section('titulo', 'Página no encontrada')

@section('contenido')
    <p class="codigo">404</p>
    <h1>No encontramos esta página</h1>
    <p>Puede que el enlace esté mal escrito o que la página ya no exista.</p>
    <a class="boton" href="/">Volver al inicio</a>
@endsection
