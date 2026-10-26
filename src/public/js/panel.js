document.addEventListener('alpine:init', () => {
    Alpine.data('panel', () => ({
        token: sessionStorage.getItem('adbana:token') || '', user: null,
        menuAbierto: false, perfilAbierto: false, avisosAbiertos: false, notificaciones: [], dashboard: null, ultimaActualizacion: '', busquedaGlobal: '', filtroPedidos: '', tema: localStorage.getItem('adbana:tema') || 'light', perfil: {name:'',email:'',current_password:'',password:'',password_confirmation:''}, registro: false, nombre: '', restauranteNombre: '', email: '', password: '', tab: 'venta', busy: false, cargando: false, cargaVersion: 0, cajaEnCurso: null,  error: '', aviso: '',
        productos: [], categorias: [], pedidos: [], pagina: 1, ultima: 1, q: '',
        caja: null, resumen: null, cierre: null, reporte: null, fecha: new Date().toLocaleDateString('en-CA'),
        carrito: {}, clave: '', cliente: 'Venta mostrador', tipo: 'recojo', mesa: '', direccion: '', telefono: '', notas: '',
        cobro: null, medio: 'efectivo', recibido: '', comprobante: null,
        inicial: '0', contado: '', movimiento: { tipo: 'salida', monto: '', motivo: '' },
        producto: { nombre: '', precio: '', costo: '', stock: 0, stock_minimo: 0, categoria_id: '', activo: true },
        categoriaNombre: '', stockForm: { producto_id: '', tipo: 'entrada', cantidad: '', stock_real: '', motivo: '' },
        gasto: { descripcion: '', monto: '', categoria: '' }, catalogoPermisos: null, editandoUsuario: null, permisosEditados: {}, usuarios: [], nuevoUsuario: { name: '', email: '', password: '', rol: 'cajero' }, timer: null, timerPermisos: null, refrescandoPermisos: false, pedidosEnCurso: null, sesionVersion: 0,
        get rolNombre() { return ({dueno:'Dueño',cajero:'Cajero',mozo:'Mesero',mozo_cajero:'Mesero con cobro',cocina:'Cocina'})[this.user?.rol] || ''; },
        get iniciales() { return (this.user?.name || '').trim().split(/\s+/).slice(0,2).map(n=>n[0]).join('').toUpperCase(); },
        get navegacion() {
            if (!this.user) return [];
            const p=this.user.permisos;
            return [
                ...(p.reportes ? [{id:'inicio',nombre:'Resumen',icono:'grid'}] : []),
                ...(p.pedidos ? [{id:'venta',nombre:'Punto de venta',icono:'bag'}] : []),
                ...((p.pedidos || p.caja || p.cancelar) ? [{id:'pedidos',nombre:'Pedidos',icono:'orders'}] : []),
                ...(p.cocina ? [{id:'cocina',nombre:'Cocina',icono:'chef'}] : []),
                ...(p.pedidos ? [{id:'mesas',nombre:'Mesas',icono:'table'}] : []),
                ...(p.caja ? [{id:'caja',nombre:'Mi caja',icono:'wallet'}] : []),
                ...((p.productos || p.stock) ? [{id:'productos',nombre:'Productos y stock',icono:'box'}] : []),
                ...((p.reportes || p.gastos) ? [{id:'ventas',nombre:p.reportes?'Ventas y gastos':'Gastos',icono:'chart'}] : []),
                ...(p.equipo ? [{id:'equipo',nombre:'Equipo',icono:'users'}] : []),
            ];
        },
        get tituloSeccion() { return this.tab==='perfil'?'Mi perfil':(this.navegacion.find(i=>i.id===this.tab)?.nombre || 'Operación'); },
        get pedidosVisibles() { const q=this.filtroPedidos.toLowerCase().trim(); return q ? this.pedidos.filter(p=>[p.id,p.mesa,p.cliente_nombre,...p.detalles.map(d=>d.nombre_producto)].join(' ').toLowerCase().includes(q)) : this.pedidos; },
        get grafico() {
            const serie=this.dashboard?.serie || []; const max=Math.max(1,...serie.map(d=>Number(d.ventas)));
            const puntos=serie.map((d,i)=>({x:35+i*90,y:155-Number(d.ventas)/max*125,valor:Number(d.ventas),fecha:d.fecha}));
            return {puntos,linea:puntos.map(p=>p.x+','+p.y).join(' '),area:puntos.length?'M35,155 L'+puntos.map(p=>p.x+','+p.y).join(' L')+' L575,155 Z':'',max,hayVentas:serie.some(d=>Number(d.ventas)>0)};
        },
        ir(id) { if(this.busy || (id!=='perfil' && !this.navegacion.some(n=>n.id===id)))return; this.tab=id; this.menuAbierto=false; this.perfilAbierto=false; this.avisosAbiertos=false; },
        cambiarTema() { this.tema=this.tema==='dark'?'light':'dark'; localStorage.setItem('adbana:tema',this.tema); },
        fechaCorta(fecha) { return new Date(fecha+'T12:00:00').toLocaleDateString('es-PE',{weekday:'short'}); },
        abrirPerfil() { this.perfil={name:this.user.name,email:this.user.email,current_password:'',password:'',password_confirmation:''}; this.ir('perfil'); },
        async guardarPerfil() { await this.accion(async()=>{ this.user=await this.api('/perfil','PATCH',this.perfil); this.perfil.current_password='';this.perfil.password='';this.perfil.password_confirmation='';this.aviso='Tu perfil se actualizó correctamente.'; }); },
        async buscarGlobal() { if(this.user.permisos.pedidos){this.q=this.busquedaGlobal;this.pagina=1;this.ir('venta');await this.accion(()=>this.actualizar());}else{this.filtroPedidos=this.busquedaGlobal;} },
        async verAvisos() {
            if(this.busy)return;this.avisosAbiertos=!this.avisosAbiertos;this.perfilAbierto=false;if(!this.avisosAbiertos)return;
            await this.accion(async()=>{
                const p=this.user.permisos;
                const [pedidos,stock]=await Promise.all([(p.pedidos||p.caja||p.cancelar)?this.api('/pedidos?estado=pendiente'):p.cocina?this.api('/cocina'):Promise.resolve({data:[]}),p.caja?this.api('/stock/alertas'):Promise.resolve([])]);
                this.notificaciones=[...pedidos.data.slice(0,5).map(o=>({id:'p'+o.id,titulo:'Pedido #'+o.id,texto:o.estado+(o.mesa?' · Mesa '+o.mesa:''),destino:(p.pedidos||p.caja||p.cancelar)?'pedidos':'cocina',icono:'orders'})),...stock.slice(0,5).map(o=>({id:'s'+o.id,titulo:o.nombre,texto:'Stock bajo: '+o.stock,destino:(p.productos||p.stock)?'productos':(p.pedidos?'venta':'caja'),icono:'box'}))];
            });
        },
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
            const version = this.sesionVersion;
            const response = await fetch('/api' + path, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(this.token ? { Authorization: 'Bearer ' + this.token } : {}) }, ...(data === undefined ? {} : { body: JSON.stringify(data) }) });
            const body = await response.json().catch(() => ({}));
            if (version !== this.sesionVersion) throw Error('La sesión cambió.');
            if (response.status === 401) { this.salirLocal(); throw Error('Inicia sesión para continuar.'); }
            if (!response.ok) throw Error(body.errors ? Object.values(body.errors).flat().join(' ') : (body.message || 'No se pudo completar la operación.'));
            return body;
        },
        async accion(fn) {
            if (this.busy || this.cargando) return;
            this.busy = true; this.cargaVersion++; this.cargando = false; this.error = ''; this.aviso = '';
            try { if (this.pedidosEnCurso) await this.pedidosEnCurso.promise.catch(() => {}); await fn(); } catch (e) { this.error = e.message; } finally { this.busy = false; }
        },
        async init() {
            if (this.token) await this.accion(async () => { this.user = await this.api('/auth/me'); this.tab = this.navegacion[0]?.id || 'perfil'; await this.actualizar(); });
            this.$watch('tab', () => { if (this.user && !this.busy) this.cargarSeccion(); });
            this.timer = setInterval(() => {
                if (this.user && !this.busy && !document.hidden && ['pedidos', 'cocina', 'mesas'].includes(this.tab)) {
                    this.cargarPedidos().catch(e => this.error = e.message);
                }
            }, 15000);
            this.timerPermisos=setInterval(()=>{if(this.user&&!this.busy&&!this.cargando&&!document.hidden)this.sincronizarPermisos().catch(e=>this.error=e.message);},30000);
        },
        destroy() { clearInterval(this.timer);clearInterval(this.timerPermisos); },
        async login() {
            await this.accion(async () => {
                const data = await this.api(this.registro ? '/auth/registro-dueno' : '/auth/login', 'POST', { email: this.email, password: this.password, ...(this.registro ? { name: this.nombre, restaurante: this.restauranteNombre } : {}) });
                this.token = data.token; sessionStorage.setItem('adbana:token', data.token); this.password = '';
                this.user = data.user; this.tab = this.navegacion[0]?.id || 'perfil'; await this.actualizar();
            });
        },
        salirLocal() { this.perfil={name:'',email:'',current_password:'',password:'',password_confirmation:''}; this.editandoUsuario=null;this.catalogoPermisos=null;this.usuarios=[];this.permisosEditados={};this.dashboard=null; this.notificaciones=[]; this.perfilAbierto=false; this.avisosAbiertos=false; this.cargaVersion++; this.cargando=false; this.cajaEnCurso=null; this.sesionVersion++; this.pedidos = []; this.productos = []; this.categorias = []; this.caja = null; this.resumen = null; this.pedidosEnCurso = null; this.token = ''; this.user = null; this.carrito = {}; this.cobro = null; this.comprobante = null; sessionStorage.removeItem('adbana:token'); },
        async logout() { await this.accion(async () => { await this.api('/auth/logout', 'POST'); this.salirLocal(); }); },
        async sincronizarPermisos() {
            if(this.refrescandoPermisos || !this.user)return;
            this.refrescandoPermisos=true;
            try {
                const anterior=JSON.stringify(this.user.permisos);
                const user=await this.api('/auth/me');
                if(!user.activo || !user.restaurante?.activo){this.salirLocal();throw Error('Tu cuenta o restaurante está desactivado.');}
                this.user=user;
                if(anterior!==JSON.stringify(user.permisos)) {
                    this.caja=null;this.resumen=null;this.cobro=null;this.productos=[];this.carrito={};this.pedidos=[];this.reporte=null;this.dashboard=null;this.editandoUsuario=null;this.usuarios=[];this.notificaciones=[];
                    if(this.tab!=='perfil'&&!this.navegacion.some(n=>n.id===this.tab))this.tab=this.navegacion[0]?.id||'perfil';
                    this.aviso='Tus permisos se actualizaron.';
                }
            } finally {this.refrescandoPermisos=false;}
        },
        async cargarSeccion() {
            const version = ++this.cargaVersion;
            this.cargando = true; this.error = '';
            try { await this.sincronizarPermisos(); await this.actualizar(); }
            catch (error) { if (version === this.cargaVersion) this.error = error.message; }
            finally { if (version === this.cargaVersion) this.cargando = false; }
        },
        async actualizar() {
            if (!this.user) return;
            const version = this.sesionVersion;
            const tab = this.tab;
            const carga = this.cargaVersion;
            const vigente = () => version === this.sesionVersion && tab === this.tab && carga === this.cargaVersion;
            if (['pedidos', 'cocina', 'mesas'].includes(this.tab)) {
                await Promise.all([this.cargarPedidos(), this.user.permisos.caja && this.tab !== 'cocina' ? this.cargarCaja() : Promise.resolve()]);
                return;
            }
            this.ultimaActualizacion = new Date().toLocaleTimeString('es-PE',{hour:'2-digit',minute:'2-digit'});
            if(tab==='inicio'){const data=await this.api('/reportes/dashboard');if(vigente())this.dashboard=data;return;}
            if(this.tab==='perfil')return;
            if (this.tab === 'caja') { await this.cargarCaja(); return; }
            if (tab === 'ventas') { if(!this.user.permisos.reportes)return; const data = await this.api('/reportes/resumen?desde=' + this.fecha + '&hasta=' + this.fecha); if(vigente())this.reporte=data; return; }
            if (tab === 'equipo') { const [data,catalogo] = await Promise.all([this.api('/usuarios'),this.api('/usuarios/permisos')]); if(vigente()){this.usuarios=data;this.catalogoPermisos=catalogo;if(this.editandoUsuario&&!data.some(u=>u.id===this.editandoUsuario.id))this.editandoUsuario=null;if(!this.rolesDisponibles.includes(this.nuevoUsuario.rol))this.nuevoUsuario.rol=this.rolesDisponibles[0]||'mozo';} return; }
            const [productos, categorias] = await Promise.all([
                this.api('/productos?q=' + encodeURIComponent(this.q) + '&page=' + this.pagina), this.api('/categorias'),
                this.user.permisos.caja ? this.cargarCaja() : Promise.resolve(),
            ]);
            if (!vigente()) return;
            this.productos = productos.data; this.ultima = productos.last_page; this.categorias = categorias.data || categorias;
        },
        async cargarCaja() {
            if (this.cajaEnCurso) return this.cajaEnCurso;
            const version = this.sesionVersion;
            const promise = (async () => {
                const data = await this.api('/caja/actual');
                if (version !== this.sesionVersion) return;
                this.caja = data.caja; this.resumen = data.resumen;
            })();
            this.cajaEnCurso = promise;
            try { await promise; } finally { if(this.cajaEnCurso === promise)this.cajaEnCurso=null; }
        },
        async cargarPedidos() {
            const version = this.sesionVersion;
            const cocina = this.tab === 'cocina';
            const key = version + ':' + cocina;
            if (this.pedidosEnCurso?.key === key) return this.pedidosEnCurso.promise;
            const promise = (async () => {
                let page = 1, last = 1, pedidos = [];
                do {
                    const ruta = cocina ? '/cocina?page=' : '/pedidos?estado=pendiente,confirmado,preparando,listo&page=';
                    const data = await this.api(ruta + page);
                    if (version !== this.sesionVersion || cocina !== (this.tab === 'cocina')) return;
                    pedidos.push(...data.data); last = data.last_page; page++;
                } while (page <= last);
                this.pedidos = pedidos;
            })();
            this.pedidosEnCurso = {key, promise};
            try { await promise; } finally { if (this.pedidosEnCurso?.promise === promise) this.pedidosEnCurso = null; }
        },
        agregar(p) {
            if (!p.activo || p.stock < 1 || this.busy || this.cargando) return;
            const existente = this.carrito[p.id];
            if ((existente?.cantidad || 0) >= Math.min(p.stock, 100)) return;
            this.carrito[p.id] = { ...p, cantidad: (existente?.cantidad || 0) + 1 }; this.clave = '';
        },
        quitar(p) { if (this.busy || this.cargando) return; if (--this.carrito[p.id].cantidad <= 0) delete this.carrito[p.id]; this.clave = ''; },
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
        prepararCobro(p) { if (p.cobrado_at) return; this.cobro = p; this.medio = 'efectivo'; this.recibido = p.total; },
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
        siguiente(p) {
            const permiso = this.user.permisos;
            if(this.tab==='cocina')return permiso.cocina ? ({confirmado:'preparando',preparando:'listo'})[p.estado] : null;
            if(p.estado==='pendiente' && permiso.pedidos)return 'confirmado';
            if(permiso.cocina && ['confirmado','preparando'].includes(p.estado))return ({confirmado:'preparando',preparando:'listo'})[p.estado];
            return p.estado==='listo' && p.cobrado_at && permiso.pedidos ? 'entregado' : null;
        },
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
        async crearUsuario() { await this.accion(async () => { await this.api('/usuarios', 'POST', this.nuevoUsuario); this.nuevoUsuario = { name: '', email: '', password: '', rol: this.rolesDisponibles[0]||'mozo' }; this.usuarios = await this.api('/usuarios'); }); },
        async activarUsuario(u) { await this.accion(async () => { await this.api('/usuarios/' + u.id, 'PATCH', { activo: !u.activo }); this.usuarios = await this.api('/usuarios'); }); },
        nombreRol(rol) {return ({dueno:'Dueño',cajero:'Cajero',mozo:'Mesero',mozo_cajero:'Mesero con cobro',cocina:'Cocina'})[rol]||rol;},
        get rolesDisponibles() {
            return Object.keys(this.catalogoPermisos?.roles||{}).filter(rol=>rol!=='mozo_cajero' && (this.user?.rol==='dueno'||Object.entries(this.catalogoPermisos.roles[rol]).every(([k,v])=>!v||this.catalogoPermisos.delegables.includes(k))));
        },
        editarPermisos(u) {
            this.editandoUsuario={...u};this.permisosEditados={...u.permisos};this.aviso='';this.error='';
        },
        restaurarPermisos() {
            if(!this.editandoUsuario)return;
            const base=this.catalogoPermisos.roles[this.editandoUsuario.rol];
            for(const clave of this.catalogoPermisos.delegables)this.permisosEditados[clave]=base[clave];
        },
        async guardarPermisos() {
            const u=this.editandoUsuario;
            const base=this.catalogoPermisos.roles[u.rol];
            const cambios=Object.fromEntries(Object.keys(this.catalogoPermisos.permisos).filter(k=>!!this.permisosEditados[k]!==base[k]).map(k=>[k,!!this.permisosEditados[k]]));
            await this.accion(async()=>{
                await this.api('/usuarios/'+u.id,'PATCH',{permisos_personalizados:cambios});
                this.usuarios=await this.api('/usuarios');this.editandoUsuario=null;this.aviso='Permisos guardados. Se aplican a las nuevas operaciones; el menú de esa persona se actualizará automáticamente.';
            });
        },
        imprimir() { window.print(); },
    }));
});
