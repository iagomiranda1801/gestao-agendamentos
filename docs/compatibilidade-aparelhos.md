# Compatibilidade de aparelhos

Menu **Assistência técnica → Compatibilidade**, restrito a empresas ativas do perfil de assistência técnica, com módulo de OS e permissão `manage_service_orders`. As ações revalidam o acesso.

## Consulta

- Três abas independentes: Películas, Capas e Telas.
- Somente Películas consulta a API pública Tabela Películas: `GET https://tabelapeliculas.com.br/index?route=api/sistemas&modelo=...`.
- O endereço pode ser configurado por `TABELA_PELICULAS_API_URL`. Não há chave de acesso na documentação atual: https://tabelapeliculas.com.br/api-docs.
- Primeira pesquisa importa o resultado para `device_compatibility_lookups`. Pesquisas equivalentes em caixa, acentos e espaços usam o registro local. Resultados positivos permanecem no banco; buscas vazias são reconsultadas após 24 horas.
- O catálogo público é compartilhado entre empresas. Não guarda clientes, aparelhos recebidos, estoque ou qualquer dado privado das lojas. Consultas ainda não conhecidas são enviadas ao fornecedor somente como texto de modelo de aparelho.
- Bloqueio por consulta e índice único evitam duplicidade de importação concorrente. Falhas e respostas inválidas não são persistidas como buscas sem resultado.
- Preservar variantes e direção das indicações. Não inferir relações transitivas nem transformar os links afiliados de capas e telas em compatibilidade.
- Armazenar apenas modelo, marca e modelos compatíveis; fonte e data ficam internas. Não importar anúncios ou links de compra. A API é uma referência externa, não uma verificação de encaixe pela loja.
- Capas e Telas mostram “Compatibilidade ainda não cadastrada”. Cadastro manual e novas fontes para essas categorias são evolução futura.

## Implantação

Aplicar a migração `2026_11_04_160000_create_device_compatibility_lookups_table.php` e executar o build dos assets. Não é necessária uma carga completa ou worker para as consultas. Verificar com o fornecedor as condições de armazenamento em escala e limites de uso antes de ampliar o consumo. Não foi implementada extração integral do catálogo.

## Verificação

`php vendor/phpunit/phpunit/phpunit tests/Feature/ServiceOrders/DeviceCompatibilityTest.php`

Testes usam banco SQLite em memória e HTTP simulado, sem chamadas reais ao fornecedor.
