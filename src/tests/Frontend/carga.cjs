const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

async function comprobar(archivo, nombre, invocar) {
    let factory;
    const requests = [];
    const context = {
        document: {addEventListener: (_, fn) => fn(), querySelector: () => ({content: 'csrf-test'}), documentElement: {}, hidden: false},
        Alpine: {data: (_, fn) => { factory = fn; }},
        localStorage: {getItem: () => null, setItem() {}},
        sessionStorage: {getItem: () => null, setItem() {}, removeItem() {}},
        setInterval: () => 1, clearInterval() {},
        window: {location: {reload() {}}},
        fetch: () => new Promise((resolve, reject) => requests.push({resolve, reject})),
    };
    vm.runInNewContext(fs.readFileSync(__dirname + '/../../public/js/' + archivo, 'utf8'), context);
    const app = factory('prueba');
    app.cargando = false;
    app.busy = true;
    assert.equal(app.consultasBackend, 0, nombre + ': las acciones locales no activan la carga');
    const first = invocar(app);
    const second = invocar(app);
    assert.equal(app.consultasBackend, 2, nombre + ': cuenta solicitudes simultáneas');
    let finishBody;
    requests[1].resolve({status: 200, ok: true, json: () => new Promise(r => { finishBody = r; })});
    await new Promise(setImmediate);
    assert.equal(app.consultasBackend, 2, nombre + ': espera también el cuerpo de la respuesta');
    finishBody({});
    await second;
    assert.equal(app.consultasBackend, 1, nombre + ': conserva la carga mientras otra solicitud sigue pendiente');
    requests[0].reject(new Error('Sin conexión'));
    await assert.rejects(first, /Sin conexión/);
    assert.equal(app.consultasBackend, 0, nombre + ': libera la carga después de un error');
    const interrupted=invocar(app);requests[2].resolve({status:200,ok:true,json:async()=>{const error=Error('Respuesta incompleta');error.name='AbortError';throw error;}});await assert.rejects(interrupted,/Respuesta incompleta/);assert.equal(app.consultasBackend,0);
}
(async () => {
    await comprobar('administracion.js', 'Administración', app => app.api('/me'));
    await comprobar('panel.js', 'Panel', app => app.api('/productos'));
    await comprobar('carta.js', 'Carta', app => app.consultarBackend('/api/carta/prueba'));
    console.log('OK: carga solo con backend, peticiones simultáneas, lectura de respuesta y errores en las tres interfaces.');
})().catch(error => { console.error(error); process.exitCode = 1; });
