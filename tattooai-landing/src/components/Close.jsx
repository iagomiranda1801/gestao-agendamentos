export default function Close({ ctaUrl }) {
  return (
    <section className="section close container" id="contato" aria-labelledby="close-title">
      <p className="eyebrow">TattooAI</p>
      <h2 id="close-title">O cliente manda a ideia no WhatsApp. O pedido chega pronto para orçar.</h2>
      <a className="button button-primary" href={ctaUrl}>Quero ver no meu estúdio</a>
    </section>
  );
}
