# Especificação — Assinatura por módulo (cobrança manual)

**Status:** implementada  
**Versão:** 1.1  
**Data:** 04/09/2026  
**Produto:** Agendaqui

> Preços abaixo são **iniciais (faixa entrada)** e editáveis no admin.

## 1. Objetivo

Monetizar empresas do Agendaqui cobrando **por módulo** e **por ciclo** (mensal, semestral, anual), com pagamento **fora do sistema** (PIX/boleto). O app calcula o valor, guarda até quando está pago e **bloqueia o painel da empresa** quando o período acaba.

Quem quer só PDV ou só Financeiro não é forçado a um pacote.

## 2. Decisões de produto

1. O preço da empresa é a **soma** dos módulos ativos no ciclo escolhido. Não há SKU de pacote cobrado.
2. Ciclos: mensal (1 mês), semestral (6 meses = 5× o mensal, 1 mês grátis), anual (12 meses = 10× o mensal, 2 meses grátis).
3. Catálogo de preços no banco (`module_prices`), não hardcoded. Superadmin altera quando quiser.
4. Pagamento não passa pelo Agendaqui nesta versão. O admin **marca a fatura como paga** depois do PIX.
5. Ao pagar a fatura, grava **snapshot** do valor (`quoted_price_cents` e itens da fatura). Mudança no catálogo não altera o contrato vigente até a próxima renovação.
6. Trial continua **7 dias**. Depois, sem pagamento registrado com vencimento futuro, o acesso cai.
7. Carência de **3 dias** após `current_period_end` (PIX atrasado).
8. Empresa **já Ativa sem vencimento** (legado) continua acessando até o admin converter.
9. Atalhos Essencial / Profissional / Completo só **marcam módulos**; o valor continua sendo a soma.
10. Sem checkout, gateway ou o cliente montar o combo sozinho nesta versão. A fatura é o documento de cobrança (aberta / vencida / paga / cancelada).

## 3. Situação atual aproveitada

Já existia: `subscription_status` (trial / ativa / expirada), `trial_ends_at`, `enabled_modules`, middleware que manda para `/assinatura`, trial de 7 dias.

Lacunas cobertas por esta spec: Ativa não vencia; não havia preço, ciclo nem renovação; `/assinatura` só falava de trial.

## 4. Preços iniciais (faixa entrada, BRL)

Mensal sugerido; semestral = ×5; anual = ×10. Persistidos em **centavos**.

| Módulo | Mensal | Semestral | Anual |
|--------|--------|-----------|-------|
| Agenda | 49 | 245 | 490 |
| Vendas/PDV | 39 | 195 | 390 |
| Financeiro | 39 | 195 | 390 |
| Estoque | 25 | 125 | 250 |
| Prontuário clínico | 49 | 245 | 490 |
| WhatsApp operacional | 19 | 95 | 190 |
| Marketing | 39 | 195 | 390 |

Exemplos:

- Só PDV mensal → **R$ 39**
- Só Financeiro anual → **R$ 390**
- Agenda + WhatsApp mensal → **R$ 68**
- Completo (todos) mensal → **R$ 259**
- Completo anual → **R$ 2.590**

Se o WhatsApp “vier junto” com a Agenda, zere o preço do WhatsApp no admin.

Atalhos de marcação (não são planos):

- Essencial: Agenda + WhatsApp
- Profissional: Agenda + WhatsApp + Financeiro + PDV + Estoque
- Completo: todos os módulos

## 5. Modelo de dados

### 5.1 `module_prices`

- `module` (valor de `CompanyModule`)
- `interval`: `monthly` | `semiannual` | `annual`
- `price_cents`
- unique `(module, interval)`

### 5.2 `companies`

- `billing_interval` nullable
- `current_period_end` datetime nullable
- `quoted_price_cents` nullable (cache da última fatura **paga**)

### 5.3 `platform_invoices`

Cobrança da plataforma (o que a empresa paga pelo Agendaqui). Não misturar com contas a receber do tenant.

- `company_id`, `number`, `status` (`open` / `overdue` / `paid` / `cancelled`)
- `billing_interval`, `amount_cents`
- `items` JSON (`module`, `label`, `price_cents`)
- `period_start`, `period_end`, `due_at`, `paid_at`, `cancelled_at`
- `notes` (opcional, admin)

Config: `subscriptions.grace_days` = 3. `subscriptions.pix_instructions` é o texto de PIX na página Assinatura.

## 6. Regras de acesso

1. `is_active === false` → bloqueia
2. Trial → libera só se `trial_ends_at` no futuro
3. Ativa → libera se `current_period_end` é nulo (legado) **ou** `now <= current_period_end + 3 dias`
4. Expirada / outro → bloqueia

Job diário `subscriptions:expire` marca faturas **abertas** com `due_at` no passado como **vencidas**, e marca a empresa `expired` quando trial ou período (já com carência) passou.

O acesso da empresa **não** bloqueia só porque a fatura está vencida: continua valendo período + 3 dias de carência.

## 7. Faturas da plataforma

Tabela `platform_invoices` (não misturar com contas a receber do tenant).

Status: **aberta**, **vencida**, **paga**, **cancelada**. Só uma fatura aberta ou vencida por empresa.

Geração (`issueInvoice`):

- Exige ciclo + pelo menos um módulo
- Recusa se já existir aberta/vencida
- Número `AQ-{ano}-0001` (sequencial por ano)
- Itens: uma linha por módulo com preço do catálogo no ciclo (snapshot JSON). Total = soma
- `due_at`: se ainda está no trial ou `current_period_end` futuro, vence nesse dia; senão, hoje + 3 dias
- `period_start` / `period_end`: intervalo que o pagamento vai cobrir

Pagamento (só admin): grava `paid_at`, atualiza `quoted_price_cents`, avança `current_period_end` a partir de `max(agora, vigente)` pelo ciclo, status da empresa `active`. É o **único** caminho de renovação.

Cancelada: não renova. Só se ainda não estiver paga.

Job diário `subscriptions:issue-due-invoices`: empresas ativas com `current_period_end` nos próximos 7 dias, ou trial acabando em 3 dias, **sem** fatura aberta/vencida → gera sozinho.

## 8. Admin (`/admin`)

- Resource **Preços dos módulos**
- Resource **Faturas** (lista de todas as empresas): filtros de status, empresa, vencimento; ações gerar / marcar paga / marcar vencida / cancelar
- Ficha da empresa: módulos + ciclo + total ao vivo + vigente até + snapshot + atalhos; relation **Faturas**; ação **Gerar fatura** (não há mais Registrar pagamento)
- Tabela e dashboard com vencimentos e ativas sem vencimento

## 9. Empresa (`/app`)

- Banner se a assinatura vence em ≤ 7 dias
- **Assinatura** sempre no menu Configurações para Company Admin e Manager (Employee só vê se o acesso já estiver bloqueado)
- `/assinatura`: plano atual (módulos, ciclo, vigente até) + lista de faturas + destaque da fatura aberta/vencida. Instruções de PIX vêm de `subscriptions.pix_instructions`

## 10. Fora do escopo (próxima fase)

Gateway (Asaas/Stripe), boleto/PIX gerado no app, dunning, proration, o cliente escolher módulos no signup.

## 11. Critérios de aceite

- Só PDV: total = preço de Vendas no ciclo; fatura com 1 item e valor 39 no mensal
- Só Financeiro: idem
- Agenda + Financeiro: soma dos dois
- Recusa segunda fatura aberta/vencida na mesma empresa
- Pagar fatura renova mensal vs anual a partir de `max(agora, vigente)`
- Empresa vê a fatura em Assinatura
- `subscriptions:expire` marca fatura aberta vencida
- `subscriptions:issue-due-invoices` gera fatura antecipada quando o período está acabando
- Ativa vencida (depois da carência) vai para `/assinatura`
- Ativa no período ou na carência acessa
- Ativa legado sem `current_period_end` acessa
- Snapshot permanece se o catálogo mudar no dia seguinte
