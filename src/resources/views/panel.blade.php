<!doctype html>

<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Tu espacio | Adbana</title><link rel="icon" type="image/svg+xml" href="/images/adbana-logo.svg"><link rel="stylesheet" href="/css/panel.css?v=20261108-configuracion"><script src="/js/qrcode-2.0.4.js"></script><script src="/js/panel.js?v=20261109-fotos-carta"></script><script defer src="/js/alpine.min.js"></script><link rel="stylesheet" href="/css/carga.css?v=20261031d"><link rel="stylesheet" href="/css/operacion.css?v=20261031"><link rel="stylesheet" href="/css/carta-visual.css?v=1"></head>

<body><div x-data="panel" @keydown.tab.window="atraparOperacion($event)" @offline.window="enLinea=false" @online.window="enLinea=true" :data-theme="tema" class="app-root" x-cloak @keydown.escape.window="menuAbierto=false;perfilAbierto=false;avisosAbiertos=false;idiomaAbierto=false;cerrarOperacion()">

@include('layouts.carga', ['visible' => 'consultasBackend > 0 && (busy || cargando)', 'bloqueante' => 'busy'])



<div class="auth-layout" x-show="!user"><aside class="auth-art"><a class="auth-brand" href="/"><img class="brand-logo" src="/images/adbana-logo.svg" alt="" width="30" height="40"> ADBANA</a><div><span class="eyebrow" x-text="t('auth_eyebrow')">CADA MESA. CADA PEDIDO.</span><h2><span x-text="t('auth_hero_1')">Todo listo para</span><br><span style="color:inherit" x-text="t('auth_hero_2')">un gran servicio</span><span>.</span></h2><p x-text="t('auth_sub')">Tu equipo conectado. Tu operación, bajo control.</p><div class="auth-illustration"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#food"></use></svg><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#chef"></use></svg><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#bag"></use></svg></div></div><small x-text="t('auth_foot')">Hecho para el ritmo de tu restaurante.</small></aside>

<div class="auth-panel-wrap">

<div class="auth-topbar">

    <div class="popover-anchor" @click.outside="idiomaAbierto=false">

        <button type="button" class="lang-trigger" :class="{'is-open':idiomaAbierto}" :aria-label="t('cambiar_idioma')" :aria-expanded="idiomaAbierto" aria-haspopup="listbox" @click="idiomaAbierto=!idiomaAbierto">

            <svg class="icon" aria-hidden="true"><use href="/images/panel-icons.svg#globe"></use></svg>

            <span class="lang-code-pill" x-text="idiomaActual.corto">ES</span>

            <svg class="lang-chevron" aria-hidden="true"><use href="/images/panel-icons.svg#chevron"></use></svg>

        </button>

        <div class="popover lang-popover" x-show="idiomaAbierto" role="listbox" :aria-label="t('idioma_titulo')">

            <div class="popover-heading"><strong x-text="t('idioma_titulo')">Idioma del sistema</strong><small x-text="t('idioma_subtitulo')">Interfaz global</small></div>

            <div class="lang-list">

                <template x-for="lang in idiomasDisponibles" :key="lang.codigo">

                    <button type="button" class="lang-option" :class="{active:idioma===lang.codigo}" role="option" :aria-selected="idioma===lang.codigo" @click="cambiarIdioma(lang.codigo)">

                        <span class="lang-badge" x-text="lang.corto"></span>

                        <span class="lang-meta"><strong x-text="lang.nombre"></strong><small x-text="lang.region"></small></span>

                        <svg class="lang-check" x-show="idioma===lang.codigo" aria-hidden="true"><use href="/images/panel-icons.svg#check"></use></svg>

                    </button>

                </template>

            </div>

        </div>

    </div>

    <button type="button" class="icon-button" :aria-label="tema==='light'?t('tema_oscuro'):t('tema_claro')" @click="cambiarTema()"><svg class="icon"><use :href="'/images/panel-icons.svg#'+(tema==='light'?'moon':'sun')"></use></svg></button>

</div>

<form class="login card" x-show="!user && authModo==='login'" @submit.prevent="login()">

<span class="eyebrow" x-text="t('login_welcome')">BIENVENIDO A ADBANA</span><h1 x-text="registro ? t('login_title_reg') : t('login_title_in')">Tu restaurante, en marcha</h1><p class="muted" x-text="registro ? t('login_sub_reg') : t('login_sub_in')"></p>

<label x-show="registro"><span x-text="t('lbl_restaurante')">Nombre del restaurante</span><input x-model="restauranteNombre" :required="registro" maxlength="120"></label><label x-show="registro"><span x-text="t('lbl_tu_nombre')">Tu nombre</span><input x-model="nombre" :required="registro" maxlength="120"></label><label><span x-text="t('lbl_correo')">Correo</span><input type="email" x-model="email" @input="espaciosAcceso=[];espacioAcceso=''" required autocomplete="username"></label><label><span x-text="t('lbl_password')">Contraseña</span><input type="password" x-model="password" @input="espaciosAcceso=[];espacioAcceso=''" :minlength="registro ? 8 : 1" required autocomplete="current-password"></label>

<label x-show="!registro&&espaciosAcceso.length">Elige tu espacio<select x-model="espacioAcceso" :required="!registro&&espaciosAcceso.length>0"><option value="">Selecciona dónde trabajar</option><template x-for="e in espaciosAcceso" :key="e.id"><option :value="e.id" x-text="e.nombre+' · '+e.descripcion"></option></template></select></label><p class="muted" x-show="!registro">Una entrada para tu restaurante y la administración de Adbana. Solo verás tus accesos autorizados.</p><p class="alert" x-show="error" x-text="error" role="alert"></p><button class="primary wide" :disabled="busy" x-text="registro ? t('btn_crear_rest') : t('btn_ingresar')">Ingresar</button><button type="button" class="wide" @click="registro=!registro;espaciosAcceso=[];espacioAcceso='';error='';aviso=''" x-text="registro ? t('btn_ya_tengo') : t('btn_crear_mi_rest')"></button>

<button type="button" class="wide" @click="authModo='recuperar';resetEmail=email;error='';aviso=''" x-text="t('recuperar')"></button><p class="muted" role="status" x-show="aviso" x-text="aviso"></p>

</form>

<form class="login card" x-show="!user && authModo!=='login'" @submit.prevent="authModo==='recuperar'?recuperarCuenta():restablecerCuenta()">

<h1 x-text="t('recuperar')"></h1><label><span x-text="t('lbl_correo')"></span><input type="email" x-model="resetEmail" required autocomplete="email"></label>

<template x-if="authModo==='restablecer'"><div><label><span x-text="t('nueva_password')"></span><input type="password" x-model="nuevaPassword" required minlength="8" autocomplete="new-password"></label><label><span x-text="t('confirmar_password')"></span><input type="password" x-model="confirmarPassword" required minlength="8" autocomplete="new-password"></label></div></template>

<p class="alert" role="alert" x-show="error" x-text="error"></p><p class="muted" role="status" x-show="aviso" x-text="aviso"></p>

<button class="primary wide" :disabled="busy" x-text="authModo==='recuperar'?t('enviar_enlace'):t('guardar_password')"></button><button type="button" class="wide" @click="authModo='login';error='';aviso=''" x-text="t('volver_login')"></button>

</form></div></div>

<template x-if="user"><div>

<div class="sidebar-backdrop" x-show="menuAbierto" @click="menuAbierto=false"></div>

<aside class="sidebar" :class="{'is-open':menuAbierto}" :aria-label="t('menu_principal')">

    <div class="restaurant-brand"><span class="brand-mark"><img class="brand-logo" src="/images/adbana-logo.svg" alt="" width="30" height="40"></span><strong x-text="user.restaurante?.nombre || t('mi_restaurante')"></strong><button class="mobile-only icon-button" :aria-label="t('cerrar_menu')" @click="menuAbierto=false"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#close"></use></svg></button></div>

    <div class="workspace-caption" x-text="t('espacio_trabajo')">TU ESPACIO DE TRABAJO</div>

    <nav class="nav" :aria-label="t('secciones')"><template x-for="item in navegacion" :key="item.id"><button :class="{active:tab===item.id}" :aria-current="tab===item.id?'page':null" :disabled="busy" @click="ir(item.id)"><span class="nav-icon"><svg class="icon" aria-hidden="true"><use :href="'/images/panel-icons.svg#'+item.icono"></use></svg></span><span x-text="item.nombre"></span></button></template></nav>

    <div class="sidebar-bottom"><a class="menu-link" :href="'/c/'+user.restaurante?.slug" target="_blank" rel="noopener"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#external"></use></svg><span x-text="t('ver_carta')">Ver carta digital</span></a><button class="sidebar-user" @click="abrirPerfil()"><span class="avatar" x-text="iniciales"></span><span><strong x-text="user.name"></strong><small x-text="rolNombre"></small></span><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#chevron"></use></svg></button><small class="powered"><span x-text="t('impulsado_por')">Impulsado por</span> <b>Adbana</b></small></div>

</aside>

<div class="workspace">

<header class="top">

    <button class="icon-button mobile-only" :aria-label="t('abrir_menu')" :aria-expanded="menuAbierto" @click="menuAbierto=true"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#menu"></use></svg></button>

    <form class="global-search" @submit.prevent="buscarGlobal()"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#search"></use></svg><input :aria-label="t('buscar_operacion')" x-model="busquedaGlobal" :placeholder="user.rol==='cocina'?t('buscar_comanda'):t('buscar_productos_ph')"><kbd>↵</kbd></form>

    <div class="top-actions"><span class="today-date" x-text="new Date().toLocaleDateString(locale,{day:'numeric',month:'short',year:'numeric'})"></span><button class="icon-button" :aria-label="t('actualizar_seccion')" :title="t('actualizar')" :disabled="busy" @click="accion(()=>actualizar())" :class="{'is-loading':busy || cargando}"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#refresh"></use></svg></button>

    <div class="popover-anchor" @click.outside="avisosAbiertos=false"><button class="icon-button" :aria-label="t('avisos_titulo')" :aria-expanded="avisosAbiertos" @click="verAvisos()"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#bell"></use></svg><span class="notification-dot" x-show="notificaciones.length"></span></button><div class="popover notifications" x-show="avisosAbiertos"><div class="popover-heading"><strong x-text="t('avisos_titulo')">Avisos operativos</strong><small x-text="t('estado_actual')">Estado actual</small></div><p class="muted" x-show="busy" x-text="t('consultando_op')">Consultando tu operación…</p><p class="empty-copy" x-show="!busy && !notificaciones.length" x-text="t('sin_avisos')">No hay avisos por ahora.</p><template x-for="n in notificaciones" :key="n.id"><button class="notification-item" @click="ir(n.destino)"><svg class="icon"><use :href="'/images/panel-icons.svg#'+n.icono"></use></svg><span><strong x-text="n.titulo"></strong><small x-text="n.texto"></small></span><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#arrow"></use></svg></button></template></div></div>

    <div class="popover-anchor" @click.outside="idiomaAbierto=false">

        <button type="button" class="lang-trigger" :class="{'is-open':idiomaAbierto}" :aria-label="t('cambiar_idioma')" :aria-expanded="idiomaAbierto" aria-haspopup="listbox" @click="idiomaAbierto=!idiomaAbierto;avisosAbiertos=false;perfilAbierto=false">

            <svg class="icon" aria-hidden="true"><use href="/images/panel-icons.svg#globe"></use></svg>

            <span class="lang-code-pill" x-text="idiomaActual.corto">ES</span>

            <svg class="lang-chevron" aria-hidden="true"><use href="/images/panel-icons.svg#chevron"></use></svg>

        </button>

        <div class="popover lang-popover" x-show="idiomaAbierto" role="listbox" :aria-label="t('idioma_titulo')">

            <div class="popover-heading"><strong x-text="t('idioma_titulo')">Idioma del sistema</strong><small x-text="t('idioma_subtitulo')">Interfaz global</small></div>

            <div class="lang-list">

                <template x-for="lang in idiomasDisponibles" :key="lang.codigo">

                    <button type="button" class="lang-option" :class="{active:idioma===lang.codigo}" role="option" :aria-selected="idioma===lang.codigo" @click="cambiarIdioma(lang.codigo)">

                        <span class="lang-badge" x-text="lang.corto"></span>

                        <span class="lang-meta"><strong x-text="lang.nombre"></strong><small x-text="lang.region"></small></span>

                        <svg class="lang-check" x-show="idioma===lang.codigo" aria-hidden="true"><use href="/images/panel-icons.svg#check"></use></svg>

                    </button>

                </template>

            </div>

        </div>

    </div>

    <button class="icon-button" :aria-label="tema==='light'?t('tema_oscuro'):t('tema_claro')" @click="cambiarTema()"><svg class="icon"><use :href="'/images/panel-icons.svg#'+(tema==='light'?'moon':'sun')"></use></svg></button>

    <div class="popover-anchor" @click.outside="perfilAbierto=false"><button class="profile-trigger" :aria-label="t('abrir_perfil_de')+user.name" :aria-expanded="perfilAbierto" @click="perfilAbierto=!perfilAbierto;avisosAbiertos=false;idiomaAbierto=false"><span class="avatar" x-text="iniciales"></span><span class="profile-label"><strong x-text="user.name"></strong><small x-text="rolNombre"></small></span><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#chevron"></use></svg></button><div class="popover profile-popover" x-show="perfilAbierto"><strong x-text="user.name"></strong><small x-text="user.email"></small><button @click="abrirPerfil()"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#user"></use></svg> <span x-text="t('mi_perfil')">Mi perfil</span></button><button @click="logout()" :disabled="busy"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#logout"></use></svg> <span x-text="t('cerrar_sesion')">Cerrar sesión</span></button></div></div></div>

</header>

<main class="shell"><div class="page-heading"><div><div class="breadcrumb"><span x-text="t('mi_restaurante')">Mi restaurante</span> <span>/</span> <span x-text="tituloSeccion"></span></div><h1 x-text="tab==='inicio'?t('hola')+user.name.split(' ')[0]:tituloSeccion"></h1><p class="muted" x-text="tab==='inicio'?t('sub_inicio'):t('sub_general')"></p></div><span class="update-time" x-show="ultimaActualizacion"><span x-text="t('actualizado')">Actualizado</span> <b x-text="ultimaActualizacion"></b></span></div>

<p class="section-loading" x-show="cargando" role="status"><span class="loading-dot"></span><span x-text="t('actualizando_datos')">Actualizando datos de esta sección…</span></p><p class="alert" role="alert" x-show="error" x-text="error"></p>@include('layouts.aviso')



@include('layouts.configuracion-restaurante')

<section x-show="tab==='inicio'" class="dashboard-section">

<div class="dashboard-loading" x-show="(busy || cargando) && !dashboard" role="status" x-text="t('dash_cargando')">Preparando el resumen de tu restaurante…</div><template x-if="dashboard"><div>

    <div class="stats-grid">

        <article class="stat-card"><span class="stat-icon red"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#wallet"></use></svg></span><span x-text="t('stat_ventas_hoy')">Ventas cobradas hoy</span><strong x-text="money(dashboard.ventas_hoy)"></strong><small x-text="dashboard.cobrados_hoy+' '+(dashboard.cobrados_hoy===1?t('cobro_reg'):t('cobros_reg'))"></small></article>

        <article class="stat-card"><span class="stat-icon blue"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#bag"></use></svg></span><span x-text="t('stat_pedidos_hoy')">Pedidos del día</span><strong x-text="dashboard.pedidos_hoy"></strong><small x-text="t('registrados_hoy')">Registrados hoy</small></article>

        <article class="stat-card"><span class="stat-icon orange"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#chef"></use></svg></span><span x-text="t('stat_en_operacion')">En operación</span><strong x-text="dashboard.abiertos"></strong><small x-text="t('pedidos_sin_fin')">Pedidos sin finalizar</small></article>

        <article class="stat-card"><span class="stat-icon purple"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#chart"></use></svg></span><span x-text="t('stat_ticket')">Ticket promedio</span><strong x-text="money(dashboard.ticket_promedio)"></strong><small x-text="t('por_pedido_cobrado')">Por pedido cobrado hoy</small></article>

    </div>

    <div class="dashboard-grid"><article class="card sales-chart"><div class="card-heading"><div><h2 x-text="t('chart_titulo')">Así van tus ventas</h2><p class="muted" x-text="t('chart_sub')">Cobros de los últimos 7 días</p></div><span class="range-chip" x-text="t('ultimos_7_dias')">Últimos 7 días</span></div><strong class="chart-total" x-text="money(dashboard.ventas_semana)"></strong><div class="chart-container"><svg viewBox="0 0 610 195" role="img" :aria-label="t('chart_aria')"><defs><linearGradient id="sales-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#ee493b" stop-opacity=".25"/><stop offset="100%" stop-color="#ee493b" stop-opacity="0"/></linearGradient></defs><path d="M35 30H575 M35 72H575 M35 113H575 M35 155H575" class="chart-grid"/><path :d="grafico.area" fill="url(#sales-fill)"/><polyline :points="grafico.linea" fill="none" stroke="#ef4b3e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><g><circle :cx="grafico.puntos[0]?.x || 0" :cy="grafico.puntos[0]?.y || 155" r="4" fill="#ef4b3e" stroke="var(--card)" stroke-width="2"><title x-text="grafico.puntos[0] ? grafico.puntos[0].fecha+': '+money(grafico.puntos[0].valor) : ''"></title></circle><text x="35" y="184" text-anchor="middle" x-text="dashboard.serie[0] ? fechaCorta(dashboard.serie[0].fecha) : ''"></text></g><g><circle :cx="grafico.puntos[1]?.x || 0" :cy="grafico.puntos[1]?.y || 155" r="4" fill="#ef4b3e" stroke="var(--card)" stroke-width="2"><title x-text="grafico.puntos[1] ? grafico.puntos[1].fecha+': '+money(grafico.puntos[1].valor) : ''"></title></circle><text x="125" y="184" text-anchor="middle" x-text="dashboard.serie[1] ? fechaCorta(dashboard.serie[1].fecha) : ''"></text></g><g><circle :cx="grafico.puntos[2]?.x || 0" :cy="grafico.puntos[2]?.y || 155" r="4" fill="#ef4b3e" stroke="var(--card)" stroke-width="2"><title x-text="grafico.puntos[2] ? grafico.puntos[2].fecha+': '+money(grafico.puntos[2].valor) : ''"></title></circle><text x="215" y="184" text-anchor="middle" x-text="dashboard.serie[2] ? fechaCorta(dashboard.serie[2].fecha) : ''"></text></g><g><circle :cx="grafico.puntos[3]?.x || 0" :cy="grafico.puntos[3]?.y || 155" r="4" fill="#ef4b3e" stroke="var(--card)" stroke-width="2"><title x-text="grafico.puntos[3] ? grafico.puntos[3].fecha+': '+money(grafico.puntos[3].valor) : ''"></title></circle><text x="305" y="184" text-anchor="middle" x-text="dashboard.serie[3] ? fechaCorta(dashboard.serie[3].fecha) : ''"></text></g><g><circle :cx="grafico.puntos[4]?.x || 0" :cy="grafico.puntos[4]?.y || 155" r="4" fill="#ef4b3e" stroke="var(--card)" stroke-width="2"><title x-text="grafico.puntos[4] ? grafico.puntos[4].fecha+': '+money(grafico.puntos[4].valor) : ''"></title></circle><text x="395" y="184" text-anchor="middle" x-text="dashboard.serie[4] ? fechaCorta(dashboard.serie[4].fecha) : ''"></text></g><g><circle :cx="grafico.puntos[5]?.x || 0" :cy="grafico.puntos[5]?.y || 155" r="4" fill="#ef4b3e" stroke="var(--card)" stroke-width="2"><title x-text="grafico.puntos[5] ? grafico.puntos[5].fecha+': '+money(grafico.puntos[5].valor) : ''"></title></circle><text x="485" y="184" text-anchor="middle" x-text="dashboard.serie[5] ? fechaCorta(dashboard.serie[5].fecha) : ''"></text></g><g><circle :cx="grafico.puntos[6]?.x || 0" :cy="grafico.puntos[6]?.y || 155" r="4" fill="#ef4b3e" stroke="var(--card)" stroke-width="2"><title x-text="grafico.puntos[6] ? grafico.puntos[6].fecha+': '+money(grafico.puntos[6].valor) : ''"></title></circle><text x="575" y="184" text-anchor="middle" x-text="dashboard.serie[6] ? fechaCorta(dashboard.serie[6].fecha) : ''"></text></g></svg><p class="chart-empty" x-show="!grafico.hayVentas" x-text="t('chart_vacio')">Aquí verás tus ventas cuando registres el primer cobro.</p></div></article>

    <article class="card top-products"><div class="card-heading"><div><h2 x-text="t('fav_titulo')">Los favoritos</h2><p class="muted" x-text="t('fav_sub')">Productos más vendidos · 7 días</p></div><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#food"></use></svg></div><div class="empty-state compact" x-show="!dashboard.top.length"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#bag"></use></svg><h3 x-text="t('fav_vacio_h')">Tu próximo favorito está por llegar</h3><p x-text="t('fav_vacio_p')">Los productos aparecerán aquí al registrar ventas.</p></div><template x-for="(p,i) in dashboard.top" :key="p.nombre"><div class="rank-item"><span class="rank-number" x-text="String(i+1).padStart(2,'0')"></span><div><strong x-text="p.nombre"></strong><small x-text="p.cantidad+' '+(Number(p.cantidad)===1?t('unidad'):t('unidades'))"></small><div class="rank-track"><span :style="'width:'+Math.max(4,Number(p.cantidad)/Number(dashboard.top[0].cantidad)*100)+'%'"></span></div></div><b x-text="money(p.ventas)"></b></div></template></article>

    <article class="card recent-orders"><div class="card-heading"><div><h2 x-text="t('recientes_titulo')">Últimos pedidos</h2><p class="muted" x-text="t('recientes_sub')">Lo más reciente de tu restaurante</p></div><button class="text-button" @click="ir('pedidos')"><span x-text="t('ver_pedidos')">Ver pedidos</span> <svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#arrow"></use></svg></button></div><div class="table-scroll"><table><thead><tr><th x-text="t('th_pedido')">Pedido</th><th x-text="t('th_cliente')">Cliente</th><th x-text="t('th_atencion')">Atención</th><th x-text="t('th_estado')">Estado</th><th x-text="t('th_total')">Total</th></tr></thead><tbody><template x-for="p in dashboard.recientes" :key="p.id"><tr><td><b x-text="'#'+p.id"></b></td><td x-text="p.cliente_nombre"></td><td x-text="nombreTipo(p.tipo)"></td><td><span class="pill" :data-status="p.estado" x-text="nombreEstado(p.estado)"></span><small x-show="p.cobrado_at" class="paid-label" x-text="t('pagado')">Pagado</small></td><td><strong x-text="money(p.total)"></strong></td></tr></template></tbody></table><p class="empty-copy" x-show="!dashboard.recientes.length" x-text="t('recientes_vacio')">Todo listo para tu primer pedido.</p></div></article>

    <article class="service-card"><span class="stat-icon"><img class="brand-logo" src="/images/adbana-logo.svg" alt="" width="30" height="40"></span><h2><span x-text="t('cta_h1')">El próximo gran servicio</span><br><span x-text="t('cta_h2')">empieza aquí.</span></h2><p><span x-text="t('cta_p1')">Tu carta, tu equipo y tus pedidos.</span><br><span x-text="t('cta_p2')">Un solo espacio para trabajar mejor.</span></p><button @click="ir('venta')"><span x-text="t('cta_btn')">Comenzar una venta</span> <svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#arrow"></use></svg></button><div class="service-orbit" aria-hidden="true"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#food"></use></svg></div></article></div>

</div></template></section>

<section x-show="tab==='perfil'" class="profile-layout"><article class="card profile-summary"><span class="avatar large" x-text="iniciales"></span><h2 x-text="user.name"></h2><span class="pill" x-text="rolNombre"></span><p class="muted" x-text="user.restaurante?.nombre"></p><div class="sep"></div><p><span x-text="t('perfil_desc1')">Un perfil para tu trabajo diario.</span><br><span x-text="t('perfil_desc2')">Tu rol lo administra el restaurante.</span></p></article><form class="card" @submit.prevent="guardarPerfil()"><h2 x-text="t('tu_info')">Tu información</h2><p class="muted" x-text="t('manten_datos')">Mantén tus datos al día.</p><label><span x-text="t('lbl_nombre')">Nombre</span><input x-model="perfil.name" maxlength="120" autocomplete="name" required></label><label><span x-text="t('lbl_correo')">Correo</span><input type="email" x-model="perfil.email" autocomplete="email" required></label><div class="sep"></div><h3 x-text="t('seguridad_cuenta')">Seguridad de tu cuenta</h3><p class="muted" x-text="t('seguridad_desc')">Para cambiar el correo o la contraseña, confirma tu contraseña actual.</p><label><span x-text="t('lbl_pass_actual')">Contraseña actual</span><input type="password" x-model="perfil.current_password" autocomplete="current-password"></label><div class="form-grid"><label><span x-text="t('lbl_pass_nueva')">Nueva contraseña</span><input type="password" x-model="perfil.password" minlength="8" autocomplete="new-password"></label><label><span x-text="t('lbl_pass_conf')">Confirmar nueva contraseña</span><input type="password" x-model="perfil.password_confirmation" minlength="8" autocomplete="new-password"></label></div><button class="primary" :disabled="cargando || (busy)" x-text="t('btn_guardar_cambios')">Guardar cambios</button></form></section>

<section x-show="tab==='venta'" class="cols pos-layout"><div class="card catalog-card"><h2 x-text="t('pos_titulo')">Punto de venta</h2><form class="row" @submit.prevent="pagina=1;accion(()=>actualizar())"><input :aria-label="t('pos_buscar_aria')" x-model="q" :placeholder="t('pos_buscar_ph')"><button x-text="t('btn_buscar')">Buscar</button></form>

<div class="empty-state" x-show="!productos.length"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#food"></use></svg><h3 x-text="t('pos_vacio_h')">Tu carta empieza aquí</h3><p x-text="t('pos_vacio_p')">No hay productos para mostrar con esta búsqueda.</p><button class="primary" x-show="user.permisos.productos" @click="ir('productos')" x-text="t('btn_agregar_prod')">Agregar productos</button></div><div class="grid product-grid"><template x-for="p in productos" :key="p.id"><button class="product" :disabled="cargando || (busy || !p.activo || (p.stock_disponible ?? p.stock)<1)" @click="agregar(p)"><span class="product-visual"><template x-if="p.imagen"><img :src="p.imagen" alt="" loading="lazy"></template><span x-show="!p.imagen"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#food"></use></svg></span><span class="product-add">+</span></span><strong x-text="p.nombre"></strong><span class="product-price" x-text="money(p.precio)"></span><small x-text="p.activo ? t('stock_lbl')+(p.stock_disponible ?? p.stock) : t('inactivo')"></small></button></template></div>

<div class="row"><button :disabled="cargando || (pagina<=1 || busy)" @click="pagina--;accion(()=>actualizar())" x-text="t('btn_anterior')">Anterior</button><small x-text="pagina+' / '+ultima"></small><button :disabled="cargando || (pagina>=ultima || busy)" @click="pagina++;accion(()=>actualizar())" x-text="t('btn_siguiente')">Siguiente</button></div></div>

<div class="card cart-card"><div class="card-heading"><h2 x-text="t('pedido_actual')">Pedido actual</h2><span class="count-chip" x-text="lineas.length+' '+t('productos_count')"></span></div><p x-show="!lineas.length" class="muted" x-text="t('pos_sel_prod')">Selecciona productos para comenzar.</p><template x-for="p in lineas" :key="p.id"><div class="row"><span x-text="p.nombre"></span><div class="actions"><button :disabled="cargando || (busy)" @click="quitar(p)" :aria-label="t('quitar_aria')+p.nombre">−</button><span x-text="p.cantidad"></span><button :disabled="cargando || (busy)" @click="agregar(p)" :aria-label="t('agregar_aria')+p.nombre">+</button></div><strong x-text="money(p.precio*p.cantidad)"></strong></div></template>

<div class="sep"></div><div class="row"><strong x-text="t('th_total')">Total</strong><span class="tot" x-text="money(total)"></span></div>

<label><span x-text="t('th_cliente')">Cliente</span><input x-model="cliente" maxlength="120" :disabled="cargando || (busy)"></label><label><span x-text="t('th_atencion')">Atención</span><select x-model="tipo" :disabled="cargando || (busy)"><option x-show="modalidades.includes('recojo')" value="recojo" x-text="t('tipo_recojo')">Mostrador / para llevar</option><option x-show="modalidades.includes('mesa')" value="mesa" x-text="t('tipo_mesa')">En mesa</option><option x-show="modalidades.includes('delivery')" value="delivery" x-text="t('tipo_delivery')">Delivery</option></select></label>

<label x-show="tipo==='mesa'"><span x-text="t('lbl_mesa')">Mesa</span><input x-model="mesa" maxlength="20" :disabled="cargando || (busy)"></label><label x-show="tipo==='delivery'"><span x-text="t('lbl_direccion')">Dirección</span><input x-model="direccion" maxlength="255" :disabled="cargando || (busy)"></label><label x-show="tipo==='delivery'"><span x-text="t('lbl_telefono')">Teléfono</span><input x-model="telefono" maxlength="30" :disabled="cargando || (busy)"></label><label><span x-text="t('lbl_notas')">Notas</span><textarea x-model="notas" maxlength="500" :disabled="cargando || (busy)"></textarea></label>

<button x-show="user.permisos.caja" class="primary wide" :disabled="cargando || (busy || !lineas.length || !caja)" @click="guardarVenta(true)" x-text="t('btn_reg_cobrar')">Registrar y cobrar</button><button class="wide" :disabled="cargando || (busy || !lineas.length)" @click="guardarVenta(false)" x-text="t('btn_enviar_cocina')">Enviar pedido a cocina</button><p class="muted" x-show="user.permisos.caja && !caja" x-text="t('aviso_abrir_caja')">Abre tu turno en «Mi caja» para cobrar.</p></div></section>



<section x-show="['pedidos','cocina'].includes(tab)"><div class="row" x-show="!soloCocina"><label>Vista<select x-model="verHistorialPedidos" @change="paginaPedidos=1;accion(()=>actualizar())"><option :value="false">Pedidos abiertos</option><option :value="true">Historial completo</option></select></label></div><h1 x-text="!soloCocina && verHistorialPedidos?t('historial_pedidos'):t('nav_pedidos')"></h1><p class="muted" x-text="t('pedidos_sub')">Se actualizan cada 15 segundos. Cobro y entrega se registran por separado.</p><div class="empty-state" x-show="!error && !cargando && !busy && !pedidosVisibles.length"><svg class="icon " aria-hidden="true"><use href="/images/panel-icons.svg#check"></use></svg><h3 x-text="t('todo_al_dia')">Todo al día</h3><p x-text="t('no_pedidos_vista')">No hay pedidos que coincidan con esta vista.</p></div><div class="grid orders-grid"><template x-for="p in pedidosVisibles" :key="p.id"><article class="card order"><div class="row"><h2 x-text="'#'+p.id"></h2><span class="pill" :data-status="p.estado" x-text="nombreEstado(p.estado)"></span></div><strong x-text="p.cliente_nombre"></strong><p x-text="nombreTipo(p.tipo) + (p.mesa?' · '+t('lbl_mesa')+' '+p.mesa:'')"></p><small x-text="new Date(p.created_at).toLocaleString(locale)"></small><template x-for="d in p.detalles" :key="d.id"><p x-text="d.cantidad+' × '+d.nombre_producto"></p></template><p x-show="p.notas" x-text="p.notas"></p><p x-show="p.direccion" x-text="p.direccion"></p><strong x-show="user.permisos.caja" x-text="'Total: '+money(p.total)+' · Pendiente: '+money(p.saldo_pendiente ?? p.total)"></strong><p class="pill" x-show="p.cobrado_at" x-text="t('pagado_indep')">Pagado · entrega independiente</p><button class="wide" x-show="user.permisos.cocina && requiereCocina(p)" @click="verComanda(p)" :disabled="busy">Ver comanda para imprimir</button><button class="primary wide" x-show="siguiente(p)" :disabled="cargando || (busy)" @click="estado(p,siguiente(p))" x-text="etiquetaSiguiente(p)"></button><button class="wide" x-show="user.permisos.caja && p.estado!=='cancelado' && !p.cobrado_at" :disabled="cargando || (busy || !caja)" @click="prepararCobro(p)" x-text="t('btn_cobrar_pedido')">Cobrar pedido</button><button class="wide" x-show="user.permisos.cancelar && p.estado!=='cancelado' && !p.cobrado_at && !Number(p.pagado)" :disabled="cargando || (busy)" @click="estado(p,'cancelado')" x-text="t('btn_cancelar_pedido')">Cancelar pedido</button></article></template></div></section>



<section x-show="tab==='mesas'">
    <header class="row">
        <h2>Servicio de mesas</h2>
        <button x-show="user?.rol==='dueno'" @click="ir('configuracion')">Administrar mesas y QR</button>
    </header>
    <template x-for="[estado, titulo, color] in [['listo','Listas para llevar','#7c3aed'],['pendiente','Pendientes de pago','#dc2626'],['preparando','En preparación','#d97706'],['ocupada','Ocupadas','#64748b'],['disponible','Disponibles','#16a34a']]" :key="estado">
        <div x-show="mesasResumen.some(m=>m.estado===estado)">
            <h3 x-text="titulo"></h3>
            <div class="grid">
                <template x-for="m in mesasResumen.filter(m=>m.estado===estado).sort((a,b)=>(a.primero_listo ?? 0)-(b.primero_listo ?? 0))" :key="m.mesa">
                    <article class="card" :style="{borderTop:'4px solid '+color}">
                        <h2 x-text="m.mesa"></h2>
                        <p x-show="Number(m.pendiente)>0" style="color:#dc2626" x-text="'Saldo pendiente: '+money(m.pendiente)"></p>
                        <button type="button" class="primary" x-show="m.estado==='pendiente' && m.id && Number(m.pendiente)>0 && user?.permisos.caja && caja" @click="verCuenta(m.id, 1, true)" :disabled="busy||cargando">Registrar pago</button>
                        <button x-show="m.id" @click="verCuenta(m.id)" :disabled="busy||cargando">Ver cuenta</button>
                        <button x-show="!m.id" @click="ocuparMesa(m)" :disabled="busy||cargando">Marcar ocupada</button>
                    </article>
                </template>
            </div>
        </div>
    </template>
</section>



<section x-show="tab==='caja'" class="cols"><div class="card"><h1 x-text="t('caja_titulo')">Mi turno de caja</h1><p class="muted" x-text="t('caja_sub')">Los cobros quedan a tu nombre. El efectivo permanece bajo tu responsabilidad hasta rendirlo; cerrar el turno no transfiere dinero a otro cajero.</p><form x-show="!caja && !cargando" @submit.prevent="abrirCaja()"><label><span x-text="t('efectivo_inicial')">Efectivo inicial (S/)</span><input type="number" min="0" step=".01" x-model="inicial" required></label><button class="primary" :disabled="cargando || (busy)" x-text="t('btn_abrir_caja')">Abrir caja</button></form>

<template x-if="caja"><div><p x-text="t('turno_num')+caja.id+' · '+t('abierto_lbl')+' '+new Date(caja.created_at).toLocaleString(locale)"></p><div class="row"><span x-text="t('fondo_inicial')">Fondo inicial</span><strong x-text="money(caja.saldo_inicial)"></strong></div><template x-for="(valor,medio) in resumen?.ventas_por_medio || {}" :key="medio"><div class="row"><span x-text="t('ventas_medio')+medio"></span><strong x-text="money(valor)"></strong></div></template><div class="row"><span x-text="t('entradas')">Entradas</span><span x-text="money(resumen?.entradas)"></span></div><div class="row"><span x-text="t('salidas')">Salidas</span><span x-text="money(resumen?.salidas)"></span></div><div class="row"><strong x-text="t('efectivo_esperado')">Efectivo esperado</strong><strong x-text="money(resumen?.efectivo_esperado)"></strong></div><form @submit.prevent="cerrarCaja()"><label><span x-text="t('efectivo_contado')">Efectivo contado (S/)</span><input type="number" min="0" step=".01" x-model="contado" required></label><button class="primary" :disabled="cargando || (busy)" x-text="t('btn_cerrar_caja')">Cerrar y registrar diferencia</button></form></div></template>

<template x-if="cierre"><div class="alert success"><h2 x-text="t('turno_cerrado')">Turno cerrado</h2><p x-text="t('esperado_lbl')+money(cierre.efectivo_esperado)"></p><p x-text="t('contado_lbl')+money(cierre.efectivo_contado)"></p><strong x-text="t('diferencia_lbl')+money(cierre.diferencia)"></strong></div></template></div>

<form class="card" x-show="caja" @submit.prevent="moverCaja()"><h2 x-text="t('mov_efectivo')">Movimiento de efectivo</h2><label><span x-text="t('lbl_tipo')">Tipo</span><select x-model="movimiento.tipo"><option value="entrada" x-text="t('opt_entrada')">Entrada</option><option value="salida" x-text="t('opt_salida')">Salida</option></select></label><label><span x-text="t('lbl_monto')">Monto</span><input type="number" min=".01" step=".01" x-model="movimiento.monto" required></label><label><span x-text="t('lbl_motivo')">Motivo</span><input x-model="movimiento.motivo" maxlength="255" required></label><button class="primary" :disabled="cargando || (busy)" x-text="t('btn_reg_mov')">Registrar movimiento</button><p class="muted" x-text="t('mov_nota')">Los movimientos de efectivo y los gastos contables se registran por separado.</p></form></section>



<div>

<section x-show="tab==='productos' && (user.permisos.productos || user.permisos.stock)" class="cols"><div class="card"><h1 x-text="t('prod_titulo')">Productos</h1><form x-show="user.permisos.productos" @submit.prevent="guardarProducto()"><label><span x-text="t('lbl_nombre')">Nombre</span><input x-model="producto.nombre" maxlength="150" required></label><label>Descripción<textarea x-model="producto.descripcion" maxlength="1000"></textarea></label><label>Foto del producto (enlace HTTPS o ruta local)<input x-model="producto.imagen" maxlength="255" placeholder="https://…/plato.webp"></label><label>Modelo 3D (archivo .glb)<input x-model="producto.modelo_3d" maxlength="255" placeholder="https://…/plato.glb"></label><p class="muted">Usa imágenes y modelos de tu plato con autorización. Se descargan cuando el cliente abre la carta o el visor.</p><div class="row"><label><span x-text="t('lbl_precio_venta')">Precio de venta</span><input type="number" min="0" step=".01" x-model="producto.precio" required></label><label><span x-text="t('lbl_costo')">Costo</span><input type="number" min="0" step=".01" x-model="producto.costo" required></label></div><label><span x-text="t('lbl_categoria')">Categoría</span><select x-model="producto.categoria_id"><option value="" x-text="t('sin_categoria')">Sin categoría</option><template x-for="c in categorias" :key="c.id"><option :value="c.id" x-text="c.nombre"></option></template></select></label><p class="muted">Puedes vincular presentaciones con una receta: una caja puede consumir 12 botellas del mismo inventario. Usa cantidades enteras de la unidad base elegida. Con receta, el costo de venta se calcula a partir de sus insumos.</p><label x-show="!producto.id"><span x-text="t('lbl_stock_inicial')">Stock inicial</span><input type="number" min="0" step="1" x-model="producto.stock"></label><label><span x-text="t('lbl_stock_min')">Stock mínimo</span><input type="number" min="0" step="1" x-model="producto.stock_minimo"></label><label>Unidad base<input x-model="producto.unidad" maxlength="20" placeholder="unidad, porción, botella"></label><label><input type="checkbox" x-model="producto.requiere_cocina" style="width:auto">Requiere preparación en cocina</label><label><input type="checkbox" x-model="producto.es_insumo" style="width:auto">Insumo: no se ofrece directamente al cliente</label><label><input type="checkbox" x-model="producto.activo" style="width:auto"> <span x-text="t('lbl_disp_venta')">Disponible para venta</span></label><button class="primary" :disabled="cargando || (busy)" x-text="t('btn_guardar_prod')">Guardar producto</button></form><div class="sep"></div><template x-for="p in productos" :key="p.id"><div class="row"><span x-text="p.nombre+' · '+money(p.precio)+' · '+t('stock_min_inline')+(p.stock_disponible ?? p.stock)"></span><button x-show="user.permisos.productos" @click="editar(p)" x-text="t('btn_editar')">Editar</button><button x-show="user.permisos.productos && !p.es_insumo" @click="editarReceta(p)">Receta / presentación</button></div></template><div class="actions"><button :disabled="cargando || (pagina<=1 || busy)" @click="pagina--;accion(()=>actualizar())" x-text="t('btn_anterior')">Anterior</button><button :disabled="cargando || (pagina>=ultima || busy)" @click="pagina++;accion(()=>actualizar())" x-text="t('btn_siguiente')">Siguiente</button></div></div>

<div class="list"><form class="card" x-show="user.permisos.productos" @submit.prevent="guardarCategoria()"><h2 x-text="t('nueva_cat')">Nueva categoría</h2><label><span x-text="t('lbl_nombre')">Nombre</span><input x-model="categoriaNombre" required maxlength="100"></label><button :disabled="cargando || (busy)" x-text="t('btn_crear_cat')">Crear categoría</button></form><form class="card" x-show="user.permisos.stock" @submit.prevent="moverStock()"><h2 x-text="t('ajustar_inv')">Ajustar inventario</h2><label><span x-text="t('lbl_producto')">Producto</span><select x-model="stockForm.producto_id" required><option value="" x-text="t('opt_selecciona')">Selecciona</option><template x-for="p in productos" :key="p.id"><option :value="p.id" x-text="p.nombre"></option></template></select></label><label><span x-text="t('lbl_movimiento')">Movimiento</span><select x-model="stockForm.tipo"><option value="entrada" x-text="t('opt_entrada_merc')">Entrada de mercadería</option><option value="salida" x-text="t('opt_salida_merma')">Salida / merma</option><option value="ajuste" x-text="t('opt_conteo_fisico')">Conteo físico</option></select></label><label x-show="stockForm.tipo!=='ajuste'"><span x-text="t('lbl_cantidad')">Cantidad</span><input type="number" min="1" step="1" x-model="stockForm.cantidad"></label><label x-show="stockForm.tipo==='salida'"><input type="checkbox" x-model="stockForm.es_merma" style="width:auto">Es una merma: incluir su costo en el reporte</label><label x-show="stockForm.tipo==='ajuste'"><span x-text="t('lbl_stock_contado')">Stock contado</span><input type="number" min="0" step="1" x-model="stockForm.stock_real"></label><label><span x-text="t('lbl_motivo')">Motivo</span><input x-model="stockForm.motivo" required maxlength="255"></label><button class="primary" :disabled="cargando || (busy)" x-text="t('btn_registrar')">Registrar</button></form></div></section>

<section x-show="tab==='ventas'" class="cols"><div class="card" x-show="user.permisos.reportes"><h1 x-text="t('ventas_dia')">Ventas del día</h1><form class="row" @submit.prevent="verReporte()"><input type="date" :aria-label="t('fecha_reporte')" x-model="fecha" required><button :disabled="cargando || (busy)" x-text="t('btn_consultar')">Consultar</button></form><template x-if="reporte"><div><template x-for="k in ['ventas','cobros_brutos','devoluciones','costo_vendido','costo_mermas','ganancia_bruta','gastos','ganancia_neta']" :key="k"><div class="row"><span x-text="etiquetaReporte(k)"></span><strong x-text="money(reporte[k])"></strong></div></template><p x-text="reporte.pedidos_cobrados+' '+t('pedidos_cobrados')"></p><h2 x-text="t('mas_vendidos')">Más vendidos</h2><template x-for="(p,n) in reporte.top_productos" :key="n"><p x-text="p.producto+' · '+p.cantidad+' '+t('unidades')"></p></template></div></template></div><form class="card" x-show="user.permisos.gastos" @submit.prevent="guardarGasto()"><h2 x-text="t('reg_gasto')">Registrar gasto</h2><label><span x-text="t('lbl_descripcion')">Descripción</span><input x-model="gasto.descripcion" required maxlength="255"></label><label><span x-text="t('lbl_monto')">Monto</span><input type="number" min=".01" step=".01" x-model="gasto.monto" required></label><label><span x-text="t('lbl_categoria')">Categoría</span><input x-model="gasto.categoria" maxlength="50"></label><button class="primary" :disabled="cargando || (busy)" x-text="t('btn_guardar_gasto')">Guardar gasto</button></form></section>

<section x-show="tab==='equipo' && user.permisos.equipo" class="cols team-section">

<div class="card"><h1 x-text="t('equipo_titulo')">Equipo y permisos</h1><p class="muted" x-text="t('equipo_sub')">Cada rol incluye funciones iniciales. Puedes adaptar los permisos de cada persona sin cambiar su puesto.</p>

<template x-for="u in usuarios" :key="u.id"><article class="team-member"><div><strong x-text="u.name"></strong><small x-text="nombreRol(u.rol)+' · '+(u.activo?t('estado_activo'):t('estado_desactivado'))"></small><small x-show="Object.keys(u.permisos_personalizados||{}).length" x-text="t('permisos_pers')">Permisos personalizados</small></div><div class="actions"><button x-show="u.editable" :disabled="busy || cargando" @click="editarPermisos(u)" x-text="t('btn_permisos')">Permisos</button><button x-show="u.editable" :disabled="busy || cargando" @click="activarUsuario(u)" x-text="u.activo?t('btn_desactivar'):t('btn_activar')"></button><span class="muted" x-show="!u.editable" x-text="u.id===user.id?t('tu_cuenta'):u.rol==='dueno'?t('acceso_dueno'):t('gestionado_dueno')"></span></div></article></template></div>

<form class="card" x-show="!editandoUsuario" @submit.prevent="crearUsuario()"><h2 x-text="t('nuevo_integrante')">Nuevo integrante</h2><label><span x-text="t('lbl_rol_inicial')">Rol inicial</span><select x-model="nuevoUsuario.rol"><template x-for="rol in rolesDisponibles" :key="rol"><option :value="rol" x-text="nombreRol(rol)"></option></template></select></label><p class="muted" x-text="t('nuevo_int_nota')">Después de crearlo, usa «Permisos» para añadir o quitar funciones. Por ejemplo, permitir que un mesero cobre.</p><label><span x-text="t('lbl_nombre')">Nombre</span><input x-model="nuevoUsuario.name" required maxlength="120"></label><label><span x-text="t('lbl_correo')">Correo</span><input type="email" x-model="nuevoUsuario.email" required></label><label><span x-text="t('lbl_password')">Contraseña</span><input type="password" x-model="nuevoUsuario.password" minlength="8" required autocomplete="new-password"></label><button class="primary" :disabled="busy || cargando || !rolesDisponibles.length" x-text="t('btn_crear_int')">Crear integrante</button></form>

<form class="card permission-editor" x-show="editandoUsuario" @submit.prevent="guardarPermisos()"><h2 x-text="t('permisos_de')+(editandoUsuario?.name||'')"></h2><p class="muted" x-text="t('rol_base')+nombreRol(editandoUsuario?.rol)"></p><p x-text="t('permisos_inst')">Activa las funciones que esta persona necesita. Desmarca las que no debe utilizar.</p>

<template x-for="grupo in ['Operación','Administración']" :key="grupo"><fieldset><legend x-text="nombreGrupoPermiso(grupo)"></legend><template x-for="[clave,opcion] in Object.entries(catalogoPermisos?.permisos||{}).filter(([,p])=>p.grupo===grupo)" :key="clave"><label class="permission-option"><input type="checkbox" x-model="permisosEditados[clave]" :disabled="busy || cargando || !catalogoPermisos.delegables.includes(clave)"><span><strong x-text="nombrePermiso(clave, opcion.nombre)"></strong><small x-text="descPermiso(clave, opcion.descripcion)"></small><small class="permission-default" x-text="!catalogoPermisos.delegables.includes(clave)?t('solo_autorizado'):catalogoPermisos.roles[editandoUsuario?.rol]?.[clave]?t('incluido_rol'):t('adicional_rol')"></small></span></label></template></fieldset></template>

<p class="muted" x-text="t('permisos_nota')">No puedes cambiar tus propios permisos. Un turno de caja abierto debe cerrarse antes de retirar el acceso o desactivar la cuenta.</p><div class="actions"><button class="primary" :disabled="busy || cargando" x-text="t('btn_guardar_perm')">Guardar permisos</button><button type="button" :disabled="busy || cargando" @click="editandoUsuario=null" x-text="t('btn_volver')">Volver</button><button type="button" :disabled="busy || cargando" @click="restaurarPermisos()" x-text="t('btn_restablecer_rol')">Restablecer rol base</button></div></form>

</section>

</div>

<nav class="row" x-show="['pedidos','cocina','mesas'].includes(tab) && ultimaPedidos>1" aria-label="Paginación"><button @click="paginaOperativa(paginaPedidos-1)" :disabled="busy||cargando||paginaPedidos<=1" x-text="t('anterior_operacion')"></button><span x-text="paginaPedidos+' / '+ultimaPedidos+' · '+totalPedidos"></span><button @click="paginaOperativa(paginaPedidos+1)" :disabled="busy||cargando||paginaPedidos>=ultimaPedidos" x-text="t('siguiente_operacion')"></button></nav>

<section class="card" x-show="tab==='perfil'"><h2 x-text="t('sesiones_titulo')"></h2><div class="row"><button @click="cargarSesiones()" :disabled="busy" x-text="t('consultar_sesiones')"></button><button @click="cerrarOtrasSesiones()" :disabled="busy" x-text="t('cerrar_otras')"></button></div><template x-for="s in sesiones" :key="s.id"><div class="row"><span x-text="s.name+' · '+s.created_at"></span><span x-show="s.actual" x-text="t('sesion_actual')"></span><button x-show="!s.actual" @click="cerrarSesion(s.id)" :disabled="busy" x-text="t('cerrar_sesion')"></button></div></template></section>

<section class="card" x-show="user && tienePosPendiente"><p x-text="t('operacion_pendiente')"></p><button @click="guardarVenta(false)" :disabled="busy||cargando" x-text="t('reintentar') || 'Reintentar'"></button></section>

<form class="row" x-show="['pedidos','cocina'].includes(tab)" @submit.prevent="paginaPedidos=1;accion(()=>cargarPedidos())"><input x-model="filtroPedidos" maxlength="120" :aria-label="t('btn_buscar')"><button :disabled="busy||cargando" x-text="t('btn_buscar')"></button></form>

@include('layouts.herramientas-operacion')

</main></div></div></template>

<div class="modal" x-show="cobro" role="dialog" aria-modal="true" aria-label="Registrar pago"><form class="card dialog" @submit.prevent="pagar()"><h2 x-text="cobro?.esCuenta?'Cobrar cuenta de mesa':'Cobrar pedido #'+cobro?.id"></h2><p x-text="'Saldo pendiente: '+money(cobro?.saldo_pendiente ?? cobro?.total)"></p><template x-if="cobro?.esCuenta"><label><input type="checkbox" x-model="cobro.mantenerOcupada" :disabled="busy"> Los clientes se quedan en la mesa</label></template><label>Medio<select x-model="medio" :disabled="busy"><option value="efectivo">Efectivo</option><option value="yape">Yape</option><option value="plin">Plin</option><option value="tarjeta">Tarjeta</option></select></label><label>Importe de este pago<input type="number" min="0" step=".01" x-model="montoCobro" :disabled="busy"></label><label x-show="medio==='efectivo'">Efectivo recibido<input type="number" min="0" step=".01" x-model="recibido" :disabled="busy"></label><p x-show="medio==='efectivo'" x-text="'Vuelto: '+money(Math.max(0,Number(recibido)-Number(montoCobro)))"></p><button type="button" @click="agregarPago()" :disabled="busy">Añadir medio de pago</button><template x-for="(p,i) in pagosCobro" :key="i"><div class="row"><span x-text="p.medio_pago+' · '+money(p.monto)"></span><button type="button" @click="pagosCobro.splice(i,1)" :disabled="busy">Quitar</button></div></template><p class="muted">Puedes registrar un abono o combinar medios. Confirma los pagos digitales en la cuenta del negocio; no hay verificación automática.</p><p class="alert" x-show="error" x-text="error"></p><button class="primary wide" :disabled="busy||!caja" :aria-busy="busy"><span class="payment-spinner" x-show="busy" aria-hidden="true"></span><span x-text="busy?'Registrando pago…':'Confirmar pago · '+money(pagosCobro.length?pagosCobro.reduce((n,p)=>n+Number(p.monto),0):montoCobro)">Confirmar pago</span></button><button type="button" class="wide" :disabled="busy" @click="cobro=null">Volver</button></form></div>

@include('layouts.operacion')

<div class="modal" x-show="comprobante" role="dialog" aria-modal="true" :aria-label="t('constancia_venta')"><div class="card dialog receipt"><h2 x-text="user?.restaurante?.nombre"></h2><p x-text="comprobante?.esComanda?'Comanda de cocina':comprobante?.esCuenta?'Cuenta de mesa':t('constancia_venta')"></p><p x-text="(comprobante?.esCuenta?'Cuenta #':t('pedido_num'))+comprobante?.id"></p><p x-text="comprobante?.cliente_nombre"></p><template x-for="d in comprobante?.detalles || []" :key="d.id"><div class="row"><span x-text="d.cantidad+' × '+d.nombre_producto"></span><span x-show="!comprobante?.esComanda" x-text="money(d.subtotal)"></span></div></template><div class="sep"></div><p x-show="!comprobante?.esComanda" x-text="t('total_dp')+money(comprobante?.total)"></p><p x-show="!comprobante?.esComanda" x-text="t('pago_dp')+comprobante?.medio_pago"></p><p x-show="!comprobante?.esComanda" x-text="t('recibido_dp')+money(comprobante?.recibido)"></p><p x-show="!comprobante?.esComanda" x-text="t('vuelto_lbl')+money(comprobante?.vuelto)"></p><p x-show="!comprobante?.esComanda" x-text="'Saldo pendiente: '+money(comprobante?.saldo_pendiente ?? comprobante?.pendiente ?? 0)"></p><small x-text="t('no_sunat')">No es comprobante de pago electrónico SUNAT.</small><button class="primary wide" @click="imprimir()" x-text="t('btn_imprimir')">Imprimir</button><button class="wide" @click="comprobante=null" x-text="t('btn_cerrar')">Cerrar</button></div></div>

</div></body></html>
