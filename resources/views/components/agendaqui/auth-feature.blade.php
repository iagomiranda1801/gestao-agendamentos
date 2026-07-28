@props([
    'title',
    'description',
])

<div class="agendaqui-feature-item">
    <div class="agendaqui-feature-item__icon" aria-hidden="true">
        {{ $icon }}
    </div>
    <div class="agendaqui-feature-item__body">
        <h3 class="agendaqui-feature-item__title">{{ $title }}</h3>
        <p class="agendaqui-feature-item__description">{{ $description }}</p>
    </div>
</div>
