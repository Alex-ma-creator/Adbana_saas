<div class="notice-toast" role="status" aria-live="polite" aria-atomic="true"
    x-data="{ cierre:null, reanudar(){clearTimeout(this.cierre);if(this.aviso)this.cierre=setTimeout(()=>{this.aviso=''},8000)}, destroy(){clearTimeout(this.cierre)} }"
    x-init="$watch('aviso',()=>reanudar());reanudar()"
    x-show="aviso" x-transition.opacity.duration.180ms
    @mouseenter="clearTimeout(cierre)" @mouseleave="reanudar()"
    @focusin="clearTimeout(cierre)" @focusout="reanudar()">
    <svg class="notice-toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <span x-text="aviso"></span>
    <button type="button" class="notice-toast-close" @click="aviso=''" aria-label="Cerrar notificación"><svg class="icon" aria-hidden="true"><use href="/images/panel-icons.svg#close"></use></svg></button>
</div>
