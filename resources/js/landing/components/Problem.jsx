const facts = [
    ['A mesma pergunta', 'Oi, áudio, print e “quanto fica?” ocupam o dia inteiro.'],
    ['Pedido incompleto', 'Falta nome, tamanho ou foto quando alguém vai responder.'],
    ['Sessão interrompida', 'O tatuador para o trabalho para responder no WhatsApp.'],
];

export function Problem() {
    return (
        <section className="section">
            <h2>O WhatsApp não devia parar a sessão.</h2>
            <ul className="facts">
                {facts.map(([title, text]) => (
                    <li key={title}>
                        <strong>{title}</strong>
                        <span>{text}</span>
                    </li>
                ))}
            </ul>
        </section>
    );
}
