<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo') | Adbana</title>
    <style>
        :root {
            --fondo: #fff8f1;
            --tarjeta: #ffffff;
            --texto: #2b1d14;
            --suave: #6f5a4a;
            --marca: #c2410c;
            --boton-texto: #ffffff;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --fondo: #1a120d;
                --tarjeta: #261a13;
                --texto: #f6ece4;
                --suave: #b9a595;
                --marca: #ff8a4c;
                --boton-texto: #1a120d;
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
    <main>
        <p class="marca">ADBANA</p>
        @yield('contenido')
    </main>
</body>
</html>
