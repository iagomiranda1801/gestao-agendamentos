import { After } from './components/After';
import { Close } from './components/Close';
import { Hero } from './components/Hero';
import { Nav } from './components/Nav';
import { Path } from './components/Path';
import { Price } from './components/Price';
import { Problem } from './components/Problem';
import { Proof } from './components/Proof';

export function App() {
    return (
        <>
            <Nav />
            <main id="conteudo">
                <Hero />
                <Problem />
                <Path />
                <Proof />
                <Price />
                <After />
                <Close />
            </main>
            <footer className="site-footer">
                <a href="#inicio" aria-label="TattoFlow — voltar ao início">
                    <img src="/images/tattoflow/01_Logo_Principal_Fundo_Preto.png" alt="TattoFlow" width="1460" height="708" loading="lazy" />
                </a>
                <p>Gestão para estúdios de tatuagem.<br />Mais tempo para a sua arte.</p>
                <a className="text-link" href="/painel/login">Acessar meu estúdio <span aria-hidden="true">↗</span></a>
            </footer>
        </>
    );
}
