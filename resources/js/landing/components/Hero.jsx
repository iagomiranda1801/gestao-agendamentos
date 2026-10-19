import { Cta } from './Cta';

const messages = [
    { from: 'studio', text: 'Qual seu nome?' },
    { from: 'client', text: 'Pensei em algo assim' },
    { from: 'studio', text: 'Qual seu nome?' },
    { from: 'client', text: 'Paulo' },
    { from: 'studio', text: 'Você tem preferência de artista? Se tiver, me fala qual.' },
    { from: 'client', text: 'Gustavo' },
    { from: 'studio', text: 'Que desenho você quer na tattoo?' },
];

export function Hero() {
    return (
        <header className="hero">
            <div className="hero-copy">
                <p className="brand">TatooAI</p>
                <h1>O cliente manda a ideia no WhatsApp. O pedido chega pronto para orçar.</h1>
                <p className="lede">A assistente coleta nome, artista, desenho e referência. Sua equipe só define o valor e envia.</p>
                <Cta>Entrar no estúdio</Cta>
                <p className="note">A conversa continua no WhatsApp do estúdio.</p>
                <ul className="collected">
                    <li>Nome</li>
                    <li>Artista</li>
                    <li>Desenho</li>
                    <li>Foto</li>
                </ul>
            </div>
            <div className="chat" aria-label="Conversa de exemplo">
                <div className="chat-bar">
                    <span className="chat-dot" aria-hidden="true" />
                    WhatsApp do estúdio
                </div>
                <div className="chat-log">
                    {messages.map((message, index) => (
                        <p key={index} className={message.from}>
                            {message.text}
                            <time>14:02</time>
                        </p>
                    ))}
                </div>
            </div>
        </header>
    );
}
