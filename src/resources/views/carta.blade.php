<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#c2410c">
    <title>Carta | Adbana</title>
    <link rel="stylesheet" href="/css/adbana.css">
    <script src="/js/carta.js"></script>
    <script defer src="/js/alpine.min.js"></script>
</head>
<body>
<div x-data="carta(@js($slug))" x-init="cargar()" x-cloak>

    <p class="estado" x-show="cargando">Cargando carta…</p>

    <div class="estado" x-show="error && !cargando">
        <p x-text="error"></p>
        <button type="button" class="btn" @click="cargar()">Reintentar</button>
    </div>

    <main class="contenedor" x-show="!cargando && !error">
        <header class="encabezado">
            <h1 x-text="restaurante && restaurante.nombre"></h1>
            <p>Arma tu pedido y envíalo directo al restaurante.</p>
        </header>

        <nav class="chips" aria-label="Categorías">
            <template x-for="c in categorias" :key="c.id ?? 'otros'">
                <a :href="'#cat-' + (c.id ?? 'otros')" x-text="c.nombre"></a>
            </template>
        </nav>

        <p class="vacio" x-show="categorias.length === 0">Esta carta aún no tiene productos.</p>

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
                            <span class="agotado" x-show="!p.disponible">Agotado</span>
                        </div>

                        <div class="cantidad" x-show="p.disponible">
                            <button type="button" class="redondo" :aria-label="'Quitar ' + p.nombre"
                                    x-show="cantidad(p) > 0" @click="quitar(p)">−</button>
                            <span x-show="cantidad(p) > 0" x-text="cantidad(p)"></span>
                            <button type="button" class="redondo" :aria-label="'Agregar ' + p.nombre"
                                    @click="agregar(p)">+</button>
                        </div>
                    </article>
                </template>
            </section>
        </template>
    </main>

    <button type="button" class="barra-carrito" x-show="unidades > 0 && !abierto" @click="abierto = true">
        <span x-text="unidades + (unidades === 1 ? ' producto' : ' productos')"></span>
        <strong>Ver pedido · <span x-text="money(total)"></span></strong>
    </button>

    <div class="fondo" x-show="abierto" @click.self="abierto = false" @keydown.escape.window="abierto = false">
        <form class="hoja" @submit.prevent="enviar()" novalidate>
            <h2>Tu pedido</h2>

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
                <strong>Total</strong>
                <strong x-text="money(total)"></strong>
            </div>

            <label class="campo">Tu nombre
                <input type="text" x-model="form.cliente_nombre" maxlength="120" autocomplete="name">
            </label>

            <label class="campo">¿Cómo lo quieres?
                <select x-model="form.tipo">
                    <option value="recojo">Para recoger</option>
                    <option value="delivery">Delivery</option>
                    <option value="mesa">En mesa</option>
                </select>
            </label>

            <label class="campo" x-show="form.tipo === 'mesa'">Número de mesa
                <input type="text" x-model="form.mesa" maxlength="20" inputmode="numeric">
            </label>

            <label class="campo" x-show="form.tipo === 'delivery'">Dirección de entrega
                <input type="text" x-model="form.direccion" maxlength="255" autocomplete="street-address">
            </label>

            <label class="campo">Teléfono <span x-show="form.tipo !== 'delivery'">(opcional)</span>
                <input type="tel" x-model="form.cliente_telefono" maxlength="30" inputmode="tel" autocomplete="tel">
            </label>

            <label class="campo">Notas para el restaurante (opcional)
                <textarea x-model="form.notas" rows="2" maxlength="500"></textarea>
            </label>

            <ul class="errores" x-show="errores.length" role="alert">
                <template x-for="(e, n) in errores" :key="n"><li x-text="e"></li></template>
            </ul>

            <button type="submit" class="btn" :disabled="enviando"
                    x-text="enviando ? 'Enviando…' : 'Enviar pedido'"></button>
            <button type="button" class="btn-sec" @click="abierto = false">Seguir viendo la carta</button>
        </form>
    </div>

    <div class="fondo" x-show="pedido" @click.self="pedido = null">
        <div class="hoja">
            <p class="icono" aria-hidden="true">✅</p>
            <h2>¡Pedido recibido!</h2>
            <p>
                Pedido <strong x-text="'#' + (pedido ? pedido.pedido_id : '')"></strong>
                a nombre de <strong x-text="nombrePedido"></strong>.
                El restaurante lo confirmará pronto.
            </p>

            <template x-for="(i, n) in (pedido ? pedido.items : [])" :key="n">
                <div class="linea">
                    <span x-text="i.cantidad + ' × ' + i.producto"></span>
                    <span x-text="money(i.subtotal)"></span>
                </div>
            </template>

            <div class="linea total">
                <strong>Total</strong>
                <strong x-text="money(pedido ? pedido.total : 0)"></strong>
            </div>

            <a class="btn" :href="whatsapp" target="_blank" rel="noopener" x-show="whatsapp">Avisar por WhatsApp</a>
            <button type="button" class="btn-sec" @click="pedido = null">Listo</button>
        </div>
    </div>
</div>
</body>
</html>
