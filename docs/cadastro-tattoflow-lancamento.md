# TattoFlow — cadastro e plano de lançamento

## Experiência

A landing do domínio de tatuagem apresenta a oferta de R$ 79,90/mês em uma faixa no início e em uma seção de plano com formulário integrado. `/cadastro`, no mesmo domínio, abre uma página dedicada com o mesmo componente Livewire. Os outros segmentos mantêm seu cadastro atual.

Cinco campos: estúdio, responsável, WhatsApp com DDD, e-mail e senha. O servidor gera o identificador do estúdio, cria empresa, usuário e vínculo de administrador em uma transação, inicia os 7 dias de teste e abre o painel autenticado. Há validação de e-mail único, senha mínima de oito caracteres e limite de cinco tentativas de envio por IP em cinco minutos.

## Pacote

Perfil `tattoo_studio`, ciclo mensal, módulos: Agenda (inclui orçamentos e referências de tatuagem), WhatsApp, Financeiro, Estoque, Vendas/PDV e Marketing. Os módulos de assistência técnica, restaurantes e prontuário clínico não fazem parte desse pacote.

O bot de coleta de pedidos fica habilitado nas configurações, mas começa a atender somente após a conexão do WhatsApp. IA generativa continua dependendo da configuração de provedor e chave da empresa, conforme a regra existente. O cadastro não cria serviços, tatuadores fictícios, conexão WhatsApp nem credenciais de terceiros.

## Preço e pagamento

`TATTOFLOW_LAUNCH_PRICE_CENTS=7990` define o valor para novos cadastros. O servidor grava `subscription_plan=tattoflow_launch` e `subscription_price_cents` na empresa, sem aceitar preço ou módulos enviados pelo navegador. Empresas existentes não são convertidas automaticamente.

Faturas mensais do pacote usam o valor registrado na empresa: itens do catálogo mais o ajuste de lançamento. A soma dos itens é igual ao total efetivamente cobrado. Alterações posteriores no catálogo ou no preço de novos cadastros não mudam o valor registrado desses contratos. Outros ciclos e empresas sem o pacote continuam usando o catálogo.

Após os 7 dias, permanece o fluxo existente: fatura da plataforma, pagamento por PIX fora do sistema e confirmação pela equipe. O cadastro não cobra cartão, não confirma pagamentos e não cria checkout Asaas. A rotina existente `subscriptions:issue-due-invoices` deve continuar agendada para gerar faturas e renovações.

## Implantação

Executar `php artisan migrate` para adicionar os dois campos de plano na tabela `companies`, e `npm run build` para gerar os assets. A migration é aditiva, sem conversão de registros existentes. No ambiente local ela já foi aplicada.

## Verificação

Testes de cadastro, validação, limitação de tentativas, isolamento do preço por módulo, faturamento e renovação promocional; regressão do cadastro geral, assinatura e segmento de tatuagem. Inspeção visual em desktop e celular, com envio inválido no navegador para conferir a mensagem de erro. Contas válidas de teste são criadas apenas no banco SQLite em memória dos testes automatizados.
