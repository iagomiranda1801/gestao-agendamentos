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
            <Hero />
            <Problem />
            <Path />
            <Price />
            <Proof />
            <After />
            <Close />
        </>
    );
}
