# Bot de assistência — editor e atendimento

Implementado no painel para empresas do perfil **Assistência técnica e loja**, com módulos WhatsApp e Ordens de serviço ativos.

## Menus

- **WhatsApp → Fluxo de atendimento**: administrador ou gerente, com permissão de gerenciar OS.
- **WhatsApp → Atendimentos da assistência**: equipe com permissão de gerenciar OS.
- Cada ação revalida empresa, usuário ativo, vínculo e permissões. IDs de versões, conversas e solicitações são consultados dentro da empresa.

## Editor

O fluxo inicial cobre solicitação de conserto, consulta de OS e interesse em capa ou película. A identificação do cliente acontece antes dos blocos e não pode ser retirada do fluxo.

Blocos disponíveis: mensagem, pergunta, menu de opções, condição e ação do sistema, além do encerramento. O usuário seleciona um bloco, edita seus textos e escolhe os próximos passos pelo nome. Pode adicionar e remover blocos e opções. Endereço e horários podem ser incluídos em mensagens do próprio fluxo.

Perguntas salvam aparelho, problema ou observação. O menu de acessório também identifica capa/película. Condição disponível: cliente possui OS em andamento. Ações permitidas: registrar solicitação de conserto, consultar OS do próprio cliente e registrar interesse em acessório. Não há execução de código, consultas livres ou seleção de outros clientes nos blocos.

Variáveis nas mensagens: `{nome}`, `{empresa}`, `{aparelho}` e `{problema}`.

- Salvar rascunho não altera o atendimento publicado. Uma revisão impede sobrescrever silenciosamente alterações de outro editor.
- Publicar valida ligações, blocos desconectados, tipos de ação e ciclos automáticos sem espera de resposta.
- Versões publicadas são imutáveis. Restaurar copia uma versão para o rascunho; exige nova publicação para utilizá-la.
- Publicar não ativa o bot. A ativação é uma ação separada, desativada por padrão.
- Conversas em andamento mantêm sua versão. Novos atendimentos usam a última versão publicada.

## Simulador

Executa o rascunho usando o mesmo executor de blocos, com dados fictícios para as ações. Pode testar cliente novo/cadastrado e com/sem OS. Não envia mensagens, cria cadastros, solicitações, vendas ou OS. Reiniciar o teste incorpora as alterações recentes do editor.

## Atendimento real

- Integração específica no `HandleWhatsAppInboundMessageJob`, antes do fallback de agendamento; assistência não exige módulo de Agenda.
- O telefone chega pelo WhatsApp e é persistido na conversa. Cliente é procurado por variantes normalizadas, somente dentro da empresa. Cadastros ambíguos ou inativos ficam para conferência da equipe.
- Para contato novo, perguntar o nome quando não foi informado. A criação do cliente usa o nome reconhecido e telefone recebido. Não habilita mensagens promocionais nem avisos operacionais futuros automaticamente.
- Solicitação de conserto gera protocolo e pendência no painel. Não significa recebimento do aparelho. O funcionário confirma o recebimento no painel e abre uma única OS, usando as regras existentes.
- Consulta de OS mostra aparelho, número e etapa, apenas dos consertos em andamento do próprio cliente. Não revela fotos, notas internas ou previsão inventada.
- Acessórios registram interesse em capa/película para um aparelho, com protocolo. Não há consulta de disponibilidade, reserva, cobrança ou baixa de estoque nesta entrega. O vínculo de produtos com aparelhos continua uma evolução separada.
- O cliente pode digitar `menu` para reiniciar o caminho atual. O atendimento é guiado por perguntas e opções; não usa um modelo de IA para extrair automaticamente todos os dados de textos livres.
- Esta versão processa texto. Fotos, áudio, anexos, seleção individual quando há várias OS e avisos automáticos por mudança de etapa não foram adicionados nesta entrega. Orçamentos e mensagens da OS continuam disponíveis pelas ações já existentes.

## Equipe e envios

- Assumir conversa persiste a pausa no banco, preserva a etapa e cancela respostas ainda na fila. Devolver ao bot é uma ação explícita; o fim da pausa temporária não reativa essa conversa automaticamente.
- Responder pelo WhatsApp segue a identificação de mensagens manuais já existente, incluindo a janela de proteção para saudação/ausência do WhatsApp Business. Para garantir a pausa imediata, usar Assumir conversa antes de responder.
- A equipe responde pelo WhatsApp conectado; o painel exibe histórico de entradas e respostas do bot, situação do envio e solicitações.
- Mensagens recebidas são deduplicadas pelo identificador do provedor, empresa e conexão. Ausência de identificador não inicia processamento. Há bloqueio por contato e serialização da criação do cliente.
- Respostas usam a fila e o controle de saída existentes, na conexão da empresa. Antes do envio, revalidar ativação e pausa humana. Resultado de transporte ambíguo fica como envio não confirmado, sem repetição automática.
- Concluir o recebimento de uma solicitação é idempotente; mensagens repetidas não recriam OS.

## Implantação

1. Aplicar `2026_11_04_180000_create_assistance_bot_flow_tables.php`.
2. Gerar os assets com `npm run build` e manter o worker da fila configurado.
3. Abrir o editor, ajustar mensagens e testar no simulador.
4. Publicar uma versão e ativar o bot somente quando a loja estiver pronta para atendimento real.

Testes usam SQLite em memória e envios simulados. A migração também foi aplicada no MySQL local. Nenhum WhatsApp real é enviado durante a verificação.
