<?php

namespace Tests\Feature;

use App\Models\Pedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class InterfazPanelTest extends TestCase
{
    use RefreshDatabase, PreparaDatos;

    public function test_dashboard_muestra_cobros_reales_y_aisla_restaurantes(): void
    {
        $r = $this->crearRestaurante();
        $u = $this->crearUsuario($r);
        $otro = $this->crearRestaurante('Ajeno');
        Pedido::create(['restaurante_id' => $r->id, 'cliente_nombre' => 'Ana', 'tipo' => 'recojo', 'total' => '25.00', 'cobrado_at' => now()]);
        Pedido::create(['restaurante_id' => $otro->id, 'cliente_nombre' => 'Ajeno', 'tipo' => 'recojo', 'total' => '500.00', 'cobrado_at' => now()]);
        $this->actuarComo($u);
        $this->getJson('/api/reportes/dashboard')->assertOk()->assertJsonPath('ventas_hoy', '25.00')
            ->assertJsonPath('cobrados_hoy', 1)->assertJsonCount(7, 'serie')->assertJsonCount(1, 'recientes');
        $this->actuarComo($this->crearUsuario($r, 'cajero'));
        $this->getJson('/api/reportes/dashboard')->assertForbidden();
    }

    public function test_perfil_actualiza_solo_datos_propios_y_no_cambia_rol(): void
    {
        $r = $this->crearRestaurante();
        $u = $this->crearUsuario($r, 'mozo');
        $this->actuarComo($u);
        $this->patchJson('/api/perfil', ['name' => 'Nuevo nombre', 'email' => $u->email, 'rol' => 'dueno', 'restaurante_id' => 999])
            ->assertOk()->assertJsonPath('name', 'Nuevo nombre')->assertJsonPath('rol', 'mozo')->assertJsonPath('restaurante_id', $r->id);
    }

    public function test_cambio_de_password_exige_la_actual_y_revoca_otros_tokens(): void
    {
        $u = $this->crearUsuario($this->crearRestaurante());
        $u->createToken('otra sesion');
        $this->actuarComo($u);
        $body = ['name' => $u->name, 'email' => $u->email, 'password' => 'NuevaClave123', 'password_confirmation' => 'NuevaClave123'];
        $this->patchJson('/api/perfil', $body + ['current_password' => 'incorrecta'])->assertUnprocessable();
        $this->patchJson('/api/perfil', $body + ['current_password' => 'clave12345'])->assertOk();
        $this->assertTrue(Hash::check('NuevaClave123', $u->fresh()->password));
        $this->assertSame(0, $u->tokens()->count());
    }

    public function test_cambiar_correo_exige_password_y_cuenta_activa(): void
    {
        $u = $this->crearUsuario($this->crearRestaurante());
        $this->actuarComo($u);
        $this->patchJson('/api/perfil', ['name' => $u->name, 'email' => 'nuevo@example.com'])->assertUnprocessable();
        $this->patchJson('/api/perfil', ['name' => $u->name, 'email' => 'nuevo@example.com', 'current_password' => 'clave12345'])->assertOk();
        $u->update(['activo' => false]);
        $this->patchJson('/api/perfil', ['name' => $u->name, 'email' => $u->email])->assertForbidden();
    }
}
