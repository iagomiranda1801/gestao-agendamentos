# Análise da identidade visual TattoFlow

## Correção do kit em 09/11/2026

O usuário substituiu o kit analisado abaixo por `C:\Users\IAGO\Downloads\TattooFlow_Identidade_Visual_Corrigida`. A grafia correta é **TattooFlow**. A análise original abaixo fica como histórico e não define mais a grafia nem os arquivos da marca.

O kit corrigido está em `public/images/tattooflow/`. A landing e o cadastro usam a nova logo, o favicon `09_Favicon.ico` e o ícone de aplicativo do novo kit. A imagem de compartilhamento usa a logo principal corrigida, com suas dimensões reais de 1045 × 395. Os arquivos do kit antigo foram removidos.

Data: 09/11/2026.

## Material analisado

Kit `C:\Users\IAGO\Downloads\TattoFlow_Kit_Identidade_Visual.zip`: 11 imagens PNG, um favicon ICO e um LEIA-ME. Conferidos visualmente o guia, a apresentação, a logo transparente, o símbolo transparente e o favicon PNG.

A identidade aprovada usa a grafia **TattoFlow**. A logo combina lettering serifado inclinado, máquina de tatuagem, arco, folhas e acabamento dourado. O guia recomenda preservar proporções e área livre, preferir fundo preto para a versão original e usar a versão escura sobre fundo claro.

## Paleta e direção de aplicação

| Cor | Código do kit | Aplicação proposta |
| --- | --- | --- |
| Preto profundo | `#0C0B0A` | Fundo principal e cabeçalho |
| Dourado champagne | `#C9A66B` | Botões, destaques e detalhes |
| Off-white | `#F6F4EF` | Títulos e texto principal |
| Dourado escuro | `#977044` | Bordas e detalhes secundários, com contraste verificado |

A landing integrada já segue uma direção preta e dourada, mas usa valores aproximados. A alteração pode preservar sua estrutura e alinhar as variáveis à paleta oficial. O acabamento metálico pode ficar concentrado na logo, com menos gradientes nos demais elementos.

O kit não inclui fontes nem especifica uma família tipográfica para a interface. Cormorant Garamond e Outfit, já referenciadas pela landing, são uma proposta de continuidade; não devem ser tratadas como fontes oficiais do kit. Usar a imagem aprovada para a marca, sem reconstruí-la com fonte comum.

## Onde está a landing integrada

- `routes/web.php` vincula a raiz do domínio configurado para tatuagem à view `landing.tattoo`.
- `resources/views/landing/tattoo.blade.php` contém título, fontes e entrada Vite.
- `resources/js/landing/` contém os componentes React usados por essa view.
- `resources/css/landing.css` contém os estilos.

Existe também `tattooai-landing/`, um projeto Vite separado, com estrutura e especificação próprias. Ele não é a entrada utilizada pela rota Laravel examinada. Evitar aplicar o trabalho apenas nessa cópia e deixar a página integrada desatualizada.

## Alterações propostas, em ordem de prioridade

1. Substituir “TatooAI” por “TattoFlow” no título HTML, cabeçalho, hero e fechamento; incluir descrição da página.
2. Integrar a logo oficial ao cabeçalho e ao fechamento, com tamanho responsivo, proporções preservadas e texto alternativo. Aplicar favicon PNG/ICO. A view atual não declara favicon.
3. Atualizar as cores principais do CSS e revisar contraste de textos pequenos, horários, links, foco e botões.
4. Ajustar cabeçalho e navegação para acomodar a logo real em telas pequenas; verificar a altura do cabeçalho fixo e o deslocamento das âncoras.
5. Manter a apresentação do fluxo WhatsApp → pedido → orçamento → sinal → agendamento. A marca identifica um produto de gestão para estúdios; a página deve continuar explicando esse produto.
6. Revisar a conversa ilustrativa, que repete “Qual seu nome?” e deixa a coleta incompleta. Tornar o exemplo coerente com os dados do cartão de pedido.
7. Rever os CTAs: hoje todos os botões principais apontam para `/painel/login`, coerente com o caminho `painel` definido em `TattooPanelProvider` (o identificador do painel é `estudio`). Separar o acesso de quem já é cliente de uma eventual ação comercial, cujo destino ainda precisa ser definido.
8. Identificar claramente o cartão de pedido como demonstração. Seu link “Fazer orçamento” hoje leva ao login e pode sugerir uma ação real sobre o exemplo.

## Qualidade e uso dos arquivos

O próprio LEIA-ME informa que logos transparentes e ícones foram extraídos de uma arte raster, com possíveis artefatos nas bordas. A visualização da logo transparente apresenta ruído aparente; avaliar sua composição sobre o fundo final antes de escolhê-la para o cabeçalho. A versão original com fundo preto pode ser a opção mais fiel, desde que a diferença entre seu preto e o fundo da página seja tratada.

O símbolo transparente e o favicon têm bastante área vazia. Testar sua legibilidade no tamanho real, especialmente em 16 e 32 pixels; o arquivo de 256 pixels não garante reconhecimento nesses tamanhos. Não converter PNG em um suposto SVG apenas por encapsulamento.

## Validação necessária na implementação

- Conferir layout em 360/390 px, tablet e desktop, incluindo navegação sem estouro horizontal.
- Verificar legibilidade da marca, margens, contraste, foco por teclado e âncoras.
- Conferir destinos dos links e carregamento dos assets.
- Executar o build Vite do projeto Laravel após as alterações.

Esta etapa é uma análise dos arquivos e do código. Não houve alteração da landing, build, publicação ou validação visual da página em execução.

## Implementação após autorização

Identidade aplicada na landing integrada: logo original em fundo preto no cabeçalho, fechamento e rodapé; paleta oficial; favicon; imagem Open Graph; título e descrição TattoFlow. O kit completo foi copiado para `public/images/tattoflow/`, preservando as versões alternativas para futuras aplicações.

O hero, a conversa demonstrativa e a apresentação do pedido foram revisados. A navegação usa âncoras; os acessos continuam em `/painel/login`. Não foi criado destino comercial sem configuração existente.

Validação: `npm run build` concluído, página Laravel carregada em servidor temporário local, inspeção visual em 1440 e 390 pixels, âncoras válidas, sem rolagem horizontal nessas larguras e sem erros no console da página. Publicação não realizada.
