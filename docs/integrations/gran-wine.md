# Integração GRAN.Wine → AgendaAqui

## Preparação

1. Aplique a migration `2026_10_14_100000_create_external_integrations.php` no ambiente desejado.
2. Cadastre ou selecione a empresa **Vitis Blend** no AgendaAqui. Nenhum ID de empresa é configurado no cliente da API.
3. Entre no painel da Vitis Blend como administrador e abra **Configurações → Integrações externas**.
4. Crie a credencial `GRAN.Wine Landing Page` e copie a chave exibida uma única vez. O escopo inicial é `customers:create`.
5. Armazene a chave como segredo de uma Edge Function ou outra função server-side do Lovable. Nunca use variável pública Vite, código do navegador ou log.

A chave pertence à empresa selecionada no painel. Administradores de outras empresas não a visualizam. É possível desativar, revogar ou gerar uma nova chave na mesma tela. A regeneração invalida a chave anterior imediatamente. O painel mostra somente o prefixo e a data do último uso.

## API

`POST https://SEU-DOMINIO/api/v1/integrations/customers`

Headers: `Authorization: Bearer SUA_API_KEY`, `Content-Type: application/json`, `Accept: application/json`.

```json
{
  "name": "João da Silva",
  "email": "joao@email.com",
  "phone": "34999999999",
  "cpf": "12345678909",
  "source": "gran_wine",
  "notes": "Solicitação de admissão GRAN.Wine",
  "metadata": {
    "access_type": "indicacao_membro",
    "referred_by": "Carlos Oliveira",
    "club": "GRAN.Wine",
    "admission_status": "pending"
  }
}
```

`name` e `phone` são obrigatórios. `email` e `cpf` são opcionais, mas validados quando presentes. O telefone deve ter 10 a 13 dígitos após remover pontuação. `source` aceita letras minúsculas, números e `_`. `metadata` aceita somente `access_type`, `referred_by`, `club` e `admission_status` (`pending`, `approved`, `rejected`), com limites de tamanho. Para solicitações de admissão, envie `pending`; o cadastro de cliente não aprova a entrada no clube. `company_id` e `tenant_id` são rejeitados.

```bash
curl -X POST 'https://SEU-DOMINIO/api/v1/integrations/customers' \
  -H 'Authorization: Bearer SUA_API_KEY' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  --data '{"name":"João da Silva","phone":"34999999999","cpf":"12345678909","source":"gran_wine","metadata":{"admission_status":"pending","club":"GRAN.Wine"}}'
```

Criado (`201`):

```json
{"success":true,"message":"Cliente cadastrado com sucesso.","data":{"id":125,"name":"João da Silva","created":true}}
```

Já existente (`200`):

```json
{"success":true,"message":"Cliente já cadastrado nesta empresa.","data":{"id":125,"name":"João da Silva","created":false}}
```

Erros: `401` credencial ausente, inválida ou revogada; `403` integração sem escopo, desativada, empresa inativa ou HTTP em produção; `409` identificadores conflitantes; `422` campos inválidos (com objeto `errors`); `429` limite excedido; `500` erro interno. Todos retornam `success: false` e `message`. O limite é de 30 requisições por chave e 60 por IP por minuto.

O CPF tem prioridade na identificação. Telefone e e-mail são examinados somente na empresa vinculada à chave. Se identificadores apontarem para clientes diferentes ou a identidade não for segura, a API retorna `409`. Clientes existentes não têm seus dados sobrescritos. Cada envio aceito grava os dados da solicitação em `external_customer_submissions`, separados do cadastro de cliente. Nenhum agendamento é criado.

## Exemplo de função server-side

```ts
// Edge Function: segredo AGENDAQUI_API_KEY configurado no servidor.
const allowedOrigin = 'https://SEU-DOMINIO-LOVABLE';

Deno.serve(async (request) => {
  if (request.headers.get('origin') !== allowedOrigin) {
    return new Response('Não autorizado', { status: 403 });
  }
  if (request.method === 'OPTIONS') {
    return new Response(null, { headers: {
      'Access-Control-Allow-Origin': allowedOrigin,
      'Access-Control-Allow-Methods': 'POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
    } });
  }
  if (request.method !== 'POST') return new Response('Método inválido', { status: 405 });

  const key = Deno.env.get('AGENDAQUI_API_KEY');
  if (!key) return new Response('Integração indisponível', { status: 503 });

  // Antes do envio: verificar CAPTCHA no servidor e aplicar limite por IP em
  // armazenamento compartilhado da Edge Function. Rejeitar payloads grandes.
  const form = await request.json();
  if (typeof form.name !== 'string' || form.name.length < 2 || form.name.length > 255 ||
      typeof form.whatsapp !== 'string' || form.whatsapp.length > 32) {
    return new Response('Dados inválidos', { status: 422 });
  }

  const response = await fetch('https://SEU-DOMINIO/api/v1/integrations/customers', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${key}`,
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify({
      name: form.name,
      phone: form.whatsapp,
      cpf: form.cpf || undefined,
      email: form.email || undefined,
      source: 'gran_wine',
      metadata: {
        access_type: form.accessType,
        referred_by: form.referredBy,
        club: 'GRAN.Wine',
        admission_status: 'pending',
      },
    }),
  });
  return new Response(await response.text(), {
    status: response.status,
    headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': allowedOrigin },
  });
});
```

O frontend deve chamar somente a função server-side. Configure CORS nessa função apenas para o domínio da landing page, valide todos os dados nela e limite tentativas por IP. A API exige HTTPS em produção e não depende de CORS para autenticação.
