const steps = [
    ['Nome', 'A assistente pede o nome e não aceita uma frase de ideia no lugar.'],
    ['Preferência de artista', 'Pergunta se há preferência e qual seria, sem citar a equipe.'],
    ['Desenho e referência', 'Guarda a ideia, o local, o tamanho, o estilo e a foto.'],
    ['Pedido na fila', 'A equipe vê o pedido completo, com o artista que o cliente falou.'],
    ['Orçamento enviado', 'Alguém define o valor e manda pelo WhatsApp.'],
];

export function Path() {
    return (
        <section className="section" id="servico">
            <p className="kicker">O serviço</p>
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
