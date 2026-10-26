<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#c2410c">
    <title>Carta | Adbana</title>
    <link rel="stylesheet" href="/css/adbana.css">
    <link rel="stylesheet" href="/demo-ar/acceso-carta.css">
    <script src="/js/carta.js"></script>
    <script defer src="/js/alpine.min.js"></script>
</head>
<body>
<div x-data="carta(@js($slug))" x-init="cargar()" x-cloak @keydown.escape.window="abierto = false; idiomaAbierto = false">

    <p class="estado" x-show="cargando" x-text="t('cargando_carta')">Cargando carta…</p>

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

        <aside class="demo-carta" :aria-label="t('demo_aria')">
            <div><span class="demo-carta-tag" x-text="t('demo_tag')">EXPERIENCIA INTERACTIVA · DEMO</span><h2 x-text="t('demo_h2')">Mira un plato antes de pedir.</h2><p x-text="t('demo_p')">Explora nuestra pizza de prueba en 3D o con la cámara en realidad aumentada.</p></div>
            <a class="demo-carta-link" href="{{ '/demo-ar/?carta='.rawurlencode('/c/'.$slug) }}"><span x-text="t('demo_btn')">Probar 3D y realidad aumentada</span> <span aria-hidden="true">↗</span></a>
            <small x-text="t('demo_nota')">Demostración visual. No añade productos al pedido.</small>
        </aside>

        <nav class="chips" :aria-label="t('categorias_aria')">
            <template x-for="c in categorias" :key="c.id ?? 'otros'">
                <a :href="'#cat-' + (c.id ?? 'otros')" x-text="c.nombre"></a>
            </template>
        </nav>

        <p class="vacio" x-show="categorias.length === 0" x-text="t('carta_vacia')">Esta carta aún no tiene productos.</p>

        <template x-for="c in categorias" :key="c.id ?? 'otros'">
            <section :id="'cat-' + (c.id ?? 'otros')">
                <h2 x-text="c.nombre"></h2>

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

                        <div class="cantidad" x-show="p.disponible">
                            <button type="button" class="redondo" :aria-label="t('quitar_aria') + p.nombre"
                                    x-show="cantidad(p) > 0" @click="quitar(p)">−</button>
                            <span x-show="cantidad(p) > 0" x-text="cantidad(p)"></span>
                            <button type="button" class="redondo" :aria-label="t('agregar_aria') + p.nombre"
                                    @click="agregar(p)">+</button>
                        </div>
                    </article>
                </template>
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
                    <option value="recojo" x-text="t('opt_recojo')">Para recoger</option>
                    <option value="delivery" x-text="t('opt_delivery')">Delivery</option>
                    <option value="mesa" x-text="t('opt_mesa')">En mesa</option>
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
