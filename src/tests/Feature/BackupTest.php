<?php

namespace Tests\Feature;

use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_dueno_puede_generar_listar_y_descargar_backup(): void
    {
        Storage::fake('local');

        $restaurante = Restaurante::create([
            'nombre' => 'Restaurante Respaldo',
            'slug' => 'restaurante-respaldo',
        ]);

        $dueno = User::create([
            'restaurante_id' => $restaurante->id,
            'name' => 'Dueño Respaldo',
            'email' => 'dueno@respaldo.test',
            'password' => 'secreto123',
            'rol' => 'dueno',
        ]);

        $token = $dueno->createToken('api')->plainTextToken;

        $crear = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/backups');

        $crear->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'backup' => ['archivo', 'tamano_bytes', 'sha256', 'creado_en'],
            ]);

        $archivo = $crear->json('backup.archivo');

        $listar = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/backups');

        $listar->assertStatus(200);
        $this->assertNotEmpty($listar->json('backups'));

        $descargar = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/backups/' . $archivo);

        $descargar->assertStatus(200);
        $contenido = json_decode($descargar->getContent(), true);
        $this->assertSame('Adbana SaaS', $contenido['meta']['proyecto'] ?? null);
    }

    public function test_empleado_no_dueno_no_puede_acceder_a_backups(): void
    {
        $restaurante = Restaurante::create([
            'nombre' => 'Restaurante Rol',
            'slug' => 'restaurante-rol',
        ]);

        $mozo = User::create([
            'restaurante_id' => $restaurante->id,
            'name' => 'Mozo',
            'email' => 'mozo@rol.test',
            'password' => 'secreto123',
            'rol' => 'mozo',
        ]);

        $token = $mozo->createToken('api')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/backups')
            ->assertStatus(403);
    }
}
