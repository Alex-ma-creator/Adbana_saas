const view=document.querySelector('#view'),help=document.querySelector('#help');
const back=document.querySelector('#back');
const carta=new URLSearchParams(window.location.search).get('carta');
if(carta && /^\/c\/[a-z0-9-]+$/.test(carta)){back.href=carta;back.setAttribute('data-i18n','back');}
let arActive=false,secureWarning=false;
function renderMode(){
    document.querySelector('#preview').setAttribute('aria-pressed',String(!arActive));
    document.querySelector('#camera').setAttribute('aria-pressed',String(arActive));
    document.querySelector('#mode-label').textContent=DemoI18n.t(arActive?'viewAr':'view3d');
    help.textContent=DemoI18n.t(secureWarning?'secure':arActive?'helpAr':'help3d');
    back.textContent=DemoI18n.t(back.getAttribute('data-i18n'));
}
function mode(ar){
    secureWarning=ar&&!window.isSecureContext;
    if(secureWarning){renderMode();return;}
    arActive=ar;
    const destino=(ar?'ar.html':'visor.html')+'?v=idiomas-1';
    if(view.getAttribute('src')!==destino)view.src=destino;
    renderMode();
}
document.querySelector('#preview').onclick=()=>mode(false);
document.querySelector('#camera').onclick=()=>mode(true);
document.addEventListener('adbana:idioma',renderMode);
const menu=document.querySelector('#demo-language'),summary=menu.querySelector('summary');
menu.querySelectorAll('[data-language]').forEach(button=>button.addEventListener('click',()=>{DemoI18n.setLanguage(button.dataset.language);menu.open=false;summary.focus();}));
document.addEventListener('click',event=>{if(!menu.contains(event.target))menu.open=false;});
menu.addEventListener('keydown',event=>{
    if(event.key==='Escape'){menu.open=false;summary.focus();return;}
    const options=Array.from(menu.querySelectorAll('[data-language]'));
    if(!['ArrowDown','ArrowUp','Home','End'].includes(event.key))return;
    event.preventDefault();menu.open=true;
    const index=options.indexOf(document.activeElement);
    const next=event.key==='Home'?0:event.key==='End'?options.length-1:event.key==='ArrowDown'?(index+1)%options.length:(index-1+options.length)%options.length;
    options[next].focus();
});
renderMode();
