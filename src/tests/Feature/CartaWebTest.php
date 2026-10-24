<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartaWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_carta_web_carga_con_el_slug_del_restaurante(): void
    {
        $this->get('/c/picanteria-prueba')
            ->assertOk()
            ->assertSee('picanteria-prueba')
            ->assertSee('x-data="carta(', false);
    }

    public function test_un_slug_con_caracteres_raros_da_404(): void
    {
        $this->get('/c/Raro_Slug!')->assertNotFound();
    }
}
