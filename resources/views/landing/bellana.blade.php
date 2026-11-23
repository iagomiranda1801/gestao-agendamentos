@php
    $signupUrl = route('signup.company', ['perfil' => \App\Enums\CompanyProfile::Salon->value]);
    $whatsappUrl = config('services.bellana.whatsapp_url');
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="description" content="Bellana: gestão para salões e clínicas de estética. Agenda por profissional, IA que marca horário pelo WhatsApp, lembretes, comissões e financeiro em um só lugar.">
    <meta name="theme-color" content="#300919">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Bellana | Beleza & Inteligência">
    <meta property="og:description" content="Seu talento cuida da beleza. A Bellana cuida da gestão.">
    <title>Bellana | Beleza & Inteligência</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--wine:#5B0D2B;--dark:#300919;--rose:#C76B7E;--pink:#E8B4B9;--cream:#F8EDE8;--gold:#D9A47B}
        *{box-sizing:border-box}html{scroll-behavior:smooth}
        body{margin:0;font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--dark)}
        h1,h2,h3,.brand{font-family:Montserrat,sans-serif}a{text-decoration:none}
        header{background:var(--dark);color:#fff}
        .nav{max-width:1120px;margin:auto;display:flex;justify-content:space-between;align-items:center;padding:22px 24px}
        .brand{font-size:28px;font-weight:700;letter-spacing:-1.6px;color:#fff}.brand span{color:var(--gold)}
        .navlinks{display:flex;gap:25px;align-items:center}.navlinks a{color:#f8e5e7;font-size:14px}
        .btn{display:inline-block;background:var(--gold);color:var(--dark)!important;border-radius:10px;padding:15px 22px;font-weight:800;text-align:center;box-shadow:0 6px 20px #0002}
        .btn.outline{border:1px solid #e9c9c9;background:transparent;color:#fff!important;box-shadow:none}
        .btn.ghost{border:1px solid var(--wine);background:transparent;color:var(--wine)!important;box-shadow:none}
        .hero{background:radial-gradient(circle at 85% 15%,#8b2b4b 0%,transparent 40%),linear-gradient(125deg,#300919,#5B0D2B);color:#fff;padding:88px 24px 110px}
        .wrap{max-width:1120px;margin:auto}
        .heroGrid{display:grid;grid-template-columns:1.1fr .9fr;gap:50px;align-items:center}
        .eyebrow{text-transform:uppercase;letter-spacing:2px;font-size:12px;color:#efb8bb;font-weight:800}
        .hero h1{font-size:clamp(38px,5vw,64px);line-height:1.1;letter-spacing:-2px;margin:20px 0}.hero h1 em{font-style:normal;color:#e9b3aa}
        .lead{font-size:18px;color:#f1d9dc;line-height:1.65;max-width:590px}
        .actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:30px}
        .hint{font-size:13px;color:#eac5c9;margin-top:20px}
        .mock{background:#fff;border-radius:18px;padding:18px;box-shadow:0 35px 70px #1b051a66;color:var(--dark);transform:rotate(1deg)}
        .mockhead{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #f2e6e8;padding:5px 4px 17px}.mockhead strong{color:var(--wine)}
        .pill{background:#f8e6e9;color:#8b3a55;border-radius:30px;padding:6px 10px;font-size:11px}
        .statrow{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:18px 0}
        .stat{background:#fbf3f1;border-radius:10px;padding:13px 9px}.stat b{display:block;font-size:15px;color:var(--wine);margin-bottom:3px}.stat small{font-size:10px;color:#7b6170}
        .calendar{display:grid;grid-template-columns:repeat(5,1fr);gap:6px}
        .day{text-align:center;font-size:11px;color:#6d5261}
        .slot{height:32px;background:#f8e9ed;border-radius:7px;margin-top:7px}.slot.filled{background:#b85c7a}.slot.soft{background:#e8b4b9}
        .legend{display:flex;gap:14px;font-size:11px;color:#8c7580;margin-top:16px}.legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px;vertical-align:-1px}
        .section{padding:90px 24px}.section h2{font-size:clamp(28px,3.5vw,42px);letter-spacing:-1.3px;margin:12px 0 18px}
        .sectionIntro{color:#775d67;max-width:650px;line-height:1.6}.center{text-align:center}.center .sectionIntro{margin:auto}
        .features{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:40px}
        .feature{background:#fff;padding:30px;border:1px solid #ead9d9;border-radius:18px}.feature .symbol{font-size:27px}.feature h3{font-size:18px}.feature p{color:#725f66;line-height:1.6}
        .alt{background:#fff8f5}
        .split{display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center}
        .steps{display:grid;gap:16px;margin-top:25px}.step{display:flex;gap:15px;align-items:flex-start}
        .num{background:var(--wine);color:#fff;width:36px;height:36px;border-radius:50%;display:grid;place-items:center;flex-shrink:0}
        .step p{margin:4px 0;color:#745c67;line-height:1.5}
        .chat{background:#e9f1e9;border-radius:20px;padding:20px;box-shadow:0 15px 50px #340a251c}
        .chatbar{background:#fff;border-radius:12px;padding:15px;margin-bottom:15px;font-weight:700;color:#284d37}
        .bubble{background:#fff;border-radius:12px 12px 12px 2px;padding:14px;margin:10px 35px 10px 0;line-height:1.5;font-size:14px}
        .bubble.right{background:#d0f6c9;border-radius:12px 12px 2px 12px;margin:10px 0 10px 35px}
        .pricing{background:linear-gradient(145deg,var(--dark),var(--wine));color:#fff}
        .pricebox{max-width:550px;margin:38px auto 0;background:#fff;color:var(--dark);padding:38px;border-radius:24px;box-shadow:0 25px 55px #0003}
        .price{font-family:Montserrat;font-size:56px;font-weight:800;letter-spacing:-2px;color:var(--wine)}.price small{font-size:18px;font-weight:500}
        .pricebox ul{padding-left:20px;line-height:2.1;color:#634e58}.pricebox .btn{display:block;margin-top:14px}.pricebox .btn:first-of-type{margin-top:25px}
        .faq{max-width:750px;margin:30px auto 0}.faq details{padding:21px 0;border-bottom:1px solid #dfcdd0}.faq summary{font-weight:700;cursor:pointer}.faq p{color:#745c67;line-height:1.6}.faq a{color:var(--wine);font-weight:700}
        .footer{background:var(--dark);color:#edd6d9;padding:35px 24px;text-align:center}.footer small{display:block;margin-top:10px;opacity:.8}
        @media(max-width:760px){.navlinks a:not(.btn){display:none}.hero{padding:55px 20px 75px}.heroGrid,.split{grid-template-columns:1fr}.heroGrid{gap:42px}.features{grid-template-columns:1fr}.section{padding:65px 20px}.pricebox{padding:28px}.price{font-size:47px}}
    </style>
</head>
<body>
<header>
    <nav class="nav">
        <a href="#inicio" class="brand" aria-label="Bellana, voltar ao início">bella<span>na.</span></a>
        <div class="navlinks">
            <a href="#recursos">Recursos</a>
            <a href="#como-funciona">Como funciona</a>
            <a href="#preco">Planos</a>
            <a href="{{ $signupUrl }}" class="btn">Testar grátis</a>
        </div>
    </nav>
</header>
<main>
    <section class="hero" id="inicio">
        <div class="wrap heroGrid">
            <div>
                <div class="eyebrow">Beleza & Inteligência</div>
                <h1>Seu talento cuida da beleza. <em>A Bellana cuida da gestão.</em></h1>
                <p class="lead">Agenda por profissional, uma assistente com IA que marca horários pelo WhatsApp e lembretes automáticos para suas clientes. Comissões e financeiro ficam no mesmo lugar.</p>
                <div class="actions">
                    <a class="btn" href="{{ $signupUrl }}">Começar 7 dias grátis</a>
                    @if (filled($whatsappUrl))
                        <a class="btn outline" href="{{ $whatsappUrl }}" target="_blank" rel="noopener">Falar no WhatsApp</a>
                    @else
                        <a class="btn outline" href="#recursos">Conhecer os recursos</a>
                    @endif
                </div>
                <p class="hint">Para salões, clínicas de estética e profissionais da beleza.</p>
            </div>
            <div class="mock" aria-label="Exemplo da tela de agenda da Bellana">
                <div class="mockhead">
                    <strong>bellana. <span style="font-weight:400;font-size:11px">/ Agenda da semana</span></strong>
                    <span class="pill">Exemplo</span>
                </div>
                <p style="font-weight:700">Bom dia! ✨</p>
                <div class="statrow">
                    <div class="stat"><b>Agenda</b><small>Por profissional</small></div>
                    <div class="stat"><b>Lembretes</b><small>Pelo WhatsApp</small></div>
                    <div class="stat"><b>Comissões</b><small>Calculadas por serviço</small></div>
                </div>
                <div class="calendar">
                    <div class="day">SEG<div class="slot filled"></div><div class="slot"></div><div class="slot soft"></div></div>
                    <div class="day">TER<div class="slot"></div><div class="slot filled"></div><div class="slot soft"></div></div>
                    <div class="day">QUA<div class="slot soft"></div><div class="slot filled"></div><div class="slot"></div></div>
                    <div class="day">QUI<div class="slot filled"></div><div class="slot soft"></div><div class="slot"></div></div>
                    <div class="day">SEX<div class="slot"></div><div class="slot soft"></div><div class="slot filled"></div></div>
                </div>
                <div class="legend"><span><i style="background:#b85c7a"></i>Confirmado</span><span><i style="background:#e8b4b9"></i>Agendado</span><span><i style="background:#f8e9ed"></i>Livre</span></div>
            </div>
        </div>
    </section>

    <section class="section" id="recursos">
        <div class="wrap center">
            <div class="eyebrow" style="color:#9c526a">Tudo no seu ritmo</div>
            <h2>Menos tarefas. Mais tempo para suas clientes.</h2>
            <p class="sectionIntro">Feita para a rotina de salões e clínicas de estética, do primeiro contato no WhatsApp ao fechamento do caixa.</p>
            <div class="features" style="text-align:left">
                <article class="feature"><div class="symbol">💬</div><h3>IA que agenda pelo WhatsApp</h3><p>A assistente entende o pedido da cliente, pergunta o serviço e a profissional preferida e oferece só horários livres de verdade. Quando a conversa pede uma pessoa, ela chama a equipe.</p></article>
                <article class="feature"><div class="symbol">📅</div><h3>Agenda por profissional</h3><p>Cada profissional com seus serviços, horários de trabalho e bloqueios. Nada de dois atendimentos no mesmo horário.</p></article>
                <article class="feature"><div class="symbol">🔔</div><h3>Lembretes e pós-venda</h3><p>Mensagens automáticas de lembrete antes do horário, pós-venda depois do atendimento e reconquista para clientes que não voltam há um tempo.</p></article>
                <article class="feature"><div class="symbol">🔗</div><h3>Link de agendamento online</h3><p>Sua cliente escolhe serviço, profissional e horário por um link do seu negócio, e recebe um link para remarcar ou cancelar quando precisar.</p></article>
                <article class="feature"><div class="symbol">👩‍💼</div><h3>Equipe e comissões</h3><p>Defina a comissão padrão ou por profissional e serviço, e acompanhe quanto cada uma tem a receber pelos atendimentos.</p></article>
                <article class="feature"><div class="symbol">💳</div><h3>Financeiro, produtos e estoque</h3><p>Contas a pagar e a receber, venda de produtos e controle de estoque junto com a agenda.</p></article>
            </div>
        </div>
    </section>

    <section class="section alt" id="como-funciona">
        <div class="wrap split">
            <div>
                <div class="eyebrow" style="color:#9c526a">Atendimento mais leve</div>
                <h2>Do primeiro oi ao horário marcado.</h2>
                <div class="steps">
                    <div class="step"><span class="num">1</span><div><strong>Você cadastra serviços e equipe</strong><p>Informe os serviços, as profissionais e o horário de trabalho de cada uma, e conecte o WhatsApp do seu negócio.</p></div></div>
                    <div class="step"><span class="num">2</span><div><strong>A cliente chama no WhatsApp</strong><p>A assistente com IA responde, entende o serviço desejado e oferece os horários livres na agenda.</p></div></div>
                    <div class="step"><span class="num">3</span><div><strong>O horário entra na agenda</strong><p>A cliente escolhe, o agendamento é criado e o lembrete sai antes do atendimento.</p></div></div>
                    <div class="step"><span class="num">4</span><div><strong>Você acompanha tudo</strong><p>Agenda, comissões e financeiro em um só painel.</p></div></div>
                </div>
            </div>
            <div class="chat">
                <div class="chatbar">💬 Exemplo de conversa</div>
                <div class="bubble right">Oi! Queria marcar design de sobrancelhas na sexta.</div>
                <div class="bubble">Oi! 💗 Claro. Tem preferência de profissional?</div>
                <div class="bubble right">Pode ser qualquer uma.</div>
                <div class="bubble">Na sexta tenho 10h, 14h ou 16h30. Qual fica melhor pra você?</div>
                <div class="bubble right">14h ✨</div>
                <div class="bubble">Pronto, ficou marcado pra sexta às 14h. Te mando um lembrete antes do horário.</div>
            </div>
        </div>
    </section>

    <section class="section pricing" id="preco">
        <div class="wrap center">
            <div class="eyebrow">Condição de lançamento</div>
            <h2>Comece a transformar sua gestão.</h2>
            <p class="sectionIntro" style="color:#f0d4dc">Teste por 7 dias sem pagar nada e veja a Bellana funcionando no seu negócio.</p>
            <div class="pricebox" style="text-align:left">
                <span class="pill">Oferta de lançamento</span>
                <h3 style="margin:18px 0 5px">Bellana • Plano de lançamento</h3>
                <div class="price">R$ 79,90<small>/mês</small></div>
                <ul>
                    <li>Agenda por profissional e link de agendamento online</li>
                    <li>Assistente com IA no WhatsApp</li>
                    <li>Lembretes, pós-venda e reconquista automáticos</li>
                    <li>Comissões, financeiro, produtos e estoque</li>
                    <li>7 dias grátis para testar</li>
                </ul>
                <a href="{{ $signupUrl }}" class="btn">Criar minha conta</a>
                @if (filled($whatsappUrl))
                    <a href="{{ $whatsappUrl }}" class="btn ghost" target="_blank" rel="noopener">Tirar dúvidas no WhatsApp</a>
                @endif
            </div>
        </div>
    </section>

    <section class="section" id="faq">
        <div class="wrap center">
            <div class="eyebrow" style="color:#9c526a">Perguntas frequentes</div>
            <h2>Conheça melhor a Bellana</h2>
            <div class="faq" style="text-align:left">
                <details><summary>Para quem é a Bellana?</summary><p>Para salões de beleza, clínicas de estética e profissionais autônomos do setor de beleza.</p></details>
                <details><summary>A IA marca horário sozinha?</summary><p>Sim. Ela usa os serviços, as profissionais e os horários que você cadastrou para oferecer só horários livres e criar o agendamento. Se a cliente precisar de uma pessoa, a conversa passa para a sua equipe.</p></details>
                <details><summary>Preciso de outro número de WhatsApp?</summary><p>Não. Você conecta o WhatsApp do seu negócio e a assistente responde por ele.</p></details>
                <details><summary>Quanto custa?</summary><p>Na oferta de lançamento, R$ 79,90 por mês, com 7 dias grátis para testar antes.</p></details>
                <details><summary>Como começo?</summary><p><a href="{{ $signupUrl }}">Crie sua conta</a> e teste por 7 dias.@if (filled($whatsappUrl)) Se preferir, <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener">fale com a gente no WhatsApp</a>.@endif</p></details>
            </div>
        </div>
    </section>
</main>
<footer class="footer">
    <div class="brand">bella<span>na.</span></div>
    <small>Beleza & Inteligência • Uma solução da IM Soluções Digitais</small>
    <small>© {{ now()->year }} Bellana</small>
</footer>
</body>
</html>
