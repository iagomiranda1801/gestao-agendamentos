const links = [
    ['#servico', 'Como funciona'],
    ['#pedido', 'No seu estúdio'],
    ['#valor', 'Seu orçamento'],
    ['#cadastro', 'Plano'],
];

export function Nav() {
    return (
        <>
        <a className="skip-link" href="#conteudo">Pular para o conteúdo</a>
        <header className="site-header">
            <a className="wordmark" href="#inicio" aria-label="TattoFlow — início">
                <img src="/images/tattoflow/01_Logo_Principal_Fundo_Preto.png" alt="TattoFlow" width="1460" height="708" />
            </a>
            <nav className="nav" aria-label="Seções">
                {links.map(([href, label]) => (
                    <a key={href} href={href}>{label}</a>
                ))}
            </nav>
            <a className="header-login" href="/painel/login">Entrar no estúdio <span aria-hidden="true">↗</span></a>
        </header>
        </>
    );
}
