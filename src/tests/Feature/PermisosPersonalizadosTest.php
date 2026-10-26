<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaDatos;
use Tests\TestCase;

class PermisosPersonalizadosTest extends TestCase
{
    use RefreshDatabase, PreparaDatos;

    private function equipo(): array
    {
        $r=$this->crearRestaurante();
        return [$r,$this->crearUsuario($r),$this->crearUsuario($r,'mozo')];
    }

    public function test_dueno_delega_cobro_sin_cambiar_rol_y_queda_auditado(): void
    {
        [$r,$dueno,$mozo]=$this->equipo();$p=$this->crearProducto($r);
        $this->actuarComo($dueno);
        $this->patchJson("/api/usuarios/$mozo->id",['permisos_personalizados'=>['caja'=>true]])->assertOk()->assertJsonPath('rol','mozo')->assertJsonPath('permisos.caja',true);
        $this->assertDatabaseHas('auditoria',['tabla'=>'users','registro_id'=>$mozo->id,'user_id'=>$dueno->id,'accion'=>'actualizar']);
        $this->actuarComo($mozo->fresh());
        $caja=$this->postJson('/api/caja/abrir',['saldo_inicial'=>0])->assertCreated()->json('id');
        $id=$this->postJson('/api/pedidos',['clave_operacion'=>(string)Str::uuid(),'cliente_nombre'=>'Prueba','tipo'=>'recojo','items'=>[['producto_id'=>$p->id,'cantidad'=>1]]])->assertCreated()->json('id');
        $this->postJson("/api/ventas/$id/cobrar",['medio_pago'=>'yape'])->assertOk()->assertJsonPath('caja_id',$caja)->assertJsonMissingPath('detalles.0.costo_unitario');
        $this->assertDatabaseHas('cajas',['id'=>$caja,'user_id'=>$mozo->id]);
        $this->getJson('/api/reportes/dashboard')->assertForbidden();
        $this->getJson('/api/usuarios')->assertForbidden();
    }

    public function test_revocar_con_token_existente_bloquea_siguiente_peticion(): void
    {
        [$r,$dueno,$mozo]=$this->equipo();$this->actuarComo($dueno);
        $this->patchJson("/api/usuarios/$mozo->id",['permisos_personalizados'=>['caja'=>true]])->assertOk();
        $token=$mozo->createToken('prueba')->plainTextToken;
        $this->patchJson("/api/usuarios/$mozo->id",['permisos_personalizados'=>[]])->assertOk();
        auth()->forgetGuards();
        $this->withToken($token)->getJson('/api/caja/actual')->assertForbidden();
    }

    public function test_turno_abierto_impide_revocar_caja_y_desactivar_incluso_boolean_cero(): void
    {
        [$r,$dueno,$mozo]=$this->equipo();
        $mozo->permisos_personalizados=['caja'=>true];$mozo->save();
        $this->actuarComo($mozo);$this->postJson('/api/caja/abrir',['saldo_inicial'=>0])->assertCreated();
        $this->actuarComo($dueno);
        $this->patchJson("/api/usuarios/$mozo->id",['permisos_personalizados'=>['caja'=>false]])->assertConflict()->assertJsonPath('message','Primero debe cerrar su turno de caja. Después podrás retirar el permiso o desactivar su cuenta.');
        $this->patchJson("/api/usuarios/$mozo->id",['activo'=>0])->assertConflict();
        $this->assertTrue($mozo->fresh()->activo);
        $this->actuarComo($mozo->fresh());$this->postJson('/api/caja/cerrar',['efectivo_contado'=>0])->assertOk();
        $this->actuarComo($dueno);$this->patchJson("/api/usuarios/$mozo->id",['permisos_personalizados'=>[]])->assertOk()->assertJsonPath('permisos.caja',false);
    }

    public function test_encargado_no_escala_ni_restablece_password_de_superior(): void
    {
        [$r,$dueno,$mozo]=$this->equipo();
        $encargado=$this->crearUsuario($r,'mozo');$encargado->permisos_personalizados=['equipo'=>true,'caja'=>true];$encargado->save();
        $this->actuarComo($encargado);
        $this->patchJson("/api/usuarios/$mozo->id",['permisos_personalizados'=>['caja'=>true]])->assertOk();
        $this->patchJson("/api/usuarios/$mozo->id",['permisos_personalizados'=>['reportes'=>true]])->assertForbidden();
        $this->patchJson("/api/usuarios/$mozo->id",['permisos_personalizados'=>['equipo'=>true]])->assertForbidden();
        $this->patchJson("/api/usuarios/$encargado->id",['permisos_personalizados'=>['reportes'=>true]])->assertForbidden();
        $this->patchJson("/api/usuarios/$dueno->id",['password'=>'claveOtra123'])->assertNotFound();
        $superior=$this->crearUsuario($r,'cajero');
        $this->patchJson("/api/usuarios/$superior->id",['password'=>'claveOtra123'])->assertForbidden();
        $this->postJson('/api/usuarios',['name'=>'Escala','email'=>'escala@prueba.com','password'=>'claveOtra123','rol'=>'cajero'])->assertForbidden();
    }

    public function test_aislamiento_claves_desconocidas_y_propietario_protegidos(): void
    {
        [$r,$dueno,$mozo]=$this->equipo();$otro=$this->crearUsuario($this->crearRestaurante('Otro'),'mozo');
        $this->actuarComo($dueno);
        $this->patchJson("/api/usuarios/$otro->id",['permisos_personalizados'=>['caja'=>true]])->assertNotFound();
        $this->patchJson("/api/usuarios/$dueno->id",['permisos_personalizados'=>['equipo'=>false]])->assertNotFound();
        $this->patchJson("/api/usuarios/$mozo->id",['permisos_personalizados'=>['superadmin'=>true]])->assertUnprocessable();
        $this->patchJson("/api/usuarios/$mozo->id",['permisos_personalizados'=>['caja'=>'si']])->assertUnprocessable();
        $this->getJson('/api/usuarios/permisos')->assertOk()->assertJsonPath('roles.mozo.caja',false);
    }

    public function test_permisos_separados_de_productos_stock_y_reportes(): void
    {
        [$r,$dueno,$mozo]=$this->equipo();$p=$this->crearProducto($r);
        $this->actuarComo($dueno);
        $this->patchJson("/api/usuarios/$mozo->id",['permisos_personalizados'=>['stock'=>true]])->assertOk();
        $this->actuarComo($mozo->fresh());
        $this->getJson('/api/productos')->assertOk()->assertJsonMissingPath('data.0.costo');
        $this->postJson('/api/stock/movimientos',['producto_id'=>$p->id,'tipo'=>'entrada','cantidad'=>2,'motivo'=>'Recepción'])->assertCreated();
        $this->patchJson("/api/productos/$p->id",['precio'=>50])->assertForbidden();
        $this->getJson('/api/reportes/resumen')->assertForbidden();
        $this->getJson('/api/usuarios')->assertForbidden();
    }

    public function test_quitar_permiso_base_y_restablecer_no_altera_otros_usuarios(): void
    {
        [$r,$dueno,$mozo]=$this->equipo();$cajero=$this->crearUsuario($r,'cajero');$this->actuarComo($dueno);
        $this->patchJson("/api/usuarios/$cajero->id",['permisos_personalizados'=>['cocina'=>false,'cancelar'=>false]])->assertOk();
        $this->actuarComo($cajero->fresh());$this->getJson('/api/cocina')->assertForbidden();
        $this->actuarComo($dueno);$this->patchJson("/api/usuarios/$cajero->id",['permisos_personalizados'=>[]])->assertOk()->assertJsonPath('permisos.cocina',true);
        $this->actuarComo($mozo);$this->getJson('/api/caja/actual')->assertForbidden();
    }
}
