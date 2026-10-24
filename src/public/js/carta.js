document.addEventListener('alpine:init', () => {
    Alpine.data('carta', (slug) => ({
        slug,
        cargando: true,
        error: '',
        restaurante: null,
        categorias: [],
        carrito: {},
        abierto: false,
        enviando: false,
        errores: [],
        pedido: null,
        nombrePedido: '',
        form: {
            cliente_nombre: '',
            cliente_telefono: '',
            tipo: 'recojo',
            mesa: '',
            direccion: '',
            notas: '',
        },

        async cargar(silencioso = false) {
            if (!silencioso) {
                this.cargando = true;
                this.error = '';
            }

            try {
                const r = await fetch('/api/carta/' + encodeURIComponent(this.slug), {
                    headers: { Accept: 'application/json' },
                });

                if (r.status === 404) {
                    this.error = 'No encontramos esta carta.';
                    return;
                }

                if (!r.ok) {
                    throw new Error('http ' + r.status);
                }

                const datos = await r.json();
                this.restaurante = datos.restaurante;
                this.categorias = datos.carta;
                document.title = datos.restaurante.nombre + ' | Carta';
                this.restaurarCarrito();
            } catch (e) {
                if (!silencioso) {
                    this.error = 'No pudimos cargar la carta. Revisa tu conexión e intenta de nuevo.';
                }
            } finally {
                this.cargando = false;
            }
        },

        get productos() {
            return this.categorias.flatMap((c) => c.productos);
        },

        get lineas() {
            return this.productos
                .filter((p) => this.carrito[p.id] > 0)
                .map((p) => ({
                    ...p,
                    cantidad: this.carrito[p.id],
                    subtotal: p.precio * this.carrito[p.id],
                }));
        },

        get unidades() {
            return this.lineas.reduce((suma, l) => suma + l.cantidad, 0);
        },

        get total() {
            return this.lineas.reduce((suma, l) => suma + l.subtotal, 0);
        },

        // El total de aquí es solo para mostrar: el servidor lo recalcula con sus propios precios.
        money(valor) {
            return 'S/ ' + Number(valor).toFixed(2);
        },

        cantidad(p) {
            return this.carrito[p.id] || 0;
        },

        agregar(p) {
            if (!p.disponible || this.cantidad(p) >= 99) return;
            this.carrito[p.id] = this.cantidad(p) + 1;
            this.guardarCarrito();
        },

        quitar(p) {
            const nueva = this.cantidad(p) - 1;

            if (nueva > 0) {
                this.carrito[p.id] = nueva;
            } else {
                delete this.carrito[p.id];
            }

            this.guardarCarrito();

            if (this.unidades === 0) this.abierto = false;
        },

        guardarCarrito() {
            try {
                localStorage.setItem('carrito:' + this.slug, JSON.stringify(this.carrito));
            } catch (e) {}
        },

        restaurarCarrito() {
            let guardado = {};

            try {
                guardado = JSON.parse(localStorage.getItem('carrito:' + this.slug)) || {};
            } catch (e) {}

            if (!guardado || typeof guardado !== 'object' || Array.isArray(guardado)) guardado = {};

            const validos = {};

            this.productos.forEach((p) => {
                const cantidad = Number(guardado[p.id]);
                if (p.disponible && Number.isInteger(cantidad) && cantidad > 0) validos[p.id] = Math.min(cantidad, 99);
            });

            this.carrito = validos;
        },

        validar() {
            const f = this.form;
            const faltan = [];

            if (!f.cliente_nombre.trim()) faltan.push('Escribe tu nombre.');

            if (f.tipo === 'delivery') {
                if (!f.cliente_telefono.trim()) faltan.push('Escribe un teléfono de contacto.');
                if (!f.direccion.trim()) faltan.push('Escribe la dirección de entrega.');
            }

            if (f.tipo === 'mesa' && !f.mesa.trim()) faltan.push('Indica el número de mesa.');
            if (this.unidades === 0) faltan.push('Agrega al menos un producto.');

            return faltan;
        },

        async enviar() {
            if (this.enviando) return;
            this.errores = this.validar();
            if (this.errores.length) return;

            this.enviando = true;

            try {
                const f = this.form;

                const r = await fetch('/api/carta/' + encodeURIComponent(this.slug) + '/pedidos', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({
                        cliente_nombre: f.cliente_nombre.trim(),
                        cliente_telefono: f.cliente_telefono.trim() || null,
                        tipo: f.tipo,
                        mesa: f.tipo === 'mesa' ? f.mesa.trim() : null,
                        direccion: f.tipo === 'delivery' ? f.direccion.trim() : null,
                        notas: f.notas.trim() || null,
                        items: Object.entries(this.carrito).map(([id, cantidad]) => ({
                            producto_id: Number(id),
                            cantidad,
                        })),
                    }),
                });

                const datos = await r.json().catch(() => ({}));

                if (r.status === 201) {
                    this.pedido = datos;
                    this.nombrePedido = f.cliente_nombre.trim();
                    this.carrito = {};
                    this.guardarCarrito();
                    this.abierto = false;
                    f.notas = '';
                    this.cargar(true);
                    return;
                }

                if (r.status === 422 && datos.errors) {
                    this.errores = Object.values(datos.errors).flat();
                    this.cargar(true); // actualiza lo que quedó agotado
                } else if (r.status === 429) {
                    this.errores = ['Hiciste muchos pedidos seguidos. Espera un minuto e intenta de nuevo.'];
                } else {
                    this.errores = ['No pudimos enviar tu pedido. Intenta de nuevo.'];
                }
            } catch (e) {
                this.errores = ['Sin conexión. Revisa tu internet e intenta de nuevo.'];
            } finally {
                this.enviando = false;
            }
        },

        // Si el restaurante tiene teléfono, el cliente puede avisarle por WhatsApp con el mensaje armado.
        get whatsapp() {
            if (!this.pedido || !this.restaurante || !this.restaurante.telefono) return '';

            let numero = String(this.restaurante.telefono).replace(/\D/g, '');
            if (numero.length === 9) numero = '51' + numero;

            const texto = 'Hola, hice el pedido #' + this.pedido.pedido_id
                + ' a nombre de ' + this.nombrePedido
                + ' por ' + this.money(this.pedido.total) + '.';

            return 'https://wa.me/' + numero + '?text=' + encodeURIComponent(texto);
        },
    }));
});
