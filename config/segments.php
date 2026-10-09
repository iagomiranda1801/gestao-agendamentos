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
    |
    */

    'salon' => [
        'enabled' => filled(env('SALON_DOMAIN')),

        'domain' => env('SALON_DOMAIN') ?: 'salao.localhost',

        'scheme' => env('SALON_SCHEME', parse_url((string) env('APP_URL', 'https://localhost'), PHP_URL_SCHEME) ?: 'https'),

        'panel' => 'salao',

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

];
