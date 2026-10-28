# Changelog

Só as mudanças feitas neste fork, a partir de 2026-08-24 (o que veio do plugin original não está aqui). Um bloco por dia, do mais novo para o mais antigo. Formato fixo: `## AAAA-MM-DD` e uma linha `- ` por mudança (a tela do plugin lê este arquivo).

## 2026-10-27
- Importar a definição de um Custom Object a partir do JSON da aba Clonar, com verificação antes de confirmar (o que seria feito, categoria, lista de tipos divergentes), botões Limpar e Confirmar, e o resultado como alertas na página do objeto
- Aviso ao adicionar campos obrigatórios a um objeto que já tem itens
- A aba Clonar continua aberta depois de um Salvar que falha na validação
- Menus das linhas das listas de itens e de objetos: Excluir com o hover vermelho das outras listas

## 2026-10-26
- Definição do Custom Object como JSON somente leitura, em uma aba
- Página do objeto: tabela de campos na aba Campos, acima do gráfico, e a aba Clonar com o JSON da definição; o modal do objeto também tem as abas Campos e Clonar
- Botão de adicionar valor das opções de um campo fica abaixo da lista

## 2026-10-25
- Modal do item mostra as mesmas linhas de detalhe da página do item

## 2026-10-24
- Correção: "em" / "não em" no nome do item quando o filtro combinado está desligado
- Correção: a lista de um usuário que só pode ver os próprios itens
- Muitos testes novos (helpers, resolvedor de itens, modelo de itens, colunas de relatório, tokens de email) e Infection no plugin inteiro

## 2026-10-22
- Novo SegmentItemResolver: os itens que as condições de Custom Object de um segmento selecionam
- Botão Detalhes dos modais do objeto e do item com o ícone "i"

## 2026-10-21
- Editar, Detalhes e Fechar no rodapé dos modais do item e do objeto

## 2026-10-20
- Condição de campanha: "incluindo" / "excluindo" em campos de texto e no nome do item
- Condição de campanha de um objeto chama-se "Campos de <objeto>" (pt_BR) e "<objeto> fields" (en_US)
- Traduções dos nós de campanha de um objeto no builder

## 2026-10-16
- Botão Voltar nas páginas de um Custom Object e de um item
- Menu das linhas das listas de objetos e de itens: opção "Detalhes"
- Lista de objetos: o nome abre um modal com os campos do objeto
- Formulário do objeto: botões de editar e excluir campo no estilo do core, maiores, e excluir um campo pede confirmação
- Modal de detalhes do item: um texto longo sem espaços quebra dentro da célula
- Correção: "excluindo" em um campo de Custom Object ou no nome do item era ignorado no segmento

## 2026-10-15
- Condição de campanha em vários critérios do MESMO item, com botão de adicionar critério no tema, linhas na largura toda e idioma do sistema
- Busca da lista de itens aceita o rótulo do campo, e não só o alias
- O nome do item abre um modal somente leitura na lista de itens e na aba do contato, com o id no título
- Pasta de exportação com alternativa e importação de linha dentro de uma transação

## 2026-10-14
- Segmento: "vazio" em um campo também acha itens sem linha de valor

## 2026-10-13
- Segmento: "em" / "não em" em campos de texto, com a rota de valores para os chips

## 2026-10-07
- Aba do contato: botões de filtro e de link seguem o visual do filtro do Histórico e o tema
- API de itens: lê os valores de todos os itens juntos, sem uma consulta por item

## 2026-10-06
- Detalhe do item: o bloco de detalhes abre por padrão
- Detalhe do item: select mostra o valor completo e multiselect todas as opções

## 2026-10-02
- Filtros combinados de Custom Object: negam "não contém" e "diferente" do nome do item, leem multiselect da tabela de opções, não tratam 0 como vazio em número e data, leem o "entre" do próprio critério, e expõem os itens por uma consulta pública
- Um número não preenchido é gravado como NULL, e não 0
- Busca na lista de itens por campo (alias:valor) e filtro da API de itens por todos os tipos de campo, com avisos curtos e traduzidos para alias desconhecido e valor inválido
- Lista de itens: sem a segunda dica "crie seu primeiro Custom Object" depois de uma busca
- Testes migrados do PHPUnit 9 para o PHPUnit 10

## 2026-10-01
- Filtros combinados de Custom Object usam o operador de cada critério e vêm ligados por padrão
- Índice (campo, valor) nos valores de texto (migração 0.0.30)

## 2026-09-30
- Novo tipo de campo: decimal
- Correção: índices FULLTEXT apagados pela migração 0.0.12, recriados pela 0.0.29

## 2026-09-29
- API: exclusão em lote de itens

## 2026-09-15
- API: exclusão de um item

## 2026-08-26
- API de escrita: POST/PATCH estritos e PUT que cria ou atualiza
- Escritas em lote em uma única transação no banco

## 2026-08-25
- API de escrita direta de itens (POST/PATCH)

## 2026-08-24
- API: GET /api/customobjects/{alias}/items lista e filtra os itens de um objeto
