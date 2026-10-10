<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="{{ $themeColor }}">
        <title>Sem conexão — {{ $name }}</title>
        <style>
            html, body {
                min-height: 100dvh;
                margin: 0;
                background: {{ $backgroundColor }};
                color: {{ $ink }};
                font-family: Georgia, 'Times New Roman', serif;
            }
            body {
                display: grid;
                place-items: center;
                padding: 1.5rem;
            }
            main {
                max-width: 22rem;
                text-align: center;
            }
            h1 {
                margin: 0 0 0.5rem;
                font-size: 1.6rem;
            }
            p {
                margin: 0 0 1.25rem;
                color: {{ $muted }};
                font-family: 'Segoe UI', Arial, sans-serif;
                line-height: 1.5;
            }
            button {
                border: 0;
                border-radius: 0.5rem;
                padding: 0.75rem 1.2rem;
                background: {{ $themeColor }};
                color: {{ $backgroundColor === '#0f0d0b' ? '#14110d' : '#fff' }};
                font-weight: 700;
                cursor: pointer;
            }
        </style>
    </head>
    <body>
        <main>
            <h1>Sem conexão</h1>
            <p>O {{ $name }} precisa da internet para abrir a agenda e o painel. Conecte e tente de novo.</p>
            <button type="button" onclick="location.reload()">Tentar de novo</button>
        </main>
    </body>
</html>
