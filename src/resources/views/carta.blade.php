<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#c2410c">
    <title>Carta | Adbana</title>
    <link rel="stylesheet" href="/css/adbana.css">
    <link rel="stylesheet" href="/css/carta-visual.css?v=20261110ar8">
    <script>
        window.ADBANA_CONFIG = {
            arPublicUrl: @js(rtrim((string) env('AR_PUBLIC_URL', ''), '/')),
            arPublicBaseUrl: @js(rtrim((string) env('AR_PUBLIC_URL', ''), '/'))
        };
    </script>
    <script src="/js/carta.js?config=20261108&v=20261110ar8"></script>
    <script defer src="/js/alpine.min.js"></script>
    <script type="module" src="https://ajax.googleapis.com/ajax/libs/model-viewer/4.0.0/model-viewer.min.js"></script>
<link rel="stylesheet" href="/css/carga.css?v=20261031d"></head>
<body>
<div x-data="carta(@js($slug))" x-init="cargar()"  x-cloak @keydown.escape.window="abierto = false; idiomaAbierto = false">
@include('layouts.carga', ['visible' => 'consultasBackend > 0 && (enviando || cargando)', 'bloqueante' => 'enviando'])



    <div class="estado" x-show="error && !cargando">
        <p x-text="error"></p>
        <button type="button" class="btn" @click="cargar()" x-text="t('reintentar')">Reintentar</button>
    </div>

    <main class="contenedor" x-show="!cargando && !error">
        <header class="encabezado">
            <div class="encabezado-top">
                <div>
                    <h1 x-text="restaurante && restaurante.nombre"></h1>
                    <p x-text="t('sub_encabezado')">Arma tu pedido y envíalo directo al restaurante.</p>
                </div>
                <div class="carta-lang" @click.outside="idiomaAbierto = false">
                    <button type="button" class="carta-lang-btn" :class="{'is-open': idiomaAbierto}" :aria-label="t('cambiar_idioma')" :aria-expanded="idiomaAbierto" aria-haspopup="listbox" @click="idiomaAbierto = !idiomaAbierto">
                        <svg aria-hidden="true"><use href="/images/panel-icons.svg#globe"></use></svg>
                        <span x-text="idiomaActual.corto">ES</span>
                        <svg class="chev" aria-hidden="true"><use href="/images/panel-icons.svg#chevron"></use></svg>
                    </button>
                    <div class="carta-lang-menu" x-show="idiomaAbierto" role="listbox" :aria-label="t('cambiar_idioma')">
                        <template x-for="lang in idiomasDisponibles" :key="lang.codigo">
                            <button type="button" class="carta-lang-opt" :class="{active: idioma === lang.codigo}" role="option" :aria-selected="idioma === lang.codigo" @click="cambiarIdioma(lang.codigo)">
                                <span class="carta-lang-code" x-text="lang.corto"></span>
                                <span class="carta-lang-label" x-text="lang.nombre"></span>
                                <svg class="carta-lang-check" x-show="idioma === lang.codigo" aria-hidden="true"><use href="/images/panel-icons.svg#check"></use></svg>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </header>

        <section class="carta-portada">
            <span>LA CARTA</span>
            <h2>¿Qué se te antoja hoy?</h2>
            <p>Explora los platos y elige tu próximo favorito.</p>
        </section>

        <nav class="chips" :aria-label="t('categorias_aria')">
            <template x-for="c in categorias" :key="c.id ?? 'otros'">
                <a :href="'#cat-' + (c.id ?? 'otros')" x-text="c.nombre"></a>
            </template>
        </nav>

        <p class="vacio" x-show="categorias.length === 0" x-text="t('carta_vacia')">Esta carta aún no tiene productos.</p>

        <template x-for="c in categorias" :key="c.id ?? 'otros'">
            <section :id="'cat-' + (c.id ?? 'otros')">
                <h2 x-text="c.nombre"></h2>

                <div class="carta-tarjetas">
                <template x-for="p in c.productos" :key="p.id">
                    <article class="plato">
                        <div class="foto" :class="{ 'tiene-3d': p.modelo_3d }">
                            <template x-if="p.modelo_3d">
                                <div class="visor-3d-wrap">
                                    <span class="badge-3d" aria-hidden="true">✨ 3D Interactivo</span>
                                    <model-viewer
                                        :id="'mv-' + p.id"
                                        :src="p.modelo_3d + '?v=ar8'"
                                        :ios-src="obtenerIosSrc(p)"
                                        :alt="p.nombre"
                                        camera-controls
                                        camera-orbit="0deg 65deg 105%"
                                        min-camera-orbit="auto 15deg 20%"
                                        max-camera-orbit="auto 88deg 300%"
                                        auto-rotate
                                        rotation-per-second="25deg"
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
                                        @load="normalizarEscalaReal($event.target, p)"
                                        @ar-status="actualizarEstadoAR($event, $event.target)"
                                        @ar-tracking="actualizarTrackingAR($event, $event.target)"
                                    >
                                        <button slot="ar-button" style="display:none" aria-hidden="true"></button>
                                        <div class="ar-hud-escaneo" @beforexrselect.prevent>
                                            📲 Apunta al borde o centro de la mesa e inclina ligeramente el celular para fijar el plato
                                        </div>
                                        <div class="ar-hud-tracking-perdido" x-show="trackingPerdido" @beforexrselect.prevent>
                                            <p>⚠️ Muy cerca de la superficie — aléjate unos centímetros mirando a la mesa para recuperar el plato</p>
                                            <button type="button" class="btn-ar-recentrar"
                                                    @beforexrselect.prevent
                                                    @click.stop="recentrarPlatoEnMesa()">
                                                🔄 Re-centrar plato en mesa
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
                                                <span x-text="ancladoFijo ? '🔒 Plato fijo en mesa (Toca aquí para recolocar)' : '🎯 Modo recolocar activo (Toca la mesa para mover el plato y luego fija) · 📌 Fijar plato aquí'"></span>
                                            </button>
                                        </div>
                                    </model-viewer>
                                    <button type="button" class="btn-ar-flotante" @click="abrirAR(p, document.getElementById('mv-' + p.id))">
                                        📱 Ver en AR
                                    </button>
                                </div>
                            </template>
                            <template x-if="!p.modelo_3d && p.imagen"><img :src="p.imagen" alt="" loading="lazy"></template>
                            <template x-if="!p.modelo_3d && !p.imagen"><span x-text="p.nombre.charAt(0).toUpperCase()"></span></template>
                        </div>

                        <div class="info">
                            <strong x-text="p.nombre"></strong>
                            <small x-show="p.descripcion" x-text="p.descripcion"></small>
                            <span class="precio" x-text="money(p.precio)"></span>
                            <span class="agotado" x-show="!p.disponible" x-text="t('agotado')">Agotado</span>
                        </div>

                        <div class="acciones-plato">
                            <button type="button" class="btn" @click="$el.closest('.plato').querySelector('dialog.carta-detalle').showModal()">Ver detalles</button>
                            <button type="button" class="btn btn-ar-accion" x-show="p.modelo_3d" @click="abrirAR(p, document.getElementById('mv-' + p.id))">
                                📱 Ver en tu mesa (AR)
                            </button>
                        </div>

                        <dialog class="carta-detalle" @click.self="$el.close()">
                            <form method="dialog"><button class="btn" aria-label="Cerrar ficha">Cerrar</button></form>
                            <template x-if="p.modelo_3d">
                                <div class="detalle-3d-wrap">
                                    <span class="badge-3d" aria-hidden="true">✨ Vista 3D 360°</span>
                                    <model-viewer
                                        :id="'mv-modal-' + p.id"
                                        :src="p.modelo_3d + '?v=ar8'"
                                        :ios-src="obtenerIosSrc(p)"
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
                                        @load="normalizarEscalaReal($event.target, p)"
                                        @ar-status="actualizarEstadoAR($event, $event.target)"
                                        @ar-tracking="actualizarTrackingAR($event, $event.target)"
                                    >
                                        <button slot="ar-button" style="display:none" aria-hidden="true"></button>
                                        <div class="ar-hud-escaneo" @beforexrselect.prevent>
                                            📲 Apunta al borde o centro de la mesa e inclina ligeramente el celular para fijar el plato
                                        </div>
                                        <div class="ar-hud-tracking-perdido" x-show="trackingPerdido" @beforexrselect.prevent>
                                            <p>⚠️ Muy cerca de la superficie — aléjate unos centímetros mirando a la mesa para recuperar el plato</p>
                                            <button type="button" class="btn-ar-recentrar"
                                                    @beforexrselect.prevent
                                                    @click.stop="recentrarPlatoEnMesa()">
                                                🔄 Re-centrar plato en mesa
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
                                                <span x-text="ancladoFijo ? '🔒 Plato fijo en mesa (Toca aquí para recolocar)' : '🎯 Modo recolocar activo (Toca la mesa para mover el plato y luego fija) · 📌 Fijar plato aquí'"></span>
                                            </button>
                                        </div>
                                    </model-viewer>
                                    <p class="ar-guia-tip">
                                        💡 <strong>Tip de anclaje:</strong> Apunta a tu mesa. Si es muy lisa o de vidrio, enfoca cerca del borde o de un objeto (servilletero, vaso o individual) para que el sensor fije el plato al instante.
                                    </p>
                                    <button type="button" class="btn carta-ar" @click="abrirAR(p, document.getElementById('mv-modal-' + p.id))">
                                        📱 Ver en tu mesa (AR)
                                    </button>
                                    <button type="button" class="btn-sec carta-ar-nativo" @click="abrirARNativo(p, document.getElementById('mv-modal-' + p.id))">
                                        🚀 Abrir en Google AR Nativo
                                    </button>
                                </div>
                            </template>
                            <template x-if="!p.modelo_3d && p.imagen"><img :src="p.imagen" :alt="p.nombre" loading="lazy"></template>
                            <h2 x-text="p.nombre"></h2>
                            <p x-text="p.descripcion"></p>
                            <strong class="precio-detalle" x-text="money(p.precio)"></strong>
                        </dialog>

                        <div class="cantidad" x-show="p.disponible">
                            <button type="button" class="redondo" :aria-label="t('quitar_aria') + p.nombre"
                                    x-show="cantidad(p) > 0" @click="quitar(p)">−</button>
                            <span x-show="cantidad(p) > 0" x-text="cantidad(p)"></span>
                            <button type="button" class="redondo" :aria-label="t('agregar_aria') + p.nombre"
                                    @click="agregar(p)">+</button>
                        </div>
                    </article>
                </template>
                </div>
            </section>
        </template>
    </main>

    <button type="button" class="barra-carrito" x-show="unidades > 0 && !abierto" @click="abierto = true">
        <span x-text="unidades + (unidades === 1 ? t('producto_s') : t('producto_p'))"></span>
        <strong><span x-text="t('ver_pedido')">Ver pedido · </span><span x-text="money(total)"></span></strong>
    </button>

    <div class="fondo" x-show="abierto" @click.self="abierto = false">
        <form class="hoja" @submit.prevent="enviar()" novalidate>
            <h2 x-text="t('tu_pedido')">Tu pedido</h2>

            <template x-for="l in lineas" :key="l.id">
                <div class="linea">
                    <span x-text="l.nombre"></span>
                    <div class="cantidad">
                        <button type="button" class="redondo" @click="quitar(l)">−</button>
                        <span x-text="l.cantidad"></span>
                        <button type="button" class="redondo" @click="agregar(l)">+</button>
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
                <input type="text" x-model="form.mesa" maxlength="20" inputmode="numeric">
            </label>

            <label class="campo" x-show="form.tipo === 'delivery'"><span x-text="t('dir_entrega')">Dirección de entrega</span>
                <input type="text" x-model="form.direccion" maxlength="255" autocomplete="street-address">
            </label>

            <label class="campo"><span x-text="t('telefono')">Teléfono</span> <span x-show="form.tipo !== 'delivery'" x-text="t('opcional')">(opcional)</span>
                <input type="tel" x-model="form.cliente_telefono" maxlength="30" inputmode="tel" autocomplete="tel">
            </label>

            <label class="campo"><span x-text="t('notas_rest')">Notas para el restaurante (opcional)</span>
                <textarea x-model="form.notas" rows="2" maxlength="500"></textarea>
            </label>

            <ul class="errores" x-show="errores.length" role="alert">
                <template x-for="(e, n) in errores" :key="n"><li x-text="e"></li></template>
            </ul>

            <button type="submit" class="btn" :disabled="enviando"
                    x-text="enviando ? t('enviando') : t('enviar_pedido')">Enviar pedido</button>
            <button type="button" class="btn-sec" @click="abierto = false" x-text="t('seguir_viendo')">Seguir viendo la carta</button>
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

    <div class="fondo" x-show="modalAR" @click.self="modalAR = false" style="display:none;" x-cloak>
        <div class="hoja hoja-ar" style="max-width: 440px; text-align: center; padding: 28px 24px; border-radius: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 13px; font-weight: 700; color: #ff914d; letter-spacing: 0.05em; text-transform: uppercase;">
                    ✨ Realidad Aumentada
                </span>
                <button type="button" @click="modalAR = false" style="background: transparent; border: 0; color: #a8a29e; font-size: 22px; cursor: pointer; padding: 4px;">✕</button>
            </div>

            <h3 style="margin: 0 0 8px 0; color: #fff; font-size: 22px;" x-text="'Proyecta ' + (platoARSeleccionado?.nombre || 'el plato')"></h3>
            <p x-show="mensajeT5SinAR" style="color: #fde68a; background: rgba(245, 158, 11, 0.16); border: 1px solid rgba(245, 158, 11, 0.45); border-radius: 12px; padding: 10px 12px; font-size: 13px; margin: 0 0 14px 0; line-height: 1.4;">
                📱 <strong>Tu celular no soporta realidad aumentada; puedes ver el plato en 3D</strong> girándolo con el dedo en la carta o escanear este QR con otro teléfono compatible.
            </p>
            <p style="color: #d6d3d1; font-size: 14px; margin: 0 0 20px 0; line-height: 1.4;">
                Abre la cámara de tu celular y <strong>escanea este código QR</strong> para ver el plato en 3D colocado sobre tu mesa en tamaño real.
            </p>

            <div style="background: #ffffff; padding: 16px; border-radius: 16px; display: inline-block; margin-bottom: 16px; box-shadow: 0 8px 24px rgba(0,0,0,0.5);">
                <template x-if="platoARSeleccionado">
                    <img :src="'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=' + encodeURIComponent(urlARParaCelular)"
                         alt="QR de Realidad Aumentada"
                         width="220" height="220" style="display: block; border-radius: 6px;">
                </template>
            </div>

            <p style="font-size: 13px; color: #a8a29e; margin: 0 0 16px 0; background: rgba(255,255,255,0.05); padding: 10px; border-radius: 10px;">
                📶 Conéctate al mismo Wi-Fi del restaurante para que tu teléfono cargue los modelos 3D al instante.
            </p>

            <button type="button" class="btn" @click="modalAR = false" style="width: 100%;">Listo, cerrar</button>
        </div>
    </div>

    <div class="ar-guia-toast" x-show="avisoGuiaAR" x-cloak role="status" aria-live="polite" @click="avisoGuiaAR = false">
        <span>🎯</span>
        <div>
            <strong>Anclando plato en tu mesa…</strong>
            <small>Mueve suavemente el celular apuntando a la mesa. Si es muy lisa o brillante, enfoca cerca del borde o de un objeto para fijar el plato al instante.</small>
        </div>
    </div>
</div>
</body>
</html>
