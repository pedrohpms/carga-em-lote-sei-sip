<?
/**
 * Tela do modulo Carga em Lote (SIP): escolhe o tipo de carga, envia o .csv/.xlsx/.ods e
 * mostra o relatorio linha a linha do processamento. Incluida via
 * SipCargaEmLoteIntegracao::processarControlador(), ja dentro do controlador.php do SIP
 * (sessao/pagina ja inicializadas) - segue o mesmo padrao das demais telas do SIP (ex.:
 * sip/web/unidade_cadastro.php), inclusive repetindo o require_once/session_start do topo,
 * que e seguro (idempotente) mesmo ja tendo rodado antes.
 *
 * Processamento particionado em lotes (CargaEmLoteRN::TAMANHO_LOTE linhas por vez, varias
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
$arrTiposCarga = array(
  'unidades_e_hierarquia' => 'Unidades e Hierarquia',
  'usuarios_e_permissoes' => 'Usuários e Primeiras Permissões',
);

const CHAVE_ESTADO_SESSAO = 'sipCargaEmLoteEstado';

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
    // CargaEmLoteRN::lerCsv() (csv/xlsx/ods).
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
  // automatico): processa UM lote (CargaEmLoteRN::TAMANHO_LOTE linhas) e acumula o resultado
  // na sessao. So para de rodar quando offset >= total (carga concluida).
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
        case 'unidades_e_hierarquia':
          $arrRetornoLote = $objCargaEmLoteRN->processarUnidadesEHierarquia($arrParametrosChamada);
          break;
        case 'usuarios_e_permissoes':
          $arrRetornoLote = $objCargaEmLoteRN->processarUsuariosEPermissoes($arrParametrosChamada);
          break;
        default:
          throw new InfraException('Tipo de carga desconhecido em andamento na sessão.');
      }

      $arrEstado['resultado'] = array_merge($arrEstado['resultado'], $arrRetornoLote['resultado']);
      $arrEstado['total'] = $arrRetornoLote['total'];
      $arrEstado['offset'] += max($arrRetornoLote['processadas'], ($arrRetornoLote['processadas'] === 0) ? $arrEstado['total'] : 0);

      // Resumo separado por sub-operacao (ex.: "usuario(s)" e "permissao(oes)") em vez de um
      // total unico misturando as duas - achado do usuario testando contra o container real
      // ("400 cadastrado(s)" quando eram na verdade 200 usuarios + 200 permissoes).
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

// ETAPA 3 de 3 - renderiza a tela: formulario de upload (se nao ha carga em andamento),
// mensagem de progresso (se ainda processando) e/ou o relatorio linha a linha (se ja existe
// algum resultado, mesmo que parcial).
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
  /* Layout em fluxo normal (nao absoluto) para nao depender de adivinhar a altura real do
     container de abrirAreaDados() - ja causou aperto visual com posicionamento absoluto. */
  #areaCargaEmLote label {display:block;margin-top:1.5em;margin-bottom:0.4em;font-weight:bold;}
  #areaCargaEmLote select, #areaCargaEmLote input[type=file] {display:block;margin-bottom:0.5em;}
<?
PaginaSip::getInstance()->fecharStyle();
// Faltava esta chamada: e ela quem inclui InfraMenu.js/InfraAcaoMenu.js (JS que monta os
// submenus). Sem isso, o menu principal carrega mas os submenus param de funcionar so nesta
// pagina - bug encontrado testando contra o container real.
PaginaSip::getInstance()->montarJavaScript();
PaginaSip::getInstance()->abrirJavaScript();
?>
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
PaginaSip::getInstance()->fecharJavaScript();
PaginaSip::getInstance()->fecharHead();
PaginaSip::getInstance()->abrirBody($strTitulo);
?>
  <?
  if ($bolProcessamentoConcluido) {
  ?>
  <form id="frmCargaEmLote" method="post" enctype="multipart/form-data" onsubmit="return OnSubmitForm();"
        action="<?=SessaoSip::getInstance()->assinarLink('controlador.php?acao=' . $_GET['acao'])?>">
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
      // resultado nao esta dentro do container padrao de lista (divInfraAreaTabela). Mesma
      // implementacao ja feita no modulo SEI, replicada aqui por consistencia.
      $arrComandos[] = '<button type="button" id="btnImprimir" value="Imprimir" onclick="infraImprimirDiv(\'divResultadoCargaEmLote\');" class="infraButton">Imprimir</button>';
    }
    PaginaSip::getInstance()->montarBarraComandosSuperior($arrComandos);
    PaginaSip::getInstance()->abrirAreaDados('30em');
    ?>
    <div id="areaCargaEmLote">
    <label id="lblTipoCarga" for="selTipoCarga" class="infraLabelObrigatorio">Tipo de carga:</label>
    <select id="selTipoCarga" name="selTipoCarga" class="infraSelect">
      <option value="null">&nbsp;</option>
      <?
      foreach ($arrTiposCarga as $strChave => $strDescricaoTipo) {
        $strSelected = ($strTipoCarga === $strChave) ? 'selected="selected"' : '';
        echo '<option value="' . $strChave . '" ' . $strSelected . '>' . $strDescricaoTipo . '</option>';
      }
      ?>
    </select>

    <label id="lblArquivo" for="filArquivo" class="infraLabelObrigatorio">Arquivo (.csv, .xlsx ou .ods):</label>
    <input type="file" id="filArquivo" name="filArquivo" accept=".csv,.xlsx,.ods"/>
    <p style="color:#666;font-style:italic;">Isto pode demorar um pouco, dependendo da
    quantidade de linhas do arquivo. Se o arquivo tiver muitas linhas, o processamento é
    feito em lotes de <?=CargaEmLoteRN::TAMANHO_LOTE?> - esta tela se atualizará
    periodicamente com o progresso, sozinha, até concluir. Não feche nem atualize a
    janela manualmente enquanto isso.</p>
    </div>

    <?
    PaginaSip::getInstance()->fecharAreaDados();
    ?>
  </form>
  <?
  } else {
    $arrComandos = array();
    PaginaSip::getInstance()->montarBarraComandosSuperior($arrComandos);
    PaginaSip::getInstance()->abrirAreaDados('10em');
    ?>
    <p><b>Processando <?=PaginaSip::tratarHTML($arrTiposCarga[$strTipoCargaEmAndamento] ?? $strTipoCargaEmAndamento)?>...</b>
    <?=$numLinhasProcessadas?> de <?=$numTotalLinhas?> linha(s) do arquivo já passaram pelo
    sistema. Esta tela vai se atualizar sozinha em instantes - não feche nem atualize a
    janela manualmente.</p>
    <?
    PaginaSip::getInstance()->fecharAreaDados();
  }
  ?>

  <?
  if ($arrResultado !== null) {
    ?>
    <div id="divResultadoCargaEmLote">
    <p><b><?=$bolProcessamentoConcluido ? 'Resultado:' : 'Resultado parcial (até agora):'?></b><br/>
    <?
    if ($arrResumoPorOperacao !== null) {
      foreach ($arrResumoPorOperacao as $arrResumoOperacao) {
        echo $arrResumoOperacao['tally']['ok'] . ' ' . PaginaSip::tratarHTML($arrResumoOperacao['rotulo']) . ' cadastrado(s), ' . $arrResumoOperacao['tally']['pulado'] . ' pulado(s) (já existiam), ' . $arrResumoOperacao['tally']['erro'] . ' com erro.<br/>';
      }
    } else {
      $numOk = count(array_filter($arrResultado, function ($r) { return $r['status'] === CargaEmLoteRN::STA_OK; }));
      $numPulado = count(array_filter($arrResultado, function ($r) { return $r['status'] === CargaEmLoteRN::STA_PULADO; }));
      $numErro = count(array_filter($arrResultado, function ($r) { return $r['status'] === CargaEmLoteRN::STA_ERRO; }));
      echo $numOk . ' cadastrado(s), ' . $numPulado . ' pulado(s) (já existiam), ' . $numErro . ' com erro.';
    }
    ?>
    </p>
    <table class="infraTable" width="100%">
      <thead>
        <tr><th>Linha</th><th>Status</th><th>Mensagem</th></tr>
      </thead>
      <tbody>
        <?
        foreach ($arrResultado as $arrLinhaResultado) {
          echo '<tr><td>' . $arrLinhaResultado['linha'] . '</td><td>' . $arrLinhaResultado['status'] . '</td><td>' . PaginaSip::tratarHTML($arrLinhaResultado['mensagem']) . '</td></tr>';
        }
        ?>
      </tbody>
    </table>
    </div>
  <?
  }
  PaginaSip::getInstance()->fecharBody();
  PaginaSip::getInstance()->fecharHtml();
  ?>
