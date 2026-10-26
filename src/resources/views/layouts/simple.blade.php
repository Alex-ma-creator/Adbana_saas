<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo') | Adbana</title><link rel="icon" type="image/svg+xml" href="/images/adbana-logo.svg">
    <style>
        :root {
            --fondo: #fff8f1;
            --tarjeta: #ffffff;
            --texto: #2b1d14;
            --suave: #6f5a4a;
            --marca: #c2410c;
            --boton-texto: #ffffff;
            --linea: #ecdccf;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --fondo: #1a120d;
                --tarjeta: #261a13;
                --texto: #f6ece4;
                --suave: #b9a595;
                --marca: #ff8a4c;
                --boton-texto: #1a120d;
                --linea: #3a2a20;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 16px;
            background: var(--fondo);
            color: var(--texto);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            position: relative;
        }

        .simple-lang {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 20;
        }

        .simple-lang-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 36px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid var(--linea);
            background: var(--tarjeta);
            color: var(--texto);
            font: inherit;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .04em;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .06);
        }

        .simple-lang-btn svg { width: 15px; height: 15px; color: var(--marca); }
        .simple-lang-btn .chev { width: 12px; height: 12px; color: var(--suave); transition: transform .18s; }
        .simple-lang-btn[aria-expanded="true"] .chev { transform: rotate(180deg); color: var(--marca); }

        .simple-lang-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 8px);
            width: 210px;
            padding: 8px;
            background: var(--tarjeta);
            border: 1px solid var(--linea);
            border-radius: 14px;
            box-shadow: 0 12px 36px rgba(0, 0, 0, .16);
            display: none;
            gap: 4px;
        }

        .simple-lang-menu.open { display: grid; }

        .simple-lang-opt {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 8px 10px;
            border: 1px solid transparent;
            border-radius: 10px;
            background: transparent;
            color: var(--texto);
            font: inherit;
            font-size: 13px;
            font-weight: 600;
            text-align: left;
            cursor: pointer;
        }

        .simple-lang-opt:hover { background: var(--fondo); }
        .simple-lang-opt.active { background: var(--fondo); border-color: var(--marca); }
        .simple-lang-code {
            width: 26px;
            height: 26px;
            border-radius: 7px;
            background: var(--fondo);
            border: 1px solid var(--linea);
            display: grid;
            place-items: center;
            font-size: 11px;
            font-weight: 700;
            color: var(--suave);
        }
        .simple-lang-opt.active .simple-lang-code {
            background: var(--marca);
            border-color: var(--marca);
            color: var(--boton-texto);
        }

        main {
            width: 100%;
            max-width: 440px;
            padding: 32px 24px;
            text-align: center;
            background: var(--tarjeta);
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, .08);
        }

        .marca { margin: 0 0 16px; font-weight: 700; letter-spacing: .12em; color: var(--marca); }
        .codigo { margin: 0; font-size: 56px; font-weight: 800; line-height: 1; color: var(--marca); }
        .icono { margin: 0; font-size: 48px; line-height: 1; }
        h1 { margin: 12px 0 8px; font-size: 22px; }
        p { margin: 0 0 20px; line-height: 1.5; color: var(--suave); }

        .boton {
            display: inline-block;
            padding: 10px 20px;
            border-radius: 10px;
            background: var(--marca);
            color: var(--boton-texto);
            font-weight: 600;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="simple-lang" id="simple-lang">
        <button type="button" class="simple-lang-btn" id="simple-lang-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Cambiar idioma">
            <svg aria-hidden="true"><use href="/images/panel-icons.svg#globe"></use></svg>
            <span id="simple-lang-code">ES</span>
            <svg class="chev" aria-hidden="true"><use href="/images/panel-icons.svg#chevron"></use></svg>
        </button>
        <div class="simple-lang-menu" id="simple-lang-menu" role="listbox">
            <button type="button" class="simple-lang-opt" data-lang="es"><span class="simple-lang-code">ES</span><span>Español</span></button>
            <button type="button" class="simple-lang-opt" data-lang="en"><span class="simple-lang-code">EN</span><span>English</span></button>
            <button type="button" class="simple-lang-opt" data-lang="pt"><span class="simple-lang-code">PT</span><span>Português</span></button>
            <button type="button" class="simple-lang-opt" data-lang="fr"><span class="simple-lang-code">FR</span><span>Français</span></button>
        </div>
    </div>
    <main>
        <p class="marca"><img src="/images/adbana-logo.svg" alt="" width="25" height="33" style="vertical-align:middle;margin-right:8px">ADBANA</p>
        @yield('contenido')
    </main>
    <script>
        (function(){
            const dict = {
                es: {
                    construccion_t: 'En construcción | Adbana',
                    construccion_h1: 'Estamos construyendo esta sección',
                    construccion_p: 'El panel de Adbana estará disponible muy pronto.',
                    e404_t: 'Página no encontrada | Adbana',
                    e404_h1: 'No encontramos esta página',
                    e404_p: 'Puede que el enlace esté mal escrito o que la página ya no exista.',
                    e404_btn: 'Volver al inicio',
                },
                en: {
                    construccion_t: 'Under construction | Adbana',
                    construccion_h1: 'We are building this section',
                    construccion_p: 'The Adbana dashboard will be available very soon.',
                    e404_t: 'Page not found | Adbana',
                    e404_h1: 'We could not find this page',
                    e404_p: 'The link might be misspelled or the page may no longer exist.',
                    e404_btn: 'Back to home',
                },
                pt: {
                    construccion_t: 'Em construção | Adbana',
                    construccion_h1: 'Estamos construindo esta seção',
                    construccion_p: 'O painel da Adbana estará disponível muito em breve.',
                    e404_t: 'Página não encontrada | Adbana',
                    e404_h1: 'Não encontramos esta página',
                    e404_p: 'O link pode estar incorreto ou a página pode não existir mais.',
                    e404_btn: 'Voltar ao início',
                },
                fr: {
                    construccion_t: 'En construction | Adbana',
                    construccion_h1: 'Nous construisons cette section',
                    construccion_p: 'Le tableau de bord Adbana sera disponible très bientôt.',
                    e404_t: 'Page introuvable | Adbana',
                    e404_h1: 'Nous n’avons pas trouvé cette page',
                    e404_p: 'Le lien est peut-être mal orthographié ou la page n’existe plus.',
                    e404_btn: 'Retour à l’accueil',
                },
            };
            const btn = document.getElementById('simple-lang-btn');
            const menu = document.getElementById('simple-lang-menu');
            const codeEl = document.getElementById('simple-lang-code');
            const opts = menu.querySelectorAll('.simple-lang-opt');
            function applyLang(lang) {
                if (!dict[lang]) lang = 'es';
                try { localStorage.setItem('adbana:idioma', lang); } catch (e) {}
                document.documentElement.lang = lang;
                codeEl.textContent = lang.toUpperCase();
                opts.forEach(o => o.classList.toggle('active', o.dataset.lang === lang));
                document.querySelectorAll('[data-i18n]').forEach(el => {
                    const k = el.getAttribute('data-i18n');
                    if (dict[lang][k]) el.textContent = dict[lang][k];
                });
                const titleKey = document.body.getAttribute('data-title-i18n');
                if (titleKey && dict[lang][titleKey]) document.title = dict[lang][titleKey];
            }
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const open = menu.classList.toggle('open');
                btn.setAttribute('aria-expanded', String(open));
            });
            opts.forEach(o => o.addEventListener('click', () => {
                applyLang(o.dataset.lang);
                menu.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
            }));
            document.addEventListener('click', () => {
                menu.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
            });
            let saved = 'es';
            try { saved = localStorage.getItem('adbana:idioma') || 'es'; } catch (e) {}
            applyLang(saved);
        })();
    </script>
</body>
</html>
