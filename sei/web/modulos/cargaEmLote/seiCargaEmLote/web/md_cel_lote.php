<?php
/**
 * Tela do modulo Carga em Lote (SEI): escolhe o tipo de carga, envia o .csv/.xlsx/.ods e
 * mostra o relatorio linha a linha do processamento. Incluida via
 * MdCelSeiIntegracao::processarControlador(), ja dentro do controlador.php do SEI
 * (sessao/pagina ja inicializadas) - segue o mesmo padrao das demais telas do SEI, inclusive
 * repetindo o require_once/session_start do topo (idempotente mesmo ja tendo rodado antes).
 *
 * Processamento particionado em lotes (MdCelSeiRN::TAMANHO_LOTE linhas por vez, varias
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
// Tipo de carga => [rotulo, recurso exigido]. O seletor mostra so as cargas que o perfil do
// operador permite (verificarPermissao); a RN valida de novo em cada chamada.
$arrTiposCargaCatalogo = array(
  'unidades_complementar' => array('Dados Complementares de Unidade', MdCelSeiRN::RECURSO_UNIDADE_COMPLEMENTAR),
  'contato_usuarios' => array('Contato de Usuários', MdCelSeiRN::RECURSO_CONTATO_USUARIOS),
  'assuntos' => array('Assuntos', MdCelSeiRN::RECURSO_ASSUNTOS),
  'tipos_processo' => array('Tipos de Processo', MdCelSeiRN::RECURSO_TIPOS_PROCESSO),
);
$arrTiposCarga = array();
foreach ($arrTiposCargaCatalogo as $strChaveTipo => $arrItemTipo) {
  if (SessaoSEI::getInstance()->verificarPermissao($arrItemTipo[1])) {
    $arrTiposCarga[$strChaveTipo] = $arrItemTipo[0];
  }
}
if (count($arrTiposCarga) === 0) {
  throw new InfraException('O perfil do usuário não permite nenhuma carga em lote nesta unidade.');
}

// Chave unica na sessao pra guardar o estado do processamento em andamento (arquivo temp,
// quantas linhas ja foram processadas, relatorio acumulado) entre os recarregamentos
// automaticos da pagina. So existe enquanto uma carga esta em andamento.
const CHAVE_ESTADO_SESSAO = 'md_cel_sei_estado';

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

// Limite de tamanho do arquivo (ver MdCelSeiRN::obterLimiteUploadMbConectado()): usado no
// aviso da tela, na validacao antes do envio (JS) e na validacao do servidor (ETAPA 1).
$numLimiteUploadMb = (new MdCelSeiRN())->obterLimiteUploadMb();

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

// ETAPA 1 de 3 - novo envio do formulario ou recarregamento automatico de um lote em
// andamento (ver ETAPA 2 logo abaixo) - o bloco try/catch inteiro cobre as duas ETAPAS.
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

    if ($_FILES['filArquivo']['size'] > $numLimiteUploadMb * 1024 * 1024) {
      throw new InfraException('O arquivo tem ' . round($_FILES['filArquivo']['size'] / 1048576, 1) . ' Mb e excede o limite de ' . $numLimiteUploadMb . ' Mb (parâmetro SEI_TAM_MB_DOC_EXTERNO).');
    }

    // processarUpload() nao retorna valor: da echo direto no resultado (pensado para ser
    // lido por um iframe/JS) - mesma observacao ja feita no modulo SIP.
    ob_start();
    // bolArquivoTemporarioIdentificado=true preserva o nome/extensao original no arquivo
    // temporario (sanitizado) - precisamos da extensao pra escolher o leitor certo em
    // MdCelSeiRN::lerCsv() (csv/xlsx/ods).
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

  // ETAPA 2 de 3 - roda a cada requisicao (POST inicial OU GET de recarregamento
  // automatico): processa UM lote (MdCelSeiRN::TAMANHO_LOTE linhas) e acumula o resultado
  // na sessao. So para de rodar quando offset >= total (carga concluida).
  if (isset($_SESSION[CHAVE_ESTADO_SESSAO])) {
    $arrEstado = &$_SESSION[CHAVE_ESTADO_SESSAO];
    $strTipoCargaEmAndamento = $arrEstado['tipoCarga'];

    if ($arrEstado['total'] === null || $arrEstado['offset'] < $arrEstado['total']) {
      $objMdCelRN = new MdCelSeiRN();
      $arrParametrosChamada = array(
        'csv' => $arrEstado['arquivo'],
        'offset' => $arrEstado['offset'],
        'limite' => MdCelSeiRN::TAMANHO_LOTE,
      );

      switch ($arrEstado['tipoCarga']) {
        case 'unidades_complementar':
          $arrRetornoLote = $objMdCelRN->processarUnidadesComplementar($arrParametrosChamada);
          break;
        case 'contato_usuarios':
          $arrRetornoLote = $objMdCelRN->processarContatoUsuarios($arrParametrosChamada);
          break;
        case 'assuntos':
          $arrParametrosChamada['nomeTabela'] = $arrEstado['nomeTabela'];
          $arrRetornoLote = $objMdCelRN->processarAssuntos($arrParametrosChamada);
          break;
        case 'tipos_processo':
          $arrRetornoLote = $objMdCelRN->processarTiposProcesso($arrParametrosChamada);
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

// ETAPA 3 de 3 - renderiza a tela no desenho das telas nativas (grupos em infraAreaDados com
// os campos posicionados por id, como tarja_assinatura_cadastro.php e assunto_cadastro.php): formulario
// de envio (se nao ha carga em andamento), progresso (se ainda processando) e relatorio
// linha a linha (se ja existe algum resultado, mesmo que parcial).
$strResultado = '';
$numRegistros = 0;
if ($arrResultado !== null) {
  $numRegistros = count($arrResultado);
  $numOk = count(array_filter($arrResultado, function ($r) { return $r['status'] === MdCelSeiRN::STA_OK; }));
  $numPulado = count(array_filter($arrResultado, function ($r) { return $r['status'] === MdCelSeiRN::STA_PULADO; }));
  $numErro = count(array_filter($arrResultado, function ($r) { return $r['status'] === MdCelSeiRN::STA_ERRO; }));
  $strCaptionTabela = ($bolProcessamentoConcluido ? 'Resultado' : 'Resultado parcial (até agora)')
    . ': ' . $numOk . ' atualizado(s)/cadastrado(s), ' . $numPulado . ' pulado(s) (já existiam), ' . $numErro . ' com erro';
  $strResultado .= '<table width="99%" class="infraTable" summary="Resultado da carga por linha do arquivo">' . "\n";
  $strResultado .= '<caption class="infraCaption">' . PaginaSEI::tratarHTML($strCaptionTabela) . '</caption>';
  $strResultado .= '<tr>';
  $strResultado .= '<th class="infraTh" width="8%">Linha</th>' . "\n";
  $strResultado .= '<th class="infraTh" width="10%">Status</th>' . "\n";
  $strResultado .= '<th class="infraTh">Mensagem</th>' . "\n";
  $strResultado .= '</tr>' . "\n";
  $strCssTr = '';
  foreach ($arrResultado as $arrLinhaResultado) {
    $strCssTr = ($strCssTr === '<tr class="infraTrClara">') ? '<tr class="infraTrEscura">' : '<tr class="infraTrClara">';
    $strResultado .= $strCssTr;
    $strResultado .= '<td align="center" valign="top">' . (int)$arrLinhaResultado['linha'] . '</td>';
    $strResultado .= '<td align="center" valign="top">' . PaginaSEI::tratarHTML($arrLinhaResultado['status']) . '</td>';
    $strResultado .= '<td valign="top">' . PaginaSEI::tratarHTML($arrLinhaResultado['mensagem']) . '</td>';
    $strResultado .= '</tr>' . "\n";
  }
  $strResultado .= '</table>';
}

$strTipoEmAndamento = ($strTipoCargaEmAndamento !== null) ? ($arrTiposCarga[$strTipoCargaEmAndamento] ?? $strTipoCargaEmAndamento) : '';

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
#lblTipoCarga {position:absolute;left:0%;top:5%;}
#selTipoCarga {position:absolute;left:0%;top:40%;width:45%;}

#divTabelaAssuntos {<?=$strDisplayTabelaAssuntos?>}
#lblTabelaAssuntos {position:absolute;left:0%;top:5%;}
#selTabelaAssuntos {position:absolute;left:0%;top:40%;width:45%;}

#lblArquivo {position:absolute;left:0%;top:5%;}
#filArquivo {position:absolute;left:0%;top:45%;width:90%;}

#lblAviso {position:absolute;left:0%;top:5%;width:90%;}

#lblProcessando {position:absolute;left:0%;top:5%;}
#lblProgresso {position:absolute;left:0%;top:40%;width:90%;}

<?php
PaginaSEI::getInstance()->fecharStyle();
// Inclui InfraMenu.js/InfraAcaoMenu.js (submenus) - faltar essa chamada quebrou o menu so
// nesta tela no modulo SIP (Sprint 1); incluida desde o inicio aqui.
PaginaSEI::getInstance()->montarJavaScript();
PaginaSEI::getInstance()->abrirJavaScript();
?>
var numLimiteUploadMb = <?=$numLimiteUploadMb?>;

function inicializar(){
  if (document.getElementById('selTipoCarga')){
    document.getElementById('selTipoCarga').focus();
  }
  infraEfeitoTabelas();
}

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
  var arqSelecionado = document.getElementById('filArquivo').files[0];
  if (arqSelecionado && arqSelecionado.size > numLimiteUploadMb * 1024 * 1024) {
    alert('O arquivo tem ' + (arqSelecionado.size / 1048576).toFixed(1) + ' Mb e excede o limite de ' + numLimiteUploadMb + ' Mb.');
    document.getElementById('filArquivo').focus();
    return false;
  }
  return true;
}

function OnSubmitForm() {
  return validarCargaEmLote();
}

<?php
PaginaSEI::getInstance()->fecharJavaScript();
PaginaSEI::getInstance()->fecharHead();
PaginaSEI::getInstance()->abrirBody($strTitulo, 'onload="inicializar();"');
?>
<form id="frmCargaEmLote" method="post" enctype="multipart/form-data" onsubmit="return OnSubmitForm();" action="<?=SessaoSEI::getInstance()->assinarLink('controlador.php?acao=' . $_GET['acao'] . '&acao_origem=' . $_GET['acao'])?>">
<?php
$arrComandos = [];
if ($bolProcessamentoConcluido) {
  $arrComandos[] = '<button type="submit" accesskey="P" name="sbmProcessar" value="Processar" class="infraButton"><span class="infraTeclaAtalho">P</span>rocessar</button>';
  if ($arrResultado !== null) {
    // infraImprimirDiv() imprime o conteudo de um div pelo id (window.print() sobre uma copia):
    // o navegador/SO oferece "salvar como PDF" na propria caixa de impressao, sem biblioteca.
    $arrComandos[] = '<button type="button" accesskey="I" id="btnImprimir" value="Imprimir" onclick="infraImprimirDiv(\'divResultadoCargaEmLote\');" class="infraButton"><span class="infraTeclaAtalho">I</span>mprimir</button>';
  }
}
PaginaSEI::getInstance()->montarBarraComandosSuperior($arrComandos);

if ($bolProcessamentoConcluido) {
?>
  <div id="divTipoCarga" class="infraAreaDados" style="height:6em;">
    <label id="lblTipoCarga" for="selTipoCarga" accesskey="" class="infraLabelObrigatorio">Tipo de carga:</label>
    <select id="selTipoCarga" name="selTipoCarga" class="infraSelect" onchange="trocarTipoCarga();" tabindex="<?=PaginaSEI::getInstance()->getProxTabDados()?>">
      <option value="null">&nbsp;</option>
<?php
  foreach ($arrTiposCarga as $strChave => $strDescricaoTipo) {
    $strSelected = ($strTipoCarga === $strChave) ? 'selected="selected"' : '';
    echo '      <option value="' . PaginaSEI::tratarHTML($strChave) . '" ' . $strSelected . '>' . PaginaSEI::tratarHTML($strDescricaoTipo) . '</option>' . "\n";
  }
?>
    </select>
  </div>

  <div id="divTabelaAssuntos" class="infraAreaDados" style="height:6em;">
    <label id="lblTabelaAssuntos" for="selTabelaAssuntos" accesskey="" class="infraLabelOpcional">Tabela de Assuntos:</label>
    <select id="selTabelaAssuntos" name="selTabelaAssuntos" class="infraSelect" tabindex="<?=PaginaSEI::getInstance()->getProxTabDados()?>">
      <option value="null">(usar a tabela marcada como atual)</option>
<?php
  foreach ($arrTabelasAssuntos as $objTabelaAssuntosDTOItem) {
    $strNomeTabelaItem = $objTabelaAssuntosDTOItem->getStrNome();
    $strSelectedTabela = (PaginaSEI::POST('selTabelaAssuntos') === $strNomeTabelaItem) ? 'selected="selected"' : '';
    $strRotuloTabelaItem = $strNomeTabelaItem . (($objTabelaAssuntosDTOItem->getStrSinAtual() === 'S') ? ' (atual)' : '');
    echo '      <option value="' . PaginaSEI::tratarHTML($strNomeTabelaItem) . '" ' . $strSelectedTabela . '>' . PaginaSEI::tratarHTML($strRotuloTabelaItem) . '</option>' . "\n";
  }
?>
    </select>
  </div>

  <div id="divArquivo" class="infraAreaDados" style="height:6em;">
    <label id="lblArquivo" for="filArquivo" accesskey="" class="infraLabelObrigatorio">Arquivo (.csv, .xlsx ou .ods):</label>
    <input type="file" id="filArquivo" name="filArquivo" accept=".csv,.xlsx,.ods" tabindex="<?=PaginaSEI::getInstance()->getProxTabDados()?>" />
  </div>

  <div id="divAviso" class="infraAreaDados" style="height:6em;">
    <label id="lblAviso" class="infraLabelOpcional">Isto pode demorar um pouco, dependendo da quantidade de linhas do arquivo. Se o arquivo tiver muitas linhas, o processamento é feito em lotes de <?=MdCelSeiRN::TAMANHO_LOTE?>: esta tela se atualizará periodicamente com o progresso, sozinha, até concluir. Não feche nem atualize a janela manualmente enquanto isso. Tamanho máximo do arquivo: <?=$numLimiteUploadMb?> Mb.</label>
  </div>
<?php
} else {
  // Carga em andamento: sem campos, so o progresso (a pagina se recarrega sozinha via
  // <meta refresh> ate concluir).
?>
  <div id="divProgresso" class="infraAreaDados" style="height:6em;">
    <label id="lblProcessando" class="infraLabelObrigatorio">Processando <?=PaginaSEI::tratarHTML($strTipoEmAndamento)?>...</label>
    <label id="lblProgresso" class="infraLabelOpcional"><?=(int)$numLinhasProcessadas?> de <?=(int)$numTotalLinhas?> linha(s) do arquivo já passaram pelo sistema. Esta tela vai se atualizar sozinha em instantes: não feche nem atualize a janela manualmente.</label>
  </div>
<?php
}

if ($arrResultado !== null) {
?>
  <br />
  <div id="divResultadoCargaEmLote">
<?php
  PaginaSEI::getInstance()->montarAreaTabela($strResultado, $numRegistros);
?>
  </div>
<?php
}
?>
</form>
<?php
PaginaSEI::getInstance()->fecharBody();
PaginaSEI::getInstance()->fecharHtml();
?>
