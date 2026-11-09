import { Cta } from './Cta';

const messages = [
    { from: 'studio', text: 'Oi! Como posso te chamar?' },
    { from: 'client', text: 'Paulo. Quero uma rosa em fine line no antebraço, de uns 12 cm.' },
    { from: 'studio', text: 'Prazer, Paulo! Tem preferência de artista?' },
    { from: 'client', text: 'Gustavo! Vou mandar a referência também.' },
    { from: 'studio', text: 'Perfeito. Com a referência, sua ideia fica organizada para a equipe preparar o orçamento.' },
];

export function Hero() {
    return (
        <section className="hero" id="inicio" aria-labelledby="hero-title">
            <div className="hero-copy">
                <p className="brand">Gestão para estúdios de tatuagem</p>
                <h1 id="hero-title">Sua arte merece<br /><em>mais tempo.</em></h1>
                <p className="lede">O cliente manda a ideia no WhatsApp. A TattoFlow organiza os detalhes. Sua equipe recebe o pedido pronto para orçar.</p>
                <Cta href="#cadastro">Quero meu estúdio organizado</Cta>
                <a className="hero-secondary" href="/painel/login">Acessar meu estúdio</a>
                <p className="note">O atendimento flui. O valor continua nas suas mãos.</p>
                <ul className="collected">
                    <li>Nome</li>
                    <li>Artista</li>
                    <li>Desenho</li>
                    <li>Foto</li>
                </ul>
            </div>
            <div className="hero-product">
            <div className="product-caption"><span>DA CONVERSA À PRÓXIMA SESSÃO</span><span aria-hidden="true">01 — 03</span></div>
            <div className="chat" aria-label="Conversa ilustrativa no WhatsApp">
                <div className="chat-bar">
                    <span className="chat-avatar" aria-hidden="true">TF</span>
                    <div><strong>Seu estúdio</strong><span>Atendimento com TattoFlow</span></div>
                    <span className="chat-dot" aria-hidden="true" />
                </div>
                <div className="chat-log">
                    {messages.map((message, index) => (
                        <p key={index} className={message.from}>
                            {message.text}
                            <span className="message-time">14:{String(2 + index).padStart(2, '0')}</span>
                        </p>
                    ))}
                </div>
            </div>
            <div className="flow-summary"><span className="summary-check" aria-hidden="true">✓</span><div><strong>Ideia recebida. Pedido organizado.</strong><span>Nome · Artista · Desenho · Referência</span></div></div>
            <p className="demo-label">Exemplo ilustrativo de atendimento</p>
            </div>
        </section>
    );
}
