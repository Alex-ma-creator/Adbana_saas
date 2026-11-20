<div class="adbana-loading" x-show="{{ $visible }}" x-cloak :class="{'adbana-loading--blocking': {{ $bloqueante }}}" role="status" aria-label="Cargando" aria-live="polite">
    <div class="adbana-loading__orbit" aria-hidden="true">
        <svg class="adbana-loading__ring adbana-loading__ring--outer" viewBox="0 0 160 160" fill="none">
            <path d="M126.67 33.33a66 66 0 0 1 0 93.34M33.33 126.67a66 66 0 0 1 0-93.34"/>
        </svg>
        <svg class="adbana-loading__ring adbana-loading__ring--inner" viewBox="0 0 160 160" fill="none">
            <path d="M116.06 43.94a51 51 0 0 1 0 72.12M43.94 116.06a51 51 0 0 1 0-72.12"/>
        </svg>
        <img class="adbana-loading__brand" src="/images/adbana-carga.svg" width="54" height="76" alt="">
    </div>
</div>
