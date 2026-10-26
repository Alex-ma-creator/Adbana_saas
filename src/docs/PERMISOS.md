# Permisos por persona

Cada empleado conserva su rol inicial y puede recibir o perder funciones en **Equipo → Permisos**. Las cuentas existentes mantienen sus accesos mientras no se personalicen. El antiguo rol `mozo_cajero` se conserva; las cuentas nuevas usan Mesero y el permiso de Caja cuando corresponde.

## Uso
1. Crear un integrante con su rol inicial.
2. Elegir Permisos, leer las descripciones y marcar o desmarcar funciones.
3. Guardar. Restablecer rol base prepara los valores originales y también requiere Guardar.
4. El dueño puede conceder Administrar el equipo. Ese encargado solo gestiona personas cuyos permisos sean iguales o inferiores a los suyos; no modifica otros administradores, al dueño ni su propia cuenta.

Caja incluye cobros y apertura, movimientos y cierre del turno propio, sin acceso a cajas ajenas ni reportes. Productos incluye costos y stock inicial de productos nuevos; ajustar existencias de productos anteriores requiere Inventario. Reportes incluye costos y ganancias. Cancelar solo permite pedidos sin cobrar y devuelve stock; no procesa devoluciones.

## Controles
- Catálogo central en `app/Support/Permisos.php`. Una columna JSON nullable por usuario guarda las excepciones. No se ejecutan consultas adicionales por cada permiso.
- Middleware por capacidad en los endpoints y autorización por acción para estados de pedidos. El servidor no confía en botones o menús.
- Dueño protegido, sin creación de dueños o superadministradores desde Equipo. Administración del equipo solo es delegable por el dueño.
- Gestión limitada al restaurante y a cuentas activas. No se permite restablecer contraseñas de cuentas con poderes superiores al encargado.
- Cambios de permisos y rol guardados en transacción y auditoría con actor, fecha y valores anteriores/nuevos; las contraseñas están excluidas.
- Un turno abierto impide retirar Caja, cambiar a un rol sin Caja o desactivar al operador. Debe cerrar primero su turno. Abrir caja bloquea y vuelve a comprobar el usuario para impedir una carrera con la retirada del permiso.
- Cada petición nueva valida los permisos vigentes. Una operación ya aceptada puede terminar. El menú se sincroniza al cambiar de sección y cada 30 segundos mientras el panel está activo; limpia datos y formularios al cambiar los permisos.
- Desactivar o cambiar contraseña revoca tokens; cambiar permisos no exige cerrar sesión.

## Instalación y pruebas
Migración aditiva: `2026_10_26_020000_add_permission_overrides_to_users`. No altera ventas, stock ni cajas.

```bat
docker compose exec app php artisan migrate
docker compose exec app php artisan view:clear
docker compose exec app php artisan test
node src/tests/Frontend/panel-carta.cjs
```

Integración PostgreSQL: delegación de cobro, caja propia, costos privados, revocación con token existente, turno abierto, escalamiento por permisos o contraseña, aislamiento, claves desconocidas y restauración del rol. Las pruebas de permisos no sustituyen las de carga de escritura.
