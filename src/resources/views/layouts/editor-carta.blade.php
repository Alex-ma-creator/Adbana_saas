<template x-if="editorCarta">
    <section class="card" aria-label="Editar carta">
        <header class="row">
            <div><h2>Editar carta</h2><p class="muted">Elige un plato para cambiar lo que ven tus clientes.</p></div>
            <button type="button" @click="editorCarta=null; platoCarta=null" :disabled="busy">Cerrar editor</button>
        </header>
        <form x-show="platoCarta" @submit.prevent="guardarPlatoCarta()">
            <template x-if="platoCarta">
                <div>
                    <label>Nombre del plato<input x-model="platoCarta.nombre" required maxlength="150"></label>
                    <label>Descripción<textarea x-model="platoCarta.descripcion" maxlength="1000"></textarea></label>
                    <label>Precio (S/)<input type="number" x-model="platoCarta.precio" min="0" max="99999999.99" step="0.01" required></label>
                    <img x-show="platoCarta.imagen" :src="platoCarta.imagen || null" alt="Foto actual del plato" width="96">
                    <label>Foto del plato<input type="file" accept="image/jpeg,image/png,image/webp" @change="platoCarta.foto = $event.target.files[0] || null"></label>
                    <small>JPG, PNG o WebP · hasta 4 MB</small>
                    <label>Categoría<select x-model="platoCarta.categoria_id">
                        <option value="">Sin categoría</option>
                        <template x-for="categoria in editorCarta.categorias" :key="categoria.id">
                            <option :value="categoria.id" x-text="categoria.nombre"></option>
                        </template>
                    </select></label>
                    <label x-show="!platoCarta.id">Porciones disponibles<input type="number" x-model="platoCarta.stock" min="0" step="1"></label>
                    <label><input type="checkbox" x-model="platoCarta.activo"> Mostrar en la carta</label>
                    <button class="primary" :disabled="busy">Guardar plato</button>
                    <button type="button" @click="platoCarta=null" :disabled="busy">Cancelar</button>
                </div>
            </template>
        </form>
        <button type="button" :disabled="busy" @click="platoCarta={nombre:'', descripcion:'', precio:'', imagen:'', categoria_id:'', stock:0, activo:true}">Añadir plato</button>
        <div class="carta-tarjetas">
            <template x-for="plato in editorCarta.data.filter(p=>!p.es_insumo)" :key="plato.id">
                <article class="card carta-plato-editor">
                    <div class="carta-foto-editor">
                        <template x-if="plato.imagen"><img :src="plato.imagen" alt="" loading="lazy"></template>
                        <span x-show="!plato.imagen">Sin foto</span>
                    </div>
                    <small x-text="plato.categoria?.nombre || 'Sin categoría'"></small>
                    <h3 x-text="plato.nombre"></h3>
                    <p x-text="plato.descripcion"></p>
                    <strong x-text="money(plato.precio)"></strong>
                    <p x-show="!plato.activo">Oculto en la carta</p>
                    <button type="button" :disabled="busy" @click="platoCarta={...plato,categoria_id:plato.categoria_id || ''}; $nextTick(()=> $el.closest('section').querySelector('form input').focus())">Editar plato</button>
                </article>
            </template>
        </div>
        <nav class="row" x-show="editorCarta.last_page > 1" aria-label="Páginas de platos">
            <button :disabled="busy || editorCarta.current_page <= 1" @click="abrirEditorCarta(editorCarta.current_page-1)">Anterior</button>
            <button :disabled="busy || editorCarta.current_page >= editorCarta.last_page" @click="abrirEditorCarta(editorCarta.current_page+1)">Siguiente</button>
        </nav>
    </section>
</template>
