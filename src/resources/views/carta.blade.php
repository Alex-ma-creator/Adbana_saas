<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#181818">
    <title>Carta | Adbana</title>
    <link rel="stylesheet" href="/css/adbana.css">
    <link rel="stylesheet" href="/css/carta-visual.css?v=20261115polish1">
    <script>
        window.ADBANA_CONFIG = {
            arPublicUrl: @js(rtrim((string) env('AR_PUBLIC_URL', ''), '/')),
            arPublicBaseUrl: @js(rtrim((string) env('AR_PUBLIC_URL', ''), '/'))
        };
    </script>
    <script src="/js/carta.js?config=20261108&v=20261115polish1"></script>
    <script defer src="/js/alpine.min.js"></script>
    <script type="module" src="https://ajax.googleapis.com/ajax/libs/model-viewer/4.0.0/model-viewer.min.js"></script>
<link rel="stylesheet" href="/css/carga.css?v=20261031d"></head>
<body class="carta-publica">
<div x-data="carta(@js($slug))" x-init="cargar()"  x-cloak @keydown.escape.window="if (abierto) cerrarPedido(); idiomaAbierto = false; modalAR = false" x-effect="document.body.style.overflow = abierto || pedido || modalAR || detalleActivo !== null ? 'hidden' : ''">
@include('layouts.carga', ['visible' => 'consultasBackend > 0 && (enviando || cargando)', 'bloqueante' => 'enviando'])



    <div class="estado" x-show="error && !cargando">
        <p x-text="error"></p>
        <button type="button" class="btn" @click="cargar()" x-text="t('reintentar')">Reintentar</button>
    </div>

    <main class="contenedor" x-show="!cargando && !error" :inert="abierto || !!pedido || modalAR">
        <header class="encabezado">
            <div class="encabezado-top">
                <div class="carta-identidad">
                    <template x-if="restaurante?.configuracion?.logo"><img class="carta-logo" decoding="async" fetchpriority="high" :src="restaurante.configuracion.logo" alt="Logo del restaurante" width="84" height="84"></template>
                    <div>
                        <h1 x-text="restaurante && restaurante.nombre"></h1>
                        <p x-show="restaurante?.configuracion?.descripcion" x-text="restaurante?.configuracion?.descripcion"></p>
                    </div>
                </div>
                <div class="carta-lang" @click.outside="idiomaAbierto = false">
                    <button type="button" class="carta-lang-btn" :class="{'is-open': idiomaAbierto}" :aria-label="t('cambiar_idioma')" :aria-expanded="idiomaAbierto" aria-haspopup="listbox" @click="idiomaAbierto = !idiomaAbierto">
                        <span x-text="idiomaActual.corto">ES</span>
                    </button>
                    <div class="carta-lang-menu" x-show="idiomaAbierto" role="listbox" :aria-label="t('cambiar_idioma')">
                        <template x-for="lang in idiomasDisponibles" :key="lang.codigo">
                            <button type="button" class="carta-lang-opt" :class="{active: idioma === lang.codigo}" role="option" :aria-selected="idioma === lang.codigo" @click="cambiarIdioma(lang.codigo)">
                                <span class="carta-lang-code" x-text="lang.corto"></span>
                                <span class="carta-lang-label" x-text="lang.nombre"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </header>

        <p class="mesa-identificada" x-show="datosMesaNombre" x-text="datosMesaNombre"></p>
        <h2 class="categorias-titulo" x-text="t('categorias_aria')">Categorías</h2>

        <nav class="chips" :aria-label="t('categorias_aria')">
            <template x-for="c in categorias" :key="c.id ?? 'otros'">
                <button type="button" :aria-pressed="categoriaActual === c" :class="{ activa: categoriaActual === c }" @click="categoriaSeleccionada = String(c.id ?? 'otros')" x-text="c.nombre"></button>
            </template>
        </nav>

        <p class="vacio" x-show="categorias.length === 0" x-text="t('carta_vacia')">Esta carta aún no tiene productos.</p>

        <template x-for="c in categoriasVisibles" :key="c.id ?? 'otros'">
            <section :id="'cat-' + (c.id ?? 'otros')">
                <h2 class="solo-lectores" x-text="c.nombre"></h2>

                <div class="carta-tarjetas">
                <template x-for="p in c.productos" :key="p.id">
                    <article class="plato">
                        <div class="foto">
                            <template x-if="p.imagen"><img :src="p.imagen" :alt="p.nombre" loading="lazy" decoding="async"></template>
                            <template x-if="!p.imagen"><span x-text="p.nombre.charAt(0).toUpperCase()"></span></template>
                        </div>

                        <div class="info">
                            <strong x-text="p.nombre"></strong>
                            <small x-show="p.descripcion" x-text="p.descripcion"></small>
                            <div class="compra-plato"><span class="precio" x-text="money(p.precio)"></span>
                        <div class="cantidad cantidad-plato" x-show="p.disponible">
                            <button type="button" class="redondo" :aria-label="t('quitar_aria') + p.nombre"
                                    x-show="cantidad(p) > 0" @click="quitar(p)">−</button>
                            <span x-show="cantidad(p) > 0" x-text="cantidad(p)"></span>
                            <button type="button" class="redondo" :aria-label="t('agregar_aria') + p.nombre"
                                    :disabled="cantidad(p) >= 99" @click="agregar(p)"><span x-text="cantidad(p) > 0 ? '+' : t('pedir_plato')"></span></button>
                        </div>
                            </div>
                            <small class="estado-carrito" x-show="cantidad(p) > 0" x-text="cantidad(p) + ' ' + t('en_tu_pedido')"></small>
                            <span class="agotado" x-show="!p.disponible" x-text="t('agotado')">Agotado</span>
                        </div>

                        <div class="acciones-plato">
                            <button type="button" class="btn" @click="abrirDetalles(p, $el.closest('.plato').querySelector('dialog.carta-detalle'))" x-text="t('detalles_plato')">Ver detalles</button>
                            <button type="button" class="btn btn-ar-accion" x-show="p.modelo_3d" :aria-label="'Ver ' + p.nombre + ' en 3D y realidad aumentada'" @click="abrirDetalles(p, $el.closest('.plato').querySelector('dialog.carta-detalle'))">
                                <span class="carta-boton-texto" x-text="t('ver_plato')">Ver plato</span>
                            </button>
                        </div>

                        <dialog :aria-label="p.nombre" class="carta-detalle" @click.self="$el.close()" @close="detalleActivo = null; estadoModelo = 'cargando'">
                            <form method="dialog"><button class="btn" aria-label="Cerrar ficha">Cerrar</button></form>
                            <div class="detalle-cabecera"><h2 x-text="p.nombre"></h2><strong class="precio-detalle" x-text="money(p.precio)"></strong></div>
                            <p class="detalle-descripcion" x-show="p.descripcion" x-text="p.descripcion"></p>
                            <template x-if="p.modelo_3d && detalleActivo === p.id">
                                <div class="detalle-3d-wrap">
                                    <span class="badge-3d" aria-hidden="true">Vista 3D</span>
                                    <model-viewer
                                        :id="'mv-modal-' + p.id"
                                        :src="p.modelo_3d + '?v=ar8'"
                                        :ios-src="obtenerIosSrc(p)"
                                        :poster="p.imagen || null"
                                        :alt="p.nombre"
                                        camera-controls
                                        camera-orbit="0deg 65deg 105%"
                                        min-camera-orbit="auto 15deg 20%"
                                        max-camera-orbit="auto 88deg 300%"
                                        auto-rotate
                                        rotation-per-second="20deg"
                                        touch-action="pan-y"
                                        ar
                                        ar-modes="webxr scene-viewer quick-look"
                                        ar-placement="floor"
                                        ar-scale="fixed"
                                        xr-environment
                                        shadow-intensity="1.6"
                                        shadow-softness="0.75"
                                        exposure="1.1"
                                        tone-mapping="neutral"
                                        loading="lazy"
                                        reveal="auto"
                                        @load="estadoModelo = 'listo'; normalizarEscalaReal($event.target, p)" x-on:error="estadoModelo = 'error'"
                                        @ar-status="actualizarEstadoAR($event, $event.target)"
                                        @ar-tracking="actualizarTrackingAR($event, $event.target)"
                                    >
                                        <button slot="ar-button" style="display:none" aria-hidden="true"></button>
                                        <div class="ar-hud-escaneo" @beforexrselect.prevent>
                                            Apunta a la mesa y mueve el celular despacio para colocar el plato.
                                        </div>
                                        <div class="ar-hud-tracking-perdido" x-show="trackingPerdido" @beforexrselect.prevent>
                                            <p>Aleja un poco el celular y vuelve a apuntar a la mesa.</p>
                                            <button type="button" class="btn-ar-recentrar"
                                                    @beforexrselect.prevent
                                                    @click.stop="recentrarPlatoEnMesa()">
                                                Volver a colocar
                                            </button>
                                        </div>
                                        <div class="ar-lock-shield" :class="{ 'is-locked': ancladoFijo }"
                                             @beforexrselect.prevent
                                             @pointerdown.stop
                                             @touchstart.stop></div>
                                        <div class="ar-hud-controles" @beforexrselect.prevent>
                                            <button type="button" class="btn-ar-lock" :class="{ 'is-locked': ancladoFijo }"
                                                    @beforexrselect.prevent
                                                    @click.stop="alternarAnclajePlato()">
                                                <span x-text="ancladoFijo ? 'Plato fijo · Toca para mover' : 'Fijar plato en este lugar'"></span>
                                            </button>
                                        </div>
                                    </model-viewer>
                                    <p class="modelo-estado" role="status" x-show="estadoModelo !== 'listo'" x-text="t(estadoModelo === 'error' ? 'error_modelo' : 'cargando_modelo')"></p>
                                    <p class="modelo-guia" x-show="estadoModelo === 'listo'" x-text="t('guia_modelo')"></p>
                                    <p class="ar-guia-tip" x-show="estadoModelo === 'listo'">
                                        Se abrirá la cámara. Apunta a una mesa despejada y mueve el celular despacio hasta colocar el plato.
                                    </p>
                                    <button type="button" class="btn carta-ar btn-ar-accion" :disabled="estadoModelo !== 'listo'" @click="abrirAR(p, document.getElementById('mv-modal-' + p.id))">
                                        <span class="carta-boton-texto" x-text="t('ver_plato')">Ver plato</span>
                                    </button>
                                    <button type="button" class="btn-sec carta-ar-nativo" :disabled="estadoModelo !== 'listo'" @click="abrirARNativo(p, document.getElementById('mv-modal-' + p.id))">
                                        Probar con el visor de Android
                                    </button>
                                </div>
                            </template>
                            <template x-if="!p.modelo_3d && p.imagen"><img :src="p.imagen" :alt="p.nombre" loading="lazy"></template>
                        </dialog>


                    </article>
                </template>
                </div>
            </section>
        </template>
    </main>

    <button type="button" class="barra-carrito" x-ref="barraPedido" x-show="unidades > 0 && !abierto" @click="abrirPedido()">
        <span x-text="unidades + (unidades === 1 ? t('producto_s') : t('producto_p'))"></span>
        <strong><span x-text="t('ver_pedido')">Ver pedido · </span><span x-text="money(total)"></span></strong>
    </button>

    <div class="fondo" x-show="abierto" role="dialog" aria-modal="true" aria-labelledby="titulo-pedido" @click.self="cerrarPedido()">
        <form class="hoja" x-ref="formPedido" @keydown.tab="atraparFocoPedido($event)" @submit.prevent="enviar()" novalidate>
            <p class="mesa-identificada" x-show="datosMesaNombre" x-text="datosMesaNombre"></p>
            <div class="pedido-cabecera"><h2 id="titulo-pedido" x-ref="tituloPedido" tabindex="-1" x-text="t('tu_pedido')">Tu pedido</h2><button type="button" class="cerrar-pedido" @click="cerrarPedido()" x-text="t('cerrar')">Cerrar</button></div>

            <template x-for="l in lineas" :key="l.id">
                <div class="linea">
                    <span x-text="l.nombre"></span>
                    <div class="cantidad">
                        <button type="button" class="redondo" :aria-label="t('quitar_aria') + l.nombre" @click="quitar(l)">−</button>
                        <span x-text="l.cantidad"></span>
                        <button type="button" class="redondo" :disabled="cantidad(l) >= 99" :aria-label="t('agregar_aria') + l.nombre" @click="agregar(l)">+</button>
                    </div>
                    <span x-text="money(l.subtotal)"></span>
                </div>
            </template>

            <div class="linea total">
                <strong x-text="t('total')">Total</strong>
                <strong x-text="money(total)"></strong>
            </div>

            <label class="campo"><span x-text="t('tu_nombre')">Tu nombre</span>
                <input type="text" x-model="form.cliente_nombre" maxlength="120" autocomplete="name">
            </label>

            <label class="campo"><span x-text="t('como_lo_quieres')">¿Cómo lo quieres?</span>
                <select x-model="form.tipo">
                    <option x-show="!restaurante?.configuracion || restaurante.configuracion.modalidades.includes('recojo')" value="recojo" x-text="t('opt_recojo')">Para recoger</option>
                    <option x-show="!restaurante?.configuracion || restaurante.configuracion.modalidades.includes('delivery')" value="delivery" x-text="t('opt_delivery')">Delivery</option>
                    <option x-show="!restaurante?.configuracion || restaurante.configuracion.modalidades.includes('mesa')" value="mesa" x-text="t('opt_mesa')">En mesa</option>
                </select>
            </label>

            <label class="campo" x-show="form.tipo === 'mesa'"><span x-text="t('num_mesa')">Número de mesa</span>
                <template x-if="mesas && mesas.length > 0">
                    <select x-model="form.mesa">
                        <option value="" disabled x-text="t('sel_mesa')">-- Selecciona tu mesa --</option>
                        <template x-for="m in mesas" :key="m.id">
                            <option :value="m.nombre" :disabled="m.ocupada && (!datosMesaNombre || m.nombre !== datosMesaNombre)" x-text="m.nombre + (m.ocupada ? ' · ' + t('mesa_ocupada') : ' · ' + t('mesa_disponible'))"></option>
                        </template>
                    </select>
                </template>
                <template x-if="!mesas || mesas.length === 0">
                    <input type="text" x-model="form.mesa" maxlength="20" placeholder="Ej. Mesa 01">
                </template>
            </label>

            <label class="campo" x-show="form.tipo === 'delivery'"><span x-text="t('dir_entrega')">Dirección de entrega</span>
                <input type="text" x-model="form.direccion" maxlength="255" autocomplete="street-address">
            </label>

            <label class="campo"><span x-text="t('telefono')">Teléfono</span> <span x-show="form.tipo !== 'delivery'" x-text="t('opcional')">Opcional</span>
                <input type="tel" x-model="form.cliente_telefono" @input="$event.target.value = form.cliente_telefono = normalizarTelefono($event.target.value)" maxlength="30" inputmode="numeric" pattern="[0-9]*" autocomplete="tel">
            </label>

            <label class="campo"><span x-text="t('notas_rest')">Notas para el restaurante · Opcional</span>
                <textarea x-model="form.notas" rows="2" maxlength="500"></textarea>
            </label>

            <ul class="errores" x-show="errores.length" role="alert">
                <template x-for="(e, n) in errores" :key="n"><li x-text="e"></li></template>
            </ul>

            <button type="submit" class="btn" :disabled="enviando"
                    x-text="enviando ? t('enviando') : t('enviar_pedido')">Enviar pedido</button>
            <button type="button" class="btn-sec" @click="cerrarPedido()" x-text="t('seguir_viendo')">Seguir viendo la carta</button>
        </form>
    </div>

    <div class="fondo" x-show="pedido" @click.self="pedido = null">
        <div class="hoja">
            <h2 x-text="t('pedido_recibido')">¡Pedido recibido!</h2>
            <p>
                <span x-text="t('pedido_lbl')">Pedido</span> <strong x-text="'#' + (pedido ? pedido.pedido_id : '')"></strong>
                <span x-text="t('a_nombre_de')">a nombre de</span> <strong x-text="nombrePedido"></strong>.
                <span x-text="t('confirmara_pronto')">El restaurante lo confirmará pronto.</span>
            </p>

            <template x-for="(i, n) in (pedido ? pedido.items : [])" :key="n">
                <div class="linea">
                    <span x-text="i.cantidad + ' × ' + i.producto"></span>
                    <span x-text="money(i.subtotal)"></span>
                </div>
            </template>

            <div class="linea total">
                <strong x-text="t('total')">Total</strong>
                <strong x-text="money(pedido ? pedido.total : 0)"></strong>
            </div>

            <a class="btn" :href="whatsapp" target="_blank" rel="noopener" x-show="whatsapp" x-text="t('avisar_whatsapp')">Avisar por WhatsApp</a>
            <button type="button" class="btn-sec" @click="pedido = null" x-text="t('listo')">Listo</button>
        </div>
    </div>

    <div class="fondo" x-show="modalAR" @click.self="modalAR = false" x-cloak>
        <div class="hoja hoja-ar">
            <div>
                <span>
                    Ver plato
                </span>
                <button type="button" @click="modalAR = false">Cerrar</button>
            </div>

            <h3 x-text="'Proyecta ' + (platoARSeleccionado?.nombre || 'el plato')"></h3>
            <p x-show="mensajeT5SinAR">
                <strong>Este dispositivo no puede abrir la cámara AR.</strong> Puedes girar el plato en 3D o usar otro celular compatible.
            </p>
            <p>
                Escanea este código con la cámara de tu celular para abrir el plato. La vista en tu mesa requiere un dispositivo compatible.
            </p>

            <div>
                <template x-if="platoARSeleccionado">
                    <img :src="'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=' + encodeURIComponent(urlARParaCelular)"
                         alt="QR de Realidad Aumentada"
                         width="220" height="220">
                </template>
            </div>

            <p>
                El celular necesita conexión a internet y permiso para usar la cámara.
            </p>

            <button type="button" class="btn" @click="modalAR = false">Listo, cerrar</button>
        </div>
    </div>

    <div class="ar-guia-toast" x-show="avisoGuiaAR" x-cloak role="status" aria-live="polite" @click="avisoGuiaAR = false">
        
        <div>
            <strong>Coloca el plato en tu mesa</strong>
            <small>Mueve el celular despacio. Si no detecta la superficie, apunta cerca del borde de la mesa.</small>
        </div>
    </div>
</div>
</body>
</html>
