import { Cta } from './Cta';

const links = [
    ['#casa', 'A casa'],
    ['#servico', 'O serviço'],
    ['#valor', 'O valor'],
    ['#pedido', 'O pedido'],
    ['#depois', 'Depois'],
];

export function Nav() {
    return (
        <header className="site-header">
            <a className="wordmark" href="/">TatooAI</a>
            <nav className="nav" aria-label="Seções">
                {links.map(([href, label]) => (
                    <a key={href} href={href}>{label}</a>
                ))}
            </nav>
            <Cta>Entrar</Cta>
        </header>
    );
}
