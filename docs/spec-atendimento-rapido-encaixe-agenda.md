# Especificação — Atendimento rápido e encaixe na agenda

**Status:** proposta, sem implementação  
**Versão:** 1.0  
**Data:** 01/10/2026  
**Produto:** Agendaqui

## 1. Objetivo

Permitir que a equipe de qualquer empresa que usa a agenda registre um cliente que chegou para ser atendido agora e crie um encaixe quando decidir ocupar um horário que conflita com outro agendamento. Os dois fluxos são internos e independem do bot, da agenda pública e do perfil de negócio.

## 2. Termos e decisões

| Ação | Significado | Resultado inicial |
|---|---|---|
| **Atender agora** | O cliente chegou e o profissional vai começar o serviço. | Compromisso na agenda em estado **Em atendimento**, com início real registrado. |
| **Criar encaixe** | A equipe reserva um horário mesmo sabendo que ele se sobrepõe a outro compromisso do profissional. | Agendamento **Confirmado**, identificado como **Encaixe**. |

- As duas ações usam a estrutura de agendamentos. O registro financeiro de atendimento continua sendo criado apenas na conclusão, pelo fluxo existente. Não haverá atendimento financeiro vazio ou receita gerada ao iniciar.
- **Encaixe** é uma característica da criação do agendamento; **Confirmado** e **Em atendimento** continuam sendo estados. Um encaixe pode depois ser iniciado e concluído.
- O sistema nunca transforma silenciosamente um agendamento comum em encaixe. A equipe vê o conflito e confirma a exceção.
- O cadastro retroativo de um atendimento já realizado fica fora da primeira versão. **Atender agora** registra o momento atual.

## 3. Experiência na agenda

### 3.1 Entrada

Na agenda e na lista de agendamentos, apresentar as ações **Atender agora** e **Criar encaixe** junto de **Novo agendamento**. Ao abrir a partir de uma data, horário ou profissional da agenda, preencher esses dados automaticamente. As ações só aparecem para usuários com as permissões correspondentes.

### 3.2 Atender agora

1. A equipe pesquisa o cliente por nome ou telefone; se necessário, cadastra nome e telefone sem sair da ação.
2. Seleciona o profissional e o serviço. Pode escolher **Definir no atendimento**, informando a duração prevista.
3. A tela mostra a hora atual no fuso da empresa, a duração e o fim previsto. A opção de confirmação por WhatsApp começa desligada, mas pode ser ligada manualmente.
4. Ao confirmar, o sistema verifica as regras aplicáveis, cria o registro na agenda e o inicia na mesma operação. Registra quem criou e iniciou e a hora efetiva do início. A equipe é levada à tela do atendimento em andamento.
5. A conclusão usa a ação atual de concluir atendimento, com serviço realizado, valor, materiais, pagamentos, comissão e permissões existentes.

O início deve corresponder à hora real da ação, sem ser arredondado para o próximo intervalo de agendamento. A verificação de disponibilidade precisa admitir essa exceção ao alinhamento de horários e tolerar os segundos transcorridos durante a gravação. A duração prevista continua obrigatória para ocupar a agenda. Se houver sobreposição com outro agendamento, a tela apresenta o conflito e exige a confirmação explícita de **encaixe**, com as mesmas regras da seção 3.3.

### 3.3 Criar encaixe

1. A equipe seleciona cliente, profissional, serviço ou **Definir no atendimento**, data, hora e duração prevista.
2. O sistema mostra os agendamentos que se sobrepõem, incluindo os intervalos de preparação e finalização configurados para cada compromisso. Exibe cliente, horário e serviço dos registros afetados para que a decisão seja informada.
3. A equipe informa um motivo curto para o encaixe e confirma a exceção.
4. O sistema revalida o conflito ao salvar, registra o agendamento como **Confirmado** e o identifica como **Encaixe** na agenda, no detalhe e no histórico.

O encaixe dispensa somente a regra de conflito com outro agendamento. Continuam obrigatórios: empresa e profissional ativos, vínculo válido do serviço com o profissional, horário de funcionamento, jornada, pausas, bloqueios, duração e regras de data futura. Criar encaixe sem conflito deve ser permitido, pois outro compromisso pode ter sido alterado entre a abertura e a confirmação; ele permanece identificado como encaixe e o histórico registra que não havia conflito no momento de salvar.

## 4. Situação atual e mudanças necessárias

- `AppointmentService::createInternalAppointment()` cria agendamentos confirmados, verifica disponibilidade e já grava histórico. O novo fluxo pode reutilizar a validação de cliente, profissional, serviço, duração e snapshots, mas precisa de uma opção explícita e restrita para ignorar somente conflitos entre agendamentos.
- `AvailabilityService` hoje rejeita conflito e exige alinhamento ao intervalo da agenda. Para **Atender agora**, a exceção ao alinhamento deve ser específica; para **Criar encaixe**, a exceção ao conflito deve ser específica. As demais verificações não podem ser puladas por essas opções.
- `AppointmentStatusService::start()` já leva um compromisso confirmado para **Em atendimento**. A criação e o início do atendimento rápido devem formar uma operação consistente: uma falha no início não pode deixar um agendamento confirmado que a equipe acreditou ter iniciado.
- `AttendanceCompletionService` já cria o atendimento financeiro a partir de um agendamento confirmado ou em andamento. As novas origens devem seguir esse caminho, sem criar atendimento duplicado.
- A criação rápida de cliente e a opção **Definir no atendimento** já existem no formulário de agendamento e devem ser reaproveitadas.

Para identificar os registros, adicionar uma classificação interna de fluxo ao agendamento, por exemplo `normal`, `quick_attendance` e `fit_in`, com valor padrão `normal` para registros existentes. O estado do compromisso permanece no campo de status atual. Para encaixes, registrar motivo, usuário que autorizou e momento da autorização; o histórico deve guardar os compromissos que estavam em conflito no momento da criação. Esses dados são internos e não devem ir para mensagens ao cliente.

## 5. Regras operacionais

- **Permissões:** Atender agora exige permissão para criar agendamento e iniciar atendimento. Criar encaixe exige permissão para gerenciar a agenda; a autorização de sobreposição deve ser auditada com usuário e motivo. Concluir e alterar valores seguem as permissões atuais.
- **Empresa:** cliente, profissional, serviço e agendamento devem pertencer à mesma empresa. Consultas de conflito e telas não podem mostrar dados de outra empresa.
- **Concorrência:** disponibilidade, conflitos e autorização são conferidos novamente ao salvar, sob a mesma proteção transacional usada na criação atual. Um segundo usuário que marque outro horário durante o preenchimento não deve gerar um encaixe implícito.
- **Agenda:** os registros ocupam o período previsto e são distinguíveis por texto e indicador visual, além de cor. Um encaixe pode coexistir com outro compromisso sem ocultá-lo.
- **Edição e cancelamento:** seguem as regras do estado atual. Alterar o horário de um encaixe exige nova checagem dos conflitos e mantém o histórico da autorização original; uma nova sobreposição exige nova confirmação. Um atendimento iniciado segue as restrições atuais de edição e cancelamento.
- **Notificações:** para atendimento rápido, não enviar confirmação automática por padrão, pois o cliente já está presente. Para encaixe, a opção segue visível e a mensagem comunica o horário confirmado, sem mencionar conflitos internos. Automações e bot permanecem sem mudança nesta fase.
- **Relatórios e financeiro:** as duas origens aparecem em filtros e contagens operacionais. Receita, comissão, contas a receber e pagamentos continuam vindo somente do atendimento concluído. Não contar o registro duas vezes.
- **Fuso:** datas e horas mostradas à equipe usam o fuso da empresa; armazenamento e comparação seguem o padrão UTC existente.

## 6. Critérios de aceite

1. Usuário autorizado inicia um atendimento agora com cliente existente ou criado na hora, profissional, serviço ou duração a definir, e vê o registro em **Em atendimento** na agenda.
2. O horário efetivo do atendimento rápido é o momento da ação, mesmo quando não coincide com o intervalo configurado para agendamentos.
3. A conclusão de um atendimento rápido cria um único atendimento financeiro e segue as mesmas regras de valor, pagamento, comissão e material de um agendamento comum.
4. Ao criar um encaixe, a tela mostra os compromissos afetados, exige motivo e confirmação, e grava o usuário responsável pela exceção.
5. O encaixe aparece como **Confirmado** e **Encaixe** na agenda; depois pode ser iniciado e concluído normalmente.
6. Conflitos com outro agendamento podem ser autorizados apenas por essas ações explícitas. Bloqueios, pausas, jornada, funcionamento e vínculos inválidos continuam impedindo a gravação.
7. Uma alteração concorrente na agenda é percebida antes de salvar; não há sobreposição involuntária nem registros parciais.
8. Usuários sem permissão não veem ou não conseguem executar as ações, inclusive por chamada direta. Dados e conflitos de outra empresa nunca são exibidos.
9. Não há envio de WhatsApp ao criar atendimento rápido, salvo escolha explícita da equipe. O bot, a agenda pública e as automações atuais mantêm seu comportamento.

## 7. Fora do escopo inicial

- Lançar atendimento já realizado em data passada.
- Permitir exceção manual a bloqueios, pausas, jornada ou funcionamento.
- Criar encaixes por bot, agenda pública ou integração externa.
- Atendimento com vários profissionais ou divisão de um mesmo compromisso em vários atendimentos.
