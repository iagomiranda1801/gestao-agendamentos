# Especificação — Perfil Clínica Odontológica

**Status:** implementada  
**Versão:** 1.0  
**Data:** 20/08/2026  
**Produto:** Agendaqui

> Implementação inicial concluída em 20/08/2026. O odontograma visual combina diagrama responsivo com edição estruturada por dente/face. Integrações externas que permanecem na seção “Fora do escopo inicial” não fazem parte desta entrega.

## 1. Objetivo

Permitir que o Agendaqui atenda uma clínica odontológica com um ou mais dentistas, mantendo a agenda e o financeiro existentes e acrescentando uma experiência própria para pacientes e registros clínicos.

O perfil odontológico deve mudar o vocabulário, os menus, os formulários e as permissões. Não se trata apenas de renomear “Cliente” para “Paciente”.

## 2. Decisões de produto

1. Será criado o perfil de empresa `dental_clinic`, exibido como **Clínica odontológica**.
2. O cadastro base continuará usando `Client`; no perfil odontológico ele será apresentado como **Paciente**. Isso preserva as integrações existentes com agenda, atendimento, financeiro e WhatsApp.
3. Os dados exclusivamente odontológicos ficarão em entidades próprias, vinculadas ao paciente, sem poluir o cadastro genérico de clientes.
4. Prontuário clínico e informações financeiras terão permissões independentes.
5. Registros clínicos assinados/finalizados não serão sobrescritos. Correções serão feitas por adendo, mantendo autoria e data.
6. O primeiro lançamento será dividido em fases. O odontograma visual faz parte do produto planejado, mas não bloqueia o prontuário clínico inicial.

## 3. Situação atual aproveitada

O sistema já possui:

- empresas isoladas por tenant;
- perfis de empresa e módulos habilitáveis;
- profissionais vinculados à empresa e, opcionalmente, a um usuário;
- serviços vinculados aos profissionais;
- jornada, pausas, bloqueios e disponibilidade por profissional;
- agendamentos vinculados a cliente, profissional e serviço;
- atendimentos concluídos, materiais, pagamentos e histórico;
- cadastro básico de cliente com nome, telefone, e-mail, CPF e nascimento;
- papéis de administrador, gerente e colaborador.

Lacunas atuais:

- não existe perfil específico para odontologia;
- o menu e os textos usam “Clientes”;
- não há anamnese, evolução clínica, plano de tratamento, odontograma ou anexos clínicos;
- os papéis atuais não separam adequadamente recepção e acesso clínico;
- as observações do atendimento atual não constituem prontuário clínico estruturado.

## 4. Escopo funcional

### 4.1 Perfil Clínica odontológica

Na criação ou edição da empresa, a opção **Clínica odontológica** deverá:

- definir `business_profile = dental_clinic`;
- habilitar por padrão Agenda, Financeiro e WhatsApp operacional;
- ativar os recursos clínicos odontológicos;
- usar “Paciente/Pacientes” nas telas de cadastro, agenda, atendimento, busca global e dashboard;
- manter “Cliente/Clientes” para os demais perfis de empresa;
- permitir que uma empresa existente migre para o perfil odontológico sem duplicar clientes ou agendamentos.

### 4.2 Navegação esperada

Para uma clínica odontológica:

- **Agenda**
  - Agenda
  - Agendamentos
  - Bloqueios
  - Atendimentos
- **Pacientes**
  - Lista de pacientes
  - Novo paciente
- **Clínico**
  - Prontuários
  - Planos de tratamento
  - Odontogramas (fase 2)
- **Cadastros**
  - Dentistas/Profissionais
  - Procedimentos/Serviços
- Estoque, Financeiro, WhatsApp e Marketing continuam condicionados aos módulos habilitados.

O menu **Clínico** só deve aparecer para usuários com ao menos uma permissão clínica.

## 5. Cadastro do paciente

### 5.1 Identificação

Campos:

- nome completo — obrigatório;
- nome social — opcional;
- CPF — opcional, único por empresa quando informado;
- data de nascimento — recomendada;
- sexo cadastral — opcional;
- telefone/WhatsApp — obrigatório na primeira versão, preservando a regra atual;
- e-mail — opcional;
- número do prontuário — gerado automaticamente e único por empresa;
- status ativo/inativo;
- observação administrativa — não clínica.

### 5.2 Endereço

- CEP;
- logradouro;
- número;
- complemento;
- bairro;
- cidade;
- UF.

O endereço é opcional no cadastro inicial e pode ser completado depois.

### 5.3 Responsável legal

Disponível para qualquer paciente e destacado quando o paciente for menor de idade:

- nome;
- CPF;
- data de nascimento;
- parentesco;
- telefone;
- e-mail;
- indicação de responsável financeiro;
- indicação de responsável legal.

Regra: paciente menor pode ser salvo sem responsável apenas mediante confirmação explícita e registro no histórico. A obrigatoriedade definitiva será configurável por empresa.

### 5.4 Convênio

- operadora/plano;
- número da carteirinha;
- validade;
- titular;
- observações administrativas.

Convênio será opcional e não fará faturamento TISS na primeira versão.

### 5.5 Tela do paciente

A ficha do paciente deverá apresentar:

- cabeçalho com nome, idade, telefone, número do prontuário e alertas clínicos;
- ações rápidas para agendar, iniciar registro clínico e criar plano de tratamento;
- abas **Resumo**, **Dados pessoais**, **Anamnese**, **Evoluções**, **Plano de tratamento**, **Odontograma**, **Documentos** e **Histórico**;
- linha do tempo unificada de consultas, evoluções, planos, anexos e alterações relevantes;
- separação visual clara entre observações administrativas e informações clínicas.

## 6. Prontuário odontológico

### 6.1 Regras gerais

- Todo registro pertence à empresa e ao paciente.
- Toda evolução identifica o dentista responsável, o usuário autor, data e hora.
- O dentista responsável deve estar ativo e vinculado à empresa.
- Uma evolução pode ser vinculada a um agendamento/atendimento, mas também pode ser criada sem consulta para registrar fatos clínicos autorizados.
- Rascunhos podem ser editados pelo autor.
- Ao finalizar, o conteúdo recebe uma versão imutável.
- Alterações posteriores são registradas como adendos; o texto original permanece disponível.
- Exclusão física de registros clínicos não será oferecida na interface.
- Dados clínicos não devem aparecer em notificações, campanhas de marketing ou busca global sem autorização específica.

### 6.2 Anamnese

Questionário inicial sugerido:

- queixa principal e motivo da consulta;
- tratamentos médicos em andamento;
- medicamentos em uso;
- alergias;
- doenças ou condições sistêmicas;
- cirurgias e internações;
- gestação/amamentação;
- tabagismo e consumo de álcool;
- histórico de sangramento;
- uso de anticoagulantes;
- diabetes;
- hipertensão;
- problemas cardíacos;
- doenças infectocontagiosas relevantes ao atendimento;
- reação anterior a anestésico;
- hábitos de higiene oral;
- bruxismo/apertamento;
- observações do paciente;
- observações do profissional.

Requisitos:

- respostas “Sim”, “Não” e “Não informado”, com complemento textual quando aplicável;
- alertas clínicos gerados a partir de respostas selecionadas;
- data de preenchimento e profissional revisor;
- status `draft`, `completed` ou `superseded`;
- criação de nova versão sem apagar a anterior;
- registro opcional de ciência/assinatura do paciente em fase posterior.

O questionário deve ser armazenado com snapshot de perguntas e respostas, permitindo alterar o modelo futuro sem modificar anamneses antigas.

### 6.3 Evolução clínica

Campos mínimos:

- data e hora;
- dentista responsável;
- agendamento/atendimento relacionado, quando houver;
- queixa/relato;
- avaliação clínica;
- procedimento executado;
- dentes e faces envolvidos, quando aplicável;
- materiais/medicamentos utilizados;
- anestésico e quantidade, quando aplicável;
- intercorrências;
- orientações fornecidas;
- conduta e próximos passos;
- retorno recomendado;
- anexos;
- status `draft` ou `finalized`.

O prontuário clínico não deve reutilizar os campos financeiros `notes` e `internal_notes` de `Attendance` como fonte principal. Uma evolução poderá referenciar um atendimento já concluído.

### 6.4 Alertas clínicos

Alertas podem ser gerados pela anamnese ou inseridos manualmente:

- alergia;
- medicamento/anticoagulante;
- condição sistêmica;
- gestação;
- risco ou cuidado especial;
- alerta livre.

Cada alerta terá severidade informativa, atenção ou crítica, origem, estado ativo/inativo, autor e datas. Alertas ativos serão mostrados no paciente e durante o atendimento, sem expor detalhes na agenda pública.

### 6.5 Plano de tratamento

Um paciente pode possuir vários planos, com apenas um marcado como principal por vez.

Plano:

- título;
- dentista responsável;
- data;
- validade da proposta;
- observações clínicas;
- observações comerciais separadas;
- situação: rascunho, apresentado, aprovado, parcialmente aprovado, em execução, concluído ou cancelado;
- totais estimados, descontos e total final.

Itens:

- procedimento/serviço;
- dente e face, quando aplicável;
- descrição complementar;
- quantidade;
- valor unitário e desconto;
- dentista previsto;
- prioridade;
- situação: proposto, aprovado, recusado, agendado, realizado ou cancelado;
- vínculo com agendamento, atendimento e evolução clínica quando realizado.

Regras:

- aprovação deve guardar data, usuário e snapshot financeiro;
- mudanças de preço após aprovação não alteram o snapshot já aprovado;
- concluir um atendimento poderá marcar itens relacionados como realizados, mediante confirmação;
- um plano pode ser impresso/exportado em fase posterior.

### 6.6 Odontograma — fase 2

O odontograma deverá:

- usar numeração FDI para dentição permanente e decídua;
- permitir seleção do dente completo ou de faces;
- registrar condição existente, diagnóstico e tratamento planejado/realizado;
- diferenciar visualmente situação existente, planejada e concluída;
- manter histórico temporal, sem sobrescrever estados anteriores;
- vincular marcações ao plano de tratamento e à evolução clínica;
- permitir observação livre por dente;
- funcionar em desktop e tablet.

Condições iniciais sugeridas: hígido, ausente, extraído, cárie, restauração, coroa, implante, canal tratado, indicação endodôntica, fratura, prótese, selante e observação. A lista deverá ser extensível.

### 6.7 Documentos e anexos

Tipos iniciais:

- radiografia;
- fotografia;
- exame;
- receita;
- atestado;
- termo/consentimento;
- documento geral.

Regras:

- arquivo vinculado ao paciente e, opcionalmente, a uma anamnese, evolução ou plano;
- título, tipo, descrição, data do documento, autor do envio e data de inclusão;
- armazenamento privado, sem URL pública permanente;
- download e visualização somente após autorização;
- validação de extensão e tamanho;
- registro de upload, visualização e download na auditoria;
- exclusão lógica restrita, com motivo e auditoria.

## 7. Fluxos principais

### 7.1 Configurar a clínica

1. Usuário escolhe **Clínica odontológica** no cadastro da empresa.
2. O sistema provisiona módulos padrão e configurações clínicas.
3. Usuário cadastra os dois dentistas e os vincula aos respectivos usuários.
4. Define procedimentos, preços, duração e quais dentistas os executam.
5. Configura horários individuais e permissões da equipe.

### 7.2 Cadastrar e agendar paciente

1. Recepção acessa **Pacientes > Novo paciente**.
2. Informa os dados mínimos e salva.
3. O sistema gera o número de prontuário.
4. A recepção cria o agendamento escolhendo paciente, procedimento e dentista.
5. Agendamentos simultâneos são permitidos para dentistas diferentes, respeitando os conflitos individuais já existentes.

### 7.3 Realizar consulta

1. Dentista abre o agendamento próprio.
2. O sistema exibe alertas clínicos ativos.
3. Dentista consulta ou atualiza a anamnese.
4. Registra a evolução clínica e, se necessário, atualiza o odontograma/plano.
5. Finaliza e confirma o registro clínico.
6. O atendimento financeiro/operacional é concluído pelo fluxo existente, mantendo vínculo com a evolução.

### 7.4 Corrigir uma evolução finalizada

1. Usuário autorizado abre a evolução.
2. Seleciona **Adicionar adendo**.
3. Informa justificativa e conteúdo complementar/corretivo.
4. O sistema preserva o original e registra autor e horário do adendo.

## 8. Papéis e permissões

Os papéis devem funcionar como modelos de permissões, com capacidades granulares. O vínculo `company_user` deverá permitir permissões específicas no futuro.

| Capacidade | Administrador | Gerente | Recepção | Dentista |
|---|---:|---:|---:|---:|
| Cadastrar/editar dados pessoais | Sim | Sim | Sim | Sim |
| Ver agenda da clínica | Sim | Sim | Sim | Configurável |
| Criar/remarcar agendamento | Sim | Sim | Sim | Próprios/configurável |
| Ver alertas clínicos essenciais | Sim | Sim | Não por padrão | Sim |
| Ver prontuário completo | Configurável | Configurável | Não | Sim, conforme política clínica |
| Criar evolução | Não por padrão | Não por padrão | Não | Sim |
| Finalizar evolução | Não por padrão | Não por padrão | Não | Sim, própria |
| Criar adendo | Configurável | Configurável | Não | Sim, conforme política clínica |
| Criar plano de tratamento | Configurável | Sim | Não | Sim |
| Ver valores do plano | Sim | Sim | Sim | Configurável |
| Ver financeiro gerencial | Sim | Conforme permissão | Não | Não |
| Configurar usuários/permissões | Sim | Não por padrão | Não | Não |

Requisitos adicionais:

- usuário dentista deve estar vinculado a um `Professional` ativo;
- acesso de dentista pode ser limitado aos pacientes atendidos por ele ou liberado para todos os pacientes da clínica; essa será uma configuração da empresa;
- recepção pode ver apenas um indicador genérico de cuidado especial quando necessário para o fluxo, sem abrir o prontuário;
- administrador não recebe acesso clínico automaticamente se a clínica optar por segregação estrita;
- toda tentativa negada deve retornar 403 sem revelar a existência de registro de outro tenant.

## 9. Modelo de dados proposto

Os nomes abaixo são orientativos e devem seguir os padrões finais do projeto.

### 9.1 Alterações em entidades existentes

`CompanyProfile`:

- adicionar `DentalClinic = 'dental_clinic'`.

`clients`:

- adicionar `social_name`, se aprovado para uso geral;
- manter dados odontológicos fora desta tabela.

`company_user` ou estrutura equivalente:

- evoluir de papel único para capacidades granulares ou criar tabela de permissões por vínculo.

### 9.2 Novas entidades

`dental_patient_profiles`

- `id`, `company_id`, `client_id` único;
- `record_number` único por empresa;
- sexo cadastral e campos odontológicos administrativos;
- dados de endereço, se não forem generalizados em `clients`;
- timestamps.

`patient_guardians`

- paciente, dados do responsável, parentesco;
- flags de responsável legal e financeiro;
- timestamps.

`patient_insurances`

- paciente, operadora, plano, carteirinha, validade, titular e status.

`dental_anamneses`

- empresa, paciente, versão, status;
- `questionnaire_snapshot` JSON;
- `answers` JSON;
- alertas derivados;
- autor, revisor, datas de conclusão e substituição.

`patient_clinical_alerts`

- empresa, paciente, tipo, severidade, título e descrição;
- origem, registro de origem, status, autor e datas.

`dental_clinical_entries`

- empresa, paciente, dentista/profissional;
- usuário autor;
- agendamento e atendimento opcionais;
- campos clínicos estruturados;
- status, finalização e timestamps.

`dental_clinical_entry_addenda`

- evolução original, autor, justificativa, conteúdo e timestamp imutável.

`dental_treatment_plans` e `dental_treatment_plan_items`

- plano, itens, snapshots de preço, situação e vínculos operacionais.

`dental_odontograms` e `dental_odontogram_entries`

- snapshots/versionamento do odontograma e marcações por dente/face.

`clinical_attachments`

- empresa, paciente, tipo, metadados do arquivo, caminho privado;
- relação opcional com entidade clínica;
- autor, exclusão lógica e motivo.

`clinical_audit_events`

- empresa, paciente, usuário, ação, entidade, identificador, data/hora, IP e metadados mínimos;
- não armazenar conteúdo clínico integral no log quando não for necessário.

Todas as tabelas clínicas devem possuir `company_id`, índices por empresa/paciente e validação de pertencimento ao tenant no serviço de domínio, além das policies.

## 10. Arquitetura e integração

- Criar um módulo/capacidade de produto `clinical_records`, habilitado automaticamente no perfil odontológico. A decisão comercial de expô-lo como módulo contratável separado pode ser tomada depois.
- Manter `ClientResource` como base, mas resolver dinamicamente os labels de acordo com o perfil da empresa.
- A ficha completa pode ser uma página dedicada (`PatientRecordPage`) para comportar abas, timeline e ações clínicas, evitando sobrecarregar o formulário genérico.
- Criar serviços de domínio para finalizar evolução, adicionar adendo, versionar anamnese e alterar plano; não concentrar regras em actions do Filament.
- Policies clínicas devem validar tenant, capacidade, vínculo profissional e escopo de pacientes.
- Eventos clínicos relevantes devem produzir auditoria na mesma transação da alteração.
- O fluxo existente de conclusão de atendimento não deve depender da existência de evolução na primeira versão; a empresa poderá tornar essa exigência configurável posteriormente.

## 11. Segurança, privacidade e auditoria

Requisitos mínimos:

- isolamento completo por empresa;
- princípio do menor privilégio;
- arquivos em armazenamento privado;
- criptografia em trânsito e proteção adequada no armazenamento e backups;
- trilha de auditoria para criação, visualização, alteração, finalização, adendo, download e exclusão lógica;
- reautenticação ou confirmação para ações sensíveis, quando aplicável;
- não incluir conteúdo clínico em logs técnicos, URLs, notificações ou analytics;
- backups com restauração testada;
- política definida de retenção, exportação e atendimento a solicitações do titular;
- termos e controles de acesso revisados antes do uso em produção com dados reais.

Esta especificação define requisitos de produto e engenharia; a validação jurídica e profissional sobre guarda de prontuário, consentimento e documentos obrigatórios deve ocorrer antes da operação real.

## 12. Requisitos não funcionais

- As páginas principais devem responder adequadamente com pelo menos 10 mil pacientes por empresa, usando paginação e índices.
- Busca por nome, telefone, CPF e número do prontuário deve respeitar o tenant.
- A tela de prontuário deve ser utilizável em desktop e tablet.
- Datas clínicas devem ser armazenadas em UTC e exibidas no fuso da empresa, seguindo o padrão atual.
- Operações de finalização/versionamento devem usar transações e ser idempotentes quando aplicável.
- Uploads devem ter limites configuráveis e varredura de segurança quando a infraestrutura oferecer suporte.
- Falha ao gravar auditoria deve impedir a finalização de um registro clínico sensível.

## 13. Critérios de aceite do primeiro lançamento

### Perfil e linguagem

- É possível escolher **Clínica odontológica** na criação da empresa.
- A empresa odontológica vê **Pacientes**; outros perfis continuam vendo **Clientes**.
- A troca de perfil não duplica nem perde registros existentes.

### Paciente

- Recepção autorizada consegue cadastrar um paciente e o número do prontuário é gerado.
- CPF duplicado na mesma empresa é impedido quando informado; o mesmo CPF em empresas distintas não conflita.
- É possível cadastrar responsável e convênio.
- O paciente pode ser localizado por nome, telefone, CPF ou número do prontuário.

### Anamnese e alertas

- Dentista autorizado consegue criar, concluir e consultar versões de anamnese.
- Concluir uma nova versão não apaga a anterior.
- Respostas configuradas geram alertas visíveis durante o atendimento.
- Recepção não consegue abrir respostas clínicas sem permissão.

### Evolução

- Dentista consegue criar rascunho vinculado ou não a um atendimento.
- Apenas usuário autorizado consegue finalizar uma evolução.
- Evolução finalizada não pode ser editada diretamente.
- Correção cria adendo mantendo o conteúdo original.
- Outro tenant não consegue acessar o registro nem por URL direta.

### Plano de tratamento

- É possível criar plano com vários itens, dentes/faces, valores e situações.
- Aprovação guarda snapshot dos valores.
- Item realizado pode ser vinculado ao atendimento e à evolução.

### Auditoria

- Criação, finalização, adendo, visualização e download geram eventos de auditoria.
- Os eventos registram usuário, empresa, ação e horário sem copiar conteúdo clínico desnecessário.

## 14. Fases de entrega

### Fase 1 — Base odontológica

- perfil Clínica odontológica;
- linguagem dinâmica Paciente/Cliente;
- ficha ampliada, responsável, convênio e número do prontuário;
- novos papéis/capacidades;
- página de resumo do paciente;
- testes de tenant e permissões.

### Fase 2 — Prontuário clínico essencial

- anamnese versionada;
- alertas clínicos;
- evolução em rascunho/finalizada;
- adendos imutáveis;
- vínculo com agenda e atendimento;
- auditoria clínica.

### Fase 3 — Tratamento e documentos

- plano de tratamento e itens;
- anexos privados;
- impressão/exportação controlada;
- vínculos com atendimento e financeiro.

### Fase 4 — Odontograma

- componente visual responsivo;
- condições, dentes e faces;
- histórico e integração com plano/evolução;
- cobertura de testes de interação.

## 15. Fora do escopo inicial

- faturamento TISS e integração direta com operadoras;
- assinatura digital qualificada;
- prescrição eletrônica integrada a terceiros;
- integração com equipamentos de radiografia;
- teleodontologia;
- portal do paciente;
- inteligência artificial para diagnóstico;
- migração automática de prontuários de outros sistemas;
- armazenamento DICOM especializado.

## 16. Pontos para validação do produto

As seguintes premissas foram adotadas nesta versão e podem ser ajustadas antes da implementação:

1. O odontograma será entregue depois do prontuário essencial.
2. O telefone continuará obrigatório no cadastro inicial.
3. Administrador e gerente não terão acesso clínico irrestrito apenas por causa do cargo; a clínica poderá conceder essa capacidade.
4. Dentistas poderão, por configuração da clínica, ver todos os prontuários ou apenas pacientes relacionados a eles.
5. O prontuário clínico e a conclusão financeira do atendimento serão integrados, mas não obrigatoriamente dependentes no primeiro lançamento.
6. Termos e assinaturas do paciente serão tratados depois da base de prontuário e auditoria.

Após validar esses seis pontos, a Fase 1 pode ser decomposta em histórias técnicas e iniciada sem decisões estruturais pendentes.
