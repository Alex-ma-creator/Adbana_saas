const view=document.querySelector('#view'),help=document.querySelector('#help');
const back=document.querySelector('#back');
const carta=new URLSearchParams(window.location.search).get('carta');
// Solo se admiten rutas locales de carta; nunca redirecciones a destinos externos.
if(carta && /^\/c\/[a-z0-9-]+$/.test(carta)){back.href=carta;back.textContent='Volver a la carta ↗';}
function mode(ar){
    if(ar&&!window.isSecureContext){help.textContent='La cámara necesita una conexión HTTPS o localhost. Puedes seguir explorando el plato en 3D.';return;}
    const destino=ar?'ar.html':'visor.html';
    if(view.getAttribute('src')!==destino)view.src=destino;
    document.querySelector('#preview').setAttribute('aria-pressed',String(!ar));
    document.querySelector('#camera').setAttribute('aria-pressed',String(ar));
    document.querySelector('#mode-label').textContent=ar?'CÁMARA AR':'VISTA 3D';
    help.textContent=ar?'Permite la cámara y apunta al marcador HIRO completo, con buena luz. Sin marcador no aparecerá el plato.':'Arrastra con el mouse o el dedo para girar el plato. Esta vista no utiliza la cámara.';
}
document.querySelector('#preview').onclick=()=>mode(false);
document.querySelector('#camera').onclick=()=>mode(true);
