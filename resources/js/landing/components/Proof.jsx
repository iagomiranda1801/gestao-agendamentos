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
        <section className="section">
            <h2>O pedido, como a equipe vê.</h2>
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
                <button type="button">Fazer orçamento</button>
            </article>
        </section>
    );
}
