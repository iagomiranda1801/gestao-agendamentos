const steps = [
  ['Nome', 'A assistente identifica quem está pedindo.'],
  ['Preferência de artista', 'O cliente informa um nome ou diz sem preferência.'],
  ['Desenho e referência', 'Entram ideia, parte do corpo, tamanho, estilo e imagem.'],
  ['Pedido na fila', 'A equipe recebe o contexto organizado.'],
  ['Orçamento', 'A equipe define o valor e envia pelo WhatsApp.'],
];

export default function Path() {
  return (
    <section className="section container" aria-labelledby="path-title">
      <p className="eyebrow">O percurso</p>
      <h2 id="path-title">Da primeira mensagem ao pedido.</h2>
      <ol className="steps">{steps.map(([title, body], index) => <li key={title}><span className="step-number">{String(index + 1).padStart(2, '0')}</span><h3>{title}</h3><p>{body}</p></li>)}</ol>
    </section>
  );
}
