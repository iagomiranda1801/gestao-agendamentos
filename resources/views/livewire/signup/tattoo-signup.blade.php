<section class="launch-section" id="cadastro" aria-labelledby="launch-title">
    <div class="launch-shell">
        <div class="launch-copy">
            <span class="launch-badge"><span aria-hidden="true">✦</span> CONDIÇÃO DE LANÇAMENTO</span>
            <h2 id="launch-title">Seu próximo capítulo<br />começa com <em>mais fluxo.</em></h2>
            <p class="launch-description">Da primeira mensagem ao fechamento do caixa. O essencial para cuidar do estúdio, em um só lugar.</p>
            <div class="launch-price"><span>{{ $price }}</span><span>/mês</span></div>
            <p class="launch-price-note">Pacote completo para estúdios · cobrança mensal</p>
            <ul class="launch-features">
                <li><span aria-hidden="true">✓</span> Agenda, clientes e equipe de tatuadores</li>
                <li><span aria-hidden="true">✓</span> Pedidos de orçamento e referências</li>
                <li><span aria-hidden="true">✓</span> WhatsApp e automações de atendimento</li>
                <li><span aria-hidden="true">✓</span> Financeiro, caixa e controle de sinais</li>
                <li><span aria-hidden="true">✓</span> Estoque e vendas de produtos</li>
                <li><span aria-hidden="true">✓</span> Marketing e relacionamento com clientes</li>
            </ul>
            <p class="launch-footnote">7 dias para conhecer o sistema. Depois, {{ $price }}/mês.<br />Conecte o WhatsApp do estúdio nas configurações após o cadastro.</p>
        </div>

        <div class="launch-form-card">
            @auth
                <div class="launch-form-heading"><span class="launch-form-eyebrow">BEM-VINDO DE VOLTA</span><h3>Você já está conectado.</h3><p>Acesse o painel para continuar cuidando do seu estúdio.</p></div>
                <a class="cta launch-submit" href="/painel">Acessar meu estúdio <span aria-hidden="true">↗</span></a>
            @else
                <div class="launch-form-heading">
                    <span class="launch-form-eyebrow">POUCOS DADOS. UM NOVO COMEÇO.</span>
                    <h3>Vamos criar seu estúdio?</h3>
                    <p>Sem escolher módulos. Seu pacote já vem pronto.</p>
                </div>
                <form wire:submit="submit" class="launch-form">
                    <div class="launch-field">
                        <label for="tattoo-company">Nome do estúdio</label>
                        <input id="tattoo-company" name="companyName" type="text" wire:model="companyName" autocomplete="organization" placeholder="Como seu estúdio se chama?" required minlength="2" maxlength="120" @error('companyName') aria-invalid="true" aria-describedby="tattoo-company-error" @enderror>
                        @error('companyName') <p class="launch-error" id="tattoo-company-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="launch-field-row">
                        <div class="launch-field">
                            <label for="tattoo-name">Seu nome</label>
                            <input id="tattoo-name" name="name" type="text" wire:model="name" autocomplete="name" placeholder="Seu nome" required minlength="2" maxlength="120" @error('name') aria-invalid="true" aria-describedby="tattoo-name-error" @enderror>
                            @error('name') <p class="launch-error" id="tattoo-name-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="launch-field">
                            <label for="tattoo-phone">WhatsApp com DDD</label>
                            <input id="tattoo-phone" name="phone" type="tel" wire:model="phone" autocomplete="tel" inputmode="tel" placeholder="(11) 99999-9999" required maxlength="20" @error('phone') aria-invalid="true" aria-describedby="tattoo-phone-error" @enderror>
                            @error('phone') <p class="launch-error" id="tattoo-phone-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="launch-field">
                        <label for="tattoo-email">Seu e-mail de acesso</label>
                        <input id="tattoo-email" name="email" type="email" wire:model="email" autocomplete="email" placeholder="voce@seuestudio.com.br" required maxlength="255" @error('email') aria-invalid="true" aria-describedby="tattoo-email-error" @enderror>
                        @error('email') <p class="launch-error" id="tattoo-email-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="launch-field" x-data="{ showPassword: false }">
                        <label for="tattoo-password">Crie uma senha</label>
                        <div class="launch-password">
                            <input id="tattoo-password" name="password" type="password" :type="showPassword ? 'text' : 'password'" wire:model="password" autocomplete="new-password" placeholder="Pelo menos 8 caracteres" required minlength="8" maxlength="128" @error('password') aria-invalid="true" aria-describedby="tattoo-password-error" @enderror>
                            <button type="button" @click="showPassword = !showPassword" :aria-pressed="showPassword" x-text="showPassword ? 'Ocultar' : 'Mostrar'" aria-controls="tattoo-password">Mostrar</button>
                        </div>
                        @error('password') <p class="launch-error" id="tattoo-password-error">{{ $message }}</p> @enderror
                    </div>
                    <div aria-live="polite">@error('signup') <p class="launch-error" role="alert">{{ $message }}</p> @enderror</div>
                    <button class="cta launch-submit" type="submit" wire:loading.attr="disabled" wire:target="submit">
                        <span wire:loading.remove wire:target="submit">Criar meu estúdio <span aria-hidden="true">↗</span></span>
                        <span wire:loading wire:target="submit">Preparando seu estúdio…</span>
                    </button>
                    <p class="launch-payment-note">7 dias grátis · sem cartão de crédito</p>
                    <p class="launch-terms">Depois do teste: {{ $price }}/mês, via PIX com confirmação pela equipe. Nenhuma cobrança automática no cadastro.</p>
                </form>
                <p class="launch-signin">Já usa a TattooFlow? <a href="/painel/login">Entrar no estúdio</a></p>
            @endauth
        </div>
    </div>
</section>
