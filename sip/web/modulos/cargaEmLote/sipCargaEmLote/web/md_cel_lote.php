<?php
/**
 * Tela do modulo Carga em Lote (SIP): escolhe o tipo de carga, envia o .csv/.xlsx/.ods e
 * mostra o relatorio linha a linha do processamento. Incluida via
 * MdCelSipIntegracao::processarControlador(), ja dentro do controlador.php do SIP
 * (sessao/pagina ja inicializadas) - segue o mesmo padrao das demais telas do SIP (ex.:
 * sip/web/unidade_cadastro.php), inclusive repetindo o require_once/session_start do topo,
 * que e seguro (idempotente) mesmo ja tendo rodado antes.
 *
 * Processamento particionado em lotes (MdCelSipRN::TAMANHO_LOTE linhas por vez, varias
 * requisicoes HTTP curtas em sequencia via <meta refresh>) em vez de uma unica requisicao
 * longa - existe porque o timeout que interrompe uma carga grande normalmente NAO e do PHP
 * (o modulo nao controla isso, e so codigo acrescentado a uma instalacao SIP ja existente) e
 * sim do servidor web/proxy na frente dele. O estado entre requisicoes (arquivo, offset,
 * relatorio acumulado ate agora) fica na sessao do usuario ($_SESSION), nao em banco -
 * decisao deliberada: e descartavel, nao interessa sobreviver a um logout/reinicio, e assim
 * nao precisa de nenhuma tabela nova (modulo so acrescenta arquivos, nunca mexe em schema do
 * core). Mesma implementacao do modulo SEI, replicada aqui por consistencia.
 */

require_once __DIR__ . '/../../../../Sip.php';

session_start();

SessaoSip::getInstance()->validarLink();
SessaoSip::getInstance()->validarPermissao($_GET['acao']);

$strTitulo = 'Carga em Lote (SIP)';
// Tipo de carga => [rotulo, recursos exigidos]. As cargas combinadas exigem os dois recursos.
// O seletor mostra so as cargas que o perfil do operador permite (verificarPermissao); a RN
// valida de novo em cada chamada.
$arrTiposCargaCatalogo = array(
  'unidades_e_hierarquia' => array('Unidades e Hierarquia', array(MdCelSipRN::RECURSO_UNIDADE, MdCelSipRN::RECURSO_HIERARQUIA)),
  'usuarios_e_permissoes' => array('Usuários e Primeiras Permissões', array(MdCelSipRN::RECURSO_USUARIO, MdCelSipRN::RECURSO_PERMISSAO)),
);
$arrTiposCarga = array();
foreach ($arrTiposCargaCatalogo as $strChaveTipo => $arrItemTipo) {
  $bolPermitido = true;
  foreach ($arrItemTipo[1] as $strRecursoTipo) {
    $bolPermitido = $bolPermitido && SessaoSip::getInstance()->verificarPermissao($strRecursoTipo);
  }
  if ($bolPermitido) {
    $arrTiposCarga[$strChaveTipo] = $arrItemTipo[0];
  }
}
if (count($arrTiposCarga) === 0) {
  throw new InfraException('O perfil do usuário não permite nenhuma carga em lote nesta unidade.');
}

const CHAVE_ESTADO_SESSAO = 'md_cel_sip_estado';

$arrResultado = null;
$numTotalLinhas = null;
$numLinhasProcessadas = null;
$bolProcessamentoConcluido = true;
$strTipoCargaEmAndamento = null;
$arrResumoPorOperacao = null;

$strTipoCarga = PaginaSip::POST('selTipoCarga');

try {
  // ETAPA 1 de 3 - novo envio do formulario: descarta qualquer carga anterior em andamento
  // (nunca mistura arquivos) e inicia o estado desta nova carga na sessao.
  if (isset($_POST['sbmProcessar'])) {
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

    // processarUpload() nao retorna valor: ele da echo direto no resultado (pensado para ser
    // lido por um iframe/JS). Capturamos via buffer de saida em vez de reescrever a tela como
    // um fluxo de duas requisicoes - bug encontrado testando contra o container real.
    ob_start();
    // bolArquivoTemporarioIdentificado=true preserva o nome/extensao original no arquivo
    // temporario (sanitizado) - precisamos da extensao pra escolher o leitor certo em
    // MdCelSipRN::lerCsv() (csv/xlsx/ods).
    PaginaSip::getInstance()->processarUpload('filArquivo', DIR_SIP_TEMP, true, true);
    $strRetUpload = ob_get_clean();
    $arrRetUpload = explode('#', $strRetUpload);

    if ($arrRetUpload[0] === 'ERRO') {
      throw new InfraException($arrRetUpload[1] ?? 'Erro no upload do arquivo.');
    }

    $strArquivoTemp = DIR_SIP_TEMP . '/' . $arrRetUpload[0];

    $_SESSION[CHAVE_ESTADO_SESSAO] = array(
      'tipoCarga' => $strTipoCarga,
      'arquivo' => $strArquivoTemp,
      'offset' => 0,
      'total' => null,
      'resultado' => array(),
      'resumoPorOperacao' => null,
    );
  }

  // ETAPA 2 de 3 - roda a cada requisicao (POST inicial OU GET de recarregamento
  // automatico): processa UM lote (MdCelSipRN::TAMANHO_LOTE linhas) e acumula o resultado
  // na sessao. So para de rodar quando offset >= total (carga concluida).
  if (isset($_SESSION[CHAVE_ESTADO_SESSAO])) {
    $arrEstado = &$_SESSION[CHAVE_ESTADO_SESSAO];
    $strTipoCargaEmAndamento = $arrEstado['tipoCarga'];

    if ($arrEstado['total'] === null || $arrEstado['offset'] < $arrEstado['total']) {
      $objMdCelRN = new MdCelSipRN();
      $arrParametrosChamada = array(
        'csv' => $arrEstado['arquivo'],
        'offset' => $arrEstado['offset'],
        'limite' => MdCelSipRN::TAMANHO_LOTE,
      );

      switch ($arrEstado['tipoCarga']) {
        case 'unidades_e_hierarquia':
          $arrRetornoLote = $objMdCelRN->processarUnidadesEHierarquia($arrParametrosChamada);
          break;
        case 'usuarios_e_permissoes':
          $arrRetornoLote = $objMdCelRN->processarUsuariosEPermissoes($arrParametrosChamada);
          break;
        default:
          throw new InfraException('Tipo de carga desconhecido em andamento na sessão.');
      }

      $arrEstado['resultado'] = array_merge($arrEstado['resultado'], $arrRetornoLote['resultado']);
      $arrEstado['total'] = $arrRetornoLote['total'];
      $arrEstado['offset'] += max($arrRetornoLote['processadas'], ($arrRetornoLote['processadas'] === 0) ? $arrEstado['total'] : 0);

      // Resumo separado por sub-operacao (ex.: "usuario(s)" e "permissao(oes)") em vez de um
      // total unico misturando as duas. (ex.: "400 cadastrado(s)" quando eram na verdade 200 usuarios + 200 permissoes).
      if ($arrEstado['resumoPorOperacao'] === null) {
        $arrEstado['resumoPorOperacao'] = $arrRetornoLote['resumoPorOperacao'];
      } else {
        foreach ($arrRetornoLote['resumoPorOperacao'] as $numIndiceOperacao => $arrResumoLote) {
          $arrEstado['resumoPorOperacao'][$numIndiceOperacao]['tally']['ok'] += $arrResumoLote['tally']['ok'];
          $arrEstado['resumoPorOperacao'][$numIndiceOperacao]['tally']['pulado'] += $arrResumoLote['tally']['pulado'];
          $arrEstado['resumoPorOperacao'][$numIndiceOperacao]['tally']['erro'] += $arrResumoLote['tally']['erro'];
        }
      }
    }

    $arrResultado = $arrEstado['resultado'];
    $numTotalLinhas = $arrEstado['total'];
    $numLinhasProcessadas = $arrEstado['offset'];
    $arrResumoPorOperacao = $arrEstado['resumoPorOperacao'];
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
  PaginaSip::getInstance()->processarExcecao($e);
}

// ETAPA 3 de 3 - renderiza a tela no desenho das telas nativas (grupos em infraAreaDados com
// os campos posicionados por id): formulario de envio (se nao ha carga em andamento), progresso
// (se ainda processando) e relatorio linha a linha (se ja existe algum resultado, mesmo que parcial).
$strResultado = '';
$numRegistros = 0;
if ($arrResultado !== null) {
  $numRegistros = count($arrResultado);
  $arrTotais = [];
  if ($arrResumoPorOperacao !== null) {
    foreach ($arrResumoPorOperacao as $arrResumoOperacao) {
      $arrTotais[] = $arrResumoOperacao['tally']['ok'] . ' ' . $arrResumoOperacao['rotulo'] . ' cadastrado(s), ' . $arrResumoOperacao['tally']['pulado'] . ' pulado(s) (já existiam), ' . $arrResumoOperacao['tally']['erro'] . ' com erro';
    }
  } else {
    $numOk = count(array_filter($arrResultado, function ($r) { return $r['status'] === MdCelSipRN::STA_OK; }));
    $numPulado = count(array_filter($arrResultado, function ($r) { return $r['status'] === MdCelSipRN::STA_PULADO; }));
    $numErro = count(array_filter($arrResultado, function ($r) { return $r['status'] === MdCelSipRN::STA_ERRO; }));
    $arrTotais[] = $numOk . ' cadastrado(s), ' . $numPulado . ' pulado(s) (já existiam), ' . $numErro . ' com erro';
  }
  $strCaptionTabela = ($bolProcessamentoConcluido ? 'Resultado' : 'Resultado parcial (até agora)') . ': ' . implode('; ', $arrTotais);
  $strResultado .= '<table width="99%" class="infraTable" summary="Resultado da carga por linha do arquivo">' . "\n";
  $strResultado .= '<caption class="infraCaption">' . PaginaSip::tratarHTML($strCaptionTabela) . '</caption>';
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
    $strResultado .= '<td align="center" valign="top">' . PaginaSip::tratarHTML($arrLinhaResultado['status']) . '</td>';
    $strResultado .= '<td valign="top">' . PaginaSip::tratarHTML($arrLinhaResultado['mensagem']) . '</td>';
    $strResultado .= '</tr>' . "\n";
  }
  $strResultado .= '</table>';
}

$strTipoEmAndamento = ($strTipoCargaEmAndamento !== null) ? ($arrTiposCarga[$strTipoCargaEmAndamento] ?? $strTipoCargaEmAndamento) : '';

PaginaSip::getInstance()->montarDocType();
PaginaSip::getInstance()->abrirHtml();
PaginaSip::getInstance()->abrirHead();
PaginaSip::getInstance()->montarMeta();

if (!$bolProcessamentoConcluido) {
  // Recarrega a propria pagina (mesma URL, GET simples - sem reenviar o formulario) depois
  // de alguns segundos, pra continuar processando o proximo lote. Escolhido no lugar de
  // AJAX/barra de progresso "de verdade" como MVP - mais simples de manter, versao com AJAX
  // fica pra depois se for preciso.
  echo '<meta http-equiv="refresh" content="2" />' . "\n";
}

PaginaSip::getInstance()->montarTitle(PaginaSip::getInstance()->getStrNomeSistema() . ' - ' . $strTitulo);
PaginaSip::getInstance()->montarStyle();
PaginaSip::getInstance()->abrirStyle();
?>
#lblTipoCarga {position:absolute;left:0%;top:5%;}
#selTipoCarga {position:absolute;left:0%;top:40%;width:45%;}

#lblArquivo {position:absolute;left:0%;top:5%;}
#filArquivo {position:absolute;left:0%;top:45%;width:90%;}

#lblAviso {position:absolute;left:0%;top:5%;width:90%;}

#lblProcessando {position:absolute;left:0%;top:5%;}
#lblProgresso {position:absolute;left:0%;top:40%;width:90%;}

<?php
PaginaSip::getInstance()->fecharStyle();
// E esta chamada que inclui InfraMenu.js/InfraAcaoMenu.js (JS que monta os submenus): sem ela
// o menu principal carrega mas os submenus param de funcionar so nesta pagina.
PaginaSip::getInstance()->montarJavaScript();
PaginaSip::getInstance()->abrirJavaScript();
?>
function inicializar(){
  if (document.getElementById('selTipoCarga')){
    document.getElementById('selTipoCarga').focus();
  }
  infraEfeitoTabelas();
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

<?php
PaginaSip::getInstance()->fecharJavaScript();
PaginaSip::getInstance()->fecharHead();
PaginaSip::getInstance()->abrirBody($strTitulo, 'onload="inicializar();"');
?>
<form id="frmCargaEmLote" method="post" enctype="multipart/form-data" onsubmit="return OnSubmitForm();" action="<?=SessaoSip::getInstance()->assinarLink('controlador.php?acao=' . $_GET['acao'] . '&acao_origem=' . $_GET['acao'])?>">
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
PaginaSip::getInstance()->montarBarraComandosSuperior($arrComandos);

if ($bolProcessamentoConcluido) {
?>
  <div id="divTipoCarga" class="infraAreaDados" style="height:6em;">
    <label id="lblTipoCarga" for="selTipoCarga" accesskey="" class="infraLabelObrigatorio">Tipo de carga:</label>
    <select id="selTipoCarga" name="selTipoCarga" class="infraSelect" tabindex="<?=PaginaSip::getInstance()->getProxTabDados()?>">
      <option value="null">&nbsp;</option>
<?php
  foreach ($arrTiposCarga as $strChave => $strDescricaoTipo) {
    $strSelected = ($strTipoCarga === $strChave) ? 'selected="selected"' : '';
    echo '      <option value="' . PaginaSip::tratarHTML($strChave) . '" ' . $strSelected . '>' . PaginaSip::tratarHTML($strDescricaoTipo) . '</option>' . "\n";
  }
?>
    </select>
  </div>

  <div id="divArquivo" class="infraAreaDados" style="height:6em;">
    <label id="lblArquivo" for="filArquivo" accesskey="" class="infraLabelObrigatorio">Arquivo (.csv, .xlsx ou .ods):</label>
    <input type="file" id="filArquivo" name="filArquivo" accept=".csv,.xlsx,.ods" tabindex="<?=PaginaSip::getInstance()->getProxTabDados()?>" />
  </div>

  <div id="divAviso" class="infraAreaDados" style="height:6em;">
    <label id="lblAviso" class="infraLabelOpcional">Isto pode demorar um pouco, dependendo da quantidade de linhas do arquivo. Se o arquivo tiver muitas linhas, o processamento é feito em lotes de <?=MdCelSipRN::TAMANHO_LOTE?>: esta tela se atualizará periodicamente com o progresso, sozinha, até concluir. Não feche nem atualize a janela manualmente enquanto isso. Tamanho máximo do arquivo: <?=PaginaSip::tratarHTML((string)ini_get('upload_max_filesize'))?> (limite do PHP; o SIP não tem parâmetro próprio para isso).</label>
  </div>
<?php
} else {
  // Carga em andamento: sem campos, so o progresso (a pagina se recarrega sozinha via
  // <meta refresh> ate concluir).
?>
  <div id="divProgresso" class="infraAreaDados" style="height:6em;">
    <label id="lblProcessando" class="infraLabelObrigatorio">Processando <?=PaginaSip::tratarHTML($strTipoEmAndamento)?>...</label>
    <label id="lblProgresso" class="infraLabelOpcional"><?=(int)$numLinhasProcessadas?> de <?=(int)$numTotalLinhas?> linha(s) do arquivo já passaram pelo sistema. Esta tela vai se atualizar sozinha em instantes: não feche nem atualize a janela manualmente.</label>
  </div>
<?php
}

if ($arrResultado !== null) {
?>
  <br />
  <div id="divResultadoCargaEmLote">
<?php
  PaginaSip::getInstance()->montarAreaTabela($strResultado, $numRegistros);
?>
  </div>
<?php
}
?>
</form>
<?php
PaginaSip::getInstance()->fecharBody();
PaginaSip::getInstance()->fecharHtml();
?>
