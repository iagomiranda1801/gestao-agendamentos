# Especificação — Agendamento com procedimento a definir

**Status:** proposta  
**Versão:** 1.0  
**Data:** 25/08/2026  
**Produto:** Agendaqui

## 1. Objetivo

Permitir que clínicas criem um agendamento quando o procedimento e o preço ainda não são conhecidos, sem comprometer a disponibilidade da agenda nem misturar previsão com receita realizada.

O fluxo deve atender principalmente consultas de avaliação, triagem e retornos nos quais o profissional define o procedimento e o valor somente após avaliar o paciente.

## 2. Decisões de produto

1. Um agendamento sempre terá paciente, profissional, início e duração. A duração continua obrigatória porque ela bloqueia a agenda.
2. O procedimento previsto deixa de ser obrigatório no agendamento interno. O agendamento poderá ficar no modo **A definir no atendimento**.
3. O preço previsto não será usado como preço final. No modo a definir, a interface apresentará **A combinar**.
4. A conclusão de um atendimento originado nesse modo exigirá o registro do procedimento realizado e do valor bruto final.
5. O procedimento realizado e o valor final serão a fonte para financeiro, comissões, materiais e relatórios. O dado originalmente previsto no agendamento será preservado como histórico.
6. Agendamento online permanece baseado em serviço definido nesta primeira fase. O modo a definir é inicialmente interno, criado pela recepção ou equipe clínica.

## 3. Situação atual e impacto

Atualmente, `appointments.service_id` é obrigatório e o serviço fornece duração, preço, buffers, vínculo com profissional, consumo previsto de materiais e regra de comissão. O atendimento concluído também possui um único `service_id` e recebe, por padrão, o preço registrado no agendamento.

Isso significa que tornar apenas `service_id` nulo não é uma solução suficiente: a agenda perderia a duração e o financeiro perderia a referência necessária para comissão e materiais.

## 4. Escopo funcional

### 4.1 Tipos de agendamento

Será introduzido o campo `service_selection_mode` em `appointments`:

| Valor | Rótulo | Uso |
|---|---|---|
| `defined` | Serviço definido | Fluxo existente: serviço, duração e preço previstos vêm do cadastro do serviço/profissional. |
| `to_be_defined` | A definir no atendimento | Avaliação ou consulta em que ainda não há procedimento e preço definidos. |

O valor padrão será `defined`, preservando todo o comportamento existente.

### 4.2 Criação e edição de agendamento interno

No formulário de agendamento interno, será exibida uma escolha entre:

- **Selecionar serviço**;
- **Definir no atendimento**.

Quando o modo for `defined`:

- o serviço permanece obrigatório;
- profissional elegível, duração, buffers e preço previsto seguem as regras atuais.

Quando o modo for `to_be_defined`:

- o campo serviço deixa de ser obrigatório e fica oculto;
- profissional continua obrigatório;
- data e hora continuam obrigatórias;
- duração prevista passa a ser obrigatória e editável, limitada a um intervalo configurável (padrão: 15 a 480 minutos);
- buffers podem usar o padrão da empresa ou ser definidos pela equipe com permissão de agenda;
- o campo **motivo da consulta** será opcional e visível para orientar o profissional;
- o preço previsto será nulo e a interface mostrará **A combinar**;
- não haverá filtro de elegibilidade por serviço; o profissional precisa apenas estar ativo e disponível para agenda.

Depois de iniciado ou concluído, o tipo do agendamento não poderá ser alterado.

### 4.3 Agenda, conflito e histórico

Os conflitos de agenda continuarão sendo calculados por profissional, horário, duração e buffers gravados no agendamento. Portanto, o modo a definir ocupa horário normalmente.

Na agenda e nas notificações internas, o título deverá ser:

`Paciente — A definir` ou `Paciente — Avaliação`, quando houver motivo configurado.

O histórico do agendamento deve registrar criação, edição e eventual definição posterior do procedimento, incluindo usuário, data e valores anterior/novo.

### 4.4 Conclusão do atendimento

Para `service_selection_mode = defined`, o fluxo atual continua: o sistema sugere o serviço previsto e permite que usuários financeiros autorizados ajustem o valor bruto.

Para `service_selection_mode = to_be_defined`, a etapa **Valores** deve exigir:

- o procedimento realizado;
- o valor bruto final;
- desconto e pagamentos, conforme as regras existentes.

O sistema deve copiar para o atendimento os dados efetivamente executados. A comissão deve ser calculada usando o procedimento realizado, nunca um serviço genérico de agendamento. Materiais continuam sendo registrados no atendimento.

### 4.5 Atendimento com múltiplos procedimentos

O primeiro lançamento suporta um único procedimento realizado por atendimento. Ele é gravado em `attendances.service_id`, preservando compatibilidade com financeiro, comissão e relatórios existentes.

Uma evolução posterior poderá criar `attendance_service_items` para suportar vários procedimentos, incluindo rateio de desconto, materiais e comissão por item.

## 5. Modelo de dados proposto

### 5.1 `appointments`

- adicionar `service_selection_mode` (`defined` por padrão);
- tornar `service_id` anulável;
- tornar `service_name_snapshot` e `price_snapshot` anuláveis;
- manter `duration_minutes_snapshot`, `buffer_before_minutes_snapshot` e `buffer_after_minutes_snapshot` obrigatórios;
- adicionar `appointment_reason` anulável.

Para registros já existentes, o modo será preenchido como `defined`; nenhum dado histórico será alterado.

### 5.2 `attendances`

- manter os campos financeiros agregados (`gross_amount`, `final_amount`, comissão total e resultado operacional) no cabeçalho do atendimento;
- `service_id` e `service_name_snapshot` receberão o procedimento efetivamente realizado quando o agendamento estiver a definir;
- materiais poderão continuar registrados em `attendance_materials`, vinculados ao atendimento.

## 6. Regras de autorização

- Usuários com permissão de criar/editar agendamento podem usar o modo a definir.
- Apenas usuários que já podem concluir atendimento podem informar procedimentos realizados.
- Alterar valores, descontos, comissões e data de conclusão mantém as permissões financeiras atuais.
- A eventual exceção para registrar procedimento não associado ao profissional deve exigir permissão explícita de administrador/gerente e ser auditada.

## 7. Relatórios e integrações

- Receita, comissão, DRE, contas a receber e pagamentos devem usar os valores do atendimento concluído, como já ocorre.
- Relatórios por procedimento devem usar `attendance_service_items` para não atribuir faturamento ao rótulo “A definir”.
- O agendamento pode continuar aparecendo em indicadores operacionais como “consulta a definir”, mas não pode gerar receita prevista baseada em R$ 0.
- Mensagens de WhatsApp e tela interna devem informar “Procedimento a definir” quando não houver serviço, sem prometer preço.
- O agendamento online não exibirá essa opção na primeira fase. Uma futura fase poderá oferecer “Solicitar avaliação” com duração e regras configuráveis pela empresa.

## 8. Estratégia de implementação

### Fase 1 — Agendamento interno a definir

1. Adicionar `service_selection_mode`, `appointment_reason` e nulabilidade dos campos de serviço no agendamento.
2. Adaptar criação, edição, disponibilidade, calendário, validações e mensagens para aceitar agendamento sem serviço.
3. Permitir duração manual e usar preço nulo/“A combinar”.
4. Garantir testes de isolamento por empresa e de conflito de horários.

### Fase 2 — Procedimento realizado na conclusão

1. Alterar a conclusão para exigir o procedimento em agendamentos a definir.
2. Calcular comissão com base no procedimento realizado.
3. Atualizar a visualização do atendimento.

### Fase 3 — Opcional: agendamento online de avaliação

1. Criar configuração por empresa para habilitar “Solicitar avaliação”.
2. Configurar duração, buffers, profissionais elegíveis e texto público.
3. Apresentar “Valor a confirmar após avaliação”, sem preço numérico.

## 9. Critérios de aceite

1. A recepção consegue criar um agendamento interno sem selecionar serviço, informando profissional, horário e duração.
2. Um agendamento a definir bloqueia a agenda e respeita jornada, pausas, bloqueios e conflitos do profissional.
3. Um agendamento com serviço definido mantém o comportamento atual sem regressões.
4. No modo a definir, nenhuma tela pública ou interna apresenta R$ 0 como se fosse preço do atendimento; o texto é “A combinar”.
5. Não é possível concluir um agendamento a definir sem registrar o procedimento realizado e o valor bruto correspondente.
6. O valor do atendimento, contas a receber, pagamentos, comissão e DRE refletem o procedimento efetivamente realizado.
7. Relatórios por serviço não contabilizam receita em “A definir”.
8. Dados anteriores à migração continuam consultáveis e permanecem no modo `defined`.
9. Todas as mudanças respeitam `company_id`, permissões existentes e são auditáveis no histórico.

## 10. Fora do escopo inicial

- orçamento formal com aceite do paciente;
- parcelamento ou regras comerciais específicas por procedimento;
- alteração de procedimentos após o atendimento já concluído;
- agendamento online de avaliação;
- divisão de um item realizado entre múltiplos profissionais.
