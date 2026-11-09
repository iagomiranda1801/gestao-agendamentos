const fields = [
    ['Cliente', 'Paulo'],
    ['Artista', 'Gustavo'],
    ['Ideia', 'Rosa fina no antebraço'],
    ['Local', 'Antebraço'],
    ['Tamanho', '12 cm'],
    ['Estilo', 'Fine line'],
];

export function Proof() {
    return (
        <section className="section" id="pedido">
            <p className="kicker">02 / No seu estúdio</p>
            <h2>Uma ideia.<br /><em>Todos os detalhes.</em></h2>
            <p className="section-intro">Sem procurar informações no histórico da conversa. O que a equipe precisa para orçar fica no mesmo lugar.</p>
            <article className="order">
                <div className="order-heading"><span>Pedido de tatuagem</span><span className="order-badge">Aguardando orçamento</span></div>
                <dl>
                    {fields.map(([label, value]) => (
                        <div key={label}>
                            <dt>{label}</dt>
                            <dd>{value}</dd>
                        </div>
                    ))}
                </dl>
                <p className="order-status">Foto recebida</p>
                <p className="order-demo">Demonstração do pedido · dados ilustrativos</p>
            </article>
        </section>
    );
}
