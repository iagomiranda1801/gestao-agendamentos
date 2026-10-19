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
            <p className="kicker">O pedido</p>
            <h2>Como a equipe vê.</h2>
            <article className="order">
                <div className="order-photo" aria-hidden="true" />
                <dl>
                    {fields.map(([label, value]) => (
                        <div key={label}>
                            <dt>{label}</dt>
                            <dd>{value}</dd>
                        </div>
                    ))}
                </dl>
                <p className="order-status">Foto recebida</p>
                <a href="/painel/login">Fazer orçamento</a>
            </article>
        </section>
    );
}
