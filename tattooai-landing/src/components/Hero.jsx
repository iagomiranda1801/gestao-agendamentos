const messages = [
  { from: 'assistant', text: 'Oi! Para começar, qual é o seu nome?', time: '10:42' },
  { from: 'client', text: 'Quero tatuar uma andorinha no braço.', time: '10:42' },
  { from: 'assistant', text: 'Claro. Como posso te chamar?', time: '10:43' },
  { from: 'client', text: 'Paulo', time: '10:43' },
  { from: 'assistant', text: 'Você tem preferência de artista? Se tiver, me fala qual. Se não, pode dizer sem preferência.', time: '10:43' },
  { from: 'client', text: 'Gustavo', time: '10:44' },
  { from: 'assistant', text: 'Como você imagina o desenho?', time: '10:44' },
];

export default function Hero({ ctaUrl }) {
  return (
    <section className="hero container" aria-labelledby="hero-title">
      <div className="hero-copy">
        <p className="eyebrow">TattooAI para estúdios</p>
        <h1 id="hero-title">O cliente manda a ideia no WhatsApp. O pedido chega pronto para orçar.</h1>
        <p className="lead">A assistente coleta nome, artista, desenho e referência. Sua equipe define o valor e envia.</p>
        <a className="button button-primary" href={ctaUrl}>Quero ver no meu estúdio</a>
        <p className="support">A conversa continua no WhatsApp do estúdio.</p>
      </div>
      <div className="conversation" aria-label="Exemplo ilustrativo de conversa no WhatsApp">
        <div className="conversation-head"><span className="avatar" aria-hidden="true">T</span><div><strong>Estúdio</strong><small>WhatsApp do estúdio</small></div><span className="online" aria-hidden="true" /></div>
        <div className="conversation-body">
          {messages.map((message, index) => <div className={`message message-${message.from}`} key={index}><span>{message.text}</span><time>{message.time}</time></div>)}
        </div>
      </div>
    </section>
  );
}
