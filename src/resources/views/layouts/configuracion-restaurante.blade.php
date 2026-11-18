<section x-show="tab==='configuracion' && user?.rol==='dueno'" class="setup-restaurant">
    <template x-if="configNegocio">
        <div>
            <div class="card">
                <span class="eyebrow">A tu manera</span>
                <h2>Prepara tu restaurante</h2>
                <p class="muted">Configura cómo atiendes. Puedes cambiar estas opciones después; tus pedidos e historial se conservan.</p>
                <div class="row">
                    <button class="primary" @click="abrirEditorCarta()">Editar carta</button>
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
                <label class="setup-check"><input type="checkbox" x-model="configNegocio.publicado"> Mostrar mi restaurante en el catálogo público</label>
                <p class="muted">Necesitas al menos un producto activo, dirección, horario y teléfono. Puedes compartir tu carta directamente aunque no aparezcas en el catálogo.</p>
                <button class="primary" :disabled="busy">Guardar configuración</button>
            </form>
            <div class="card setup-table-generator" x-show="configNegocio.modalidades.includes('mesa')" x-data="{ verMesas: false }">
                <div class="table-setup-hero">
                <div class="table-setup-copy">
                <span class="table-setup-label">TU SALÓN, LISTO PARA ATENDER</span>
                <h2>Prepara tus mesas</h2>
                <p class="muted">Ingresa la cantidad de mesas de tu restaurante para generarlas con sus códigos QR.</p>
                <form @submit.prevent="guardarConfiguracion()">
                <div class="setup-table-generator-controls">
                    <div class="table-quantity-field">
                    <label for="cantidad-mesas">Número de mesas</label>
                    <div class="table-quantity-stepper">
                        <button type="button" aria-label="Reducir número de mesas" :disabled="busy || Number(cantidadMesas) <= 1" @click="cantidadMesas = Math.max(1, Number(cantidadMesas) - 1)">−</button>
                        <input id="cantidad-mesas" type="text" inputmode="numeric" pattern="[0-9]{1,2}" maxlength="2" required x-model="cantidadMesas" @input="cantidadMesas = $event.target.value.replace(/[^0-9]/g, '')" aria-describedby="cantidad-mesas-ayuda">
                        <button type="button" aria-label="Aumentar número de mesas" :disabled="busy" @click="Number(cantidadMesas) >= 25 ? avisarLimiteMesas() : cantidadMesas = Number(cantidadMesas) + 1">+</button>
                    </div>
                    </div>
                    <button type="submit" class="primary" :disabled="busy || !Number(cantidadMesas)">Generar mesas</button>
                    <button type="button" @click="verMesas = !verMesas" :aria-expanded="verMesas" aria-controls="mesas-configuracion" x-text="verMesas ? 'Ocultar mesas' : 'Ver mesas'">Ver mesas</button>
                </div>
                <small id="cantidad-mesas-ayuda" class="muted">Máximo 25 mesas.</small>
                </form>
                </div>
                </div>
            <div id="mesas-configuracion" class="setup-table-overview" x-show="verMesas" x-cloak>
                <h2>Tus mesas</h2>
                <p class="muted" x-show="!mesasConfig.length">Todavía no tienes mesas. Indica una cantidad y pulsa “Generar mesas”.</p>
                <p class="muted">Cada mesa tiene su propio QR. Imprímelo y colócalo en la mesa para que tus clientes vean la carta.</p>
                <div class="setup-table-grid">
                    <template x-for="mesa in mesasConfig" :key="mesa.id">
                        <article class="setup-table-card" :class="{'is-inactive': !mesa.activa}">
                            <button type="button" class="table-delete-button" @click="eliminarMesa(mesa)" :disabled="busy" :aria-label="'Eliminar ' + mesa.nombre" :title="'Eliminar ' + mesa.nombre">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m8 5 1-2h6l1 2M4 7h16M6 7l1 13h10l1-13M10 11v5M14 11v5" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 3l3 2" stroke-linecap="round"/></svg>
                            </button>
                            <h3 x-text="mesa.nombre"></h3>
                            <p class="table-capacity" x-text="mesa.capacidad ? mesa.capacidad + (mesa.capacidad === 1 ? ' persona' : ' personas') : 'Capacidad sin definir'"></p>
                            <small class="muted" x-show="!mesa.activa">Inactiva</small>
                            <div class="table-card-actions">
                            <button type="button" @click="imprimirQr(mesa)" :disabled="!mesa.activa || busy" :aria-label="'Imprimir QR de ' + mesa.nombre">Imprimir QR</button>
                            <button type="button" @click="abrirEditorMesa(mesa)" :disabled="busy" aria-haspopup="dialog">Editar</button>
                            </div>
                        </article>
                    </template>
                </div>
            </div>
            </div>
        </div>
    </template>
    <dialog class="table-edit-dialog" x-ref="editarMesaDialog" aria-labelledby="editar-mesa-titulo" @cancel.prevent="cerrarEditorMesa()" @click.self="if ($event.offsetX < 0 || $event.offsetY < 0 || $event.offsetX > $el.clientWidth || $event.offsetY > $el.clientHeight) cerrarEditorMesa()">
        <template x-if="mesaEditor">
            <form @submit.prevent="guardarEdicionMesa()" :aria-busy="guardandoMesa">
                <div class="table-edit-heading">
                    <div><span class="eyebrow">TU SALÓN</span><h2 id="editar-mesa-titulo" x-text="'Editar ' + mesaEditor.nombre"></h2></div>
                    <button type="button" class="table-edit-close" @click="cerrarEditorMesa()" :disabled="guardandoMesa" aria-label="Cerrar edición">×</button>
                </div>
                <p class="muted">Define dónde está la mesa y cuántas personas pueden sentarse.</p>
                <fieldset :disabled="guardandoMesa">
                    <label>Nombre<input x-model="mesaEditor.nombre" maxlength="20" required autofocus></label>
                    <label>Zona<input x-model="mesaEditor.zona" maxlength="60" required></label>
                    <label>Número de sillas<input type="number" x-model="mesaEditor.capacidad" min="1" max="100" step="1" required inputmode="numeric" placeholder="Ej. 4"></label>
                </fieldset>
                <p class="table-edit-error" x-show="mesaError" x-text="mesaError" role="alert"></p>
                <div class="table-edit-actions">
                    <button type="button" @click="cerrarEditorMesa()" :disabled="guardandoMesa">Cancelar</button>
                    <button type="submit" class="primary" :disabled="guardandoMesa" x-text="guardandoMesa ? 'Guardando…' : 'Guardar cambios'">Guardar cambios</button>
                </div>
            </form>
        </template>
    </dialog>
</section>
