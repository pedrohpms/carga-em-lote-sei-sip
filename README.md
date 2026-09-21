# Carga em Lote (SIP + SEI)

Módulos de extensão para o Sistema Eletrônico de Informações - **SEI** e o Sistema de Permissões - **SIP**, que fazem carga em massa de unidades, hierarquia, usuários, permissões e demais cadastros administrativos a partir de uma planilha — chamando diretamente as classes de regra de negócio (`*RN`) que as próprias telas administrativas já usam, **sem automação de navegador e sem tocar em nenhum arquivo do core** do SEI ou do SIP.

## 📚 Sumário

- [Introdução](#introducao)
- [A quem se destina](#a-quem-se-destina)
- [Como instalar](#como-instalar)
- [Como usar](#como-usar)
- [Orientações gerais e observações](#orientacoes-gerais-e-observacoes)
- [SIP — Unidades e Hierarquia](#sip-unidades)
- [SIP — Usuários e Primeiras Permissões](#sip-usuarios)
- [SEI — Dados Complementares de Unidade](#sei-unidades)
- [SEI — Contato de Usuários](#sei-contatos)
- [SEI — Assuntos](#sei-assuntos)
- [SEI — Tipos de Processo](#sei-tipos)
- [Status](#status)

---

<a name="introducao"></a>
## ℹ️ Introdução

Este repositório contém **dois módulos** — um para o **SEI**, outro para o **SIP** —
que automatizam o cadastro em massa de unidades, hierarquia, usuários, permissões e demais parametrizações administrativas, a partir de uma planilha.

Segue estritamente o modelo de extensão oficial do TRF4, conforme documentação fornecida pelo Tribunal: um módulo é só uma classe que estende `SeiIntegracao`/`SipIntegracao` e é despachada pelo `controlador.php` **depois** de toda ação nativa — genuinamente aditivo, nunca sobrescreve uma ação existente nem altera arquivo do core.

Seu uso se aplica a contextos como:

- Implantação inicial de um novo ambiente SEI/SIP, com necessidade de carga massiva de unidades, usuários, assuntos e permissões.
- Manutenções periódicas que demandam atualização ou complementação de cadastros em larga escala (alteração da estrutura organizacional, admissão de grande quantidade de novos usuários por concurso, mudança de carreira etc.).

### Origem

O ponto de partida foi o repositório [`pengovbr/macros-sei-sip`](https://github.com/pengovbr/macros-sei-sip), que resolve o mesmo problema via macros de RPA (UI.Vision) que abrem o navegador e simulam cliques nas telas administrativas, lendo dados de `.csv`. Funciona, mas é lento, frágil a mudanças de interface, e depende de um navegador aberto e logado durante toda a execução.

Este projeto nasceu como uma tentativa de resolver o mesmo problema de forma mais integrada ao SEI — e no processo deixou de ser uma reimplementação das macros e virou outra coisa: em vez de automatizar a interface, opera diretamente na camada de regra de negócio, dentro do próprio framework de módulos do SEI/SIP. Isso muda o resultado de forma relevante: execução em segundos em vez de minutos, nenhuma dependência de navegador/sessão aberta, relatório de resultado linha a linha, suporte nativo a `.xlsx`/`.ods` além de `.csv`, e processamento particionado em lotes para arquivos grandes sem esbarrar em timeout de servidor.

<a name="a-quem-se-destina"></a>
## 👨‍🔧 A quem se destina

Usuários com **perfil de Administração do SEI/SIP** — o mesmo público das macros originais. Diferente delas, aqui o acesso é controlado por um perfil próprio (`MD_CEL`, um em cada sistema), criado pelo script de instalação e atribuído manualmente a quem for operar as cargas. Cada carga tem o seu próprio recurso, então dá para liberar só algumas (ver [Permissões por carga](#permissoes-por-carga)).

> [!WARNING]
> Estes módulos alteram diretamente cadastros administrativos do SEI/SIP. Antes de usar em produção:
> - Teste primeiro em ambiente de homologação;
> - Confira **cuidadosamente** os dados das planilhas antes de enviar — os arquivos de referência são as fontes da verdade, e algumas operações (Dados Complementares de Unidade, Contato de Usuários) **sobrescrevem** o que já existe;
> - Garanta que quem for operar tenha o perfil correto atribuído.

<a name="como-instalar"></a>
## 📥 Como instalar

O pacote traz um módulo para cada sistema e dois scripts de instalação, na mesma estrutura de pastas do SEI/SIP. Copie as pastas `sei/` e `sip/` deste repositório para dentro da árvore do SEI/SIP (os arquivos PHP são ISO-8859-1, como o restante do código do SEI) e siga o passo a passo de cada módulo:

- [`sip/web/modulos/cargaEmLote/sipCargaEmLote/instrucoes.txt`](sip/web/modulos/cargaEmLote/sipCargaEmLote/instrucoes.txt)
- [`sei/web/modulos/cargaEmLote/seiCargaEmLote/instrucoes.txt`](sei/web/modulos/cargaEmLote/seiCargaEmLote/instrucoes.txt)

Resumo do processo:

1. Registrar o módulo na chave `Modulos` de cada configuração e reiniciar o Apache/PHP: `'MdCelSeiIntegracao' => 'cargaEmLote/seiCargaEmLote'` em `ConfiguracaoSEI.php` e `'MdCelSipIntegracao' => 'cargaEmLote/sipCargaEmLote'` em `ConfiguracaoSip.php`.
2. Rodar, dentro do container ou do servidor de aplicação, primeiro o script do SEI e depois o do SIP. Cada um pede usuário e senha do banco:

```bash
php /opt/sei/scripts/sei_atualizar_versao_modulo_cel.php
php /opt/sip/scripts/sip_atualizar_versao_modulo_cel.php
```

3. Atribuir o perfil `MD_CEL` a quem for operar as cargas: o do sistema SEI para as cargas do SEI e o do sistema SIP para as cargas do SIP.

O script do SEI só registra a versão (`MD_CEL_VERSAO`), porque o módulo não tem tabelas. O script do SIP cria, nos dois sistemas, o perfil, a tela, o item de menu, um recurso por carga e a regra de auditoria `MD_CEL`. Os dois são idempotentes: rodar de novo termina com a mensagem de que a versão já está instalada.

**Atualizando da versão 1.0.0.** O script do SIP renomeia o perfil e o recurso da versão anterior em vez de recriá-los. As permissões já concedidas e o item de menu continuam valendo. Antes de rodar, troque na chave `Modulos` os nomes antigos das classes (`SeiCargaEmLoteIntegracao` e `SipCargaEmLoteIntegracao`) pelos novos e remova os arquivos antigos das pastas dos módulos (`*Integracao.php` antigos, `rn/CargaEmLoteRN.php`, `web/carga_em_lote_form.php` e `scripts/instalar.php`). Os parâmetros `CARGA_EM_LOTE_VERSAO` e `CARGA_EM_LOTE_SEI_VERSAO` ficam sem uso no banco do SIP e podem ser removidos à mão.

> [!IMPORTANT]
> Depois de atribuir o perfil a um usuário, é preciso fazer **logout/login** para o item de menu aparecer — o menu de cada sistema é montado uma única vez no login e fica guardado na sessão (comportamento genérico do framework, não peculiaridade destes módulos).

<a name="como-usar"></a>
## ▶️ Como usar

1. Acesse a tela do módulo (menu `Carga em Lote`, no SIP na raiz do menu; no SEI dentro de `Administração`).
2. Escolha o **tipo de carga** desejado no seletor.
3. Selecione o arquivo `.csv`, `.xlsx` ou `.ods` já preenchido — a tela detecta o formato pela extensão do arquivo enviado.
4. Clique em **Processar**. Se o arquivo tiver muitas linhas, a tela mostra "X de Y linhas processadas" e se recarrega sozinha até concluir — não feche nem atualize a janela manualmente enquanto isso.
5. Ao final, confira o relatório: quantas linhas foram cadastradas/atualizadas, quantas já existiam (puladas) e quantas deram erro, com a mensagem de erro específica de cada linha.
6. Use o botão **Imprimir** (disponível só ao concluir) para gerar um PDF do relatório, se precisar de um registro da execução.

<a name="orientacoes-gerais-e-observacoes"></a>
## 📝 Orientações gerais e observações

> [!CAUTION]
> ### 🚫 NÃO ALTERAR AS COLUNAS DAS TABELAS DE REFERÊNCIA
> Os arquivos de exemplo em `exemplos/`, dentro da pasta de cada módulo, definem a **estrutura exata** esperada por cada tipo de carga: quantidade de colunas, ordem e significado de cada uma. As colunas são lidas **pela posição**, não pelo nome do cabeçalho.
>
> - **Não** insira, remova, renomeie ou reordene colunas.
> - **Não** insira uma linha de cabeçalho diferente da dos arquivos de exemplo — a primeira linha é sempre ignorada como cabeçalho, então seu conteúdo exato não importa, mas a **posição das colunas de dado abaixo dela, sim**.
> - Baixe o exemplo do tipo de carga que for usar e **edite apenas o conteúdo das células**, preservando a estrutura original.
> - Caso utilize o formato `.csv`, se algum valor contiver vírgula, coloque o valor inteiro entre aspas, por exemplo, `Divisão de Obras, Contratos e Serviços Gerais` deve ser gravado como
>   `"Divisão de Obras, Contratos e Serviços Gerais"`.

### Formatos aceitos

`.csv`, `.xlsx` e `.ods` — mesma estrutura de colunas nos três formatos. Arquivos de referência nos três formatos ficam em `exemplos/`, dentro da pasta de cada módulo.

> [!NOTE]
> Se for montar a planilha no Excel e exportar como `.csv`, cuidado com a configuração de regionalização do Brasil: o Excel tende a usar ponto e vírgula (`;`) como separador em vez de vírgula, e codificação `ISO-8859-1` em vez de `UTF-8`. Prefira `.xlsx` diretamente (sem converter para `.csv` manualmente) para evitar esse problema, ou use um editor de planilhas que gere `.csv` em UTF-8 com vírgula (o Google Sheets, por exemplo, faz isso corretamente).

### Comportamento em caso de registro já existente

- **Unidades, Hierarquia, Usuários, Primeiras Permissões, Assuntos, Tipos de Processo** (operações de criação): pula a linha e reporta "já existia" — nunca sobrescreve.
- **Dados Complementares de Unidade, Contato de Usuários** (operações de atualização): o arquivo é sempre tratado como fonte de verdade — sobrescreve e reporta "atualizado". Campos vazios na planilha **preservam** o valor já existente (não apagam).

### Processamento em lotes (arquivos grandes)

Arquivos com muitas linhas são processados em lotes de `MdCelSeiRN::TAMANHO_LOTE` e `MdCelSipRN::TAMANHO_LOTE` (50 por padrão) — a tela se recarrega sozinha automaticamente até concluir. Existe porque o timeout que interromperia uma carga grande normalmente não é do PHP — é do servidor web/proxy nafrente dele, fora do alcance de um módulo que só acrescenta arquivos a uma instalação já existente. Ajuste a constante no topo de `rn/MdCelSeiRN.php` e `rn/MdCelSipRN.php` se a instalação de destino tiver um timeout mais agressivo.

<a name="permissoes-por-carga"></a>
### Permissões por carga

O perfil `MD_CEL` traz todas as cargas do sistema. Para liberar só algumas, crie outro perfil com o recurso da tela (`md_cel_lote`) e apenas os recursos das cargas desejadas. A tela mostra só as cargas que o perfil do operador permite, e cada chamada valida o recurso de novo e grava a trilha de auditoria (arquivo e faixa de linhas, nunca o conteúdo das linhas).

| Sistema | Recurso | Carga |
|---|---|---|
| SEI | `md_cel_unidade_alterar` | Dados Complementares de Unidade |
| SEI | `md_cel_contato_alterar` | Contato de Usuários |
| SEI | `md_cel_assunto_cadastrar` | Assuntos |
| SEI | `md_cel_tipo_procedimento_cadastrar` | Tipos de Processo |
| SIP | `md_cel_unidade_cadastrar` e `md_cel_hierarquia_cadastrar` | Unidades e Hierarquia (exige os dois) |
| SIP | `md_cel_usuario_cadastrar` e `md_cel_permissao_cadastrar` | Usuários e Primeiras Permissões (exige os dois) |

Além do recurso do módulo, o operador precisa dos recursos das regras de negócio nativas que a carga usa (por exemplo `assunto_cadastrar`, `unidade_alterar`, `usuario_cadastrar`). O módulo não concede escrita além do que o perfil do operador já permite.

### Falha em uma linha

Cada linha é gravada em uma transação própria. Se uma linha falha, nada dela permanece no banco, nem as gravações parciais feitas antes do erro, e as demais linhas seguem normalmente. O relatório mostra a mensagem de erro de cada linha com falha.

### Tamanho do arquivo

No SEI, a tela recusa arquivos maiores que o limite do parâmetro `SEI_TAM_MB_DOC_EXTERNO`, o mesmo usado para documentos externos, e informa o valor na própria tela. O SIP não tem esse parâmetro, então vale o limite `upload_max_filesize` do PHP, também exibido na tela.

### Ordem de execução importa

A carga de **Unidades e Hierarquia** (SIP) precisa rodar antes da carga de **Dados Complementares de Unidade** (SEI) — esta última só atualiza unidades que já existem (criadas pelo SIP e replicadas ao SEI). Da mesma forma, **Assuntos** (SEI) deve rodar antes de **Tipos de Processo** (SEI) sempre que o arquivo de Tipos de Processo sugerir códigos de assunto que ainda não existem na Tabela de Assuntos atual.

---

<a name="sip-unidades"></a>
## 🏢 SIP — Unidades e Hierarquia

Cadastra unidades administrativas e posiciona cada uma na hierarquia, numa única carga (mesmo arquivo serve para as duas operações).

**Colunas** (`exemploUnidades.csv`):

| # | Coluna | Obrigatória | Descrição |
|---|---|---|---|
| 0 | Seq. | — | Número sequencial (só orientação, não é lido) |
| 1 | orgaoUnidade | ✅ | Sigla do órgão em que a unidade será cadastrada |
| 2 | siglaUnidade | ✅ | Sigla da unidade |
| 3 | descricaoUnidade | ✅ | Nome da unidade |
| 4 | superiorNaHierarquia | ✅* | Sigla da unidade imediatamente superior — em branco se for unidade "raiz" |
| 5 | emailUnidade | | E-mail da unidade |
| 6 | usaEnderecoDoOrgao? | | `S`/`N` — se `S`, ignora as colunas 7-12 (usa o endereço do órgão) |
| 7-12 | endereço, complemento, bairro, UF, cidade, CEP | | Só usadas se a coluna 6 for `N` — consumidas pela carga de Dados Complementares (SEI), não por esta |
| 13-15 | CNPJ, telefone, site | | Idem — consumidas pela carga de Dados Complementares (SEI) |

> [!IMPORTANT]
> A hierarquia é cadastrada **de cima para baixo** — as unidades "raiz" (coluna 4 em branco) devem vir antes das que dependem delas. O arquivo não é reordenado automaticamente.

**Exemplo** (5 primeiras linhas de `exemploUnidades.csv`):

| Seq. | orgaoUnidade | siglaUnidade | descricaoUnidade | superiorNaHierarquia | emailUnidade | usaEnderecoDoOrgao? |
|---|---|---|---|---|---|---|
| 1 | GOV-CR | GABIN | Gabinete | | governador@cariris.gov.br | S |
| 2 | GOV-CR | ASIMP | Assessoria de Imprensa | GABIN | imprensa@cariris.gov.br | S |
| 5 | GOV-CR | SETIN | Secretaria de Transformação Digital e Inovação | | setin@cariris.gov.br | N |
| 6 | GOV-CR | SUTEC | Subsecretaria de Tecnologia e Infraestrutura | SETIN | sutec@cariris.gov.br | N |
| 13 | GOV-CR | COIRE | Coordenadoria de Infraestrutura e Redes | SUTEC | coire@cariris.gov.br | N |

<a name="sip-usuarios"></a>
## 🙋 SIP — Usuários e Primeiras Permissões

Cadastra usuários e concede a primeira permissão de cada um, numa única carga (mesmo arquivo serve para as duas operações) — viabiliza o acesso inicial ao SEI. Outras permissões devem ser concedidas depois, pelo próprio SIP (`Permissões` > `Atribuição em Bloco`).

**Colunas** (`exemploUsuarios.csv`):

| # | Coluna | Obrigatória | Descrição |
|---|---|---|---|
| 0 | Index | — | Número sequencial (só orientação) |
| 1 | orgao | ✅ | Sigla do órgão em que o usuário será cadastrado |
| 2 | sigla | ✅ | Login do usuário |
| 3 | nome | ✅ | Nome do usuário |
| 4 | nomeSocial | | Nome social, se aplicável (Decreto nº 8.727/2016) — não usar para apelido/nome fantasia |
| 5 | cpf | | CPF — opcional, mas necessário para autenticação gov.br |
| 6 | emailInstitucional | | E-mail institucional |
| 7 | unidadePrimeiraPermissao | ✅ | Sigla da unidade da primeira permissão |
| 8 | perfilPrimeiraPermissao | ✅ | Nome do perfil da primeira permissão |

**Exemplo** (5 primeiras linhas de `exemploUsuarios.csv`):

| Index | orgao | sigla | nome | cpf | unidadePrimeiraPermissao | perfilPrimeiraPermissao |
|---|---|---|---|---|---|---|
| 1 | ANITEC | leocadio.macambira | Leocádio Macambira | 118.229.998-98 | PRESI | Básico |
| 2 | ANITEC | tertuliano.gongora | Tertuliano Gongora | 124.039.082-31 | PROT | Básico |
| 3 | ANITEC | belarmina.batatinha | Belarmina Batatinha | 147.551.240-69 | PROT | Colaborador (Básico sem Assinatura) |
| 11 | ANITEC | norberto.camarinha | Norberto Camarinha *(nomeSocial: Zildette Brazil)* | 951.628.492-27 | PROT | Colaborador (Básico sem Assinatura) |

---

<a name="sei-unidades"></a>
## 🏤 SEI — Dados Complementares de Unidade

Completa o cadastro de uma unidade (já existente, criada pela carga do SIP) com endereço,
telefone, site, CNPJ e lista de e-mails. **Operação de atualização**: sempre sobrescreve e
reporta "OK (atualizado)" — não existe "pulado" nesta carga. Campo vazio na planilha preserva
o valor já existente.

**Colunas**: usa o **mesmo arquivo** `exemploUnidades.csv` da carga de Unidades e Hierarquia
(SIP), consumindo as colunas que aquela carga não usa (5, 6-15 — ver tabela acima). Quando a
coluna 6 (`usaEnderecoDoOrgao?`) é `S`, os campos de endereço próprio (7-12) podem ficar em
branco.

<a name="sei-contatos"></a>
## 👤 SEI — Contato de Usuários

Completa o cadastro de um usuário (já existente) com dados pessoais e de contato — endereço,
gênero, cargo, categoria, função, título, CPF, RG, data de nascimento, matrícula, telefones,
cônjuge, e-mail e observações. **Operação de atualização**, mesmo comportamento da carga de
Unidade acima (sempre sobrescreve, campo vazio preserva o valor existente).

**Colunas** (`exemploContatoUsuarios.csv`):

| # | Coluna | Descrição |
|---|---|---|
| 0 | Seq. | Número sequencial (só orientação) |
| 1 | siglaUsuario | Login do usuário — resolvido em todos os órgãos; erro se ambíguo |
| 2 | generoUsuario | `M`/`F` |
| 3 | usaEnderecoDoOrgao | `S`/`N` |
| 4-10 | endereço, complemento, bairro, país, UF, cidade, CEP | Só usadas se a coluna 3 for `N` |
| 11 | cargoUsuario | Nome exato de um cargo já cadastrado em `Administração > Contatos > Cargos` — **atenção**: cargos costumam existir em pares por gênero (ex.: `Diretor`/`Diretora`); o cargo escolhido precisa bater com o gênero da coluna 2 |
| 12 | categoriaUsuario | Nome exato de uma categoria já cadastrada em `Administração > Contatos > Categorias` |
| 13 | funcaoUsuario | Texto livre |
| 14 | tituloUsuario | Nome exato de um título já cadastrado em `Administração > Contatos > Títulos` |
| 15-17 | cpfUsuario, rgUsuario, orgaoExpRgUsuario | |
| 18 | dataNascUsuario | Formato `dd/mm/aaaa` |
| 19-20 | matriculaUsuario, matOabUsuario | |
| 21-22 | passaporteUsuario, paisPassaporteUsuario | |
| 23-25 | telefones (comercial, celular, residencial) | |
| 26-28 | conjugeUsuario, emailUsuario, obsUsuario | |

> [!NOTE]
> As colunas 11, 12 e 14 (Cargo, Categoria, Título) exigem que o valor já exista cadastrado no
> sistema — a carga não cria esses domínios automaticamente. Se o valor informado não for
> encontrado, a linha reporta erro indicando onde cadastrá-lo antes.

**Exemplo** (linhas de `exemploContatoUsuarios.csv`):

| siglaUsuario | generoUsuario | usaEnderecoDoOrgao | cidadeUsuario | cargoUsuario | categoriaUsuario | dataNascUsuario | telefoneComercialUsuario |
|---|---|---|---|---|---|---|---|
| tertuliano.gongora | M | N | Rio de Janeiro | Coordenador | Servidor Público Federal | 31/12/1978 | (21) 2345-6789 |
| zildette.brazil | F | N | São Paulo | | Terceirizado | 15/03/1990 | (11) 3456-7890 |
| feliciana.travassos | F | N | Porto Alegre | Analista Técnico-Administrativa | Servidor Público Federal | 07/07/1991 | (51) 3344-7788 |
| querubina.espinosa | F | S | Florianópolis | Coordenadora | Servidor Público Federal | 14/12/1985 | (48) 3344-1122 |

<a name="sei-assuntos"></a>
## 🗄️ SEI — Assuntos

Cadastra assuntos na Tabela de Assuntos (CCD/TTD) marcada como atual — ou em outra, à escolha, via campo opcional na tela. **Operação de criação**: pula e reporta "já existia" se o código já estiver cadastrado na tabela escolhida.

**Colunas** (`exemploAssuntos.csv`):

| # | Coluna | Obrigatória | Descrição |
|---|---|---|---|
| 0 | Index | — | Número sequencial (só orientação) |
| 1 | CodigoEstruturado | ✅ | Código único dentro da tabela (ex.: `020.01.02`) — a hierarquia é implícita no próprio código, não é preciso indicar o assunto pai |
| 2 | NomeAssunto | ✅ | Nome do assunto |
| 3 | chkEstrutural | ✅ | `S` para assunto apenas estrutural (agrupador, não selecionável na classificação de documentos) — senão, deixe em branco/`N` |
| 4-5 | PrazoCorrente, PrazoIntermed | ✅ se não estrutural | Prazos de guarda, em anos |
| 6 | Destinacao | ✅ se não estrutural | `Guarda` (permanente) ou `Eliminacao` |
| 7 | Obs | | Texto livre |

**Exemplo** (`exemploAssuntos.csv`):

| Index | CodigoEstruturado | NomeAssunto | chkEstrutural | PrazoCorrente | PrazoIntermed | Destinacao |
|---|---|---|---|---|---|---|
| 1 | 999 | Financiamento | S | | | |
| 2 | 999.1 | Financiamento Estatal | N | 5 | 10 | Guarda |
| 3 | 999.11 | Financiamento de iniciativas estaduais | N | 5 | 10 | Guarda |
| 5 | 654.321 | Aquisição de material de escritório | N | 2 | 5 | Eliminacao |

<a name="sei-tipos"></a>
## 🗂️ SEI — Tipos de Processo

Cadastra tipos de processo, com assuntos sugeridos, restrições de órgão/unidade e níveis de acesso permitidos/sugerido. **Operação de criação**: duplicidade verificada pelo par (Nome, exclusivoOuvidoria) — pula e reporta "já existia" se o par já estiver cadastrado.

**Colunas** (`exemploTiposDeProcesso.csv`):

| # | Coluna | Obrigatória | Descrição |
|---|---|---|---|
| 0 | Seq | — | Número sequencial (só orientação) |
| 1 | Nome | ✅ | Nome do tipo de processo |
| 2 | descricao | | Descrição complementar |
| 3 | sugestaoDeAssuntos | | Códigos de assunto sugeridos, separados por `;` — precisam já existir na Tabela de Assuntos atual |
| 4 | restringirAosOrgaos | | Siglas de órgão autorizados, separadas por `;` — em branco libera para todos |
| 5 | restringirAsUnidades | | Detalhamento por unidade: `ORGAO:UNIDADE1\|UNIDADE2;ORGAO2:UNIDADE3` — em branco libera todas as unidades dos órgãos autorizados |
| 6 | NiveisDeAcessoPermitidos | ✅ | `PUB`, `RES` e/ou `SIG`, separados por `;` |
| 7 | NivelDeAcessoSugerido | ✅ | Um dos valores da coluna anterior |
| 8 | GrauSigilo | ✅ se sugerido = `SIG` | `U` (ultrassecreto), `S` (secreto) ou `R` (reservado) |
| 9 | sugestaoHipoteseLegal | | Formato `Nome (Base legal)`, igual ao cadastrado em `Administração > Hipóteses Legais` |
| 10-13 | exclusivoOuvidoria, permiteContatoAnonimo, ProcessoUnicoPorInteressado, InternoDoSistema | | `SIM` ou em branco |

**Exemplo** (`exemploTiposDeProcesso.csv`):

| Nome | sugestaoDeAssuntos | restringirAsUnidades | NiveisDeAcessoPermitidos | NivelDeAcessoSugerido | GrauSigilo |
|---|---|---|---|---|---|
| Comunicação: Serviço De Transmissão De Dados, Voz E Imagem | 073.4 | `PEN:ADMIN\|NEG;SBM:GABPREF\|SEDUC` | PUB;RES | RES | |
| Gestão de Contrato: Cadastramento De Fornecedores | 030.02 | | PUB;RES;SIG | SIG | R |
| Capacitação: Contratação de curso com ônus à Instituição | 028.21 | | PUB | PUB | |
| Pessoal: Licenças | 023.3 | | SIG | SIG | S |

---

<a name="status"></a>
## ✅ Status

Validado ponta a ponta contra um ambiente de laboratório completo (SEI 5.0.5 + SIP,
containers Docker), incluindo um ciclo de reinstalação do zero e cargas de centenas de linhas
por operação, para exercitar tanto o caminho feliz quanto o processamento em lotes.

A versão 2.0.0 (classes `MdCel`, recursos por carga, scripts de release e regra de auditoria) foi validada no mesmo ambiente, incluindo a atualização a partir da 1.0.0 com as permissões já concedidas e um perfil restrito a uma única carga no SEI. O perfil restrito não foi testado no SIP.

A validação foi feita somente em MySQL. O instalador declara suporte a Oracle, SQL Server e PostgreSQL, mas esses bancos não foram testados.
