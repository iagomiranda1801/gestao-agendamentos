@php
    use App\Support\Branding;
@endphp

<div class="agendaqui-dashboard-preview" aria-hidden="true">
    <div class="agendaqui-dashboard-preview__frame">
        <aside class="agendaqui-dashboard-preview__sidebar">
            <img
                src="{{ Branding::logoUrl() }}"
                alt=""
                class="agendaqui-dashboard-preview__sidebar-logo"
            >
            <nav class="agendaqui-dashboard-preview__nav">
                <span class="is-active">Dashboard</span>
                <span>Agenda</span>
                <span>Clientes</span>
                <span>Financeiro</span>
            </nav>
        </aside>
        <div class="agendaqui-dashboard-preview__main">
            <div class="agendaqui-dashboard-preview__stats">
                <article class="agendaqui-dashboard-preview__stat">
                    <strong>32</strong>
                    <span class="agendaqui-dashboard-preview__stat-label">Agenda</span>
                </article>
                <article class="agendaqui-dashboard-preview__stat">
                    <strong>248</strong>
                    <span class="agendaqui-dashboard-preview__stat-label">Clientes</span>
                </article>
            </div>
            <div class="agendaqui-dashboard-preview__chart">
                <div class="agendaqui-dashboard-preview__chart-bars">
                    <span style="height: 42%"></span>
                    <span style="height: 68%"></span>
                    <span style="height: 55%"></span>
                    <span style="height: 82%"></span>
                    <span style="height: 64%"></span>
                </div>
            </div>
        </div>
    </div>
</div>
