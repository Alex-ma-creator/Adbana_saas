document.addEventListener('alpine:init', () => {
    Alpine.data('panel', () => ({
        token: sessionStorage.getItem('adbana:token') || '', user: null,
        registro: false, nombre: '', restauranteNombre: '', email: '', password: '', tab: 'venta', busy: false, error: '', aviso: '',
        productos: [], categorias: [], pedidos: [], pagina: 1, ultima: 1, q: '',
        caja: null, resumen: null, cierre: null, reporte: null, fecha: new Date().toLocaleDateString('en-CA'),
        carrito: {}, clave: '', cliente: 'Venta mostrador', tipo: 'recojo', mesa: '', direccion: '', telefono: '', notas: '',
        cobro: null, medio: 'efectivo', recibido: '', comprobante: null,
        inicial: '0', contado: '', movimiento: { tipo: 'salida', monto: '', motivo: '' },
        producto: { nombre: '', precio: '', costo: '', stock: 0, stock_minimo: 0, categoria_id: '', activo: true },
        categoriaNombre: '', stockForm: { producto_id: '', tipo: 'entrada', cantidad: '', stock_real: '', motivo: '' },
        gasto: { descripcion: '', monto: '', categoria: '' }, usuarios: [], nuevoUsuario: { name: '', email: '', password: '', rol: 'cajero' }, timer: null,
        money(v) { return 'S/ ' + Number(v || 0).toFixed(2); },
        cents(v) { return Math.round(Number(v || 0) * 100); },
        get lineas() { return Object.values(this.carrito); },
        get total() { return this.lineas.reduce((s, p) => s + this.cents(p.precio) * p.cantidad, 0) / 100; },
        get vuelto() { return this.cobro ? (this.cents(this.recibido) - this.cents(this.cobro.total)) / 100 : 0; },
        get mesas() {
            const grupos = {};
            for (const p of this.pedidos.filter(p => p.tipo === 'mesa' && !['cancelado', 'entregado'].includes(p.estado))) {
                (grupos[p.mesa] ||= []).push(p);
            }
            return Object.entries(grupos).map(([nombre, pedidos]) => ({ nombre, pedidos }));
        },
        async api(path, method = 'GET', data) {
            const response = await fetch('/api' + path, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(this.token ? { Authorization: 'Bearer ' + this.token } : {}) }, ...(data === undefined ? {} : { body: JSON.stringify(data) }) });
            const body = await response.json().catch(() => ({}));
            if (response.status === 401) { this.salirLocal(); throw Error('Inicia sesión para continuar.'); }
            if (!response.ok) throw Error(body.errors ? Object.values(body.errors).flat().join(' ') : (body.message || 'No se pudo completar la operación.'));
            return body;
        },
        async accion(fn) {
            if (this.busy) return;
            this.busy = true; this.error = ''; this.aviso = '';
            try { await fn(); } catch (e) { this.error = e.message; } finally { this.busy = false; }
        },
        async init() {
            if (this.token) await this.accion(async () => { this.user = await this.api('/auth/me'); this.tab = this.user.rol === 'cocina' ? 'cocina' : 'venta'; await this.actualizar(); });
            this.timer = setInterval(() => {
                if (this.user && !this.busy && !document.hidden && ['pedidos', 'cocina', 'mesas'].includes(this.tab)) {
                    this.cargarPedidos().catch(e => this.error = e.message);
                }
            }, 15000);
        },
        destroy() { clearInterval(this.timer); },
        async login() {
            await this.accion(async () => {
                const data = await this.api(this.registro ? '/auth/registro-dueno' : '/auth/login', 'POST', { email: this.email, password: this.password, ...(this.registro ? { name: this.nombre, restaurante: this.restauranteNombre } : {}) });
                this.token = data.token; sessionStorage.setItem('adbana:token', data.token); this.password = '';
                this.user = await this.api('/auth/me'); this.tab = this.user.rol === 'cocina' ? 'cocina' : 'venta'; await this.actualizar();
            });
        },
        salirLocal() { this.token = ''; this.user = null; this.carrito = {}; this.cobro = null; this.comprobante = null; sessionStorage.removeItem('adbana:token'); },
        async logout() { await this.accion(async () => { await this.api('/auth/logout', 'POST'); this.salirLocal(); }); },
        async actualizar() {
            const [productos, categorias, caja] = await Promise.all([this.user.permisos.pedidos ? this.api('/productos?q=' + encodeURIComponent(this.q) + '&page=' + this.pagina) : Promise.resolve({data:[],last_page:1}), this.user.permisos.pedidos ? this.api('/categorias') : Promise.resolve([]), this.user.permisos.caja ? this.api('/caja/actual') : Promise.resolve({caja:null,resumen:null})]);
            this.productos = productos.data; this.ultima = productos.last_page; this.categorias = categorias.data || categorias;
            this.caja = caja.caja; this.resumen = caja.resumen; await this.cargarPedidos();
        },
        async cargarPedidos() {
            if (this.user.rol === 'cocina') {
                let page = 1, last = 1, pedidos = []; do { const data = await this.api('/cocina?page=' + page); pedidos.push(...data.data); last = data.last_page; page++; } while (page <= last); this.pedidos = pedidos; return;
            }
            // La lista operativa recorre todas las paginas de pedidos abiertos.
            let page = 1, last = 1, pedidos = [];
            do {
                const data = await this.api('/pedidos?estado=pendiente,confirmado,preparando,listo&page=' + page);
                pedidos.push(...data.data); last = data.last_page; page++;
            } while (page <= last);
            this.pedidos = pedidos;
        },
        agregar(p) {
            if (!p.activo || p.stock < 1 || this.busy) return;
            const existente = this.carrito[p.id];
            if ((existente?.cantidad || 0) >= Math.min(p.stock, 100)) return;
            this.carrito[p.id] = { ...p, cantidad: (existente?.cantidad || 0) + 1 }; this.clave = '';
        },
        quitar(p) { if (this.busy) return; if (--this.carrito[p.id].cantidad <= 0) delete this.carrito[p.id]; this.clave = ''; },
        async guardarVenta(cobrar) {
            await this.accion(async () => {
                if (!this.lineas.length) throw Error('Selecciona al menos un producto.');
                this.clave ||= crypto.randomUUID();
                const pedido = await this.api('/pedidos', 'POST', {
                    clave_operacion: this.clave, cliente_nombre: this.cliente, tipo: this.tipo,
                    mesa: this.tipo === 'mesa' ? this.mesa : null,
                    direccion: this.tipo === 'delivery' ? this.direccion : null,
                    cliente_telefono: this.telefono || null, notas: this.notas || null,
                    items: this.lineas.map(p => ({ producto_id: p.id, cantidad: p.cantidad })),
                });
                this.carrito = {}; this.clave = ''; this.notas = ''; this.aviso = 'Pedido #' + pedido.id + ' registrado.';
                if (cobrar) this.prepararCobro(pedido);
                await this.actualizar();
            });
        },
        prepararCobro(p) { this.cobro = p; this.medio = 'efectivo'; this.recibido = p.total; },
        async pagar() {
            await this.accion(async () => {
                this.comprobante = await this.api('/ventas/' + this.cobro.id + '/cobrar', 'POST', { medio_pago: this.medio, recibido: this.medio === 'efectivo' ? this.recibido : null });
                this.cobro = null; await this.actualizar();
            });
        },
        async estado(p, estado) {
            if (estado === 'cancelado' && !confirm('¿Cancelar el pedido #' + p.id + ' y devolver su stock?')) return;
            await this.accion(async () => { await this.api('/pedidos/' + p.id + '/estado', 'PATCH', { estado }); await this.actualizar(); });
        },
        siguiente(p) { if (this.user.rol === 'cocina') return ({confirmado:'preparando',preparando:'listo'})[p.estado]; if (['mozo','mozo_cajero'].includes(this.user.rol)) return p.estado === 'pendiente' ? 'confirmado' : null; return ({ pendiente: 'confirmado', confirmado: 'preparando', preparando: 'listo' })[p.estado]; },
        async abrirCaja() { await this.accion(async () => { await this.api('/caja/abrir', 'POST', { saldo_inicial: this.inicial }); this.cierre = null; await this.actualizar(); }); },
        async moverCaja() { await this.accion(async () => { await this.api('/caja/movimientos', 'POST', this.movimiento); this.movimiento.monto = ''; this.movimiento.motivo = ''; await this.actualizar(); }); },
        async cerrarCaja() {
            if (!confirm('¿Cerrar el turno con el efectivo contado indicado?')) return;
            await this.accion(async () => { this.cierre = await this.api('/caja/cerrar', 'POST', { efectivo_contado: this.contado }); await this.actualizar(); });
        },
        editar(p) { this.producto = { ...p, categoria_id: p.categoria_id || '' }; this.tab = 'productos'; },
        async guardarProducto() {
            await this.accion(async () => {
                const p = { ...this.producto, categoria_id: this.producto.categoria_id || null };
                if (p.id) delete p.stock;
                await this.api('/productos' + (p.id ? '/' + p.id : ''), p.id ? 'PATCH' : 'POST', p);
                this.producto = { nombre: '', precio: '', costo: '', stock: 0, stock_minimo: 0, categoria_id: '', activo: true };
                this.aviso = 'Producto guardado.'; await this.actualizar();
            });
        },
        async guardarCategoria() { await this.accion(async () => { await this.api('/categorias', 'POST', { nombre: this.categoriaNombre }); this.categoriaNombre = ''; await this.actualizar(); }); },
        async moverStock() {
            await this.accion(async () => {
                const data = { ...this.stockForm };
                if (data.tipo === 'ajuste') delete data.cantidad; else delete data.stock_real;
                await this.api('/stock/movimientos', 'POST', data); this.aviso = 'Inventario actualizado.'; await this.actualizar();
            });
        },
        async verReporte() { await this.accion(async () => { this.reporte = await this.api('/reportes/resumen?desde=' + this.fecha + '&hasta=' + this.fecha); }); },
        async guardarGasto() { await this.accion(async () => { await this.api('/gastos', 'POST', { ...this.gasto, fecha: this.fecha }); this.aviso = 'Gasto registrado. Si pagaste con caja, registra también la salida de efectivo.'; }); },
        async verUsuarios() { await this.accion(async () => { this.usuarios = await this.api('/usuarios'); }); },
        async crearUsuario() { await this.accion(async () => { await this.api('/usuarios', 'POST', this.nuevoUsuario); this.nuevoUsuario = { name: '', email: '', password: '', rol: 'cajero' }; this.usuarios = await this.api('/usuarios'); }); },
        async activarUsuario(u) { await this.accion(async () => { await this.api('/usuarios/' + u.id, 'PATCH', { activo: !u.activo }); this.usuarios = await this.api('/usuarios'); }); },
        imprimir() { window.print(); },
    }));
});
