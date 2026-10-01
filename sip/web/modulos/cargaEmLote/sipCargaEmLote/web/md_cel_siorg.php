<?
/**
 * SIP > Unidades pelo SIORG (módulo Carga em Lote 0.2.0).
 *
 * 1. O operador escolhe o órgão do SIP e informa o código SIORG do mesmo órgão (ou entidade).
 *    O órgão não vira unidade: as unidades logo abaixo dele entram como raízes da hierarquia.
 * 2. A tela mostra a estrutura do SIORG comparada com o SIP: nova, já importada, divergente (nome,
 *    sigla ou posição na hierarquia), conflito de sigla, inválida ou bloqueada (abaixo de superior que não
 *    pode ser importada). Só as novas podem ser marcadas.
 * 3. A importação cria as marcadas de cima para baixo, uma transação por unidade, em lotes de
 *    MdCelSipSiorgRN::TAMANHO_LOTE por requisição, como nas cargas por arquivo (md_cel_lote.php): a tela se recarrega sozinha
 *    e mostra o progresso, sem depender do tempo limite do servidor web. O estado fica na sessão do
 *    usuário (descartável, sem tabela nova).
 * 4. Ao fim, mostra o relatório (órgãos, operador, início, fim e resultado de cada unidade). O botão
 *    Imprimir usa a impressão do navegador (infraImprimirDiv), onde o relatório pode ser salvo em PDF.
 * 5. Unidade já importada com sigla ou nome diferentes dos do SIORG pode ser marcada e atualizada, com uma
 *    confirmação, pelo mesmo caminho da importação. Mudança de posição na hierarquia só é apontada.
 * 6. Unidade do órgão com código SIORG que saiu da estrutura só é sinalizada. A desativação exige três
 *    passos do administrador: marcar e verificar pendências no SIP e no SEI; revisar os alertas, marcar
 *    as aptas e declarar ciência; confirmar no diálogo final. A execução usa os mesmos lotes e relatório da
 *    importação e verifica cada unidade de novo antes de desativá-la.
 * Incluída por MdCelSipIntegracao::processarControlador(). As RNs validam os recursos.
 */

$strTitulo = 'Unidades pelo SIORG';
$strChaveSessao = 'md_cel_siorg_operacao';
$arrComandos = [];
$strOptionsOrgao = '';
$arrComparacao = null;
$arrRelatorio = null;
$bolEmAndamento = false;
$numIdOrgao = null;
$strCodigoOrgao = '';
$strSiglaOrgaoSip = '';
$arrMarcados = [];
$arrMarcadosDesativar = [];
$arrVerificacao = null;
$bolImportar = false;
$bolDesativar = false;
$bolAtualizar = false;
$strOperacao = null;

try {
  require_once __DIR__ . '/../../../../Sip.php';

  session_start();

  SessaoSip::getInstance()->validarLink();
  SessaoSip::getInstance()->validarPermissao(PaginaSip::GET('acao'));

  $bolImportar = SessaoSip::getInstance()->verificarPermissao('md_cel_siorg_unidade_cadastrar');
  $bolDesativar = SessaoSip::getInstance()->verificarPermissao('md_cel_siorg_unidade_desativar');
  $bolAtualizar = SessaoSip::getInstance()->verificarPermissao('md_cel_siorg_unidade_alterar');
  // Importar e atualizar seguem o mesmo caminho: lista preparada no RN, lotes e relatório.
  $strOperacao = PaginaSip::POST('sbmImportar') !== null && $bolImportar ? 'importacao' : (PaginaSip::POST('sbmAtualizar') !== null && $bolAtualizar ? 'atualizacao' : null);

  $strOrgao = (string)PaginaSip::POST('selOrgao');
  $numIdOrgao = ctype_digit($strOrgao) ? (int)$strOrgao : null;
  $strCodigoOrgao = trim((string)PaginaSip::POST('txtCodigoOrgao'));
  $varMarcados = PaginaSip::POST(PaginaSip::POST('sbmAtualizar') !== null ? 'chkAtualizar' : 'chkCodigo');
  if (is_array($varMarcados)) {
    foreach ($varMarcados as $strCodigo) {
      if (ctype_digit((string)$strCodigo)) {
        $arrMarcados[] = (string)$strCodigo;
      }
    }
  }
  $varMarcadosDesativar = PaginaSip::POST('chkDesativar');
  if (is_array($varMarcadosDesativar)) {
    foreach ($varMarcadosDesativar as $strIdUnidade) {
      if (ctype_digit((string)$strIdUnidade)) {
        $arrMarcadosDesativar[] = (int)$strIdUnidade;
      }
    }
  }
  $bolPassoDesativacao = (PaginaSip::POST('sbmVerificarDesativacao') !== null || PaginaSip::POST('sbmDesativar') !== null);

  $objOrgaoDTO = new OrgaoDTO();
  $objOrgaoDTO->retNumIdOrgao();
  $objOrgaoDTO->retStrSigla();
  $objOrgaoDTO->retStrDescricao();
  $objOrgaoDTO->setOrdStrSigla(InfraDTO::$TIPO_ORDENACAO_ASC);
  $objOrgaoRN = new OrgaoRN();
  $arrObjOrgaoDTO = InfraArray::indexarArrInfraDTO($objOrgaoRN->listar($objOrgaoDTO), 'IdOrgao');

  $objSiorgRN = new MdCelSipSiorgRN();

  try {
    if ((PaginaSip::POST('sbmConsultar') !== null || $strOperacao !== null || $bolPassoDesativacao) && ($numIdOrgao === null || !ctype_digit($strCodigoOrgao))) {
      $objInfraException = new InfraException();
      $objInfraException->lancarValidacao('Escolha o órgão do SIP e informe o código SIORG do mesmo órgão (só números).');
    }

    // Nova importação: descarta a anterior da sessão e prepara esta (uma leitura do SIORG).
    if ($strOperacao !== null) {
      unset($_SESSION[$strChaveSessao]);
      if (count($arrMarcados) === 0) {
        $objInfraException = new InfraException();
        $objInfraException->lancarValidacao('Nenhuma unidade marcada.');
      }
      $arrPreparo = $objSiorgRN->prepararImportacao([
        'id_orgao' => $numIdOrgao,
        'codigo_orgao' => $strCodigoOrgao,
        'codigos' => $arrMarcados,
      ]);
      $objOrgaoSipDTO = $arrObjOrgaoDTO[$numIdOrgao] ?? null;
      $_SESSION[$strChaveSessao] = [
        'operacao' => $strOperacao,
        'id_orgao' => $numIdOrgao,
        'orgao_sip' => $objOrgaoSipDTO !== null ? $objOrgaoSipDTO->getStrSigla() . ' - ' . $objOrgaoSipDTO->getStrDescricao() : (string)$numIdOrgao,
        'codigo_orgao' => $strCodigoOrgao,
        'orgao_siorg' => $arrPreparo['orgao'],
        'hierarquia' => (string)$arrPreparo['hierarquia'],
        'usuario' => SessaoSip::getInstance()->getStrSiglaUsuario() . ' - ' . SessaoSip::getInstance()->getStrNomeUsuario(),
        'inicio' => InfraData::getStrDataHoraAtual(),
        'fim' => null,
        'itens' => $arrPreparo['itens'],
        'resultado' => [],
      ];
      $arrMarcados = [];
    }

    // Desativação, passo 1 de 3: verificar as marcadas (só lê, no SIP e no SEI).
    if (PaginaSip::POST('sbmVerificarDesativacao') !== null && $bolDesativar) {
      if (count($arrMarcadosDesativar) === 0) {
        $objInfraException = new InfraException();
        $objInfraException->lancarValidacao('Nenhuma unidade sinalizada foi marcada para verificar.');
      }
      $arrVerificacao = $objSiorgRN->verificarDesativacao(['id_orgao' => $numIdOrgao, 'codigo_orgao' => $strCodigoOrgao, 'ids_unidade' => $arrMarcadosDesativar]);
    }

    // Desativação, passo 3 de 3 (depois da revisão e do diálogo de confirmação): verifica de novo e desativa
    // as aptas em lotes; cada unidade é verificada mais uma vez logo antes (MdCelSipSiorgRN::desativarUnidade).
    if (PaginaSip::POST('sbmDesativar') !== null && $bolDesativar) {
      unset($_SESSION[$strChaveSessao]);
      $objInfraException = new InfraException();
      if (PaginaSip::POST('chkCiente') !== 'S') {
        $objInfraException->lancarValidacao('Leia os alertas e marque a declaração de ciência antes de desativar.');
      }
      if (count($arrMarcadosDesativar) === 0) {
        $objInfraException->lancarValidacao('Nenhuma unidade marcada para desativar.');
      }
      $arrVerificacaoAtual = $objSiorgRN->verificarDesativacao(['id_orgao' => $numIdOrgao, 'codigo_orgao' => $strCodigoOrgao, 'ids_unidade' => $arrMarcadosDesativar]);
      $objOrgaoSipDTO = $arrObjOrgaoDTO[$numIdOrgao] ?? null;
      $_SESSION[$strChaveSessao] = [
        'operacao' => 'desativacao',
        'id_orgao' => $numIdOrgao,
        'orgao_sip' => $objOrgaoSipDTO !== null ? $objOrgaoSipDTO->getStrSigla() . ' - ' . $objOrgaoSipDTO->getStrDescricao() : (string)$numIdOrgao,
        'codigo_orgao' => $strCodigoOrgao,
        'orgao_siorg' => $arrVerificacaoAtual['orgao'],
        'hierarquia' => (string)$arrVerificacaoAtual['hierarquia'],
        'usuario' => SessaoSip::getInstance()->getStrSiglaUsuario() . ' - ' . SessaoSip::getInstance()->getStrNomeUsuario(),
        'inicio' => InfraData::getStrDataHoraAtual(),
        'fim' => null,
        'itens' => $arrVerificacaoAtual['unidades'],
        'resultado' => [],
      ];
      $arrMarcadosDesativar = [];
    }

    // Um lote por requisição: o POST que inicia a operação e cada recarga automática da tela.
    if (isset($_SESSION[$strChaveSessao])) {
      try {
        $arrEstado = $_SESSION[$strChaveSessao];
        $arrLote = array_slice($arrEstado['itens'], count($arrEstado['resultado']), MdCelSipSiorgRN::TAMANHO_LOTE);
        foreach ($arrLote as $arrItem) {
          if ($arrEstado['operacao'] === 'desativacao') {
            $arrEstado['resultado'][] = $objSiorgRN->desativarItem((int)$arrEstado['id_orgao'], $arrItem);
          } elseif ($arrEstado['operacao'] === 'atualizacao') {
            $arrEstado['resultado'][] = $objSiorgRN->atualizarItem((int)$arrEstado['id_orgao'], $arrItem);
          } else {
            $arrEstado['resultado'][] = $objSiorgRN->importarItem((int)$arrEstado['id_orgao'], $arrItem);
          }
        }
        if (count($arrEstado['resultado']) >= count($arrEstado['itens'])) {
          $arrEstado['fim'] = InfraData::getStrDataHoraAtual();
        }
        $arrRelatorio = $arrEstado;
        $bolEmAndamento = ($arrEstado['fim'] === null);
        if ($bolEmAndamento) {
          $_SESSION[$strChaveSessao] = $arrEstado;
        } else {
          unset($_SESSION[$strChaveSessao]);
          $numIdOrgao = (int)$arrEstado['id_orgao'];
          $strCodigoOrgao = (string)$arrEstado['codigo_orgao'];
        }
      } catch (Exception $e) {
        unset($_SESSION[$strChaveSessao]);
        throw $e;
      }
    }

    // Comparação: no Consultar e ao fim de uma operação, para mostrar o estado depois dela. Na revisão da
    // desativação (passo 2) a tela mostra só a verificação.
    if (!$bolEmAndamento && $arrVerificacao === null && $numIdOrgao !== null && ctype_digit($strCodigoOrgao) && (PaginaSip::POST('sbmConsultar') !== null || $strOperacao !== null || $bolPassoDesativacao || $arrRelatorio !== null)) {
      $arrComparacao = $objSiorgRN->comparar(['id_orgao' => $numIdOrgao, 'codigo_orgao' => $strCodigoOrgao]);
    }
  } catch (Exception $e) {
    PaginaSip::getInstance()->processarExcecao($e);
  }

  $strOptionsOrgao = '<option value=""></option>';
  foreach ($arrObjOrgaoDTO as $numId => $objDTO) {
    if ($numId === $numIdOrgao) {
      $strSiglaOrgaoSip = (string)$objDTO->getStrSigla();
    }
    $strOptionsOrgao .= '<option value="' . $numId . '"' . ($numId === $numIdOrgao ? ' selected="selected"' : '') . '>' . PaginaSip::tratarHTML($objDTO->getStrSigla() . ' - ' . $objDTO->getStrDescricao()) . '</option>';
  }

  $arrComandos[] = '<button type="submit" accesskey="C" name="sbmConsultar" value="Consultar" class="infraButton"><span class="infraTeclaAtalho">C</span>onsultar SIORG</button>';
  if ($arrVerificacao !== null) {
    $arrComandos = [];
    $arrComandos[] = '<button type="submit" accesskey="V" name="sbmConsultar" value="Consultar" class="infraButton"><span class="infraTeclaAtalho">V</span>oltar sem desativar</button>';
    $arrComandos[] = '<button type="submit" name="sbmDesativar" value="Desativar" onclick="return confirmarDesativacao();" class="infraButton">Desativar as marcadas (passo 3 de 3)</button>';
  }
  if ($arrComparacao !== null && $bolImportar) {
    $arrComandos[] = '<button type="submit" accesskey="I" name="sbmImportar" value="Importar" onclick="return confirmarImportacao();" class="infraButton"><span class="infraTeclaAtalho">I</span>mportar marcadas</button>';
  }
  if ($arrComparacao !== null && $bolAtualizar && count(array_filter(array_column($arrComparacao['unidades'], 'atualizar'))) > 0) {
    $arrComandos[] = '<button type="submit" name="sbmAtualizar" value="Atualizar" onclick="return confirmarAtualizacao();" class="infraButton">Atualizar marcadas</button>';
  }
  if ($arrRelatorio !== null && !$bolEmAndamento) {
    $arrComandos[] = '<button type="button" accesskey="P" id="btnImprimir" value="Imprimir" onclick="infraImprimirDiv(\'divMdCelRelatorio\');" class="infraButton">Im<span class="infraTeclaAtalho">p</span>rimir relatório</button>';
  }
} catch (Exception $e) {
  PaginaSip::getInstance()->processarExcecao($e);
}

$arrRotuloSituacao = [
  MdCelSipSiorgRN::SITUACAO_NOVA => 'Nova',
  MdCelSipSiorgRN::SITUACAO_EXISTENTE => 'Já importada',
  MdCelSipSiorgRN::SITUACAO_DIVERGENTE => 'Divergente',
  MdCelSipSiorgRN::SITUACAO_CONFLITO => 'Conflito',
  MdCelSipSiorgRN::SITUACAO_INVALIDA => 'Inválida',
  MdCelSipSiorgRN::SITUACAO_BLOQUEADA => 'Bloqueada',
];
$arrTiposIgnorados = [];
try {
  $arrTiposIgnorados = MdCelSipSiorgRN::obterTiposIgnorados();
} catch (Exception $e) {
}
$bolMarcarPadrao = (PaginaSip::POST('sbmConsultar') !== null || $arrRelatorio !== null);

$strOperacaoRelatorio = $arrRelatorio['operacao'] ?? 'importacao';
$bolRelatorioDesativacao = ($strOperacaoRelatorio === 'desativacao');
$strFeita = ['importacao' => 'importada', 'atualizacao' => 'atualizada', 'desativacao' => 'desativada'][$strOperacaoRelatorio];
$strTituloRelatorio = ['importacao' => 'Relatório de importação de unidades do SIORG', 'atualizacao' => 'Relatório de atualização de sigla e nome pelo SIORG', 'desativacao' => 'Relatório de desativação de unidades que saíram da estrutura do SIORG'][$strOperacaoRelatorio];
$strRecursoRelatorio = ['importacao' => 'md_cel_siorg_unidade_cadastrar', 'atualizacao' => 'md_cel_siorg_unidade_alterar', 'desativacao' => 'md_cel_siorg_unidade_desativar'][$strOperacaoRelatorio];
$numTotal = 0;
$numFeitos = 0;
$numImportadas = 0;
$numPercentual = 0;
if ($arrRelatorio !== null) {
  $numTotal = count($arrRelatorio['itens']);
  $numFeitos = count($arrRelatorio['resultado']);
  $numImportadas = count(array_filter($arrRelatorio['resultado'], function ($arrItem) { return $arrItem['sucesso']; }));
  $numPercentual = $numTotal > 0 ? (int)floor(100 * $numFeitos / $numTotal) : 100;
}

$strHtmlRelatorio = '';
if ($arrRelatorio !== null) {
  ob_start();
  ?>
  <div id="divMdCelRelatorio">
    <h3><?=$strTituloRelatorio?><?=($bolEmAndamento ? ' (parcial)' : '')?></h3>
    <p>
      Órgão do SIP: <strong><?=PaginaSip::tratarHTML($arrRelatorio['orgao_sip'])?></strong><br />
      Órgão no SIORG: <strong><?=PaginaSip::tratarHTML($arrRelatorio['orgao_siorg']['sigla'] . ' - ' . $arrRelatorio['orgao_siorg']['nome'])?></strong> (código <?=PaginaSip::tratarHTML($arrRelatorio['orgao_siorg']['codigo'])?>)<br />
      Hierarquia de destino: <?=PaginaSip::tratarHTML($arrRelatorio['hierarquia'])?><br />
      Operador: <?=PaginaSip::tratarHTML($arrRelatorio['usuario'])?><br />
      Início: <?=PaginaSip::tratarHTML($arrRelatorio['inicio'])?>. Fim: <?=PaginaSip::tratarHTML($arrRelatorio['fim'] ?? 'em andamento')?><br />
      Unidades marcadas: <?=$numTotal?>. <?=ucfirst($strFeita)?>s: <strong><?=$numImportadas?></strong>. Não <?=$strFeita?>s: <strong><?=($numFeitos - $numImportadas)?></strong><?=($bolEmAndamento ? '. Aguardando: ' . ($numTotal - $numFeitos) : '')?>.
    </p>
    <table width="99%" class="infraTable" summary="Resultado da importação de cada unidade.">
      <caption class="infraCaption">Resultado por unidade (<?=$numFeitos?> de <?=$numTotal?>)</caption>
      <tr>
        <th class="infraTh" width="4%">Ordem</th>
        <th class="infraTh" width="8%">Código SIORG</th>
        <th class="infraTh" width="13%">Sigla no SIP</th>
        <th class="infraTh">Nome</th>
        <th class="infraTh" width="10%">Situação</th>
        <th class="infraTh" width="30%">Detalhe</th>
      </tr>
      <? foreach ($arrRelatorio['resultado'] as $numOrdem => $arrItem) { ?>
      <tr class="infraTrClara">
        <td align="center"><?=($numOrdem + 1)?></td>
        <td align="center"><?=PaginaSip::tratarHTML($arrItem['codigo'])?></td>
        <td><?=PaginaSip::tratarHTML($arrItem['sigla'])?></td>
        <td><?=PaginaSip::tratarHTML($arrItem['nome'])?></td>
        <td align="center" class="<?=($arrItem['sucesso'] ? 'mdCelNOVA' : 'mdCelINVALIDA')?>"><?=($arrItem['sucesso'] ? ucfirst($strFeita) : 'Não ' . $strFeita)?></td>
        <td><?=PaginaSip::tratarHTML($arrItem['mensagem'])?></td>
      </tr>
      <? } ?>
    </table>
    <p class="mdCelAjuda">Cada unidade <?=$strFeita?> fica registrada também na auditoria do SIP (recurso <?=$strRecursoRelatorio?>).<?=($bolRelatorioDesativacao ? ' A reativação é manual, no SIP: a unidade (Unidades &gt; Reativar) e a posição na hierarquia (Hierarquias).' : '')?></p>
  </div>
  <?
  $strHtmlRelatorio = ob_get_clean();
}

PaginaSip::getInstance()->montarDocType();
PaginaSip::getInstance()->abrirHtml();
PaginaSip::getInstance()->abrirHead();
PaginaSip::getInstance()->montarMeta();
if ($bolEmAndamento) {
  // Recarrega a própria página (GET, sem reenviar o formulário) para importar o próximo lote.
  echo '<meta http-equiv="refresh" content="1" />' . "\n";
}
PaginaSip::getInstance()->montarTitle(PaginaSip::getInstance()->getStrNomeSistema() . ' - ' . $strTitulo);
PaginaSip::getInstance()->montarStyle();
PaginaSip::getInstance()->abrirStyle();
?>
div.mdCelLinha {margin:.6em 0;}
div.mdCelLinha label {display:block; margin-bottom:.2em;}
#selOrgao {width:50%;}
#txtCodigoOrgao {width:12em;}
div.mdCelAviso {border:1px solid #b9770e; background:#fdf2e9; padding:.4em .6em; margin:.4em 0;}
span.mdCelAjuda, p.mdCelAjuda {color:#555; font-size:.9em;}
tr.mdCelApagada td {color:#888;}
td.mdCelNOVA {color:#1e8449; font-weight:bold;}
td.mdCelDIVERGENTE, td.mdCelCONFLITO, td.mdCelBLOQUEADA {color:#b9770e; font-weight:bold;}
td.mdCelINVALIDA {color:#c0392b; font-weight:bold;}
div.mdCelAlerta {border:2px solid #c0392b; background:#fdedec; padding:.5em .8em; margin:.6em 0;}
div.mdCelAlerta ol {margin:.3em 0 .3em 1.2em; padding:0;}
div.mdCelCiente {border:1px solid #c0392b; padding:.5em .8em; margin:.6em 0; font-weight:bold;}
div.mdCelBarra {border:1px solid #999; background:#eee; height:1.4em; width:60%; margin:.4em 0 1em 0;}
div.mdCelBarra div {background:#1e8449; height:100%;}
div#divMdCelRelatorio {margin:1em 0;}
div#divMdCelRelatorio h3 {margin:.2em 0 .4em 0;}
<?
PaginaSip::getInstance()->fecharStyle();
PaginaSip::getInstance()->montarJavaScript();
PaginaSip::getInstance()->abrirJavaScript();
?>
//<script>
function marcarTodas(marcar){
  var caixas = document.querySelectorAll('input.mdCelCodigo');
  for (var i = 0; i < caixas.length; i++){
    if (!caixas[i].disabled){ caixas[i].checked = marcar; }
  }
}
function confirmarVerificacao(){
  if (document.querySelectorAll('input.mdCelSinalizada:checked').length == 0){
    alert('Marque as unidades sinalizadas que quer verificar.');
    return false;
  }
  return true;
}
function confirmarDesativacao(){
  var caixas = document.querySelectorAll('input.mdCelDesativar:checked');
  if (caixas.length == 0){
    alert('Nenhuma unidade apta marcada para desativar.');
    return false;
  }
  var ciente = document.getElementById('chkCiente');
  if (!ciente.checked){
    alert('Leia os alertas e marque a declaração de ciência antes de desativar.');
    ciente.focus();
    return false;
  }
  var siglas = [];
  for (var i = 0; i < caixas.length; i++){ siglas.push(caixas[i].getAttribute('data-sigla')); }
  return confirm('ATENÇÃO: confirma a DESATIVAÇÃO de ' + caixas.length + ' unidade(s) no SIP e no SEI?\n\n' + siglas.join(', ') +
    '\n\nCada unidade é verificada de novo antes de ser desativada. A reativação é manual.');
}
function confirmarAtualizacao(){
  var marcadas = document.querySelectorAll('input.mdCelAtualizar:checked').length;
  if (marcadas == 0){
    alert('Marque as unidades divergentes que quer atualizar.');
    return false;
  }
  return confirm('Confirma a atualização de sigla e nome de ' + marcadas + ' unidade(s) no SIP e no SEI, com os dados do SIORG?');
}
function confirmarImportacao(){
  var marcadas = document.querySelectorAll('input.mdCelCodigo:checked').length;
  if (marcadas == 0){
    alert('Nenhuma unidade marcada.');
    return false;
  }
  return confirm('Confirma a importação de ' + marcadas + ' unidade(s) para o SIP?');
}
//</script>
<?
PaginaSip::getInstance()->fecharJavaScript();
PaginaSip::getInstance()->fecharHead();
PaginaSip::getInstance()->abrirBody($strTitulo);

if ($bolEmAndamento) {
  PaginaSip::getInstance()->montarBarraComandosSuperior([]);
  ?>
  <p><strong><?=$strTituloRelatorio?> em andamento:</strong> <?=$numFeitos?> de <?=$numTotal?> unidade(s) processada(s) (<?=$numPercentual?>%), <?=$numImportadas?> <?=$strFeita?>(s) até agora.
  A tela se atualiza sozinha a cada lote de <?=MdCelSipSiorgRN::TAMANHO_LOTE?> unidades. Não feche nem atualize a janela até o fim; o relatório para imprimir aparece ao concluir.</p>
  <div class="mdCelBarra" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?=$numPercentual?>"><div style="width:<?=$numPercentual?>%;"></div></div>
  <?
  echo $strHtmlRelatorio;
} else {
?>
<form id="frmMdCelSiorg" method="post" action="<?=SessaoSip::getInstance()->assinarLink('controlador.php?acao=' . MdCelSipIntegracao::ACAO_SIORG . '&acao_origem=' . MdCelSipIntegracao::ACAO_SIORG)?>">
  <?
  PaginaSip::getInstance()->montarBarraComandosSuperior($arrComandos);
  ?>
  <div class="mdCelLinha">
    <label for="selOrgao" class="infraLabelObrigatorio">Órgão do SIP que recebe as unidades:</label>
    <select id="selOrgao" name="selOrgao" class="infraSelect"><?=$strOptionsOrgao?></select>
  </div>
  <div class="mdCelLinha">
    <label for="txtCodigoOrgao" class="infraLabelObrigatorio">Código SIORG do mesmo órgão ou entidade <span class="mdCelAjuda">(ex.: 330683 para o Ministério do Empreendedorismo; consulte em estruturaorganizacional.dados.gov.br). O órgão não vira unidade: as unidades logo abaixo dele entram como raízes da hierarquia</span>:</label>
    <input type="text" id="txtCodigoOrgao" name="txtCodigoOrgao" class="infraText" maxlength="12" value="<?=PaginaSip::tratarHTML($strCodigoOrgao)?>" />
  </div>

  <?=$strHtmlRelatorio?>

  <? if ($arrComparacao !== null) { ?>
  <p>
    Órgão no SIORG: <strong><?=PaginaSip::tratarHTML($arrComparacao['orgao']['sigla'] . ' - ' . $arrComparacao['orgao']['nome'])?></strong>
    (<?=PaginaSip::tratarHTML($arrComparacao['orgao']['tipo'])?>, código <?=PaginaSip::tratarHTML($arrComparacao['orgao']['codigo'])?>), que corresponde ao órgão escolhido no SIP e não é cadastrado como unidade.
    Hierarquia de destino: <strong><?=PaginaSip::tratarHTML((string)$arrComparacao['hierarquia'])?></strong> (a do sistema SEI).
    Tipos desmarcados por padrão: <strong><?=PaginaSip::tratarHTML(count($arrTiposIgnorados) > 0 ? implode(', ', $arrTiposIgnorados) : 'nenhum')?></strong>
    <span class="mdCelAjuda">(parâmetro MD_CEL_SIORG_TIPOS_IGNORADOS em Infra &gt; Parâmetros)</span>.
    Sigla já usada em outro órgão entra composta com a sigla do órgão no SIP (<?=PaginaSip::tratarHTML(($strSiglaOrgaoSip !== '' ? $strSiglaOrgaoSip : 'ORGAO') . '_SIGLA')?>), porque o SEI exige sigla única entre órgãos.
    <a href="javascript:marcarTodas(true);">Marcar todas as novas</a> | <a href="javascript:marcarTodas(false);">Desmarcar todas</a>
  </p>
  <? if ($strSiglaOrgaoSip !== '' && strtoupper($strSiglaOrgaoSip) !== strtoupper($arrComparacao['orgao']['sigla'])) { ?>
  <div class="mdCelAviso">A sigla do órgão no SIP (<?=PaginaSip::tratarHTML($strSiglaOrgaoSip)?>) é diferente da sigla no SIORG (<?=PaginaSip::tratarHTML($arrComparacao['orgao']['sigla'])?>). Confira se o código informado é do mesmo órgão.</div>
  <? } ?>
  <? if ($arrComparacao['fora_do_orgao'] > 0) { ?>
  <div class="mdCelAviso"><?=(int)$arrComparacao['fora_do_orgao']?> unidade(s) da estrutura pertencem a outro órgão ou entidade (vinculadas) e ficaram de fora.</div>
  <? } ?>
  <table width="99%" class="infraTable" summary="Estrutura do SIORG comparada com o SIP.">
    <caption class="infraCaption">Estrutura do SIORG (<?=count($arrComparacao['unidades'])?> unidades)</caption>
    <tr>
      <th class="infraTh" width="1%"></th>
      <th class="infraTh" width="8%">Código</th>
      <th class="infraTh" width="14%">Tipo</th>
      <th class="infraTh" width="14%">Sigla</th>
      <th class="infraTh">Nome</th>
      <th class="infraTh" width="9%">Situação</th>
      <th class="infraTh" width="22%">Detalhe</th>
    </tr>
    <?
    foreach ($arrComparacao['unidades'] as $arrUnidade) {
      $bolNova = ($arrUnidade['situacao'] === MdCelSipSiorgRN::SITUACAO_NOVA);
      $bolTipoIgnorado = in_array($arrUnidade['tipo'], $arrTiposIgnorados, true);
      if ($bolMarcarPadrao) {
        $bolMarcada = $bolNova && !$bolTipoIgnorado;
      } else {
        $bolMarcada = $bolNova && in_array($arrUnidade['codigo'], $arrMarcados, true);
      }
      $strCodigo = PaginaSip::tratarHTML($arrUnidade['codigo']);
      $strDetalhe = $arrUnidade['detalhe'];
      if ($bolNova && $bolTipoIgnorado) {
        $strDetalhe = 'desmarcada por padrão (tipo ' . $arrUnidade['tipo'] . ')' . ($strDetalhe !== '' ? '; se marcada, ' . $strDetalhe : '');
      }
      ?>
      <tr class="<?=($bolNova && !$bolTipoIgnorado ? 'infraTrClara' : 'infraTrClara mdCelApagada')?>">
        <? if (!empty($arrUnidade['atualizar']) && $bolAtualizar) { ?>
        <td align="center"><input type="checkbox" class="infraCheckbox mdCelAtualizar" name="chkAtualizar[]" value="<?=$strCodigo?>" title="Atualizar para <?=PaginaSip::tratarHTML($arrUnidade['atualizar']['sigla'] . ' - ' . $arrUnidade['nome'])?>" /></td>
        <? } else { ?>
        <td align="center"><input type="checkbox" class="infraCheckbox mdCelCodigo" name="chkCodigo[]" value="<?=$strCodigo?>"<?=($bolMarcada ? ' checked="checked"' : '')?><?=($bolNova && $bolImportar ? '' : ' disabled="disabled"')?> /></td>
        <? } ?>
        <td align="center"><?=$strCodigo?></td>
        <td><?=PaginaSip::tratarHTML($arrUnidade['tipo'])?></td>
        <td style="padding-left:<?=(0.3 + 1.2 * (int)$arrUnidade['nivel'])?>em;"><?=PaginaSip::tratarHTML($arrUnidade['sigla'])?></td>
        <td><?=PaginaSip::tratarHTML($arrUnidade['nome'])?></td>
        <td align="center" class="mdCel<?=PaginaSip::tratarHTML($arrUnidade['situacao'])?>"><?=PaginaSip::tratarHTML($arrRotuloSituacao[$arrUnidade['situacao']] ?? $arrUnidade['situacao'])?></td>
        <td><?=PaginaSip::tratarHTML($strDetalhe)?></td>
      </tr>
    <? } ?>
  </table>

  <? if (count($arrComparacao['fora_da_estrutura']) > 0) { ?>
  <div class="mdCelAlerta">
    <strong><?=count($arrComparacao['fora_da_estrutura'])?> unidade(s) sinalizada(s) para desativação:</strong> estão ativas neste órgão do SIP com código SIORG, mas não aparecem mais na estrutura do órgão no SIORG.
    Nada é desativado sem o aval do administrador, em três passos: (1) marcar e verificar pendências no SIP e no SEI; (2) revisar os alertas, marcar as aptas e declarar ciência; (3) confirmar a desativação.
  </div>
  <table width="99%" class="infraTable" summary="Unidades sinalizadas para desativação.">
    <caption class="infraCaption">Unidades sinalizadas para desativação (<?=count($arrComparacao['fora_da_estrutura'])?>)</caption>
    <tr>
      <th class="infraTh" width="1%"></th>
      <th class="infraTh" width="8%">Código SIORG</th>
      <th class="infraTh" width="13%">Sigla no SIP</th>
      <th class="infraTh">Nome</th>
      <th class="infraTh" width="12%">Superior no SIP</th>
      <th class="infraTh" width="30%">Motivo</th>
    </tr>
    <? foreach ($arrComparacao['fora_da_estrutura'] as $arrUnidade) { ?>
    <tr class="infraTrClara">
      <td align="center"><input type="checkbox" class="infraCheckbox mdCelSinalizada" name="chkDesativar[]" value="<?=(int)$arrUnidade['id_unidade']?>"<?=($bolDesativar ? '' : ' disabled="disabled"')?> /></td>
      <td align="center"><?=PaginaSip::tratarHTML($arrUnidade['codigo'])?></td>
      <td><?=PaginaSip::tratarHTML($arrUnidade['sigla'])?></td>
      <td><?=PaginaSip::tratarHTML($arrUnidade['nome'])?></td>
      <td><?=PaginaSip::tratarHTML($arrUnidade['superior'])?></td>
      <td><?=PaginaSip::tratarHTML($arrUnidade['motivo'])?></td>
    </tr>
    <? } ?>
  </table>
  <? if ($bolDesativar) { ?>
  <p><button type="submit" name="sbmVerificarDesativacao" value="Verificar" onclick="return confirmarVerificacao();" class="infraButton">Verificar pendências das marcadas (passo 1 de 3)</button>
  <span class="mdCelAjuda">Só consulta o SIP e o SEI; não altera nada.</span></p>
  <? } else { ?>
  <p class="mdCelAjuda">A desativação exige o recurso md_cel_siorg_unidade_desativar.</p>
  <? } ?>
  <? } ?>
  <? } ?>

  <? if ($arrVerificacao !== null) { ?>
  <h3>Desativação de unidades: revisão (passo 2 de 3)</h3>
  <div class="mdCelAlerta">
    <strong>Leia antes de continuar.</strong>
    <ol>
      <li>A desativação vale para o SIP e para o SEI: a unidade deixa de aparecer para login, para envio de processos e nas listas de unidades.</li>
      <li>No SEI, a desativação apaga os retornos programados enviados pela unidade e a retira dos grupos de envio (regra do próprio SEI).</li>
      <li>Só pode ser desativada a unidade sem processos abertos, sem blocos pendentes (dela ou recebidos de outras unidades), sem permissões de usuários em nenhum sistema, sem coordenador de unidade e sem subunidade ativa. As outras aparecem abaixo com as pendências e não podem ser marcadas.</li>
      <li>As unidades são desativadas de baixo para cima. Se uma subunidade não puder ser desativada, a superior também não será.</li>
      <li>Cada unidade é verificada de novo, no SIP e no SEI, logo antes de ser desativada. Se o SIP ou o SEI recusar, a unidade fica como estava e o motivo aparece no relatório.</li>
      <li>A reativação é manual, no SIP: a unidade (Unidades &gt; Reativar) e a posição na hierarquia (Hierarquias).</li>
      <li>Cada desativação fica registrada na auditoria do SIP. Imprima o relatório ao fim: ele é o registro do que foi e do que não foi desativado.</li>
    </ol>
  </div>
  <p>Órgão no SIORG: <strong><?=PaginaSip::tratarHTML($arrVerificacao['orgao']['sigla'] . ' - ' . $arrVerificacao['orgao']['nome'])?></strong> (código <?=PaginaSip::tratarHTML($arrVerificacao['orgao']['codigo'])?>). Hierarquia: <?=PaginaSip::tratarHTML((string)$arrVerificacao['hierarquia'])?>.</p>
  <table width="99%" class="infraTable" summary="Verificação das unidades para desativação.">
    <caption class="infraCaption">Verificação (<?=count($arrVerificacao['unidades'])?> unidade(s), na ordem de desativação)</caption>
    <tr>
      <th class="infraTh" width="1%"></th>
      <th class="infraTh" width="8%">Código SIORG</th>
      <th class="infraTh" width="13%">Sigla no SIP</th>
      <th class="infraTh">Nome</th>
      <th class="infraTh" width="10%">Situação</th>
      <th class="infraTh" width="35%">Pendências e avisos</th>
    </tr>
    <? foreach ($arrVerificacao['unidades'] as $arrUnidade) { ?>
    <tr class="<?=($arrUnidade['apta'] ? 'infraTrClara' : 'infraTrClara mdCelApagada')?>">
      <td align="center"><input type="checkbox" class="infraCheckbox mdCelDesativar" name="chkDesativar[]" value="<?=(int)$arrUnidade['id_unidade']?>" data-sigla="<?=PaginaSip::tratarHTML($arrUnidade['sigla'])?>"<?=($arrUnidade['apta'] ? ' checked="checked"' : ' disabled="disabled"')?> /></td>
      <td align="center"><?=PaginaSip::tratarHTML($arrUnidade['codigo'])?></td>
      <td><?=PaginaSip::tratarHTML($arrUnidade['sigla'])?></td>
      <td><?=PaginaSip::tratarHTML($arrUnidade['nome'])?></td>
      <td align="center" class="<?=($arrUnidade['apta'] ? 'mdCelNOVA' : 'mdCelINVALIDA')?>"><?=($arrUnidade['apta'] ? 'Apta' : 'Com pendências')?></td>
      <td><?=PaginaSip::tratarHTML(implode('; ', array_merge($arrUnidade['pendencias'], $arrUnidade['avisos'])) ?: 'nenhuma pendência no SIP nem no SEI')?></td>
    </tr>
    <? } ?>
  </table>
  <div class="mdCelCiente">
    <input type="checkbox" class="infraCheckbox" id="chkCiente" name="chkCiente" value="S" />
    <label for="chkCiente">Li os alertas acima e confirmo que as unidades marcadas saíram da estrutura do órgão e devem ser desativadas no SIP e no SEI.</label>
  </div>
  <? } ?>
  <?
  PaginaSip::getInstance()->montarBarraComandosInferior($arrComandos);
  ?>
</form>
<?
}
PaginaSip::getInstance()->fecharBody();
PaginaSip::getInstance()->fecharHtml();
?>
