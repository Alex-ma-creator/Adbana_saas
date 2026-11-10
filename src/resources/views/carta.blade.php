<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#c2410c">
    <title>Carta | Adbana</title>
    <link rel="stylesheet" href="/css/adbana.css">
    <link rel="stylesheet" href="/css/carta-visual.css?v=1">
    <script src="/js/carta.js?config=20261108&v=20261031b"></script>
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
                        <div class="foto">
                            <template x-if="p.imagen"><img :src="p.imagen" alt="" loading="lazy"></template>
                            <template x-if="!p.imagen"><span x-text="p.nombre.charAt(0).toUpperCase()"></span></template>
                        </div>

                        <div class="info">
                            <strong x-text="p.nombre"></strong>
                            <small x-show="p.descripcion" x-text="p.descripcion"></small>
                            <span class="precio" x-text="money(p.precio)"></span>
                            <span class="agotado" x-show="!p.disponible" x-text="t('agotado')">Agotado</span>
                        </div>

                        <button type="button" class="btn" @click="$el.nextElementSibling.showModal()">Ver plato</button>
                        <dialog class="carta-detalle" x-data="{ visor: false }" @close="visor=false" @click.self="$el.close()">
                            <form method="dialog"><button class="btn" aria-label="Cerrar ficha">Cerrar</button></form>
                            <template x-if="p.imagen"><img :src="p.imagen" :alt="p.nombre" loading="lazy"></template>
                            <h2 x-text="p.nombre"></h2>
                            <p x-text="p.descripcion"></p>
                            <button type="button" class="btn carta-ar" x-show="p.modelo_3d && !visor" @click="visor=true">
                                <span aria-hidden="true">●</span> Abrir modelo 3D
                            </button>
                            <template x-if="visor && p.modelo_3d">
                                <div x-data="{ mensajeAR: '' }">
                                    <model-viewer :src="p.modelo_3d" :alt="p.nombre" camera-controls touch-action="pan-y" ar ar-modes="webxr scene-viewer quick-look"
                                        @ar-status="if ($event.detail.status === 'failed') mensajeAR = 'No se pudo abrir la cámara. Revisa los permisos de Chrome y los servicios de realidad aumentada de Android.'">
                                        <button type="button" slot="ar-button" hidden tabindex="-1" aria-hidden="true"></button>
                                    </model-viewer>
                                    <button type="button" class="btn carta-ar" @click="
                                        const modelo = $el.previousElementSibling;
                                        if (!window.isSecureContext) {
                                            mensajeAR = 'Abre esta carta con HTTPS en el celular para usar la cámara.';
                                        } else if (!modelo.canActivateAR) {
                                            mensajeAR = 'La cámara AR no está disponible. Abre la carta en Chrome para Android y comprueba que tu equipo sea compatible y tenga Google Play Services para RA actualizado.';
                                        } else {
                                            mensajeAR = '';
                                            modelo.activateAR().catch(() => mensajeAR = 'No se pudo iniciar la cámara de realidad aumentada.');
                                        }
                                    "><span aria-hidden="true">●</span> Activar cámara · Ver en tu mesa</button>
                                    <p role="status" x-show="mensajeAR" x-text="mensajeAR"></p>
                                </div>
                            </template>
                            <strong x-text="money(p.precio)"></strong>
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
</div>
</body>
</html>
