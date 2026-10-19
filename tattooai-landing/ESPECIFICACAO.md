# TattooAI — landing page de venda

## 1. Mapa da página e texto final

**1. Hero**  
Selo: TattooAI para estúdios  
Título: O cliente manda a ideia no WhatsApp. O pedido chega pronto para orçar.  
Subtítulo: A assistente coleta nome, artista, desenho e referência. Sua equipe define o valor e envia.  
Botão: Quero ver no meu estúdio  
Apoio: A conversa continua no WhatsApp do estúdio.  
À direita, exemplo ilustrativo com sete falas, nesta ordem: “Oi! Para começar, qual é o seu nome?”; “Quero tatuar uma andorinha no braço.”; “Claro. Como posso te chamar?”; “Paulo”; “Você tem preferência de artista? Se tiver, me fala qual. Se não, pode dizer sem preferência.”; “Gustavo”; “Como você imagina o desenho?”.

**2. Problema**  
Selo: O dia a dia  
Título: O trabalho começa antes do desenho.  
Fatos: A mesma pergunta, o dia inteiro. / O pedido chega sem informação para orçar. / O tatuador para a sessão para responder.

**3. Percurso**  
Selo: O percurso  
Título: Da primeira mensagem ao pedido.  
01 Nome — A assistente identifica quem está pedindo.  
02 Preferência de artista — O cliente informa um nome ou diz sem preferência.  
03 Desenho e referência — Entram ideia, parte do corpo, tamanho, estilo e imagem.  
04 Pedido na fila — A equipe recebe o contexto organizado.  
05 Orçamento — A equipe define o valor e envia pelo WhatsApp.

**4. O valor**  
Selo: O valor fica com a equipe  
Título: Quem conhece o trabalho define o preço.  
Texto: A assistente não inventa preço. Se o cliente indicar um artista do estúdio, a preferência entra no pedido para a equipe orçar o trabalho daquele artista. Sem preferência segue sem tatuador fixo.

**5. Prova de produto**  
Selo: No painel  
Título: O pedido chega com o que sua equipe precisa ler.  
Apoio: Uma ideia, os detalhes e a preferência de artista no mesmo lugar.  
Cartão ilustrativo: Pedido de tatuagem / Aguardando orçamento / Cliente Paulo / Artista preferido Gustavo / Ideia Andorinha em traço fino / Local Antebraço / Tamanho 12 cm / Estilo Traço fino / Referência Foto recebida / Fazer orçamento.

**6. Depois do orçamento**  
Selo: Depois do orçamento  
Título: A conversa segue de onde começou.  
Linhas: O orçamento vai pelo WhatsApp do estúdio. / O sinal e o comprovante entram na conversa. / Depois, vem o agendamento.

**7. Fecho**  
Selo: TattooAI  
Título: O cliente manda a ideia no WhatsApp. O pedido chega pronto para orçar.  
Botão: Quero ver no meu estúdio.

## 2. Especificação visual

- Fundo `#f6f4ef`; texto `#12110f`; acento único em dourado profundo `#8a611f`, aplicado aos botões principais, ao ponto da marca e ao indicador do mock.
- Títulos com `Newsreader` ou `Fraunces`, fallback Georgia; texto com `Inter` ou `Geist`, fallback Arial. Fontes não são carregadas de serviços externos.
- Container de até 1160 px; texto corrido até 640 px; respiro entre seções de 120 px no desktop, 88 px em tablets e 72 px em telas pequenas.
- Título hero de até 54 px no desktop, tracking `-.045em`, largura para aproximadamente três linhas. Títulos secundários de até 56 px.
- Mock e cartão com borda de 1 px `#dcd9d2`, raio de 16 px e sombra `0 12px 30px rgba(18,17,15,.045)`. Botão com raio de 9 px.
- Botão principal: dourado `#8a611f`, texto branco; hover `#735019`; pressionado `#5c3f14`; foco visível com contorno `#c9aa74`. O botão do cartão é somente parte da demonstração, por isso aparece desabilitado.
- Em 390 px, conteúdo em uma coluna, margens de 18 px e etapas em linhas compactas. A conversa segue plana, sem moldura de telefone.

## 3. App React (Vite)

Os arquivos prontos estão em `package.json`, `index.html`, `src/main.jsx`, `src/App.jsx`, `src/components/{Hero,Problem,Path,Price,Proof,After,Close}.jsx` e `src/styles.css`. Não há TypeScript, Tailwind, biblioteca de UI, JavaScript de animação ou imagens externas. Vite é a única dependência de desenvolvimento; React e React DOM são as únicas dependências de execução.

Para rodar: `npm install` e `npm run dev`. Antes de publicar, configure `VITE_TATTOOAI_CTA_URL` com o destino comercial real. Sem essa variável, o botão aponta para o fecho da própria página como marcador local.

## 4. Fora da página, por decisão de escopo

- Depoimentos, números e logotipos de clientes não verificados.
- Preço automático, escolha automática de artista ou confirmação automática de pagamento.
- Galeria de flash, fotos de braços, estética de tinta, caveiras e decoração temática.
- Formulário longo, tabela de planos, ícones ilustrativos e mais de um CTA.
