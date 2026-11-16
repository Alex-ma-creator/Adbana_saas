const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
let factory;
vm.runInNewContext(fs.readFileSync(path.resolve(__dirname,'../../public/js/carta.js'),'utf8'),{
 navigator:{userAgent:'Desktop',platform:'Win32',maxTouchPoints:0},
 document:{addEventListener:(_,fn)=>fn()},Alpine:{data:(_,fn)=>factory=fn},localStorage:{getItem:()=>null,setItem:()=>{}}
});
const menu=factory('prueba');
assert.equal(menu.categoriaActual,null);
assert.equal(menu.categoriasVisibles.length,0);
const plato={id:1,precio:'12.00',disponible:true};
menu.categorias=[{id:1,nombre:'Entradas',productos:[plato]},{id:2,nombre:'Fondos',productos:[]},{id:null,nombre:'Otros',productos:[]}];
assert.equal(menu.categoriaActual.nombre,'Entradas');
menu.agregar(plato);
menu.categoriaSeleccionada='2';
assert.equal(menu.categoriasVisibles.length,1);
assert.equal(menu.categoriaActual.nombre,'Fondos');
assert.equal(menu.unidades,1);
menu.categoriaSeleccionada='otros';
assert.equal(menu.categoriaActual.nombre,'Otros');
menu.categorias=menu.categorias.slice(0,2);
assert.equal(menu.categoriaActual.nombre,'Entradas');
assert.equal(menu.total,12);
console.log('OK: categorías, grupo sin categoría, selección retirada y carrito conservado al cambiar.');

assert.equal(menu.normalizarTelefono('abc'), '');
assert.equal(menu.normalizarTelefono('01a999-888'), '01999888');
assert.equal(menu.normalizarTelefono('+51 999 888 777'), '51999888777');
assert.equal(menu.normalizarTelefono('1'.repeat(40)).length, 30);
menu.form.cliente_nombre='Ana';menu.form.tipo='recojo';menu.form.cliente_telefono='abc';
assert(menu.validar().includes(menu.t('val_telefono_digitos')));
menu.form.cliente_telefono='';
assert.equal(menu.validar().length,0);
console.log('OK: teléfono solo dígitos, pegado, ceros iniciales y campo opcional.');

let opened=0;
menu.estadoModelo='error';
menu.abrirDetalles(plato,{showModal(){opened++;}});
assert.equal(opened,1);
assert.equal(menu.detalleActivo,plato.id);
assert.equal(menu.estadoModelo,'cargando');
let focused='';
menu.$nextTick=fn=>fn();
menu.$refs={tituloPedido:{focus(){focused='title';}},barraPedido:{focus(){focused='cart';}}};
menu.abrirPedido();assert.equal(menu.abierto,true);assert.equal(focused,'title');
menu.cerrarPedido();assert.equal(menu.abierto,false);assert.equal(focused,'cart');
for(const idioma of ['es','en','pt','fr']) {
 menu.idioma=idioma;
 for(const key of ['pedir_plato','ver_plato','detalles_plato','error_modelo','cargando_modelo']) assert.notEqual(menu.t(key),key);
}
menu.redireccionarHttpsMovilSiNecesario=()=>false;
menu.mostrarGuiaAR=()=>{};
let closed=false;
(async()=>{
 await menu.abrirAR(plato,{setAttribute(){},async activateAR(){throw Error('Unavailable');},closest(){return {open:true,close(){closed=true;}};}});
 assert.equal(closed,true);assert.equal(menu.modalAR,true);
 console.log('OK: foco del pedido, reintento de modelo, traducciones y alternativa AR fuera del diálogo.');
})().catch(error=>{console.error(error);process.exitCode=1;});
