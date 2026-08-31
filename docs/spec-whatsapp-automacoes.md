# Especificação — Automações de WhatsApp (lembrete, pós-venda e reconquista)

**Status:** implementação  
**Versão:** 1.0  
**Data:** 31/08/2026

## 1. Objetivo

Enviar WhatsApp no momento certo — uma vez, com link para remarcar — em vez de depender só de campanhas em massa.

Vale para qualquer perfil (salão, clínica, lava jato). O perfil lava jato só muda vocabulário, placa e textos padrão.

## 2. Tipos

| Tipo | Gatilho | Natureza | Opt-in de marketing |
|------|---------|----------|---------------------|
| `reminder` | Agendamento **confirmado** ou **em atendimento**, quando faltar `delay_value` horas para `start_at` | Operacional | Não. Exige telefone, módulo WhatsApp e confirmações WhatsApp ligadas |
| `after_sales` | Atendimento concluído + atraso de `delay_value` horas | Relacionamento (obrigado + CTA) | Não, se o texto for neutro. Reconquista promocional usa `win_back` |
| `win_back` | Último atendimento há `delay_value` dias, sem agendamento futuro | Marketing | **Sempre** `whatsapp_marketing_opt_in` |

Campanha manual ganha audiência `inactive_since_days` (clientes ativos com aceite, última visita há N dias, sem horário futuro).

## 3. Placeholders

`{nome}`, `{servico}`, `{data}`, `{hora}`, `{codigo}`, `{link}`, `{empresa}`, `{placa}`

`{link}` aponta para a página pública de agendamento da empresa.

## 4. Anti-spam

- Uma mensagem do mesmo tipo por agendamento (`reminder`) ou por atendimento (`after_sales`).
- `win_back`: no máximo uma mensagem por cliente a cada `cooldown_days`.
- Não enviar `after_sales` / `win_back` se já existir agendamento futuro (pendente, confirmado ou em atendimento).
- Quiet hours no fuso da empresa (padrão 08:00–20:00). Fora da janela, tenta de novo no próximo ciclo.
- Sem instância Evolution resolvível ou instância desconectada: não registra envio (retry).
- Sem telefone: não envia.
- Log em `whatsapp_automation_sends`.

## 5. LGPD

- Lembrete e pós-venda neutro: ligados ao serviço já contratado.
- Reconquista e campanha de inativos: somente com aceite explícito. Origem WhatsApp **não** concede aceite.
- Sem lista comprada.

## 6. Fora de escopo (v1)

Inbox, fidelidade/pontos, boxes/fila/frota, SMS, outro provedor além da Evolution.
