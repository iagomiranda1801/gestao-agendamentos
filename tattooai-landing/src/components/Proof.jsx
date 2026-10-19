export default function Proof() {
  return (
    <section className="section container proof" aria-labelledby="proof-title">
      <div className="proof-copy"><p className="eyebrow">No painel</p><h2 id="proof-title">O pedido chega com o que sua equipe precisa ler.</h2><p>Uma ideia, os detalhes e a preferência de artista no mesmo lugar.</p></div>
      <div className="request-card" aria-label="Exemplo ilustrativo de pedido de tatuagem">
        <div className="request-head"><span>Pedido de tatuagem</span><span className="status">Aguardando orçamento</span></div>
        <div className="request-grid">
          <div><small>Cliente</small><strong>Paulo</strong></div>
          <div><small>Artista preferido</small><strong>Gustavo</strong></div>
          <div className="request-wide"><small>Ideia</small><strong>Andorinha em traço fino</strong></div>
          <div><small>Local</small><strong>Antebraço</strong></div>
          <div><small>Tamanho</small><strong>12 cm</strong></div>
          <div><small>Estilo</small><strong>Traço fino</strong></div>
          <div className="reference"><small>Referência</small><div className="reference-row"><span className="reference-placeholder" aria-hidden="true" /><strong>Foto recebida</strong></div></div>
        </div>
        <div className="request-footer"><button className="button button-secondary" type="button" disabled aria-label="Exemplo do botão Fazer orçamento">Fazer orçamento</button></div>
      </div>
    </section>
  );
}
