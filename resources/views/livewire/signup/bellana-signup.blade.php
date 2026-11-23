<section class="signup" id="cadastro" aria-labelledby="bellana-signup-title">
    <div class="copy">
        <div class="eyebrow">Condição de lançamento</div>
        <h1 id="bellana-signup-title">Sua agenda em ordem, <em>sua cliente bem atendida.</em></h1>
        <p>Agenda por profissional, atendimento pelo WhatsApp, lembretes, comissões e financeiro em um só lugar.</p>
        <div class="price">{{ $price }}<small>/mês</small></div>
        <p>Plano Bellana de lançamento · cobrança mensal</p>
        <ul>
            <li><span aria-hidden="true">✓</span>Agenda por profissional e link de agendamento online</li>
            <li><span aria-hidden="true">✓</span>WhatsApp com agendamento automático</li>
            <li><span aria-hidden="true">✓</span>Lembretes, pós-venda e reconquista</li>
            <li><span aria-hidden="true">✓</span>Comissões, financeiro e caixa</li>
            <li><span aria-hidden="true">✓</span>Venda de produtos e estoque</li>
        </ul>
        <p>7 dias para conhecer o sistema. Depois, {{ $price }}/mês.<br>Conecte o WhatsApp do seu negócio nas configurações depois do cadastro.</p>
    </div>

    <div class="card">
        @auth
            <span class="tag">Bem-vinda de volta</span>
            <h2>Você já está conectada.</h2>
            <p class="sub">Acesse o painel para continuar.</p>
            <a class="btn" href="{{ \Filament\Facades\Filament::getPanel(\App\Support\Segment::panelId('salon'))->getUrl() }}">Acessar meu painel</a>
        @else
            <span class="tag">Poucos dados, tudo pronto</span>
            <h2>Vamos criar sua conta?</h2>
            <p class="sub">Sem escolher módulos. Seu plano já vem completo.</p>
            <form wire:submit="submit">
                <div class="field">
                    <label for="bellana-company">Nome do salão ou clínica</label>
                    <input id="bellana-company" type="text" wire:model="companyName" autocomplete="organization" placeholder="Como seu negócio se chama?" required minlength="2" maxlength="120">
                    @error('companyName') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="row">
                    <div class="field">
                        <label for="bellana-name">Seu nome</label>
                        <input id="bellana-name" type="text" wire:model="name" autocomplete="name" placeholder="Seu nome" required minlength="2" maxlength="120">
                        @error('name') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="bellana-phone">WhatsApp com DDD</label>
                        <input id="bellana-phone" type="tel" wire:model="phone" autocomplete="tel" inputmode="tel" placeholder="(11) 99999-9999" required maxlength="20">
                        @error('phone') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="field">
                    <label for="bellana-email">Seu e-mail de acesso</label>
                    <input id="bellana-email" type="email" wire:model="email" autocomplete="email" placeholder="voce@seusalao.com.br" required maxlength="255">
                    @error('email') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="field" x-data="{ showPassword: false }">
                    <label for="bellana-password">Crie uma senha</label>
                    <div class="password">
                        <input id="bellana-password" type="password" :type="showPassword ? 'text' : 'password'" wire:model="password" autocomplete="new-password" placeholder="Pelo menos 8 caracteres" required minlength="8" maxlength="128">
                        <button type="button" @click="showPassword = !showPassword" x-text="showPassword ? 'Ocultar' : 'Mostrar'" aria-controls="bellana-password">Mostrar</button>
                    </div>
                    @error('password') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div aria-live="polite">@error('signup') <p class="error" role="alert">{{ $message }}</p> @enderror</div>
                <button class="btn" type="submit" wire:loading.attr="disabled" wire:target="submit">
                    <span wire:loading.remove wire:target="submit">Criar minha conta</span>
                    <span wire:loading wire:target="submit">Preparando sua conta…</span>
                </button>
                <p class="note">7 dias grátis · sem cartão de crédito</p>
                <p class="note">Depois do teste: {{ $price }}/mês, via PIX com confirmação pela equipe. Nenhuma cobrança automática no cadastro.</p>
            </form>
        @endauth
    </div>
</section>
