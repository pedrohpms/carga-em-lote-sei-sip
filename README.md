# Carga em Lote (SIP + SEI)

Módulos de extensão para o **SEI** e o **SIP** (Sistema de Permissões, TRF4) que substituem
as macros de RPA do repositório [`pengovbr/macros-sei-sip`](https://github.com/pengovbr/macros-sei-sip)
por chamadas diretas às classes de regra de negócio (`*RN`) que as próprias telas administrativas
já usam — sem automação de navegador, sem depender de uma sessão logada aberta durante toda a
execução, e **sem tocar em nenhum arquivo do core** do SEI/SIP.

Segue estritamente o modelo de extensão oficial do TRF4 (documentado no PDF
*SEI-Módulos*): um módulo é só uma classe que estende `SeiIntegracao`/`SipIntegracao` e é
despachada pelo `controlador.php` **depois** de toda ação nativa — genuinamente aditivo, nunca
sobrescreve uma ação existente.

## O que cada módulo cobre

As 8 macros do repositório original de RPA (`1.cargaUnidades` até `8.cargaTiposDeProcesso`) se
dividem entre as que rodam no SIP e as que rodam no SEI:

### `sip/` — módulo SIP (`SipCargaEmLoteIntegracao`)

| Tipo de carga | O que faz | RN reutilizada |
|---|---|---|
| Unidades e Hierarquia | Cadastra unidades e posiciona na hierarquia | `UnidadeRN`, `RelHierarquiaUnidadeRN` |
| Usuários e Primeiras Permissões | Cadastra usuários e concede a primeira permissão | `UsuarioRN`, `PermissaoRN` |

### `sei/` — módulo SEI (`SeiCargaEmLoteIntegracao`)

| Tipo de carga | O que faz | RN reutilizada |
|---|---|---|
| Dados Complementares de Unidade | Endereço, telefone, site, CNPJ, e-mails da unidade | `ContatoRN`, `UnidadeRN` |
| Contato de Usuários | Dados pessoais/contato do usuário (endereço, cargo, CPF, telefones, etc.) | `ContatoRN` |
| Assuntos | Cadastra assuntos na Tabela de Assuntos (CCD/TTD) atual, ou em outra à escolha | `AssuntoRN` |
| Tipos de Processo | Cadastra tipo de processo com assuntos sugeridos, restrições de órgão/unidade e níveis de acesso | `TipoProcedimentoRN` |

## Como instalar

Cada módulo é autocontido — basta copiar a pasta para dentro da árvore do SEI/SIP e seguir o
passo a passo de cada um:

- [`sip/web/modulos/cargaEmLote/sipCargaEmLote/instrucoes.txt`](sip/web/modulos/cargaEmLote/sipCargaEmLote/instrucoes.txt)
- [`sei/web/modulos/cargaEmLote/seiCargaEmLote/instrucoes.txt`](sei/web/modulos/cargaEmLote/seiCargaEmLote/instrucoes.txt)

Resumo do processo (idêntico para os dois): ativar a chave `Modulos` no arquivo de
configuração (`ConfiguracaoSip.php`/`ConfiguracaoSEI.php`), reiniciar o Apache/PHP, e rodar o
script de instalação (`scripts/instalar.php`) — cria recurso, perfil e item de menu de forma
idempotente, usando o mesmo mecanismo (`InfraScriptVersao` + `ScriptSip`) que o próprio TRF4
usa em `sip/scripts/atualizar_recursos_sei.php`. Só falta atribuir o perfil criado a quem for
operar as cargas.

**Importante**: depois de conceder o perfil a um usuário, é preciso fazer logout/login para o
item de menu aparecer — o menu de cada sistema é montado uma única vez no login e fica
guardado na sessão (comportamento genérico do framework, não peculiaridade destes módulos).

## Formato de entrada

Upload de arquivo direto na tela (sem colar texto), em três formatos — `.csv`, `.xlsx` ou
`.ods`, mesmas colunas dos arquivos de exemplo do repositório de macros original. Arquivos de
referência nos três formatos ficam em `exemplos/`, dentro da pasta de cada módulo.

## Processamento em lotes (arquivos grandes)

Arquivos com muitas linhas são processados em lotes de `CargaEmLoteRN::TAMANHO_LOTE` (50 por
padrão) — a tela se recarrega sozinha automaticamente até concluir, mostrando "X de Y linhas
processadas" a cada recarregamento. Existe porque o timeout que interromperia uma carga grande
normalmente não é do PHP — é do servidor web/proxy na frente dele, fora do alcance de um
módulo que só acrescenta arquivos a uma instalação já existente. Ajuste a constante se a
instalação de destino tiver um timeout mais agressivo.

## Comportamento em caso de registro já existente

- **Unidades, Hierarquia, Usuários, Primeiras Permissões, Assuntos, Tipos de Processo**
  (operações de criação): pula a linha e reporta "já existia" — nunca sobrescreve.
- **Dados Complementares de Unidade, Contato de Usuários** (operações de atualização): o
  arquivo é sempre tratado como fonte de verdade — sobrescreve e reporta "atualizado". Campos
  vazios na planilha **preservam** o valor já existente (não apagam).

## Status

Validado ponta a ponta contra um ambiente de laboratório completo (SEI 5.0.5 + SIP,
containers Docker), incluindo um ciclo de reinstalação do zero e cargas de centenas de linhas
por operação, para exercitar tanto o caminho feliz quanto o processamento em lotes.
