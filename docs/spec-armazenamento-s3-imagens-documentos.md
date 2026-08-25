# Especificação — Armazenamento de fotos e documentos no S3

**Status:** proposta para implementação  
**Versão:** 1.0  
**Data:** 20/08/2026  
**Complementa:** `docs/spec-clinica-odontologica.md`

## 1. Objetivo

Armazenar no serviço compatível com S3 todas as fotos e documentos enviados ao sistema, evitando dependência do disco do servidor e garantindo isolamento entre empresas.

O primeiro foco são os arquivos clínicos dos pacientes odontológicos: fotografias, radiografias, exames, receitas, atestados e termos. Logos de empresas também devem usar S3, em área separada.

## 2. Decisões de produto

1. Fotos e documentos de pacientes são privados. Nunca poderão ser acessados por URL pública permanente.
2. O sistema continuará controlando quem pode ver ou baixar arquivos; o S3 será somente o armazenamento.
3. A estrutura de pastas deve começar pelo nome do sistema e, em seguida, pelo identificador seguro da empresa.
4. O nome visível da empresa não será usado diretamente no caminho. Será usado o `slug` da empresa, que evita espaços, acentos e alteração de caminho quando o nome comercial mudar.
5. Arquivos novos serão gravados diretamente no S3. Arquivos existentes no servidor serão migrados de forma gradual, verificável e reversível.
6. O banco de dados continuará armazenando apenas metadados e o caminho do objeto, nunca o conteúdo do arquivo.

## 3. Estrutura de pastas

### 3.1 Arquivos clínicos privados

```text
agendaqui/{empresa-slug}/pacientes/{prontuario-ou-id}/
  fotos/{uuid}.{extensao}
  documentos/{uuid}.{extensao}
```

Exemplo:

```text
agendaqui/clinica-naves/pacientes/P000004/fotos/9cfb2d1a-....webp
agendaqui/clinica-naves/pacientes/P000004/documentos/1bd782e4-....pdf
```

Regras:

- usar número de prontuário quando existir; antes dele, usar `paciente-{id}`;
- usar UUID para o nome físico do arquivo;
- preservar o nome original somente como metadado no banco;
- classificar imagens clínicas no diretório `fotos` e os demais anexos em `documentos`;
- não colocar CPF, nome completo, telefone ou dado clínico no caminho do arquivo.

### 3.2 Logo da empresa

```text
agendaqui/{empresa-slug}/empresa/logo/{uuid}.{extensao}
```

O logo pode ser exibido publicamente no agendamento online. A implementação poderá usar URL pública/CDN apenas para esse tipo de ativo ou uma URL temporária, sem tornar fotos de pacientes públicas.

### 3.3 Foto de perfil do WhatsApp

Fotos de perfil sincronizadas do WhatsApp continuam como referência externa (`profile_picture_url`). Elas não devem ser copiadas para o S3 nesta entrega.

## 4. Escopo funcional

### 4.1 Envio de documentos clínicos

Ao anexar arquivo ao paciente, o sistema deve:

1. validar permissão clínica, empresa e paciente;
2. validar extensão, MIME type e tamanho máximo;
3. decidir a subpasta por tipo de anexo;
4. enviar o arquivo ao disco `s3`;
5. criar o registro `clinical_attachments` somente após o envio bem-sucedido;
6. salvar `disk = s3`, caminho, nome original, MIME type, tamanho, usuário e data;
7. registrar auditoria de envio;
8. remover o objeto recém-enviado se houver falha ao persistir o banco.

Arquivos suportados inicialmente: PDF, JPEG, PNG, WEBP e DICOM, limitados a 20 MB por arquivo.

### 4.2 Consulta e download seguros

Fotos e documentos clínicos devem ser entregues de uma das formas abaixo:

- **preferencial:** rota autenticada do sistema faz o streaming do objeto S3 após validar as permissões;
- **alternativa:** gerar URL temporária assinada, com validade curta, somente após a autorização do sistema.

Em ambos os casos:

- não usar `Storage::path()`, pois o S3 não possui caminho físico local;
- registrar auditoria de download/visualização;
- negar acesso quando o usuário não tiver permissão clínica, estiver fora da empresa ou o paciente estiver fora do seu escopo autorizado;
- retornar mensagem simples de arquivo indisponível caso o objeto não exista.

### 4.3 Logos das empresas

O cadastro de logo deve gravar no disco S3 apropriado e atualizar `logo_path`. Ao trocar o logo, o novo arquivo deve ser enviado primeiro; o anterior só pode ser removido depois de a empresa apontar para o novo caminho.

## 5. Configuração e infraestrutura

### 5.1 Variáveis de ambiente

O ambiente de produção deve conter somente por variáveis seguras:

```dotenv
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=
AWS_ENDPOINT=
AWS_USE_PATH_STYLE_ENDPOINT=false
```

As credenciais não podem ser inseridas em código, documentação versionada ou telas administrativas. Caso um segredo tenha sido exposto fora do ambiente seguro, ele deve ser revogado e recriado.

### 5.2 Dependência

Adicionar e travar no projeto o adaptador Laravel/Flysystem para S3 compatível com a versão do framework. A configuração deve suportar AWS S3 e provedores compatíveis, como Cloudflare R2, por meio de `AWS_ENDPOINT`.

### 5.3 Bucket e permissões

- bucket privado por padrão;
- credencial com acesso somente aos prefixos do sistema, leitura/gravação/listagem controlada e sem permissões administrativas globais;
- CORS somente se a aplicação optar por upload direto do navegador em fase futura;
- versionamento do bucket recomendado para recuperação de exclusões acidentais;
- política de retenção e ciclo de vida definida pela clínica conforme exigências legais.

## 6. Ajustes técnicos necessários

### 6.1 Serviço de anexos clínicos

Substituir o uso fixo do disco `local` por um resolvedor de armazenamento clínico, por exemplo `ClinicalStorageService`.

Responsabilidades:

- escolher o disco `s3`;
- gerar caminhos padronizados;
- separar foto de documento;
- gravar/remover objetos;
- produzir resposta de download segura;
- permitir testes com disco falso/local em ambiente de teste.

`ClinicalAttachmentService` continuará responsável por autorização, validação, persistência e auditoria, delegando a manipulação do objeto ao novo serviço.

### 6.2 Modelo e banco de dados

`clinical_attachments.disk` já permite registrar o disco e deve passar a receber `s3` para novos arquivos.

Adicionar campos de suporte à migração, se necessários:

- `storage_migrated_at` — data da cópia confirmada para S3;
- `storage_checksum` — hash do arquivo para validação de integridade;
- `storage_migration_error` — mensagem técnica restrita para diagnóstico.

Esses campos não devem ser mostrados em telas de usuários comuns.

### 6.3 Logo da empresa

Adaptar o upload atual para um disco configurável de logos e adaptar `Company::logoUrl()` para gerar URL compatível com o disco usado. Caminhos antigos em `public` devem permanecer legíveis durante a transição.

## 7. Migração de arquivos existentes

Criar comando assíncrono e idempotente, por exemplo:

```text
php artisan storage:migrate-clinical-attachments-to-s3
```

Para cada anexo com `disk = local`:

1. localizar o arquivo local pelo caminho registrado;
2. calcular checksum e tamanho;
3. enviar ao caminho S3 padronizado;
4. validar existência, tamanho e checksum quando possível;
5. atualizar o registro para `disk = s3` e novo `path` em transação;
6. manter o original local até a conclusão de todo o lote e período de conferência;
7. registrar sucesso ou erro por arquivo;
8. permitir reexecução sem duplicar nem sobrescrever indevidamente.

Após validação manual e backup, uma ação separada poderá remover os arquivos locais já migrados. Essa remoção não faz parte da primeira execução da migração.

Logos devem ter comando ou etapa independente, pois têm regra de visibilidade diferente.

## 8. Segurança e privacidade

- fotos intraorais, radiografias e documentos são dados sensíveis e devem permanecer privados;
- objetos clínicos não podem ter ACL pública;
- logs não podem guardar conteúdo, URL assinada completa ou dados clínicos;
- exclusão pelo usuário continua sendo exclusão lógica no banco e auditada;
- exclusão física no S3 deve obedecer a prazo de retenção definido pela clínica;
- backups e retenção devem ser configurados no provedor de armazenamento.

## 9. Critérios de aceite

1. Uma foto anexada ao paciente é criada no S3, no prefixo `agendaqui/{empresa-slug}/pacientes/.../fotos`.
2. Um PDF clínico é criado no prefixo `.../documentos`.
3. O registro do anexo grava `disk = s3` e o caminho correto.
4. Dois pacientes ou empresas diferentes nunca compartilham caminho de arquivo.
5. Usuário sem acesso clínico não consegue visualizar, baixar ou obter URL temporária de anexo.
6. Nenhuma foto clínica pode ser aberta por URL pública permanente.
7. Download funciona com S3/R2, sem depender de caminho local no servidor.
8. Em erro de banco após upload, o objeto recém-criado é removido do S3.
9. A migração de arquivo local para S3 preserva nome original, tamanho e integridade.
10. Reexecutar a migração não cria cópias duplicadas nem altera anexos já confirmados.
11. Logos novos são gravados no S3 e continuam aparecendo no painel e no agendamento público.
12. Arquivos locais antigos permanecem acessíveis até sua migração ser confirmada.

## 10. Testes obrigatórios

- upload de foto, PDF e DICOM usando disco S3 falso;
- composição correta de caminho por sistema, empresa, paciente e categoria;
- isolamento entre empresas;
- autorização de visualização e download;
- falha de upload e falha de persistência com limpeza do objeto;
- geração de download por streaming ou URL temporária;
- migração idempotente de anexos locais;
- logo gravado no caminho da empresa;
- compatibilidade de logo e anexo local durante transição.

## 11. Fora do escopo inicial

- cópia automática de fotos de perfil de contatos do WhatsApp;
- reconhecimento de imagem, OCR ou interpretação clínica;
- upload direto do navegador para o S3;
- exclusão física imediata de documentos clínicos;
- galeria visual avançada de fotos odontológicas.
