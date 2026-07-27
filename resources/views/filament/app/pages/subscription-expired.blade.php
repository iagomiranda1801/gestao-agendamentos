<x-filament-panels::page>
    <div class="mx-auto max-w-2xl space-y-4 text-sm text-gray-600 dark:text-gray-300">
        <p>
            O acesso à empresa <strong>{{ filament()->getTenant()?->name }}</strong> está temporariamente indisponível
            porque o período de teste expirou ou a assinatura não está ativa.
        </p>

        <p>
            Entre em contato com a equipe Agendaqui para ativar sua conta ou escolher um plano.
        </p>

        @if (filled(filament()->getTenant()?->email))
            <p>
                E-mail cadastrado: <strong>{{ filament()->getTenant()->email }}</strong>
            </p>
        @endif
    </div>
</x-filament-panels::page>
