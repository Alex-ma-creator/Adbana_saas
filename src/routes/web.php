<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'en-construccion');
Route::view('/panel', 'panel');
Route::view('/login', 'panel');

Route::get('/c/{slug}', fn (string $slug) => view('carta', ['slug' => $slug]))
    ->where('slug', '[a-z0-9\-]+');
