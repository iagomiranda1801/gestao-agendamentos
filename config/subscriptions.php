<?php

return [
    'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 3),
    'renewal_warning_days' => (int) env('SUBSCRIPTION_RENEWAL_WARNING_DAYS', 7),
    'pix_instructions' => env(
        'SUBSCRIPTION_PIX_INSTRUCTIONS',
        'Pague via PIX para a Agendaqui e envie o comprovante. O acesso é liberado após a confirmação.',
    ),
];
