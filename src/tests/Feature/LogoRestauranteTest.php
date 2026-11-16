<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class LogoRestauranteTest extends TestCase
{
    use PreparaDatos, RefreshDatabase;

    private function imagen(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jY9kAAAAASUVORK5CYII='));
    }

    public function test_dueno_sube_logo_sin_modificar_otro_restaurante_y_se_puede_ver(): void
    {
        Storage::fake('public');
        $a = $this->crearRestaurante('A');
        $b = $this->crearRestaurante('B');
        $a->forceFill(['configuracion' => ['modalidades' => ['mesa']]])->save();
        $this->actuarComo($this->crearUsuario($a));
        $url = $this->post('/api/configuracion/logo', ['logo' => $this->imagen(), 'restaurante_id' => $b->id], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('restaurante.configuracion.modalidades', ['mesa'])
            ->json('restaurante.configuracion.logo');
        $this->assertStringStartsWith('/logos-restaurantes/'.$a->id.'/', $url);
        Storage::disk('public')->assertExists(ltrim($url, '/'));
        $this->assertNull($b->fresh()->configuracion);
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_mozo_no_puede_subir_logo(): void
    {
        Storage::fake('public');
        $this->actuarComo($this->crearUsuario($this->crearRestaurante(), 'mozo'));
        $this->post('/api/configuracion/logo', ['logo' => $this->imagen()], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_rechaza_archivos_que_no_son_imagenes(): void
    {
        Storage::fake('public');
        $this->actuarComo($this->crearUsuario($this->crearRestaurante()));
        $this->post('/api/configuracion/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_editar_configuracion_conserva_logo(): void
    {
        $r = $this->crearRestaurante();
        $r->forceFill(['configuracion' => ['logo' => '/logos-restaurantes/1/logo.png']])->save();
        $this->actuarComo($this->crearUsuario($r));
        $this->putJson('/api/configuracion', ['modalidades' => ['recojo'], 'telefono' => null, 'publicado' => false, 'cantidad_mesas' => 0])
            ->assertOk()->assertJsonPath('restaurante.configuracion.logo', '/logos-restaurantes/1/logo.png');
    }
}
