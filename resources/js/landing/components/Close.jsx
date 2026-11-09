import { Cta } from './Cta';

export function Close() {
    return (
        <section className="section close" aria-labelledby="close-title">
            <img className="close-logo" src="/images/tattoflow/01_Logo_Principal_Fundo_Preto.png" alt="TattoFlow" width="1460" height="708" loading="lazy" />
            <p className="brand">Seu estúdio. Seu ritmo.</p>
            <h2 id="close-title">Menos tempo nas mensagens.<br /><em>Mais tempo na arte.</em></h2>
            <p className="lede">Do primeiro oi ao agendamento, cada etapa no seu lugar.</p>
            <Cta href="#cadastro">Conhecer o plano de lançamento</Cta>
        </section>
    );
}
