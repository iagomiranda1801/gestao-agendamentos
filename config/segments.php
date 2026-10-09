<?php

use App\Enums\CompanyProfile;

return [

    /*
    |--------------------------------------------------------------------------
    | Produtos por segmento
    |--------------------------------------------------------------------------
    |
    | Cada segmento é um produto próprio no seu subdomínio, sobre o mesmo banco
    | e as mesmas telas. O segmento só fica ativo (redirecionamentos e links
    | públicos pelo subdomínio) quando a variável de domínio está no .env.
    | Nome, cores e textos do login podem ser alterados no painel admin.
    |
    */

    'salon' => [
        'enabled' => filled(env('SALON_DOMAIN')),

        'domain' => env('SALON_DOMAIN') ?: 'salao.localhost',

        'scheme' => env('SALON_SCHEME', parse_url((string) env('APP_URL', 'https://localhost'), PHP_URL_SCHEME) ?: 'https'),

        'panel' => 'salao',

        'label' => 'Salão',

        'profiles' => [
            CompanyProfile::Salon->value,
        ],

        'name' => env('SALON_BRAND_NAME', 'Agendaqui Beleza'),

        'tagline' => env('SALON_BRAND_TAGLINE', 'Gestão para salões, estética e bem-estar'),

        'logo' => 'images/salao/logo.svg',

        'favicon' => 'images/salao/favicon.svg',

        'logo_height' => '2.75rem',

        'primary_color' => '#b4426e',

        'login' => [
            'eyebrow' => 'Para salões, estética e bem-estar',

            'headline' => 'Sua agenda cheia,',

            'headline_accent' => 'seu salão em ordem.',

            'subtitle' => 'Horários, profissionais, comissões, pacotes e WhatsApp no mesmo lugar.',

            'image' => null,

            'form_title' => 'Que bom te ver de novo',

            'form_subtitle' => 'Entre para ver a agenda de hoje.',

            'highlights' => [
                ['title' => 'Agenda por profissional', 'description' => 'Cada profissional com seus horários e serviços.'],
                ['title' => 'Agendamento pelo WhatsApp', 'description' => 'A cliente marca sozinha, a qualquer hora.'],
                ['title' => 'Comissões e caixa', 'description' => 'Fechamento do dia sem planilha.'],
            ],

            'signup_text' => 'Ainda não usa?',

            'signup_link' => 'Criar conta do salão — 7 dias grátis',
        ],
    ],

    'tattoo' => [
        'enabled' => filled(env('TATTOO_DOMAIN')),

        'domain' => env('TATTOO_DOMAIN') ?: 'estudio.localhost',

        'scheme' => env('TATTOO_SCHEME', parse_url((string) env('APP_URL', 'https://localhost'), PHP_URL_SCHEME) ?: 'https'),

        'panel' => 'estudio',

        'label' => 'Tatuagem',

        'profiles' => [
            CompanyProfile::TattooStudio->value,
        ],

        'name' => env('TATTOO_BRAND_NAME', 'Agendaqui Estúdio'),

        'tagline' => env('TATTOO_BRAND_TAGLINE', 'Gestão para estúdios de tatuagem'),

        'logo' => 'images/estudio/logo.svg',

        'favicon' => 'images/estudio/favicon.svg',

        'logo_height' => '2.75rem',

        'primary_color' => '#c9a227',

        'login' => [
            'eyebrow' => 'Para estúdios de tatuagem',

            'headline' => 'Do orçamento',

            'headline_accent' => 'à sessão marcada.',

            'subtitle' => 'Pedidos com foto, conversa no WhatsApp, agenda e caixa no mesmo lugar.',

            'image' => null,

            'form_title' => 'Bem-vindo ao estúdio',

            'form_subtitle' => 'Entre para ver os pedidos de hoje.',

            'highlights' => [
                ['title' => 'Orçamento com foto', 'description' => 'O cliente manda a ideia, você responde com valor e prazo.'],
                ['title' => 'WhatsApp do estúdio', 'description' => 'A conversa e o pedido ficam juntos, sem planilha.'],
                ['title' => 'Agenda das sessões', 'description' => 'Marca, confirma e conclui o procedimento no mesmo fluxo.'],
            ],

            'signup_text' => 'Ainda não tem estúdio aqui?',

            'signup_link' => 'Criar conta do estúdio — 7 dias grátis',
        ],
    ],

];
