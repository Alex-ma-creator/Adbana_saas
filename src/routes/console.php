<?php

use App\Models\AdministradorPlataforma;
use App\Support\AdministracionPlataforma;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

Artisan::command('adbana:crear-superadmin', function () {
    $name = $this->ask('Tu nombre');
    $email = strtolower(trim((string) $this->ask('Correo del superadministrador')));
    $password = $this->secret('Contraseña nueva (mínimo 12 caracteres)');
    $confirmation = $this->secret('Repite la contraseña');
    $validator = Validator::make(['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation], ['name' => 'required|string|max:120', 'email' => 'required|email|max:255', 'password' => 'required|string|min:12|max:72|confirmed']);
    $validator->after(function ($validator) use ($password) {
        if (strlen((string) $password) > 72) {
            $validator->errors()->add('password', 'La contraseña admite un máximo de 72 bytes.');
        }
    });
    if ($validator->fails()) {
        $this->error($validator->errors()->first());

        return 1;
    }
    try {
        DB::transaction(function () use ($name, $email, $password) {
            DB::table('control_plataforma')->where('id', 1)->lockForUpdate()->firstOrFail();
            if (AdministradorPlataforma::withTrashed()->exists()) {
                throw new RuntimeException('Ya existe administración. Crea los siguientes accesos desde el panel.');
            }
            $admin = AdministradorPlataforma::create(['name' => $name, 'email' => $email, 'password' => $password, 'rol' => 'superadmin', 'activo' => true]);
            app(AdministracionPlataforma::class)->registrar(null, 'crear_superadmin_inicial', 'administrador', $admin->id, null, $admin->toArray(), 'Alta inicial desde consola');
        });
    } catch (RuntimeException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $this->info('Acceso creado. Abre /administracion e inicia sesión.');

    return 0;
})->purpose('Crear el primer superadministrador de Adbana sin registro público');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
