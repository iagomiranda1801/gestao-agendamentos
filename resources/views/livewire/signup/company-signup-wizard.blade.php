<div class="signup-card">
    <div class="signup-steps">
        <span @class(['signup-step', 'is-active' => $step === 'company', 'is-done' => $step !== 'company'])">1. Empresa</span>
        <span @class(['signup-step', 'is-active' => $step === 'modules', 'is-done' => in_array($step, ['admin', 'review'], true)])">2. Módulos</span>
        <span @class(['signup-step', 'is-active' => $step === 'admin', 'is-done' => $step === 'review'])">3. Conta</span>
        <span @class(['signup-step', 'is-active' => $step === 'review'])">4. Revisão</span>
    </div>

    @if ($step === 'company')
        <h1 class="signup-title">Crie sua empresa</h1>
        <p class="signup-subtitle">Comece com 7 dias grátis para testar o Agendaqui.</p>

        <div class="signup-grid signup-grid--2">
            <div class="signup-field">
                <label for="companyName">Nome da empresa</label>
                <input id="companyName" type="text" wire:model.live="companyName">
                @error('companyName') <div class="signup-error">{{ $message }}</div> @enderror
            </div>

            <div class="signup-field">
                <label for="companySlug">Identificador (URL)</label>
                <input id="companySlug" type="text" wire:model="companySlug">
                <small>agendaqui.com/app/empresa/{{ $companySlug ?: 'sua-empresa' }}</small>
                @error('companySlug') <div class="signup-error">{{ $message }}</div> @enderror
            </div>

            <div class="signup-field">
                <label for="companyEmail">E-mail da empresa</label>
                <input id="companyEmail" type="email" wire:model="companyEmail">
                @error('companyEmail') <div class="signup-error">{{ $message }}</div> @enderror
            </div>

            <div class="signup-field">
                <label for="companyPhone">Telefone</label>
                <input id="companyPhone" type="text" wire:model="companyPhone">
                @error('companyPhone') <div class="signup-error">{{ $message }}</div> @enderror
            </div>

            <div class="signup-field">
                <label for="timezone">Fuso horário</label>
                <select id="timezone" wire:model="timezone">
                    <option value="America/Sao_Paulo">America/Sao_Paulo</option>
                    <option value="America/Manaus">America/Manaus</option>
                    <option value="America/Fortaleza">America/Fortaleza</option>
                </select>
                @error('timezone') <div class="signup-error">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="signup-actions">
            <span></span>
            <button type="button" class="signup-btn signup-btn--primary" wire:click="goToModulesStep">Continuar</button>
        </div>
    @endif

    @if ($step === 'modules')
        <h1 class="signup-title">Escolha os módulos</h1>
        <p class="signup-subtitle">Ative só o que precisa agora. Você pode ajustar depois com nossa equipe.</p>

        <div class="signup-modules">
            @foreach ($this->moduleOptions() as $value => $label)
                <label class="signup-module">
                    <input type="checkbox" value="{{ $value }}" wire:model="selectedModules">
                    <span>
                        <span class="signup-module__title">{{ $label }}</span>
                        <span class="signup-module__desc">{{ $this->moduleDescriptions()[$value] ?? '' }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        @error('selectedModules') <div class="signup-error">{{ $message }}</div> @enderror

        <div class="signup-actions">
            <button type="button" class="signup-btn signup-btn--secondary" wire:click="goToCompanyStep">Voltar</button>
            <button type="button" class="signup-btn signup-btn--primary" wire:click="goToAdminStep">Continuar</button>
        </div>
    @endif

    @if ($step === 'admin')
        <h1 class="signup-title">Sua conta de administrador</h1>
        <p class="signup-subtitle">Este usuário terá acesso total à empresa no painel.</p>

        <div class="signup-grid">
            <div class="signup-field">
                <label for="adminName">Seu nome</label>
                <input id="adminName" type="text" wire:model="adminName">
                @error('adminName') <div class="signup-error">{{ $message }}</div> @enderror
            </div>

            <div class="signup-field">
                <label for="adminEmail">Seu e-mail</label>
                <input id="adminEmail" type="email" wire:model="adminEmail">
                @error('adminEmail') <div class="signup-error">{{ $message }}</div> @enderror
            </div>

            <div class="signup-field">
                <label for="adminPassword">Senha</label>
                <input id="adminPassword" type="password" wire:model="adminPassword">
                @error('adminPassword') <div class="signup-error">{{ $message }}</div> @enderror
            </div>

            <div class="signup-field">
                <label for="adminPasswordConfirmation">Confirmar senha</label>
                <input id="adminPasswordConfirmation" type="password" wire:model="adminPasswordConfirmation">
            </div>
        </div>

        <div class="signup-actions">
            <button type="button" class="signup-btn signup-btn--secondary" wire:click="goToModulesStep">Voltar</button>
            <button type="button" class="signup-btn signup-btn--primary" wire:click="goToReviewStep">Continuar</button>
        </div>
    @endif

    @if ($step === 'review')
        <span class="signup-trial-badge">7 dias grátis</span>
        <h1 class="signup-title">Revise e comece</h1>
        <p class="signup-subtitle">Confirme os dados antes de criar sua conta.</p>

        <dl class="signup-review">
            <dt>Empresa</dt>
            <dd>{{ $companyName }} ({{ $companySlug }})</dd>

            <dt>Módulos</dt>
            <dd>
                {{ collect($selectedModules)->map(fn ($value) => $this->moduleOptions()[$value] ?? $value)->join(', ') }}
            </dd>

            <dt>Administrador</dt>
            <dd>{{ $adminName }} — {{ $adminEmail }}</dd>
        </dl>

        <div class="signup-actions">
            <button type="button" class="signup-btn signup-btn--secondary" wire:click="goToAdminStep">Voltar</button>
            <button type="button" class="signup-btn signup-btn--primary" wire:click="submit" wire:loading.attr="disabled">
                Começar 7 dias grátis
            </button>
        </div>
    @endif
</div>
