<section x-show="tab==='configuracion' && user?.rol==='dueno'" class="setup-restaurant">
    <template x-if="configNegocio">
        <div>
            <div class="card">
                <span class="eyebrow">A tu manera</span>
                <h2>Prepara tu restaurante</h2>
                <p class="muted">Configura cómo atiendes. Puedes cambiar estas opciones después; tus pedidos e historial se conservan.</p>
                <div class="row">
                    <button class="primary" @click="abrirEditorCarta()">Editar carta</button>
                    <button @click="ir('equipo')" x-text="avanceConfig.equipo?'Equipo listo · revisar':'Invitar a tu equipo'"></button>
                    <a :href="'/c/'+user.restaurante.slug" target="_blank" rel="noopener">Ver como cliente</a>
                </div>
            </div>
            @include('layouts.editor-carta')
            <form class="card" @submit.prevent="guardarConfiguracion()">
                <h2>1. ¿Cómo atiendes?</h2>
                <p class="muted">Selecciona una o varias modalidades. Delivery significa que tu negocio coordina el reparto.</p>
                <div class="setup-options">
                    <label><input type="checkbox" value="mesa" x-model="configNegocio.modalidades"> En mesa</label>
                    <label><input type="checkbox" value="recojo" x-model="configNegocio.modalidades"> Mostrador / recojo</label>
                    <label><input type="checkbox" value="delivery" x-model="configNegocio.modalidades"> Delivery propio</label>
                </div>
                <h2>2. Presenta tu negocio</h2>
                <label>Descripción<textarea maxlength="500" x-model="configNegocio.descripcion" placeholder="Cuenta qué hace especial a tu restaurante"></textarea></label>
                <div class="setup-fields">
                    <label>Dirección<input maxlength="255" x-model="configNegocio.direccion"></label>
                    <label>Teléfono<input maxlength="30" x-model="configNegocio.telefono" type="tel"></label>
                    <label>Horario de atención<input maxlength="200" x-model="configNegocio.horario" placeholder="Lunes a sábado, 12:00 a 22:00"></label>
                </div>
                <div x-show="configNegocio.modalidades.includes('mesa')">
                    <h2>3. Prepara tus mesas</h2>
                    <p class="muted">Genera Mesa 01, Mesa 02… hasta el número indicado. Repetir este paso no duplica ni elimina mesas; después puedes cambiar nombres y zonas.</p>
                    <label>Generar mesas hasta el número<input type="number" min="0" max="200" step="1" x-model="cantidadMesas"></label>
                </div>
                <label class="setup-check"><input type="checkbox" x-model="configNegocio.publicado"> Mostrar mi restaurante en el catálogo público</label>
                <p class="muted">Necesitas al menos un producto activo, dirección, horario y teléfono. Puedes compartir tu carta directamente aunque no aparezcas en el catálogo.</p>
                <button class="primary" :disabled="busy">Guardar configuración</button>
            </form>
            <div class="card" x-show="mesasConfig.length">
                <h2>Tus mesas</h2><p class="muted">Imprime la tarjeta o guárdala como PDF desde la ventana de impresión. En localhost solo sirve en este equipo; genera el QR definitivo desde el dominio público HTTPS del restaurante.</p><p class="muted">Desactiva las que no uses. No se modifica una mesa mientras tenga una cuenta abierta.</p>
                <template x-for="mesa in mesasConfig" :key="mesa.id">
                    <form class="setup-table" @submit.prevent="guardarMesa(mesa)">
                        <label>Nombre<input x-model="mesa.nombre" maxlength="20" required></label>
                        <label>Zona<input x-model="mesa.zona" maxlength="60" required></label>
                        <label class="setup-check"><input type="checkbox" x-model="mesa.activa"> Activa</label>
                        <button :disabled="busy">Guardar mesa</button><button type="button" @click="imprimirQr(mesa)" :disabled="!mesa.activa">Imprimir tarjeta</button>
                    </form>
                </template>
            </div>
        </div>
    </template>
</section>
