# Especificação — Pedidos para restaurante (MVP)

**Status:** implementada  
**Versão:** 1.4  
**Data:** 14/09/2026  
**Produto:** Agendaqui

> Primeira versão para um cliente piloto de restaurante/food service. Mesas, garçom, iFood, modificadores complexos e multi-cozinha ficam fora deste MVP.

## 1. Objetivo

Uma empresa com perfil `restaurant` e módulo `orders` consegue:

1. marcar produtos como itens de cardápio online;
2. compartilhar o link público `/pedir/{company:slug}` para retirada, entrega ou **comer no local**;
3. ver os pedidos na tela da cozinha e avançar status até a conclusão;
4. consultar o histórico no painel;
5. configurar regras básicas de pedido.

O módulo é vendável no mesmo padrão de cobrança dos demais (`CompanyModule` + `module_prices`).

## 2. Decisões de produto

1. Novo perfil `CompanyProfile::Restaurant` (`restaurant`), rótulo **Restaurante ou food service**. Módulos padrão: Pedidos + WhatsApp. Financeiro, Vendas e Estoque são opcionais.
2. Novo módulo `CompanyModule::Orders` (`orders`), rótulo **Pedidos**. Preço de entrada: R$ 49 / mês (semestral ×5, anual ×10).
3. Rota pública própria: `/pedir/{slug}`. Não reutiliza `/agendar/{slug}`.
4. Entidade central é `Order` (comanda), não `Appointment`. Totais ficam no próprio pedido. Ao concluir, se o módulo **Vendas** estiver ativo, o pedido gera uma `Sale` e preenche `orders.sale_id`. `table_id` permanece nulo neste MVP.
5. Tela da cozinha é página Filament/Livewire sempre aberta, com polling de 4s.
6. Sem gateway de pagamento: o cliente paga na retirada, na entrega ou no local.
7. UI de mesas fica de fora. Pedidos `dine_in` entram pelo link público quando `dine_in_enabled` está ligado; `table_id` permanece nulo (sem mapa, reserva ou atribuição de mesa).

## 3. Status do pedido

- Retirada: `received` → `preparing` → `ready` → `completed`
- Comer no local: igual à retirada (`received` → `preparing` → `ready` → `completed`). Não usa `out_for_delivery`.
- Entrega: `received` → `preparing` → `ready` → `out_for_delivery` → `completed`
- `cancelled` exige motivo

## 4. Modelo de dados

### 4.1 `company_order_settings`

- `online_ordering_enabled`, `pickup_enabled`, `delivery_enabled`, `dine_in_enabled`
- `delivery_fee_cents`, `min_order_cents`, `delivery_radius_note`
- `orders_whatsapp_notify`
- textos da página pública (`page_title`, `page_description`, `confirmation_message`, `primary_color`)
- horário comercial reutilizado de `company_business_hours` quando existir (exibição no link público)

`dine_in_enabled` **padrão `false` na coluna** (empresas já existentes não ganham a opção de surpresa). Em `CompanyOrderSettingService::getOrCreate`, restaurantes novos recebem `dine_in_enabled = true`; demais perfis, `false`. A cozinha e o histórico mostram o rótulo **Comer no local**.

### 4.2 Produtos e cardápio

- `available_for_online_order`
- `menu_category_id` (FK para `menu_categories`; UX principal no formulário)
- `online_order_category` (string de compatibilidade; preenchida com o nome da categoria)
- `prep_time_minutes` (inteiro nulo)
- `product_variants`: tamanhos opcionais por item (`name`, `price` decimal, `sort_order`, `is_default`, `is_active`). Sem variantes, o pedido usa `sale_price`. Com 1+ variantes ativas, o cliente escolhe o tamanho (padrão pré-selecionado) e o item do pedido grava snapshot (`name` com “Item — Tamanho”, `variant_name`, preço).

`menu_categories` é por empresa (`company_id`, `name`, `slug` único, `sort_order`, `is_active`). Restaurantes novos (ou primeira visita a Categorias/Cardápio, se ainda não houver nenhuma) recebem Lanches, Bebidas, Sobremesas, Combos e Outros. O `/pedir/{slug}` agrupa pela ordem de `sort_order`.

Empresas sem o módulo Estoque gerenciam o cardápio em **Cardápio online** e as seções em **Categorias do cardápio**.

### 4.3 `orders` / `order_items` / `order_status_histories`

Pedidos têm número sequencial por empresa, `public_code`, snapshots de cliente e itens, taxas/totais em centavos e timestamps por marco de status.

## 5. Superfícies

| Superfície | Quem usa |
|---|---|
| `/pedir/{slug}` | Cliente (wizard Livewire: cardápio → tipo → dados → revisão) |
| Cozinha (`/app/empresa/{slug}/cozinha`) | Cozinha / recepção |
| Histórico de pedidos | Gerência |
| Cardápio online | Gerência |
| Categorias do cardápio | Gerência |
| Configurações de pedidos | Admin / gerente |

## 6. Permissões

- `view_orders` — ver histórico (inclui PII de contato: telefone/e-mail)
- `manage_orders` — configurações, cancelar, gerenciar cardápio (via papéis de admin/gerente)
- `kitchen_orders` — tela da cozinha e avanço de status. **Não** abre o Histórico.

Padrão: admin e gerente têm as três; recepção tem `view_orders` + `kitchen_orders`; colaborador só opera a Cozinha (`kitchen_orders`), sem Histórico.

## 7. WhatsApp

Se o módulo WhatsApp estiver ativo, houver instância Evolution e `orders_whatsapp_notify` estiver ligado, o cliente recebe mensagem em:

- pedido criado (`received`)
- pedido pronto (`ready`) — texto de retirada, consumo no local ou “logo sai para entrega”
- saiu para entrega (`out_for_delivery`)

O fluxo web funciona sem WhatsApp.

Restaurantes **não** usam o bot de agendamento. Com módulo WhatsApp, pedidos online ligados e “Enviar link do cardápio no WhatsApp” (padrão ligado), o bot responde só com o link `/pedir/{slug}` — sem conversa de horários. Regras do auto-reply:

- no máximo **um link por telefone, por empresa, por dia civil** no fuso da empresa (webhooks duplicados também são ignorados);
- só responde a texto que parece saudação ou pedido de cardápio (`oi`, `olá`, `cardápio`, `menu`, `pedir`, `pedido`, `bom dia`, etc.);
- grupos (`@g.us`) e mensagens `fromMe` continuam ignorados;
- o toggle desliga o recurso por completo.

Se o cardápio online estiver desligado, o bot não responde e também não cai no agendamento.

Empresas que não são restaurante (salão, clínica, etc.) continuam no bot de agendamento existente.

## 8. Fora do escopo (fase B)

- mapa de mesas, reserva de mesa e atribuição de `table_id`
- garçom / comanda presencial
- iFood e outros marketplaces
- modificadores/adicionais complexos (além de tamanhos/preço por tamanho)
- regras de combo, meio-a-meio
- multi-cozinha
- pagamento online / gateway
- mapa de estoque próprio do pedido (a baixa segue o `SaleService` existente, só para produtos com `tracks_stock`)

## 9. Venda na conclusão

A criação da venda acontece **somente** em `OrderService::transition` ao entrar em `completed` (cozinha e histórico usam o mesmo caminho).

| Condição | Efeito |
|---|---|
| Módulo **Vendas** (`sales`) ativo | Cria `Sale` + itens, liga `orders.sale_id`, origem `online_order` (“Pedido online”). Sem pagamentos: conta a receber em aberto (cliente paga na retirada, na entrega ou no local). |
| Vendas **não** ativo | Pedido conclui normalmente; `sale_id` fica nulo; nenhum erro. |
| Pedido já tem `sale_id` (ou `sales.reference_key` = `order:{id}`) | Não cria segunda venda. Reconcluir `completed → completed` é no-op idempotente. |
| Retirada, entrega e comer no local | Mesmo tratamento financeiro. Taxa de entrega vira item avulso “Taxa de entrega” (só na entrega). |
| Cancelar depois de concluído | **Não permitido** no MVP (`completed` é terminal). A venda e o recebível permanecem; não há estorno automático. |
| Cancelar antes de concluir | Sem venda. |
| Financeiro (`finance`) | Não é gate extra. O `SaleService` já abre o recebível como nas demais vendas do PDV. Caixa/ledger de entrada só quando alguém registrar o pagamento na conta a receber. |
| Estoque | Sem caminho novo. Cardápio online nasce com `tracks_stock = false`. Se o produto controla estoque, vale a regra já existente do PDV (saldo insuficiente **impede** a conclusão). |
| Sem usuário autenticado na transição | A conclusão do pedido não falha; a venda é adiada até uma transição idempotente com usuário (a cozinha sempre tem usuário). |
| Configuração “exigir pagamento na finalização” do PDV | Pedido online ignora essa trava (`allow_unpaid`): o combinado do MVP é pagar na retirada, na entrega ou no local. |
