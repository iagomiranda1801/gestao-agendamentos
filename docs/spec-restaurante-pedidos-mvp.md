# Especificação — Pedidos para restaurante (MVP)

**Status:** implementada  
**Versão:** 1.0  
**Data:** 12/09/2026  
**Produto:** Agendaqui

> Primeira versão para um cliente piloto de restaurante/food service. Mesas, garçom, iFood, modificadores complexos e multi-cozinha ficam fora deste MVP.

## 1. Objetivo

Uma empresa com perfil `restaurant` e módulo `orders` consegue:

1. marcar produtos como itens de cardápio online;
2. compartilhar o link público `/pedir/{company:slug}` para retirada ou entrega;
3. ver os pedidos na tela da cozinha e avançar status até a conclusão;
4. consultar o histórico no painel;
5. configurar regras básicas de pedido.

O módulo é vendável no mesmo padrão de cobrança dos demais (`CompanyModule` + `module_prices`).

## 2. Decisões de produto

1. Novo perfil `CompanyProfile::Restaurant` (`restaurant`), rótulo **Restaurante ou food service**. Módulos padrão: Pedidos + WhatsApp. Financeiro, Vendas e Estoque são opcionais.
2. Novo módulo `CompanyModule::Orders` (`orders`), rótulo **Pedidos**. Preço de entrada: R$ 49 / mês (semestral ×5, anual ×10).
3. Rota pública própria: `/pedir/{slug}`. Não reutiliza `/agendar/{slug}`.
4. Entidade central é `Order` (comanda), não `Appointment`. Totais ficam no próprio pedido. `sale_id` e `table_id` existem como ganchos nulos para fases seguintes.
5. Tela da cozinha é página Filament/Livewire sempre aberta, com polling de 4s.
6. Sem gateway de pagamento: o cliente paga na retirada ou na entrega.
7. UI de mesas fica de fora. O schema já aceita `table_id` nulo e fulfillment `dine_in`.

## 3. Status do pedido

- Retirada: `received` → `preparing` → `ready` → `completed`
- Entrega: `received` → `preparing` → `ready` → `out_for_delivery` → `completed`
- `cancelled` exige motivo

## 4. Modelo de dados

### 4.1 `company_order_settings`

- `online_ordering_enabled`, `pickup_enabled`, `delivery_enabled`
- `delivery_fee_cents`, `min_order_cents`, `delivery_radius_note`
- `orders_whatsapp_notify`
- textos da página pública (`page_title`, `page_description`, `confirmation_message`, `primary_color`)
- horário comercial reutilizado de `company_business_hours` quando existir (exibição no link público)

### 4.2 Produtos

- `available_for_online_order`
- `online_order_category` (string nula)
- `prep_time_minutes` (inteiro nulo)

Empresas sem o módulo Estoque gerenciam o cardápio em **Cardápio online**.

### 4.3 `orders` / `order_items` / `order_status_histories`

Pedidos têm número sequencial por empresa, `public_code`, snapshots de cliente e itens, taxas/totais em centavos e timestamps por marco de status.

## 5. Superfícies

| Superfície | Quem usa |
|---|---|
| `/pedir/{slug}` | Cliente (wizard Livewire: cardápio → tipo → dados → revisão) |
| Cozinha (`/app/empresa/{slug}/cozinha`) | Cozinha / recepção |
| Histórico de pedidos | Gerência |
| Cardápio online | Gerência |
| Configurações de pedidos | Admin / gerente |

## 6. Permissões

- `view_orders` — ver histórico
- `manage_orders` — configurações, cancelar, gerenciar cardápio (via papéis de admin/gerente)
- `kitchen_orders` — tela da cozinha e avanço de status

Padrão: admin e gerente têm as três; recepção vê e opera a cozinha; colaborador opera a cozinha.

## 7. WhatsApp

Se o módulo WhatsApp estiver ativo, houver instância Evolution e `orders_whatsapp_notify` estiver ligado, o cliente recebe mensagem em:

- pedido criado (`received`)
- pedido pronto (`ready`)
- saiu para entrega (`out_for_delivery`)

O fluxo web funciona sem WhatsApp.

## 8. Fora do escopo (fase B)

- mapa de mesas, reserva de mesa e `dine_in` no link público
- garçom / comanda presencial
- iFood e outros marketplaces
- modificadores/adicionais complexos
- multi-cozinha
- pagamento online
- baixa automática de estoque e vínculo obrigatório com venda/PDV
