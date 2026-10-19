const lines = [
    ['WhatsApp', 'O orçamento sai na mesma conversa.'],
    ['Sinal', 'O cliente envia o comprovante por ali.'],
    ['Agendamento', 'A sessão entra na agenda do estúdio.'],
];

export function After() {
    return (
        <section className="section">
            <h2>Depois do orçamento.</h2>
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
