<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrudCompletoTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_completo_de_gastos_y_auditoria(): void
    {
        $restaurante = Restaurante::create([
            'nombre' => 'Restaurante Gastos',
            'slug' => 'restaurante-gastos',
        ]);

        $dueno = User::create([
            'restaurante_id' => $restaurante->id,
            'name' => 'Dueño Gastos',
            'email' => 'dueno@gastos.test',
            'password' => 'secreto123',
            'rol' => 'dueno',
        ]);

        $token = $dueno->createToken('api')->plainTextToken;
        $auth = ['Authorization' => 'Bearer ' . $token];

        // Crear
        $crear = $this->withHeaders($auth)->postJson('/api/gastos', [
            'descripcion' => 'Compra de insumos frescos',
            'categoria' => 'insumos',
            'monto' => 120.50,
        ]);
        $crear->assertStatus(201);
        $gastoId = $crear->json('id');

        // Mostrar (show)
        $this->withHeaders($auth)->getJson("/api/gastos/{$gastoId}")
            ->assertStatus(200)
            ->assertJsonPath('descripcion', 'Compra de insumos frescos');

        // Actualizar (update)
        $this->withHeaders($auth)->patchJson("/api/gastos/{$gastoId}", [
            'descripcion' => 'Compra de insumos actualizada',
            'monto' => 150.00,
        ])->assertStatus(200)
            ->assertJsonPath('descripcion', 'Compra de insumos actualizada');

        // Paginación con per_page
        $this->withHeaders($auth)->getJson('/api/gastos?per_page=5')
            ->assertStatus(200)
            ->assertJsonPath('per_page', 5);

        // Eliminar (destroy)
        $this->withHeaders($auth)->deleteJson("/api/gastos/{$gastoId}")
            ->assertStatus(200);

        $this->withHeaders($auth)->getJson("/api/gastos/{$gastoId}")
            ->assertStatus(404);
    }

    public function test_crud_completo_de_categorias_y_usuarios_con_proteccion_409(): void
    {
        $restaurante = Restaurante::create([
            'nombre' => 'Restaurante Equipo',
            'slug' => 'restaurante-equipo',
        ]);

        $dueno = User::create([
            'restaurante_id' => $restaurante->id,
            'name' => 'Dueño Equipo',
            'email' => 'dueno@equipo.test',
            'password' => 'secreto123',
            'rol' => 'dueno',
        ]);

        $token = $dueno->createToken('api')->plainTextToken;
        $auth = ['Authorization' => 'Bearer ' . $token];

        // Categoría show
        $cat = $this->withHeaders($auth)->postJson('/api/categorias', [
            'nombre' => 'Bebidas Frías',
            'orden' => 1,
        ])->assertStatus(201);

        $catId = $cat->json('id');
        $this->withHeaders($auth)->getJson("/api/categorias/{$catId}")
            ->assertStatus(200)
            ->assertJsonPath('nombre', 'Bebidas Frías');

        // Crear empleado sin historial y eliminarlo
        $empSinHistorial = $this->withHeaders($auth)->postJson('/api/usuarios', [
            'name' => 'Mozo Temporal',
            'email' => 'temporal@equipo.test',
            'password' => 'secreto123',
            'rol' => 'mozo',
        ])->assertStatus(201);

        $empId = $empSinHistorial->json('id');

        $this->withHeaders($auth)->getJson("/api/usuarios/{$empId}")
            ->assertStatus(200)
            ->assertJsonPath('email', 'temporal@equipo.test');

        $this->withHeaders($auth)->deleteJson("/api/usuarios/{$empId}")
            ->assertStatus(200);

        // Crear empleado con historial (caja) y verificar bloqueo 409
        $empConCaja = User::create([
            'restaurante_id' => $restaurante->id,
            'name' => 'Cajero Histórico',
            'email' => 'cajero@equipo.test',
            'password' => 'secreto123',
            'rol' => 'cajero',
        ]);

        Caja::create([
            'restaurante_id' => $restaurante->id,
            'user_id' => $empConCaja->id,
            'saldo_inicial' => 100,
        ]);

        $this->withHeaders($auth)->deleteJson("/api/usuarios/{$empConCaja->id}")
            ->assertStatus(409);
    }
}
