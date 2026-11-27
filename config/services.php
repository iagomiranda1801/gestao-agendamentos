<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'evolution' => [
        'url' => env('EVOLUTION_API_URL'),
        'key' => env('EVOLUTION_API_KEY'),
        'instance' => env('EVOLUTION_INSTANCE'),
        'webhook_token' => env('EVOLUTION_WEBHOOK_TOKEN'),
        'webhook_base_url' => env('EVOLUTION_WEBHOOK_BASE_URL', env('APP_URL')),
        // Minutos que o bot fica em silencio numa conversa depois que a empresa responde manualmente.
        'human_takeover_minutes' => (int) env('WHATSAPP_HUMAN_TAKEOVER_MINUTES', 120),
        // Mensagens da empresa que saem logo depois da mensagem do cliente (saudacao/ausencia automatica do WhatsApp Business) nao pausam o bot.
        'auto_reply_grace_seconds' => (int) env('WHATSAPP_AUTO_REPLY_GRACE_SECONDS', 20),
        // Respostas da IA (tatuagem/estetica) chegam entre min e max segundos depois da mensagem do cliente,
        // com "digitando..." nos ultimos typing_max segundos. Min e max em 0 desligam o atraso.
        'ai_reply_delay' => [
            'min_seconds' => (int) env('WHATSAPP_AI_REPLY_DELAY_MIN_SECONDS', 5),
            'max_seconds' => (int) env('WHATSAPP_AI_REPLY_DELAY_MAX_SECONDS', 10),
            'typing_max_seconds' => (int) env('WHATSAPP_AI_TYPING_MAX_SECONDS', 4),
        ],
        'outbound' => [
            'min_interval_seconds' => (int) env('EVOLUTION_OUTBOUND_MIN_INTERVAL', 30),
            'max_interval_seconds' => (int) env('EVOLUTION_OUTBOUND_MAX_INTERVAL', 45),
            'jitter_seconds' => (int) env('EVOLUTION_OUTBOUND_JITTER', 10),
            'daily_limit' => (int) env('EVOLUTION_OUTBOUND_DAILY_LIMIT', 80),
            'circuit_failures' => (int) env('EVOLUTION_OUTBOUND_CIRCUIT_FAILURES', 5),
            'circuit_pause_minutes' => (int) env('EVOLUTION_OUTBOUND_CIRCUIT_PAUSE', 120),
        ],
    ],

    // Botao de WhatsApp da landing Bellana (/bellana). Vazio esconde o botao. Ex.: https://wa.me/5585999999999
    'bellana' => [
        'whatsapp_url' => env('BELLANA_WHATSAPP_URL'),
    ],

    'ai' => [
        'timeout' => (int) env('AI_TIMEOUT', 30),
    ],

    // Transcrição de áudios do WhatsApp (nota de voz) por um modelo Gemini via OpenRouter.
    // A chave é a própria da empresa quando ela usa o provedor OpenRouter; senão vale esta.
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'audio_model' => env('OPENROUTER_AUDIO_MODEL', 'google/gemini-2.5-flash'),
        // Caminho do ffmpeg (opcional): se o provedor recusar ogg, converte para mp3 e tenta de novo.
        'ffmpeg_path' => env('OPENROUTER_FFMPEG_PATH'),
    ],

    'whatsapp_audio' => [
        'enabled' => (bool) env('WHATSAPP_AUDIO_ENABLED', true),
        'max_seconds' => (int) env('WHATSAPP_AUDIO_MAX_SECONDS', 180),
        'max_bytes' => (int) env('WHATSAPP_AUDIO_MAX_BYTES', 10 * 1024 * 1024),
    ],

    'tabela_peliculas' => [
        'url' => env('TABELA_PELICULAS_API_URL', 'https://tabelapeliculas.com.br/index'),
    ],

];
