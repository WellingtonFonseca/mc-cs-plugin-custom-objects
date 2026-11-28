# Changelog

Só as mudanças feitas neste fork, a partir de 2026-08-24 (o que veio do plugin original não está aqui). Um bloco por dia, do mais novo para o mais antigo. Formato fixo: `## AAAA-MM-DD` e uma linha `- ` por mudança (a tela do plugin lê este arquivo).

## 2026-11-28
- A busca de itens da API REST agora é paginada: parâmetros limit (1 a 100, padrão 25) e offset, em ordem de id. A resposta traz total (todos os itens que batem), limit e offset. Atenção: chamadas sem limit (ex.: fluxos do n8n) passam a receber no máximo 25 itens.
- Nova rota da API REST para ler um item: GET /api/customobjects/{alias}/items/{id}, com o item e linkedContactCount (quantos contatos estão ligados a ele).
- A API REST de itens agora confere as permissões do usuário: a busca exige ver os itens (quem só vê os próprios itens recebe só os que criou), criar e atualizar exigem criar ou editar, e apagar exige apagar. Sem permissão a resposta é 403, antes de qualquer consulta, então não revela quais itens existem. Em lote, se um item não é permitido nada é salvo

## 2026-11-27
- Importar definição (aba Clone) em um Custom Object que já existe agora também acerta as opções dos campos de seleção e seleção múltipla pelo JSON: opções novas são adicionadas, rótulos diferentes são atualizados, opções que não estão no JSON são removidas e a ordem do JSON é aplicada. Valores padrão que apontavam para opções removidas são limpos. A prévia e o resultado listam as opções alteradas por campo
- Novas ferramentas MCP para sincronizar a estrutura dos Custom Objects entre instâncias (só aparecem quando o plugin MCP está instalado): custom_objects_list lista os objetos, custom_objects_get_definition devolve a definição (objeto e campos, sem itens, o mesmo JSON da aba Clone) e custom_objects_apply_definition aplica essa definição em outra instância. Cria o objeto se o alias for novo; se já existe, acrescenta os campos que faltam e acerta as opções dos campos de seleção, sem alterar nem apagar os demais campos. Com dryRun ligado (padrão) nada é salvo e o relatório mostra o que seria feito
- As ferramentas MCP dos Custom Objects agora declaram suas dicas de uso para os clientes MCP: custom_objects_list e custom_objects_get_definition são só leitura, e custom_objects_apply_definition grava, mas não apaga campos nem itens e pode ser repetida sem efeito extra

## 2026-11-24
- A lista de itens de um Custom Object ganhou a coluna Modificado (data e hora da última alteração; se o item nunca foi alterado, mostra a data de criação), entre o Nome e o ID. Dá para ordenar por ela
- O título da janela de detalhes agora é Custom Object Item (#id) para itens e Custom Object (#id) para objetos, em vez do nome. A janela de detalhes do item ficou mais larga e mostra título e status quando o plugin Patches está instalado
- A página do item não repete mais o selo de status no cabeçalho da coluna esquerda (ele já aparece no título da página)

## 2026-11-19
- A API de criar e alterar itens (POST, PATCH, PUT) não dá mais erro 500 quando dois pedidos gravam itens com campo de seleção múltipla ao mesmo tempo. A causa era um deadlock do banco (1213): o plugin apagava as opções antigas mesmo quando o item era novo, e dois pedidos travavam um ao outro. Agora ele só apaga quando existem opções, e apaga pelo id
- Quando o banco ainda assim cancela um pedido por deadlock ou por espera de bloqueio, a API de itens responde 503 com o cabeçalho Retry-After: 1 e não salva nada. Quem chama deve repetir o pedido inteiro. No n8n de produção, ative Retry On Fail para o status 503 (quem configura é o Wellington)
- O alias do Custom Object agora é um índice único: dois objetos nunca ficam com o mesmo alias, nem se dois pedidos de criação chegarem juntos (um cria, o outro recebe 409). Ao atualizar o plugin (mautic:plugins:reload), a migração para sem alterar nada se já existirem aliases repetidos, e diz quais são. Maiúsculas, acentos e espaço no fim contam como o mesmo alias
- Nova API para o tipo de Custom Object: POST /api/customobjects cria o objeto com seus campos (o mesmo JSON da aba Clonar, com isPublished opcional), GET /api/customobjects/{alias} lê, DELETE /api/customobjects/{alias} apaga (com os itens). Alias que já existe dá 409 sem salvar nada; apagar um objeto usado em filtro de segmento, ou um objeto mestre que tem objeto filho, dá 409 (com a lista dos segmentos, ou o nome do filho)

## 2026-11-18
- A janela de configurações do plugin abre em uma aba Sobre, com o ícone, o nome e uma frase sobre o plugin. A janela fica mais larga (780 px)

## 2026-11-13
- Na janela de um Custom Object (clique no nome na lista), a tabela de campos não fica mais colada nas abas

## 2026-11-12
- A lista de Custom Objects e a lista de itens têm a mesma barra de Campos personalizados: busca, menu de engrenagem (importações e exportação) e botão Novo. As ações do topo da página saíram
- A busca da lista de Custom Objects agora funciona: procura nos dois nomes e no alias do objeto

## 2026-11-11
- Novo ícone do plugin (uma matrioska), no tile de Configurações > Plugins e na lista de plugins. Ícone "Matryoshka" da coleção Linear Womens Day Elements, do SVG Repo (svgrepo.com/svg/157655/matryoshka), licença CC0. Obrigado a quem desenhou

## 2026-11-06
- A API de apagar itens (um item ou um lote) agora responde sempre {total, deleted, alreadyGone}. Um id que já não existe não dá mais erro: aparece em alreadyGone e os outros itens são apagados
- Um id repetido no mesmo lote é apagado uma vez, em vez de dar 404 com o id de um item que existe
- Um id que não é um número inteiro positivo dá erro 400; um item de outro Custom Object continua dando 404 e nada é apagado
- O nome de um item aceita no máximo 191 caracteres. Um nome maior dá erro 400 com essa mensagem, tanto na API quanto na tela; antes, de 192 a 255 caracteres dava erro 500
- Os campos de texto de objetos, campos e opções dos campos (alias, nomes, descrição, rótulo, tipo, valor padrão) também aceitam no máximo 191 caracteres, o tamanho das colunas. Um texto maior mostra uma mensagem no formulário em vez de um erro 500

## 2026-10-28
- A lista de objetos tem a coluna Status com o ícone de ativo e inativo, que abre ao passar o mouse; Ativar e Desativar é o primeiro item do menu da linha
- O badge de status abre dentro da coluna, sem empurrar as outras, e os nomes não quebram de linha
- Novo histórico de mudanças por dia (este arquivo), em uma aba própria do modal do plugin em Configurações > Plugins
- O plugin ganhou o tile "Custom Objects" em Configurações > Plugins, já ligado e com o ícone do plugin (versão 0.0.31)

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
