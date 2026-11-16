<section class="kitchen-view" x-show="vistaCocina" aria-label="Comandas de cocina">
    <header class="kitchen-heading">
        <div><h1 x-text="t('nav_cocina')">Cocina</h1><p x-text="t('cocina_sync')"></p></div>
        <div class="kitchen-sync"><span role="status" x-show="busy || cargando" x-text="t('actualizando_datos')"></span><span x-show="ultimaPedidos > 1" x-text="t('cocina_pagina')"></span><span x-show="ultimaCocina" x-text="t('cocina_actualizada') + ' · ' + ultimaCocina"></span></div>
    </header>
    <p class="kitchen-empty" x-show="!error && !cargando && !busy && !comandasCocina.length" x-text="t('cocina_vacio')"></p>
    <template x-for="grupo in gruposCocina" :key="grupo.id">
    <section class="kitchen-group" :aria-label="grupo.titulo">
        <h2 class="kitchen-group-title"><span x-text="grupo.titulo"></span><span x-text="grupo.pedidos.length"></span></h2>
        <p class="kitchen-group-empty" x-show="!grupo.pedidos.length" x-text="t('cocina_vacio')"></p>
    <div class="kitchen-tickets">
        <template x-for="p in grupo.pedidos" :key="p.id">
            <article class="kitchen-ticket" :data-stage="p.estado" :data-comanda-id="p.id" :aria-label="destinoCocina(p) + ' · ' + t('pedido_num') + p.id">
                <header class="kitchen-ticket-header"><h3 x-text="destinoCocina(p)"></h3></header>
                <div class="kitchen-ticket-meta"><span x-text="t('pedido_num') + p.id"></span><time :datetime="p.created_at" :title="new Date(p.created_at).toLocaleString(locale)" x-text="esperaCocina(p, relojCocina)"></time></div>
                <ul class="kitchen-items"><template x-for="d in p.detalles" :key="d.id"><li><strong class="kitchen-quantity" x-text="d.cantidad"></strong><span x-text="d.nombre_producto"></span></li></template></ul>
                <div class="kitchen-notes" x-show="p.notas"><strong x-text="t('cocina_notas')"></strong><p x-text="p.notas"></p></div>
                <footer class="kitchen-ticket-actions">
                    <button type="button" class="kitchen-action" :disabled="busy || cargando" @click="p.estado === 'preparando' ? estado(p, 'listo') : tomarComanda(p, $event)" x-text="t(p.estado === 'preparando' ? 'marcar_listo' : 'tomar_pedido')"></button>
                </footer>
            </article>
        </template>
    </div>
    </section>
    </template>
</section>
