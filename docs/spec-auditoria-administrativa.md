# Especificação — Auditoria administrativa da plataforma

**Status:** implementada  
**Versão:** 1.0  
**Data:** 25/08/2026  
**Produto:** Agendaqui

> Implementação concluída em 25/08/2026. O módulo cobre as ações do painel global `/admin` definidas nesta especificação; eventos de autenticação, comandos Artisan e operações fora do painel permanecem fora do escopo.

## 1. Objetivo

Disponibilizar uma trilha de auditoria imutável para as ações de administração da plataforma realizadas no painel global `/admin`.

O módulo deve permitir identificar com segurança quem realizou uma alteração, quando ela ocorreu, qual entidade foi afetada e quais dados foram modificados.

## 2. Decisões de produto

1. A auditoria pertence exclusivamente ao painel global `/admin` e só poderá ser consultada por superadministradores de plataforma.
2. Apenas operações que alteram estado ou disparam uma ação operacional serão registradas. Navegações, visualizações de dashboard, consultas, rotas, webhooks e Telescope não gerarão registros.
3. A auditoria é independente de `clinical_audit_events`, que permanece dedicada ao prontuário odontológico e às exigências clínicas.
4. Os logs serão preservados mesmo quando o usuário, a empresa ou o registro afetado forem excluídos.
5. Senhas, tokens, cookies, cabeçalhos de autorização e outros segredos nunca serão gravados nos detalhes do log.
6. Eventos serão gravados somente após a operação administrativa ser concluída com sucesso. Tentativas invalidadas ou transações revertidas não devem gerar log de alteração.
7. A primeira versão cobre as operações executadas pela interface `/admin`. Cadastro público de empresa, comandos Artisan e alterações diretas no banco não fazem parte deste escopo.

## 3. Situação atual aproveitada

O sistema já possui:

- painel Filament global em `/admin`;
- acesso restrito a `User::isPlatformAdmin()`;
- recursos administrativos de Empresas e Usuários;
- gerenciamento de vínculos entre usuário e empresa;
- páginas operacionais para jobs falhados, webhooks, catálogo de rotas e Telescope;
- armazenamento de datas em UTC e exibição administrativa já orientada ao fuso `America/Sao_Paulo`;
- auditoria clínica separada em `clinical_audit_events`.

Lacunas atuais:

- não há tabela de auditoria administrativa;
- não há página de pesquisa ou detalhes de logs no `/admin`;
- edições de empresa, usuário e vínculo não preservam valores anterior e posterior;
- ações nos jobs falhados não possuem rastreabilidade de negócio.

## 4. Escopo funcional

### 4.1 Ações auditáveis

| Área | Tipos de ação |
|---|---|
| Empresas | criação, edição, exclusão individual e em massa, ativação/inativação, alteração de módulos, perfil, assinatura e período de trial |
| Usuários | criação, edição, exclusão individual e em massa, ativação/inativação, concessão ou remoção de superadministrador e redefinição de senha |
| Vínculo usuário–empresa | criação do vínculo, alteração de papel ou status e desvinculação individual ou em massa |
| Jobs falhados | reenvio, remoção individual e limpeza das falhas com mais de sete dias |

Os tipos serão persistidos com chaves estáveis, por exemplo:

- `company.created`, `company.updated`, `company.deleted`;
- `user.created`, `user.updated`, `user.deleted`, `user.password_changed`;
- `company_membership.attached`, `company_membership.updated`, `company_membership.detached`;
- `failed_job.retried`, `failed_job.forgotten`, `failed_jobs.cleaned`.

O rótulo em português será resolvido pela aplicação. Assim, a chave pode evoluir sem depender do texto apresentado ao usuário.

### 4.2 Ações explicitamente fora do escopo

- login e logout de superadministradores;
- simples abertura de páginas, pesquisa e exportação;
- visualização de webhooks, rotas, dashboard e Telescope;
- operações realizadas em `/app`, inclusive por administradores de uma empresa;
- cadastro público em `/cadastro`;
- comando `users:ensure-super-admin` e demais comandos Artisan;
- alterações feitas diretamente no banco de dados.

Uma fase futura poderá incluir os eventos de autenticação ou de console, com uma origem própria e regras específicas de identificação do operador.

## 5. Modelo de dados proposto

Será criada a tabela `admin_audit_logs`.

| Campo | Regra |
|---|---|
| `id` | chave primária |
| `actor_id` | usuário que executou a ação; anulável e com `nullOnDelete` |
| `actor_name` | nome do responsável no momento da ação |
| `actor_email` | e-mail do responsável no momento da ação |
| `company_id` | empresa relacionada, quando aplicável; anulável e com `nullOnDelete` |
| `action` | chave estável do tipo de ação |
| `subject_type` | classe/tipo da entidade afetada |
| `subject_id` | identificador da entidade afetada; anulável para ações agregadas |
| `subject_label` | resumo legível da entidade no momento da ação |
| `before` | JSON com valores anteriores normalizados |
| `after` | JSON com valores posteriores normalizados |
| `metadata` | JSON com contexto não sensível da ação |
| `occurred_at` | instante da ocorrência, salvo em UTC |
| `created_at` | data técnica de inserção |

Índices mínimos:

- `occurred_at`;
- `actor_id, occurred_at`;
- `action, occurred_at`;
- `company_id, occurred_at`;
- `subject_type, subject_id`.

`admin_audit_logs` não terá exclusão, restauração ou edição pela interface. As chaves estrangeiras de ator e empresa não poderão usar `cascadeOnDelete`, pois a exclusão do alvo não pode apagar a evidência administrativa.

### 5.1 Snapshots e dados sensíveis

Os valores gravados devem ser limitados aos campos pertinentes à mudança.

- Empresa: nome, slug, perfil, contatos, fuso, status, módulos, assinatura e fim do trial.
- Usuário: nome, e-mail, status e indicador de superadministrador.
- Senha: registrar somente a ação `user.password_changed`; nunca incluir hash, valor antigo ou valor novo.
- Vínculo: empresa, usuário, papel, status e permissões quando existirem.
- Job falhado: UUID/ID, fila, nome resumido do job, total removido e data de corte; nunca registrar o payload completo ou a exceção completa.

`metadata` poderá guardar origem `admin_panel`, rota e IP, desde que o IP seja aprovado como dado operacional pela política de privacidade. O módulo não depende desses campos para atender ao objetivo principal.

## 6. Registro das ações

Um serviço central, por exemplo `AdminAuditService`, será responsável por criar os logs. Ele receberá o ator autenticado, a ação, a entidade, snapshots antes/depois e metadados permitidos.

O registro será chamado explicitamente nos pontos de alteração do painel, pois há operações que não disparam eventos convencionais de modelo:

- ações de anexar, editar e desvincular registros da tabela pivô `company_user`;
- reenviar, esquecer e limpar jobs falhados;
- exclusões em massa.

Para criação e edição de `Company` e `User`, os ciclos das páginas Filament devem capturar o snapshot antes da alteração e registrar o resultado depois de persistido. Quando uma edição não gerar diferença efetiva, nenhum novo log deve ser criado.

As configurações iniciais geradas automaticamente ao criar empresa — por exemplo, agenda e horários padrão — serão apresentadas como metadado da criação da empresa, e não como uma sequência de logs técnicos internos.

## 7. Interface administrativa

Será criada a página **Auditoria** no grupo de navegação **Operação** do painel `/admin`.

### 7.1 Lista de registros

Colunas mínimas:

- data;
- hora;
- usuário responsável;
- tipo da ação;
- entidade afetada;
- resumo da alteração.

Recursos obrigatórios:

- busca textual por responsável, e-mail, entidade e resumo;
- filtro por período, com data inicial e final;
- filtro por usuário responsável;
- filtro por tipo de ação;
- ordenação padrão do mais recente para o mais antigo;
- paginação.

O filtro de período será informado em horário local `America/Sao_Paulo` e convertido para UTC na consulta. A exibição de data e hora seguirá o mesmo fuso.

### 7.2 Detalhes do log

Cada linha terá ação **Ver detalhes**, em modal ou página somente leitura, contendo:

- responsável, data, hora e tipo de ação;
- entidade afetada e empresa relacionada, quando houver;
- resumo contextual do evento;
- comparação campo a campo entre valores anteriores e posteriores;
- campos sem alteração ocultos;
- indicação clara quando o alvo ou o ator já tiver sido excluído;
- metadados permitidos, quando existentes.

Valores de enum devem ser exibidos com rótulos de negócio, como “Empresa ativa”, “Trial” e “Administrador”, e não apenas com seus valores internos.

## 8. Autorização e integridade

- Apenas usuários que atendem a `isPlatformAdmin()` poderão listar ou visualizar detalhes.
- Não haverá ações de criar, editar, excluir, restaurar ou exportar logs nesta fase.
- A página não pertence a um tenant e não deve usar o escopo de empresa do painel `/app`.
- A exclusão de um usuário deve manter `actor_name` e `actor_email` no log, mesmo que `actor_id` seja definido como nulo.
- A exclusão de uma empresa deve manter `subject_label`, snapshots e o registro de auditoria, mesmo que `company_id` seja definido como nulo.
- As entradas serão criadas em transação compatível com a operação que representam, evitando evidência de uma alteração que não foi efetivada.

## 9. Estratégia de implementação

1. Criar migration, modelo e política de leitura para `admin_audit_logs`.
2. Criar enum ou catálogo de ações, normalizador de snapshots e `AdminAuditService` com mascaramento de dados sensíveis.
3. Integrar o registro às páginas administrativas de Empresas e Usuários, incluindo exclusões individuais e em massa.
4. Centralizar as alterações de vínculo usuário–empresa em um fluxo auditável e integrar os dois Relation Managers existentes.
5. Integrar `FailedJobs` aos eventos de retry, forget e limpeza.
6. Criar `AdminAuditLogResource` somente leitura no painel `/admin`, com listagem, filtros, busca e detalhes.
7. Criar testes de autorização, persistência, comparação, retenção pós-exclusão e filtros.

## 10. Critérios de aceite

1. Um superadministrador consegue abrir Auditoria no `/admin`; usuários comuns e usuários vinculados a empresas recebem acesso negado.
2. Criar, editar ou excluir empresa gera exatamente um log de negócio por ação concluída.
3. Alterações de módulos, assinatura, status e trial de uma empresa aparecem nos detalhes com valores anterior e posterior.
4. Criar, editar, desativar, elevar/remover superadmin ou excluir usuário gera log sem expor senha ou hash.
5. Criar, editar ou remover vínculo usuário–empresa informa os dois envolvidos, o papel e o status anterior/novo quando aplicável.
6. Reenviar, remover ou limpar jobs falhados gera log com o identificador ou total correspondente, sem payload sensível.
7. A exclusão posterior de empresa ou usuário não apaga nem torna ilegível o registro de auditoria.
8. Busca e filtros por período, usuário e tipo de ação retornam somente os registros esperados.
9. A lista apresenta data e hora no fuso de São Paulo, ainda que os dados sejam persistidos em UTC.
10. Abertura de dashboard, webhooks, rotas, Telescope e páginas de operação somente leitura não gera registros de auditoria.
11. Ações realizadas fora do `/admin`, como cadastro público e operações do painel da empresa, não aparecem neste módulo.
