const steps = [
    ['A conversa começa', 'A assistente recebe o cliente e identifica seu nome e a preferência de artista.'],
    ['A ideia ganha contexto', 'Desenho, local, tamanho, estilo e imagem de referência ficam reunidos no pedido.'],
    ['Sua equipe assume', 'Com o pedido organizado, a equipe define o valor e envia o orçamento pelo WhatsApp.'],
];

export function Path() {
    return (
        <section className="section" id="servico">
            <p className="kicker">01 / Como funciona</p>
            <h2>Do primeiro oi ao pedido pronto.</h2>
            <ol className="steps">
                {steps.map(([title, text], index) => (
                    <li key={title}>
                        <span>{String(index + 1).padStart(2, '0')}</span>
                        <div>
                            <strong>{title}</strong>
                            <p>{text}</p>
                        </div>
                    </li>
                ))}
            </ol>
        </section>
    );
}
