export function Cta({ children, href = '/painel/login' }) {
    return (
        <a className="cta" href={href}>
            {children}
            <span aria-hidden="true">↗</span>
        </a>
    );
}
