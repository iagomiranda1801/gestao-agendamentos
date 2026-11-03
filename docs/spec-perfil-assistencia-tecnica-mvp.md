# Perfil Assistência técnica e loja — MVP

## Objetivo

Operação de balcão completo para receber celulares, enviar OS e orçamentos pelo WhatsApp, acompanhar consertos, controlar peças, cobrar e vender produtos. A experiência centraliza cada conserto em uma OS, com cadastro curto, detalhes adicionais recolhidos e ações em linguagem de balcão. Deve funcionar em computador e celular.

## Perfil e integrações

- `CompanyProfile::TechnicalAssistance` / `technical_assistance`: Assistência técnica e loja.
- Novo módulo `CompanyModule::ServiceOrders` / `service_orders`, independente de pedidos de restaurante.
- Módulos sugeridos: Ordens de serviço, Vendas, Estoque, Financeiro e WhatsApp. Agenda opcional.
- Usar seleção de perfil, provisionamento, assinatura, permissões e navegação existentes. Não criar novo domínio, marca ou painel.
- Nenhuma empresa existente muda automaticamente de perfil; a seleção de módulos continua revisável.
- Tela inicial com Receber aparelho, Ver consertos e Vender produto, conforme permissões.
- Nova permissão `manage_service_orders`, padrão para administrador, gerente, recepção e colaborador. Pagamentos exigem também `view_financial`. Permissões explícitas existentes continuam prevalecendo.

## Dados e isolamento

- `service_orders`: empresa, número sequencial único por empresa, cliente, aparelho, problema, marca/modelo/IMEI opcionais, estado de entrada/checklist, acessórios, observações internas, informações de garantia, etapa, venda associada e tokens privados separados de OS e comprovante.
- `service_order_quotes`: OS, versão única por OS, itens e preços congelados, total decimal, prazo textual, validade, condições, token privado, publicação, decisão, data, origem e funcionário responsável pela aprovação presencial.
- `service_order_events`: OS, evento, autor quando aplicável e data.
- `service_order_messages`: OS, orçamento quando aplicável, tipo, destinatário, conteúdo revisado, tentativa, estado, erro e referência do provedor.
- `service_order_photos`: OS e caminho no disco privado local; download autenticado com validação da empresa e permissão. Nunca expor fotos em documentos públicos.
- Criar migração aditiva com chaves estrangeiras, índices únicos e bloqueios transacionais. Não apagar OS nem seus registros operacionais pela interface.
- Todos os serviços validam empresa, usuário ativo, vínculo, módulo e permissão. Referências a clientes e produtos são consultadas dentro da empresa.

## Recebimento

1. Buscar cliente por nome ou telefone; mostrar nome e telefone nos resultados. Criar cliente pelo mesmo formulário quando necessário, com nome e telefone válidos, sem duplicar telefone já encontrado na empresa.
2. Exigir cliente, identificação do aparelho e problema relatado. Detalhes de recebimento são opcionais. Não solicitar senha de desbloqueio.
3. Gerar número da OS de forma transacional e abrir sua tela com ação Enviar OS pelo WhatsApp.
4. Manter dados de recebimento preservados após criação. Observações internas e garantia podem ser atualizadas; fotos são adicionadas na própria OS.

## Orçamento e aprovação

- Itens: serviço do catálogo, mão de obra avulsa e peças/produtos ativos da empresa. Preço e quantidade explícitos. Prazo e validade obrigatórios.
- Peças podem ser produtos de venda ou consumo. Serviços de catálogo representam mão de obra; cadastrar as peças explicitamente para evitar consumo implícito adicional ou alterações após aprovação.
- Criar nova versão em vez de editar orçamento. Criar outra versão invalida a aprovação anterior e retorna a OS para análise. Não alterar conteúdo de versão publicada.
- Enviar orçamento disponibiliza a versão e coloca a OS em Aguardando aprovação. Uma falha de WhatsApp não altera seus valores nem aprova o orçamento.
- Link privado sem cadastro, com visualização dos itens, total, prazo, validade e condições. GET nunca decide. Aprovar e recusar são ações POST com proteção CSRF.
- Registrar decisão, versão, data e origem. Aprovação presencial exige confirmação explícita e registra o funcionário.
- Versão substituída, expirada, já respondida ou OS fora da etapa permitida não aceita decisão. Bloquear a OS durante decisões concorrentes.
- Recusa permite novo orçamento ou devolução sem conserto, sem venda.

## Etapas e conclusão

- Fluxo principal: Em análise → Aguardando aprovação → Aprovado → Em conserto → Pronto para retirada → Entregue.
- Aguardando peça disponível depois da aprovação, inclusive durante conserto. Devolvido sem conserto é um encerramento separado antes de concluir o reparo.
- Conserto exige aprovação da versão atual. Novo orçamento durante reparo interrompe esse avanço até nova aprovação.
- Orçar e aprovar não movimentam estoque.
- Concluir conserto exige confirmação de que as peças e valores correspondem ao orçamento aprovado. Divergências exigem nova versão e aprovação.
- Conclusão cria uma única venda `service_order`, recebível em aberto e baixa das peças. Referência única `service-order:{id}` e bloqueio da OS protegem contra conclusão repetida. Estoque insuficiente desfaz a transação inteira.
- Reutilizar `SaleService`, serviços de estoque e contas a receber. Produtos de consumo são aceitos somente na origem de OS; PDV mantém suas regras atuais.
- Registrar pagamento na OS usando contas, formas, caixa e regras de pagamento parcial existentes. Chave UUID por comando impede registrar novamente a mesma solicitação.
- Entrega com saldo pendente exige confirmação explícita de administrador ou gerente. Venda ou recebível cancelados e estoque estornado impedem entregar como conserto concluído.
- Após conclusão, correções usam os serviços existentes de cancelamento de pagamento/recebível e estorno de estoque, com motivo e acesso restrito. Não apagar histórico.
- Venda avulsa continua no PDV sem OS.

## WhatsApp e documentos

- Envio manual por clique, com texto pronto e revisão: Enviar OS, Enviar orçamento, Avisar que está pronto, Enviar comprovante.
- Usar conexão da própria empresa, fila e controle de saída existentes. Respeitar autorização do cliente para mensagens operacionais.
- Mensagens contêm resumo e link privado. Página permite imprimir/salvar PDF pelo navegador. Não implementar anexo de PDF nesta versão.
- OS e comprovante usam tokens diferentes; comprovante disponível somente após entrega. Cada orçamento tem seu token. Tokens aleatórios de 64 caracteres, sem IDs enumeráveis.
- Nunca publicar observações internas ou fotos. Páginas sem indexação, sem cache e sem envio de referência.
- Estados de mensagem: na fila, enviando, aceito pelo provedor, falhou ou envio não confirmado. Não chamar aceitação de entrega/leitura.
- Deduplicar solicitações iguais em andamento ou aceitas. Falha antes de envio permite tentar novamente. Resultado de transporte ambíguo não reenvia automaticamente: funcionário confere a conversa e confirma outra tentativa.
- Orçamento substituído na fila não deve ser enviado. WhatsApp desconectado não bloqueia o trabalho na OS.

## Validação e implantação

### Interfaces públicas

| Método e caminho | Finalidade |
|---|---|
| `GET /os/documento/{token}` | OS ou comprovante, conforme token distinto |
| `GET /os/orcamento/{token}` | Visualizar versão publicada e opções de decisão |
| `POST /os/orcamento/{token}` | Decidir com `decision=approved` ou `decision=declined`; requer CSRF |
| `GET /os/fotos/{photo}` | Foto interna; exige usuário autenticado e permissão na empresa |

As rotas públicas possuem limitação de frequência. Uma falha de validação na decisão redireciona com os erros da sessão. Identificadores internos e nomes de rotas dos demais módulos continuam válidos.

### Verificações

- Testes de perfil/módulos, provisionamento e permissões, CRUD Filament, busca e criação de cliente, escopo entre empresas e fotos privadas.
- Testes de decisão única, expiração, substituição, recusa, aprovação presencial e imutabilidade dos valores.
- Testes de saldo insuficiente, baixa única, venda/recebível únicos, pagamentos parciais e repetidos, entrega com saldo e estornos.
- Testes de fila, deduplicação, envio substituído, falta de conexão e falha de transporte sem reenvio automático.
- Executar regressões de vendas, estoque, financeiro, perfis e WhatsApp afetados. Validar interface em computador e celular.
- Aplicar migração antes de selecionar o perfil. Manter worker da fila configurada em execução. Testes usam SQLite em memória; nenhum envio real é necessário para a suíte.
- Conferência visual: documentos públicos em 1280 px e 390 px; painel e OS no navegador com dados fictícios e banco SQLite isolado.
- Na regressão ampliada, duas falhas também foram reproduzidas sobre os fontes originais de `HEAD`: `CompanyProfilePageTest` (Salão espera 200, recebe redirecionamento 302) e `WhatsAppOutboundGateTest::test_ten_inline_jobs_do_not_hit_evolution_in_a_burst` (espera um envio, recebe zero). Não alterar esses comportamentos como parte deste perfil.

## Limites

Reutilizar papéis existentes. Sem gestão especializada de técnicos, emissão fiscal, bot de aprovação pela conversa, pagamentos online ou mensagens automáticas. Garantia é texto informado pela loja; retorno em garantia fica para etapa posterior.
