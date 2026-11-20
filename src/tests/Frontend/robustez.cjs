const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
function panel(){let factory;const values=new Map();const storage={getItem:k=>values.get(k)||null,setItem:(k,v)=>values.set(k,v),removeItem:k=>values.delete(k)};
const context={document:{addEventListener:(_,f)=>f(),hidden:false},Alpine:{data:(_,f)=>factory=f},sessionStorage:storage,localStorage:storage,crypto:require('crypto').webcrypto,setInterval:()=>1,clearInterval(){},console};
vm.runInNewContext(fs.readFileSync(__dirname+'/../../public/js/panel.js','utf8'),context);return {app:factory(),storage,context};}
(async()=>{
const {app,storage}=panel();app.user={id:1,rol:'dueno',permisos:{pedidos:true,caja:true}};app.cargando=false;app.actualizar=async()=>{};
app.carrito={1:{id:1,precio:'60.00',cantidad:1}};app.cliente='Ana';app.tipo='recojo';const bodies=[];let failures=1;
app.api=async(_,method,body)=>{bodies.push(JSON.stringify(body));if(failures--)throw Error('network');return {id:7}};
await app.guardarVenta(false);assert(storage.getItem('adbana:pos:1'));app.carrito[1].cantidad=2;app.cliente='Cambio';await app.guardarVenta(false);
assert.equal(bodies[0],bodies[1]);assert.equal(storage.getItem('adbana:pos:1'),null);
let attempts=[];app.api=async(_,method,body)=>{attempts.push(JSON.stringify(body));if(attempts.length===1)throw Error('lost');return {efectivo_esperado:'50.00'}};
await assert.rejects(()=>app.movimientoSeguro('caja','/caja/movimientos',{tipo:'salida',monto:'50',motivo:'Compra'}));
await assert.rejects(()=>app.movimientoSeguro('caja','/caja/movimientos',{tipo:'salida',monto:'25',motivo:'Compra'}));assert.equal(attempts.length,1);
await app.movimientoSeguro('caja','/caja/movimientos',{tipo:'salida',monto:'50',motivo:'Compra'});assert.equal(attempts[0],attempts[1]);
const {app:paged}=panel();paged.user={id:2};paged.tab='pedidos';let calls=[];paged.api=async path=>{calls.push(path);return {data:[{id:2}],last_page:900,total:18000}};
await paged.cargarPedidos();assert.equal(calls.length,1);assert.equal(paged.ultimaPedidos,900);assert.equal(paged.pedidos.length,1);
paged.paginaPedidos=2;await paged.cargarPedidos();assert.equal(calls.length,2);assert(calls[1].includes('page=2'));
paged.tab='mesas';paged.paginaPedidos=1;await paged.cargarPedidos();assert(calls[2].startsWith('/cuentas?'));assert.equal(paged.mesasResumen.length,1);
const {app:late}=panel();late.tab='pedidos';let finish;late.api=()=>new Promise(r=>finish=r);const pending=late.cargarPedidos();late.filtroPedidos='nuevo';finish({data:[{id:99}],last_page:1});await pending;assert.equal(late.pedidos.length,0);
const sesion=panel();let ruta='/login',query='?administracion=1';sesion.context.URLSearchParams=URLSearchParams;sesion.context.window={location:{get pathname(){return ruta},get search(){return query}},history:{replaceState:(_,__,url)=>{const parsed=new URL(url,'http://localhost');ruta=parsed.pathname;query=parsed.search}}};sesion.app.$watch=()=>{};sesion.app.actualizar=async()=>{};sesion.app.recuperarPendientes=()=>{};await sesion.app.init();assert.equal(query,'');sesion.app.email='prueba@example.test';sesion.app.password='SoloPrueba';const usuario={id:1,rol:'dueno',permisos:{reportes:true}};sesion.app.api=async()=>({token:'token-prueba',user:usuario});await sesion.app.login();assert.equal(ruta,'/panel');assert.equal(sesion.storage.getItem('adbana:token'),'token-prueba');const recargada={...sesion.app,user:null,token:sesion.storage.getItem('adbana:token')};let validaciones=0;recargada.api=async path=>{assert.equal(path,'/auth/me');validaciones++;return usuario};await recargada.init();assert.equal(validaciones,1);assert.equal(recargada.user.id,1);recargada.salirLocal();assert.equal(sesion.storage.getItem('adbana:token'),null);
console.log('OK: POS congelado tras pérdida de respuesta, movimientos persistentes y conflictos, paginación acotada, resumen de mesas y descarte de resultados obsoletos.');
})().catch(e=>{console.error(e);process.exitCode=1});
