const lines = [
    ['WhatsApp', 'O orçamento sai na mesma conversa.'],
    ['Sinal', 'O cliente envia o comprovante por ali.'],
    ['Sessão', 'A sessão fica marcada no estúdio.'],
];

export function After() {
    return (
        <section className="section" id="depois">
            <p className="kicker">04 / O próximo passo</p>
            <h2>O orçamento segue na conversa.</h2>
            <ul className="after">
                {lines.map(([title, text]) => (
                    <li key={title}>
                        <strong>{title}</strong>
                        {text}
                    </li>
                ))}
            </ul>
        </section>
    );
}
