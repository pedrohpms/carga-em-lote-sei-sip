<?
/**
 * Tela do modulo Carga em Lote (SEI): escolhe o tipo de carga, envia o .csv/.xlsx/.ods e
 * mostra o relatorio linha a linha do processamento. Incluida via
 * SeiCargaEmLoteIntegracao::processarControlador(), ja dentro do controlador.php do SEI
 * (sessao/pagina ja inicializadas) - segue o mesmo padrao das demais telas do SEI, inclusive
 * repetindo o require_once/session_start do topo (idempotente mesmo ja tendo rodado antes).
 *
 * Processamento particionado em lotes (CargaEmLoteRN::TAMANHO_LOTE linhas por vez, varias
 * requisicoes HTTP curtas em sequencia via <meta refresh>) em vez de uma unica requisicao
 * longa - existe porque o timeout que interrompe uma carga grande normalmente NAO e do PHP
 * (o modulo nao controla isso, e so codigo acrescentado a uma instalacao SEI ja existente) e
 * sim do servidor web/proxy na frente dele. O estado entre requisicoes (arquivo, offset,
 * relatorio acumulado ate agora) fica na sessao do usuario ($_SESSION), nao em banco -
 * decisao deliberada: e descartavel, nao interessa sobreviver a um logout/reinicio, e assim
 * nao precisa de nenhuma tabela nova (modulo so acrescenta arquivos, nunca mexe em schema do
 * core).
 */

require_once __DIR__ . '/../../../../SEI.php';

session_start();

SessaoSEI::getInstance()->validarLink();
SessaoSEI::getInstance()->validarPermissao($_GET['acao']);

$strTitulo = 'Carga em Lote (SEI)';
$arrTiposCarga = array(
  'unidades_complementar' => 'Dados Complementares de Unidade',
  'contato_usuarios' => 'Contato de Usuários',
  'assuntos' => 'Assuntos',
  'tipos_processo' => 'Tipos de Processo',
);

// Chave unica na sessao pra guardar o estado do processamento em andamento (arquivo temp,
// quantas linhas ja foram processadas, relatorio acumulado) entre os recarregamentos
// automaticos da pagina. So existe enquanto uma carga esta em andamento.
const CHAVE_ESTADO_SESSAO = 'seiCargaEmLoteEstado';

$arrResultado = null;      // relatorio acumulado ate agora (parcial ou completo)
$numTotalLinhas = null;
$numLinhasProcessadas = null;
$bolProcessamentoConcluido = true; // true = sem carga em andamento (tela normal, form visivel)
$strTipoCargaEmAndamento = null;

$strTipoCarga = PaginaSEI::POST('selTipoCarga');

// Mesmo padrao de sei/web/assunto_cadastro.php ($strDisplayClassificacao): calcula o estado
// inicial de exibicao no servidor (evita "flash" do campo antes do JS rodar, cobre tambem o
// caso de reexibir a tela apos um erro de validacao com o tipo de carga ja selecionado) - o
// toggle em tempo real e feito por JS (trocarTipoCarga()), mesma tecnica.
$strDisplayTabelaAssuntos = ($strTipoCarga === 'assuntos') ? '' : 'display:none;';

// Lista de Tabelas de Assuntos existentes, para a carga de Assuntos poder escolher uma
// tabela diferente da atual (ex.: orgao preparando uma tabela nova, ainda nao promovida a
// atual) - por Nome (unico, validado nativamente em TabelaAssuntosRN), nunca por id interno.
$objTabelaAssuntosDTOLista = new TabelaAssuntosDTO();
$objTabelaAssuntosDTOLista->setBolExclusaoLogica(false);
$objTabelaAssuntosDTOLista->retStrNome();
$objTabelaAssuntosDTOLista->retStrSinAtual();
$objTabelaAssuntosDTOLista->setOrd('Nome', InfraDTO::$TIPO_ORDENACAO_ASC);
$objTabelaAssuntosRNLista = new TabelaAssuntosRN();
$arrTabelasAssuntos = $objTabelaAssuntosRNLista->listar($objTabelaAssuntosDTOLista);

try {
  if (isset($_POST['sbmProcessar'])) {
    // Novo envio: descarta qualquer carga anterior ainda em andamento (ex.: usuario mandou
    // outro arquivo antes da anterior terminar) - apaga o arquivo temp dela tambem, senao
    // fica orfao no disco.
    if (isset($_SESSION[CHAVE_ESTADO_SESSAO]['arquivo']) && file_exists($_SESSION[CHAVE_ESTADO_SESSAO]['arquivo'])) {
      unlink($_SESSION[CHAVE_ESTADO_SESSAO]['arquivo']);
    }
    unset($_SESSION[CHAVE_ESTADO_SESSAO]);

    if (!array_key_exists($strTipoCarga, $arrTiposCarga)) {
      throw new InfraException('Selecione o tipo de carga.');
    }

    if (!isset($_FILES['filArquivo']) || $_FILES['filArquivo']['error'] === UPLOAD_ERR_NO_FILE) {
      throw new InfraException('Selecione um arquivo .csv, .xlsx ou .ods.');
    }

    // processarUpload() nao retorna valor: da echo direto no resultado (pensado para ser
    // lido por um iframe/JS) - mesma observacao ja feita no modulo SIP.
    ob_start();
    // bolArquivoTemporarioIdentificado=true preserva o nome/extensao original no arquivo
    // temporario (sanitizado) - precisamos da extensao pra escolher o leitor certo em
    // CargaEmLoteRN::lerCsv() (csv/xlsx/ods).
    PaginaSEI::getInstance()->processarUpload('filArquivo', DIR_SEI_TEMP, true, true);
    $strRetUpload = ob_get_clean();
    $arrRetUpload = explode('#', $strRetUpload);

    if ($arrRetUpload[0] === 'ERRO') {
      throw new InfraException($arrRetUpload[1] ?? 'Erro no upload do arquivo.');
    }

    $strArquivoTemp = DIR_SEI_TEMP . '/' . $arrRetUpload[0];

    // So o arquivo/offset ficam na sessao agora - o processamento do 1o lote acontece mais
    // abaixo, no mesmo bloco que trata os recarregamentos automaticos seguintes (evita
    // duplicar a logica de chamada da RN).
    $_SESSION[CHAVE_ESTADO_SESSAO] = array(
      'tipoCarga' => $strTipoCarga,
      'arquivo' => $strArquivoTemp,
      'nomeTabela' => ($strTipoCarga === 'assuntos') ? ((PaginaSEI::POST('selTabelaAssuntos') !== 'null') ? PaginaSEI::POST('selTabelaAssuntos') : null) : null,
      'offset' => 0,
      'total' => null,
      'resultado' => array(),
    );
  }

  if (isset($_SESSION[CHAVE_ESTADO_SESSAO])) {
    $arrEstado = &$_SESSION[CHAVE_ESTADO_SESSAO];
    $strTipoCargaEmAndamento = $arrEstado['tipoCarga'];

    if ($arrEstado['total'] === null || $arrEstado['offset'] < $arrEstado['total']) {
      $objCargaEmLoteRN = new CargaEmLoteRN();
      $arrParametrosChamada = array(
        'csv' => $arrEstado['arquivo'],
        'offset' => $arrEstado['offset'],
        'limite' => CargaEmLoteRN::TAMANHO_LOTE,
      );

      switch ($arrEstado['tipoCarga']) {
        case 'unidades_complementar':
          $arrRetornoLote = $objCargaEmLoteRN->processarUnidadesComplementar($arrParametrosChamada);
          break;
        case 'contato_usuarios':
          $arrRetornoLote = $objCargaEmLoteRN->processarContatoUsuarios($arrParametrosChamada);
          break;
        case 'assuntos':
          $arrParametrosChamada['nomeTabela'] = $arrEstado['nomeTabela'];
          $arrRetornoLote = $objCargaEmLoteRN->processarAssuntos($arrParametrosChamada);
          break;
        case 'tipos_processo':
          $arrRetornoLote = $objCargaEmLoteRN->processarTiposProcesso($arrParametrosChamada);
          break;
        default:
          throw new InfraException('Tipo de carga desconhecido em andamento na sessão.');
      }

      $arrEstado['resultado'] = array_merge($arrEstado['resultado'], $arrRetornoLote['resultado']);
      $arrEstado['total'] = $arrRetornoLote['total'];
      // Um lote que nao processa nada (arquivo so com cabecalho, por exemplo) travaria o
      // laco em offset fixo pra sempre - forca conclusao nesse caso.
      $arrEstado['offset'] += max($arrRetornoLote['processadas'], ($arrRetornoLote['processadas'] === 0) ? $arrEstado['total'] : 0);
    }

    $arrResultado = $arrEstado['resultado'];
    $numTotalLinhas = $arrEstado['total'];
    $numLinhasProcessadas = $arrEstado['offset'];
    $bolProcessamentoConcluido = ($numTotalLinhas !== null && $numLinhasProcessadas >= $numTotalLinhas);

    if ($bolProcessamentoConcluido) {
      if (file_exists($arrEstado['arquivo'])) {
        unlink($arrEstado['arquivo']);
      }
      unset($_SESSION[CHAVE_ESTADO_SESSAO]);
    }
  }
} catch (Exception $e) {
  unset($_SESSION[CHAVE_ESTADO_SESSAO]);
  PaginaSEI::getInstance()->processarExcecao($e);
}

PaginaSEI::getInstance()->montarDocType();
PaginaSEI::getInstance()->abrirHtml();
PaginaSEI::getInstance()->abrirHead();
PaginaSEI::getInstance()->montarMeta();

if (!$bolProcessamentoConcluido) {
  // Recarrega a propria pagina (mesma URL, GET simples - sem reenviar o formulario) depois
  // de alguns segundos, pra continuar processando o proximo lote. Escolhido no lugar de
  // AJAX/barra de progresso "de verdade" como MVP - mais simples de manter, versao com AJAX
  // fica pra depois se for preciso.
  echo '<meta http-equiv="refresh" content="2" />' . "\n";
}

PaginaSEI::getInstance()->montarTitle(PaginaSEI::getInstance()->getStrNomeSistema() . ' - ' . $strTitulo);
PaginaSEI::getInstance()->montarStyle();
PaginaSEI::getInstance()->abrirStyle();
?>
  /* Layout em fluxo normal (nao absoluto) - mesma solucao adotada no modulo SIP para nao
     depender de adivinhar a altura real do container de abrirAreaDados(). */
  #areaCargaEmLote label {display:block;margin-top:1.5em;margin-bottom:0.4em;font-weight:bold;}
  #areaCargaEmLote select, #areaCargaEmLote input[type=file] {display:block;margin-bottom:0.5em;}
  #divTabelaAssuntos {<?=$strDisplayTabelaAssuntos?>}
<?
PaginaSEI::getInstance()->fecharStyle();
// Inclui InfraMenu.js/InfraAcaoMenu.js (submenus) - faltar essa chamada quebrou o menu so
// nesta tela no modulo SIP (Sprint 1); incluida desde o inicio aqui.
PaginaSEI::getInstance()->montarJavaScript();
PaginaSEI::getInstance()->abrirJavaScript();
?>
  function trocarTipoCarga() {
    if (document.getElementById('selTipoCarga').value == 'assuntos') {
      document.getElementById('divTabelaAssuntos').style.display = 'block';
    } else {
      document.getElementById('divTabelaAssuntos').style.display = 'none';
    }
  }

  function validarCargaEmLote() {
    if (!infraSelectSelecionado('selTipoCarga')) {
      alert('Selecione o tipo de carga.');
      document.getElementById('selTipoCarga').focus();
      return false;
    }
    if (document.getElementById('filArquivo').value == '') {
      alert('Selecione um arquivo .csv, .xlsx ou .ods.');
      document.getElementById('filArquivo').focus();
      return false;
    }
    return true;
  }

  function OnSubmitForm() {
    return validarCargaEmLote();
  }
<?
PaginaSEI::getInstance()->fecharJavaScript();
PaginaSEI::getInstance()->fecharHead();
PaginaSEI::getInstance()->abrirBody($strTitulo);
?>
  <?
  if ($bolProcessamentoConcluido) {
    // Sem carga em andamento - mostra o formulario normal (novo envio ou tela recem-aberta).
  ?>
  <form id="frmCargaEmLote" method="post" enctype="multipart/form-data" onsubmit="return OnSubmitForm();"
        action="<?=SessaoSEI::getInstance()->assinarLink('controlador.php?acao=' . $_GET['acao'])?>">
    <?
    $arrComandos = array();
    $arrComandos[] = '<button type="submit" name="sbmProcessar" value="Processar" class="infraButton">Processar</button>';
    if ($arrResultado !== null) {
      // Mesmo mecanismo nativo de "Imprimir" (ex.: sei/web/assunto_lista.php,
      // infra/infra_js/InfraUtil.js) - window.print() sobre uma copia do conteudo, sem
      // depender de biblioteca de PDF: o navegador/SO oferece "salvar como PDF" na propria
      // caixa de impressao. infraImprimirDiv() (variante mais simples de
      // infraImprimirTabela(), sem checkbox/coluna de acoes pra remover) imprime o conteudo
      // de um div qualquer pelo id - usado aqui em vez de infraImprimirTabela() porque nosso
      // resultado nao esta dentro do container padrao de lista (divInfraAreaTabela).
      $arrComandos[] = '<button type="button" id="btnImprimir" value="Imprimir" onclick="infraImprimirDiv(\'divResultadoCargaEmLote\');" class="infraButton">Imprimir</button>';
    }
    PaginaSEI::getInstance()->montarBarraComandosSuperior($arrComandos);
    PaginaSEI::getInstance()->abrirAreaDados('30em');
    ?>
    <div id="areaCargaEmLote">
    <label id="lblTipoCarga" for="selTipoCarga" class="infraLabelObrigatorio">Tipo de carga:</label>
    <select id="selTipoCarga" name="selTipoCarga" class="infraSelect" onchange="trocarTipoCarga();">
      <option value="null">&nbsp;</option>
      <?
      foreach ($arrTiposCarga as $strChave => $strDescricaoTipo) {
        $strSelected = ($strTipoCarga === $strChave) ? 'selected="selected"' : '';
        echo '<option value="' . $strChave . '" ' . $strSelected . '>' . $strDescricaoTipo . '</option>';
      }
      ?>
    </select>

    <div id="divTabelaAssuntos">
    <label id="lblTabelaAssuntos" for="selTabelaAssuntos">Tabela de Assuntos:</label>
    <select id="selTabelaAssuntos" name="selTabelaAssuntos" class="infraSelect">
      <option value="null">(usar a tabela marcada como atual)</option>
      <?
      foreach ($arrTabelasAssuntos as $objTabelaAssuntosDTOItem) {
        $strNomeTabelaItem = $objTabelaAssuntosDTOItem->getStrNome();
        $strSelectedTabela = (PaginaSEI::POST('selTabelaAssuntos') === $strNomeTabelaItem) ? 'selected="selected"' : '';
        $strRotuloTabelaItem = $strNomeTabelaItem . (($objTabelaAssuntosDTOItem->getStrSinAtual() === 'S') ? ' (atual)' : '');
        echo '<option value="' . PaginaSEI::tratarHTML($strNomeTabelaItem) . '" ' . $strSelectedTabela . '>' . PaginaSEI::tratarHTML($strRotuloTabelaItem) . '</option>';
      }
      ?>
    </select>
    </div>

    <label id="lblArquivo" for="filArquivo" class="infraLabelObrigatorio">Arquivo (.csv, .xlsx ou .ods):</label>
    <input type="file" id="filArquivo" name="filArquivo" accept=".csv,.xlsx,.ods"/>
    <p style="color:#666;font-style:italic;">Isto pode demorar um pouco, dependendo da
    quantidade de linhas do arquivo. Se o arquivo tiver muitas linhas, o processamento é
    feito em lotes de <?=CargaEmLoteRN::TAMANHO_LOTE?> - esta tela se atualizará
    periodicamente com o progresso, sozinha, até concluir. Não feche nem atualize a
    janela manualmente enquanto isso.</p>
    </div>

    <?
    PaginaSEI::getInstance()->fecharAreaDados();
    ?>
  </form>
  <?
  } else {
    // Carga em andamento - sem formulario, so o progresso (a pagina vai se recarregar
    // sozinha via <meta refresh> ate concluir).
    $arrComandos = array();
    PaginaSEI::getInstance()->montarBarraComandosSuperior($arrComandos);
    PaginaSEI::getInstance()->abrirAreaDados('10em');
    ?>
    <p><b>Processando <?=PaginaSEI::tratarHTML($arrTiposCarga[$strTipoCargaEmAndamento] ?? $strTipoCargaEmAndamento)?>...</b>
    <?=$numLinhasProcessadas?> de <?=$numTotalLinhas?> linha(s) do arquivo já passaram pelo
    sistema. Esta tela vai se atualizar sozinha em instantes - não feche nem atualize a
    janela manualmente.</p>
    <?
    PaginaSEI::getInstance()->fecharAreaDados();
  }
  ?>

  <?
  if ($arrResultado !== null) {
    $numOk = count(array_filter($arrResultado, function ($r) { return $r['status'] === CargaEmLoteRN::STA_OK; }));
    $numPulado = count(array_filter($arrResultado, function ($r) { return $r['status'] === CargaEmLoteRN::STA_PULADO; }));
    $numErro = count(array_filter($arrResultado, function ($r) { return $r['status'] === CargaEmLoteRN::STA_ERRO; }));
    ?>
    <div id="divResultadoCargaEmLote">
    <p><b><?=$bolProcessamentoConcluido ? 'Resultado:' : 'Resultado parcial (até agora):'?></b>
    <?=$numOk?> atualizado(s)/cadastrado(s), <?=$numPulado?> pulado(s) (já existiam), <?=$numErro?> com erro.</p>
    <table class="infraTable" width="100%">
      <thead>
        <tr><th>Linha</th><th>Status</th><th>Mensagem</th></tr>
      </thead>
      <tbody>
        <?
        foreach ($arrResultado as $arrLinhaResultado) {
          echo '<tr><td>' . $arrLinhaResultado['linha'] . '</td><td>' . $arrLinhaResultado['status'] . '</td><td>' . PaginaSEI::tratarHTML($arrLinhaResultado['mensagem']) . '</td></tr>';
        }
        ?>
      </tbody>
    </table>
    </div>
  <?
  }
  PaginaSEI::getInstance()->fecharBody();
  PaginaSEI::getInstance()->fecharHtml();
  ?>
