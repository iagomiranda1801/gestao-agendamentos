# Especificação — Importação de contatos WhatsApp para Pacientes

**Status:** proposta para implementação  
**Versão:** 1.0  
**Data:** 20/08/2026  
**Complementa:** `docs/spec-clinica-odontologica.md`

## 1. Objetivo

Fazer com que a importação de contatos do WhatsApp atenda corretamente clínicas odontológicas, sem duplicar cadastros e sem perder a compatibilidade com agenda, financeiro, atendimentos e histórico de mensagens.

No perfil **Clínica odontológica**, o contato importado deve ser apresentado como **Paciente** e receber automaticamente o perfil odontológico e o número de prontuário. Clientes já existentes devem poder ser preparados para o uso odontológico em uma operação segura e repetível.

## 2. Decisões de produto

1. `Client` continua sendo a entidade técnica única de pessoa atendida pela empresa.
2. Em empresas com `business_profile = dental_clinic`, `Client` é exibido como **Paciente**. Não haverá cópia dos dados para uma tabela `patients`.
3. O perfil odontológico (`dental_patient_profiles`) é complementar e obrigatório para todo paciente criado ou convertido em uma clínica odontológica.
4. A origem WhatsApp jamais concede consentimento de marketing automaticamente.
5. Um contato de WhatsApp permanece como registro de sincronização; importar não exclui o contato e excluir o contato não exclui o paciente vinculado.
6. A conversão de clientes existentes é idempotente: pode ser executada mais de uma vez sem criar perfis ou prontuários duplicados.

## 3. Situação atual e lacuna

O fluxo atual sincroniza contatos por empresa e tenta vinculá-los a um cliente pelo telefone normalizado. Quando o usuário escolhe importar, cria um `Client` diretamente.

Isso é suficiente para empresas genéricas, mas contorna `ClientService`, que é o serviço responsável por criar o perfil odontológico e gerar o prontuário. Como consequência, um contato importado em clínica odontológica pode aparecer como paciente sem `DentalPatientProfile` e sem número de prontuário.

Também não existe uma operação explícita para criar perfis odontológicos para clientes importados ou cadastrados antes de a empresa adotar o perfil odontológico.

## 4. Escopo funcional

### 4.1 Sincronização de contatos

A sincronização continua apenas trazendo e atualizando contatos em `whatsapp_contacts`.

- Ignorar grupos, identificadores internos e números inválidos.
- Normalizar telefones antes de salvar e antes de buscar vínculo.
- Considerar as variações brasileiras com/sem `55` e com/sem nono dígito.
- Buscar vínculo somente dentro da empresa atual.
- Não criar paciente durante a sincronização: a criação acontece somente pela ação explícita de importação.
- Se houver paciente correspondente, preencher `client_id` no contato, mesmo que ele nunca tenha sido importado manualmente.

### 4.2 Importação em empresa não odontológica

O comportamento atual deve ser preservado:

- criar ou vincular um **Cliente**;
- preencher nome e telefone a partir do contato;
- manter o cadastro ativo;
- registrar `imported_as_client_at`;
- manter `whatsapp_marketing_opt_in = false`, exceto na ação explícita de autorização de campanhas.

### 4.3 Importação em clínica odontológica

Ao escolher importar um contato em uma empresa odontológica, o sistema deve:

1. localizar um paciente existente pelo telefone normalizado;
2. se não existir, criar o registro base por `ClientService::create`;
3. criar ou garantir `DentalPatientProfile` no mesmo fluxo transacional;
4. gerar um número de prontuário único no formato atual (`P` + identificador preenchido);
5. vincular o contato ao paciente e registrar a data de importação;
6. apresentar o resultado como **Paciente criado** ou **Paciente vinculado**;
7. marcar o cadastro como **dados pendentes** quando ainda não houver os dados clínicos/administrativos recomendados.

O cadastro mínimo importado terá nome, telefone, status ativo e opção de marketing desmarcada. CPF, nascimento, responsável, convênio, endereço e anamnese não devem ser inferidos a partir do WhatsApp.

### 4.4 Garantia de perfil odontológico

Deve existir um método único, por exemplo `ClientService::ensureDentalProfile(Company $company, Client $client)`, que:

- valide o pertencimento do paciente à empresa;
- só opere em empresa odontológica;
- use `firstOrCreate` (ou equivalente) por `company_id` e `client_id`;
- gere o prontuário apenas quando o perfil for novo;
- nunca altere dados clínicos já preenchidos;
- possa ser chamado pela importação, edição de paciente e rotina de conversão.

### 4.5 Conversão de base existente

Ao mudar uma empresa para **Clínica odontológica**, o sistema deve disponibilizar uma ação administrativa: **Preparar clientes como pacientes**.

Regras:

- processar somente clientes da empresa selecionada;
- criar perfil e prontuário somente para quem ainda não possuir perfil odontológico;
- preservar cliente, agenda, atendimentos, pagamentos, campanhas e vínculo de WhatsApp existentes;
- processar em lotes para suportar bases grandes;
- mostrar total analisado, convertido, já preparado e falhas;
- permitir repetir a ação com resultado seguro;
- registrar auditoria com usuário, data, empresa, totais e eventuais falhas;
- não desativar nem remover nada.

A mudança de perfil pode disparar essa preparação automaticamente para bases pequenas, mas a primeira entrega deve manter uma ação confirmada pelo administrador para que a operação seja visível e auditável.

### 4.6 Interface e terminologia

Na tela **Contatos WhatsApp**, quando o tenant for odontológico, usar dinamicamente:

| Empresa padrão | Clínica odontológica |
| --- | --- |
| Cliente | Paciente |
| Importar como cliente | Importar como paciente |
| Importado como cliente | Importado como paciente |
| Cliente vinculado | Paciente vinculado |
| Cria ou vincula o cliente | Cria ou vincula o paciente e gera o prontuário quando necessário |

As ações em massa, filtros, notificações e diálogos de confirmação devem seguir a mesma terminologia.

Após a importação de um novo paciente, a notificação deve oferecer a ação **Completar cadastro**, levando à edição do paciente. A lista de pacientes deve permitir filtrar **Cadastro incompleto**, inicialmente definido como ausência de data de nascimento, CPF ou perfil odontológico; a clínica poderá completar anamnese em etapa posterior.

### 4.7 Consentimento e privacidade

- Importar um contato não é consentimento para mensagens promocionais.
- A ação de autorizar campanhas continua exigindo confirmação explícita do usuário.
- O histórico deve indicar a origem do paciente (`manual` ou `whatsapp`) e a data de importação.
- A recepção pode completar dados cadastrais; dados clínicos obedecem às permissões descritas na especificação odontológica.

## 5. Requisitos técnicos

### 5.1 Serviços

- Alterar `WhatsAppContactService::importAsClients` para depender de `ClientService` ao criar cadastros.
- Introduzir um nome de método neutro internamente, como `importAsPeople`, se desejado, preservando o método atual como adaptador para evitar quebra de chamadas existentes.
- Centralizar a garantia de perfil no `ClientService`; não duplicar a criação de `DentalPatientProfile` dentro do serviço de WhatsApp.
- Manter toda a importação de cada lote em transação. Erros devem desfazer o vínculo e a criação daquele lote de forma consistente.
- Criar serviço específico para conversão, por exemplo `DentalPatientMigrationService`, executando `chunkById` e emitindo resultado estruturado.

### 5.2 Dados e auditoria

Adicionar, se ainda não existir, campo de origem no cadastro base ou no perfil odontológico:

- `source`: `manual`, `whatsapp`, `migration`;
- `source_imported_at`: data/hora opcional.

Criar evento de auditoria para a conversão em massa. Para importações individuais, aproveitar a auditoria clínica/administrativa existente ou registrar evento próprio, sem gravar dados sensíveis desnecessários.

### 5.3 Concorrência e duplicidade

- A criação do perfil precisa ser protegida por índice único de `company_id + client_id`.
- A busca e a criação devem tolerar duas importações simultâneas do mesmo número: no máximo um paciente deve resultar.
- Caso haja conflito de telefone, vincular ao registro existente; não sobrescrever nome, CPF ou dados clínicos desse paciente a partir do WhatsApp.

## 6. Permissões

- Administrador da empresa: sincronizar, importar, autorizar campanha e executar conversão de base.
- Gerente: sincronizar e importar; conversão de base apenas se possuir permissão administrativa específica.
- Secretária/recepção: importar e completar cadastro administrativo, sem acesso automático a anamnese/evoluções.
- Dentista: consultar pacientes conforme sua permissão clínica; não precisa administrar sincronização de contatos.

As ações devem respeitar as permissões de módulo já existentes e não apenas a visibilidade da interface.

## 7. Critérios de aceite

1. Importar um contato em empresa comum cria um cliente e não concede marketing.
2. Importar um contato em clínica odontológica cria paciente, perfil odontológico e prontuário em uma única transação.
3. Importar novamente o mesmo telefone não cria outro paciente, inclusive para variações brasileiras válidas do número.
4. Quando já existir paciente com o telefone, a importação apenas vincula o contato e preserva os dados existentes.
5. Todo cliente pré-existente convertido recebe exatamente um perfil odontológico e um prontuário único.
6. Executar a conversão duas vezes não altera nem duplica os perfis já criados.
7. Agenda, atendimentos, financeiro, campanhas e contatos de WhatsApp anteriores continuam vinculados ao mesmo `client_id` após a conversão.
8. Em clínica odontológica, todos os textos da tela de contatos usam “Paciente”.
9. A ação de autorizar campanhas continua exigindo confirmação explícita e auditável.
10. Usuário sem permissão adequada não consegue acionar importação ou conversão pela interface nem por requisição direta.

## 8. Testes obrigatórios

- teste unitário/feature de criação de paciente por contato WhatsApp em tenant odontológico;
- teste de vínculo a paciente já existente;
- teste de criação idempotente de perfil e prontuário;
- teste da conversão em lote, incluindo repetição da operação;
- teste de isolamento entre empresas;
- teste de telefone com `55`, sem `55`, com e sem nono dígito;
- teste de consentimento de marketing desmarcado por padrão;
- teste de terminologia dinâmica da interface;
- teste de autorização para administrador, recepção e dentista.

## 9. Fora do escopo desta entrega

- Importar anamnese, documentos ou informações clínicas do WhatsApp.
- Interpretar conversas para preencher campos automaticamente.
- Mesclar automaticamente pacientes diferentes com nomes parecidos.
- Conceder marketing com base em existência de conversa ou de contato na agenda.
- Alterar ou apagar contatos da agenda externa do WhatsApp.

## 10. Ordem de implementação

1. Criar a garantia centralizada de perfil odontológico no `ClientService`.
2. Adaptar a importação de WhatsApp para usar esse fluxo.
3. Criar a ação/serviço de preparação da base existente com auditoria.
4. Ajustar terminologia e atalho para completar cadastro na interface.
5. Aplicar permissões e cobrir os cenários com testes automatizados.
