<div class="admin-operations-page">
    <div class="admin-operations-page__intro">
        <div>
            <p class="admin-operations-page__eyebrow">Mapa técnico</p>
            <h1 class="admin-operations-page__title">Rotas do sistema</h1>
            <p class="admin-operations-page__description">Rotas públicas e do painel operacional registradas nesta aplicação.</p>
        </div>
        <span class="admin-operations-count">{{ count($routes) }} rotas</span>
    </div>

    <div class="admin-operations-table-wrap">
        <table class="admin-operations-table">
            <thead><tr><th>Método</th><th>URI</th><th>Nome</th><th>Ação</th></tr></thead>
            <tbody>
                @foreach ($routes as $route)
                    <tr><td><span class="admin-operations-method">{{ $route['methods'] }}</span></td><td><code>{{ $route['uri'] }}</code></td><td>{{ $route['name'] }}</td><td><small>{{ $route['action'] }}</small></td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
