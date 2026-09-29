# Especificação — Perfil Personal trainer (MVP)

**Status:** MVP implementado (29/09/2026)  
**Data:** 29/09/2026  
**Público inicial:** personal trainer autônomo, com atendimento individual

## 1. Objetivo

Permitir que um personal autônomo cadastre alunos, configure sua disponibilidade, ofereça sessões individuais, receba agendamentos e conclua atendimentos usando os recursos atuais do sistema. A primeira entrega deve funcionar sem pacotes de aulas, turmas ou ficha de treino.

## 2. Encaixe na base atual

- `CompanyProfile` define o perfil do negócio e sugere módulos. `CompanyModule` controla os recursos contratados; não é necessário um novo módulo para este MVP.
- `Client`, `Professional`, `Service`, `Appointment` e `Attendance` já representam, respectivamente, aluno, personal, tipo de sessão, horário reservado e atendimento concluído. Os nomes exibidos podem mudar sem renomear tabelas ou modelos.
- O agendamento atual vincula um cliente e um profissional por horário, adequado à sessão individual.
- `CompanyProvisioningService` cria o administrador e configura a agenda da empresa, mas não cria um `Professional` nem sua jornada. O serviço só aparece na reserva online quando há profissional apto, vinculado a ele e com jornada ativa.
- O módulo Financeiro é independente do perfil. Sua ativação e cobrança seguem as regras atuais de módulos; os valores de atendimento seguem o fluxo financeiro existente quando aplicável.

## 3. Decisões do MVP

1. Criar `CompanyProfile::PersonalTrainer` com valor persistido `personal_trainer`, rótulo **Personal trainer** e descrição voltada a atendimentos individuais.
2. Sugerir os módulos **Agenda**, **WhatsApp operacional** e **Financeiro**. A seleção continua editável no cadastro e no painel administrativo; o perfil não ativa módulo adicional à força.
3. Exibir **Aluno/Alunos** para o cadastro de clientes e **Personal trainer/Personal trainers** para profissionais no contexto desse perfil. Manter **Serviços** como nome do catálogo; no texto de jornada, chamar o agendamento de **sessão** quando isso melhorar a compreensão.
4. Não habilitar `clinical_records` por padrão. O cadastro de alunos do MVP usa somente os dados comuns de cliente; dados de saúde e evolução física exigem desenho próprio em etapa futura.
5. A empresa pode cadastrar mais de um profissional usando o mecanismo atual, mas a jornada inicial é otimizada para um personal. Não impor restrição de cardinalidade no banco.
6. Não criar automaticamente serviços com preço ou duração arbitrários. Oferecer modelos editáveis **Avaliação inicial** e **Treino individual** na configuração inicial, exigindo preço e duração antes de publicá-los. Valor zero é permitido apenas se escolhido explicitamente pelo administrador.

## 4. Jornada do usuário

1. A pessoa seleciona **Personal trainer** no cadastro da empresa e revisa os módulos sugeridos.
2. No primeiro acesso, uma configuração inicial apresenta quatro tarefas: **Cadastrar o personal**, **Definir horários de atendimento**, **Criar serviços** e **Ativar agendamento online**.
3. O formulário do personal vem preenchido com nome e contato do administrador. Ao salvar, vincula o `Professional` ao usuário administrador, se ainda não houver outro profissional associado a esse usuário na empresa. A criação deve ser idempotente para não duplicar registros ao retornar à configuração.
4. O personal configura sua jornada semanal. Os horários gerais da empresa já criados pelo provisionamento continuam valendo; a tela deve explicar que ambos influenciam a disponibilidade online.
5. O personal cria os serviços a partir dos modelos editáveis, informa duração e preço, decide quais ficam disponíveis online e os associa ao próprio registro profissional.
6. A configuração mostra o link de agendamento somente depois de existir ao menos um serviço online com profissional ativo, apto e com jornada ativa, e da ativação da reserva pública. Antes disso, mostra as tarefas pendentes.
7. O personal cadastra alunos manualmente ou recebe reservas online pelo fluxo atual. Confirmações, lembretes e alterações por WhatsApp usam a infraestrutura existente quando configurada e autorizada.
8. Após a sessão, o personal conclui o atendimento no fluxo atual; quando o Financeiro estiver ativo, acompanha o registro financeiro conforme as regras existentes.

## 5. Superfícies e alterações previstas

| Área | Alteração |
|---|---|
| Perfil e cadastro | Adicionar enum, rótulo, descrição e módulos padrão; o wizard e o formulário administrativo já leem as opções do enum. |
| Terminologia | Atualizar `CompanyTerminology` e os recursos de alunos/profissionais. Revisar rótulos fixos na agenda, nos formulários, na reserva pública e nas mensagens operacionais exibidas para esse perfil. |
| Configuração inicial | Criar um checklist no painel, visível somente para o perfil Personal trainer, com links ou ações para profissional, jornada, serviços e ativação da reserva pública. Evitar um segundo cadastro paralelo. |
| Profissional | Usar `Professional` e o vínculo com o usuário administrador; preencher campos conhecidos e permitir edição antes de salvar. |
| Serviços | Oferecer modelos preenchidos no formulário existente, sem gravá-los antes da confirmação. Preservar a configuração de preço, duração, disponibilidade online e associação ao profissional. |
| Agenda e reserva | Reutilizar disponibilidade, conflitos, confirmação, cancelamento e remarcação atuais. Ajustar apenas textos específicos do perfil. |
| Financeiro | Reutilizar conclusão de atendimento e lançamentos existentes; não criar cobrança recorrente de alunos nesta entrega. |

Rotas, identificadores técnicos e dados históricos continuam com os nomes atuais (`clientes`, `profissionais`, `services`, `appointments`). A troca de vocabulário é de apresentação e não deve quebrar links existentes.

## 6. Regras de acesso e dados

- Reutilizar isolamento por `company_id` e permissões atuais de clientes, profissionais, serviços, agenda e financeiro.
- A configuração inicial só pode alterar registros se o usuário tiver as permissões correspondentes; para o administrador criado no cadastro, usar as permissões padrão do papel atual.
- Não enviar mensagens de WhatsApp antes da configuração da instância e do consentimento operacional aplicável ao cliente.
- Mudar uma empresa existente para o perfil Personal trainer não cria ou altera automaticamente alunos, profissionais, serviços, horários ou lançamentos. O checklist detecta o que já existe e orienta apenas o que falta.
- No formulário administrativo atual, trocar o perfil substitui a sugestão de módulos selecionados. A implementação deve deixar essa mudança visível para revisão antes de salvar; a troca de perfil não deve apagar dados históricos.

## 7. Plano de implementação

1. **Perfil e vocabulário:** enum, módulos sugeridos, helper de identificação do perfil, termos em recursos principais e cobertura de seleção/persistência.
2. **Configuração inicial:** checklist, preenchimento a partir do administrador, vínculo com `Professional`, orientação para jornada, modelos editáveis de serviço e ativação da reserva pública.
3. **Jornada ponta a ponta:** revisar reserva pública, mensagens e telas de agenda para o perfil; verificar conclusão do atendimento e interação com Financeiro ativo ou desativado.
4. **Regressão:** cobrir criação por signup e por administrador, empresa convertida, módulos editados, ausência de duplicação, isolamento entre empresas e funcionamento inalterado dos outros perfis.

## 8. Critérios de aceite

1. Personal trainer aparece como opção no cadastro e no painel administrativo, com Agenda, WhatsApp e Financeiro sugeridos e editáveis.
2. Uma empresa nova consegue cadastrar o personal, definir jornada e publicar **Avaliação inicial** e **Treino individual** com preço e duração escolhidos por ela.
3. Antes de completar a configuração necessária para reserva online, o painel mostra o que falta; depois, um aluno consegue reservar uma sessão individual pelo link público.
4. O painel apresenta **Alunos** e **Personal trainers** nos lugares pertinentes; os demais perfis mantêm seus rótulos atuais.
5. Um agendamento pode ser confirmado, remarcado, cancelado e concluído pelos fluxos atuais, respeitando disponibilidade e empresa.
6. WhatsApp operacional só envia mensagens quando o módulo e a configuração necessários estiverem ativos; o comportamento dos outros perfis não muda.
7. Uma empresa que já possui alunos, profissional, jornada e serviços pode selecionar o novo perfil sem registros duplicados ou perda de histórico.
8. O MVP funciona com Financeiro desativado; com ele ativo, a conclusão usa o fluxo financeiro existente, sem criar mensalidade ou pacote de aluno.

## 9. Evoluções posteriores

- Pacotes de sessões com saldo, validade, consumo e regras de cancelamento.
- Recorrência semanal com geração de horários futuros e tratamento de feriados e conflitos.
- Ficha de treino, avaliações físicas e evolução do aluno, com permissões e proteção de dados próprios.
- Aulas em grupo, com capacidade, vários alunos por horário e lista de presença.
- Cobrança periódica de alunos, distinta da assinatura da empresa no sistema.
