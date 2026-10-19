import Hero from './components/Hero.jsx';
import Problem from './components/Problem.jsx';
import Path from './components/Path.jsx';
import Price from './components/Price.jsx';
import Proof from './components/Proof.jsx';
import After from './components/After.jsx';
import Close from './components/Close.jsx';

// Defina VITE_TATTOOAI_CTA_URL com o destino comercial antes de publicar.
const ctaUrl = import.meta.env.VITE_TATTOOAI_CTA_URL || '#contato';

export default function App() {
  return (
    <>
      <header className="site-header container" aria-label="TattooAI">
        <span className="wordmark">TattooAI<span className="wordmark-dot">.</span></span>
        <span className="header-note">Atendimento para estúdios de tatuagem</span>
      </header>
      <main>
        <Hero ctaUrl={ctaUrl} />
        <Problem />
        <Path />
        <Price />
        <Proof />
        <After />
        <Close ctaUrl={ctaUrl} />
      </main>
      <footer className="site-footer container"><span>TattooAI</span><span>Pedidos de tatuagem, com contexto.</span></footer>
    </>
  );
}
