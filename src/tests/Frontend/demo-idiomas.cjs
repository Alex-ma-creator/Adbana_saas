const fs=require('fs'),vm=require('vm'),assert=require('assert/strict'),path=require('path');
const source=fs.readFileSync(path.resolve(__dirname,'../../public/demo-ar/idiomas.js'),'utf8');
function setup(initial,blocked=false){
 const handlers={},values=new Map([['adbana:idioma',initial]]);
 const status={dataset:{i18n:'loading'},textContent:'',setAttribute(k,v){if(k==='data-i18n')this.dataset.i18n=v;}};
 const document={readyState:'complete',documentElement:{lang:''},querySelector:()=>null,querySelectorAll:selector=>selector==='[data-i18n]'?[status]:[],dispatchEvent(){}};
 const window={addEventListener:(name,fn)=>handlers[name]=fn};
 const context={window,document,Event:class{constructor(type){this.type=type;}},localStorage:{getItem:k=>{if(blocked)throw Error('blocked');return values.get(k);},setItem:(k,v)=>{if(blocked)throw Error('blocked');values.set(k,v);}}};
 vm.runInNewContext(source,context);return {api:window.DemoI18n,status,document,values,handlers};
}
const s=setup('fr');assert.equal(s.document.documentElement.lang,'fr');assert.equal(s.status.textContent,'Chargement de la pizza 3D…');
s.api.status(s.status,'ready');s.api.setLanguage('en');assert.equal(s.status.textContent,'3D pizza ready');assert.equal(s.values.get('adbana:idioma'),'en');
s.api.setLanguage('pt');assert.equal(s.status.textContent,'Pizza 3D pronta');
s.api.setLanguage('invalid');assert.equal(s.api.language,'pt');
s.handlers.storage({key:'adbana:idioma',newValue:'es'});assert.equal(s.status.textContent,'Pizza 3D lista');
s.api.status(s.status,'cameraError');s.api.setLanguage('fr');assert(s.status.textContent.startsWith('Impossible d’ouvrir'));
for(const lang of ['es','en','pt','fr']){s.api.setLanguage(lang);for(const key of ['preview','camera','helpAr','secure','markerFound','markerLost','print','back','language'])assert.notEqual(s.api.t(key),key);}
const blocked=setup('xx',true);assert.equal(blocked.api.language,'es');assert.doesNotThrow(()=>blocked.api.setLanguage('en'));assert.equal(blocked.document.documentElement.lang,'en');
console.log('OK: idioma heredado, persistencia, sincronización entre documentos, mensajes 3D/AR, cuatro idiomas y almacenamiento no disponible.');
