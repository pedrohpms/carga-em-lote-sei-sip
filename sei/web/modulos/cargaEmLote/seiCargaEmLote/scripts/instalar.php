<?
/**
 * Script de instalacao do modulo Carga em Lote (SEI): cria (se ainda nao existirem) o
 * recurso, o item de menu e o perfil necessarios para operar o modulo, e atribui o recurso
 * e o item de menu ao perfil.
 *
 * Mesma mecanica do script equivalente do modulo SIP (Sprint 1) - InfraScriptVersao +
 * ScriptSip, ambos idempotentes (fazem consultar()/contar() antes de cadastrar). A diferenca
 * e que aqui o id_sistema usado e o do "SEI" (nao "SIP"): recurso/perfil/menu de telas do SEI
 * sao geridos nas mesmas tabelas do SIP, so filtrados por id_sistema - confirmado lendo
 * sip/scripts/atualizar_recursos_sei.php, o script oficial equivalente do proprio TRF4.
 *
 * Uso (dentro do container httpd, via linha de comando - InfraScriptVersao exige CLI):
 *   php /opt/sei/web/modulos/cargaEmLote/seiCargaEmLote/scripts/instalar.php
 * ou, do host:
 *   docker exec -it httpd php /opt/sei/web/modulos/cargaEmLote/seiCargaEmLote/scripts/instalar.php
 *
 * Pede usuario/senha do banco SIP interativamente (mesmo banco onde recurso/perfil/menu do
 * SEI sao armazenados). Registra a versao instalada em infra_parametro (chave
 * CARGA_EM_LOTE_SEI_VERSAO, distinta da chave CARGA_EM_LOTE_VERSAO usada pelo modulo SIP).
 */

require_once __DIR__ . '/../../../../../../sip/web/Sip.php';
// SeiIntegracao nao e autocarregavel sob o bootstrap do SIP (Infra.php so registra os
// caminhos do sistema que fez o bootstrap) - precisa ser incluida explicitamente.
require_once __DIR__ . '/../../../../SeiIntegracao.php';
require_once __DIR__ . '/../SeiCargaEmLoteIntegracao.php';

class VersaoCargaEmLoteSeiRN extends InfraScriptVersao {

  public function __construct() {
    parent::__construct();
  }

  protected function inicializarObjInfraIBanco() {
    return BancoSip::getInstance();
  }

  public function versao_1_0_0($strVersaoAtual) {
    try {
      $numIdSistemaSei = ScriptSip::obterIdSistema('SEI');

      $objPerfilDTO = ScriptSip::cadastrarPerfil(
        $numIdSistemaSei,
        'Carga em Lote (SEI)',
        'Operador de Carga em Lote (SEI)',
        'S',
        'N'
      );
      $numIdPerfil = $objPerfilDTO->getNumIdPerfil();

      $numIdMenuSei = ScriptSip::obterIdMenu($numIdSistemaSei, 'Principal');

      // Item fica dentro de "Administração" (nao na raiz do menu) - itens que nao sao raiz
      // nao mostram icone (confirmado empiricamente: só o nível 1 do menu reserva o espaço
      // do ícone), por isso nenhum ícone é passado abaixo.
      $numIdItemMenuAdministracao = ScriptSip::obterIdItemMenu($numIdSistemaSei, $numIdMenuSei, 'Administração');

      $objRecursoDTO = ScriptSip::adicionarRecursoPerfil(
        $numIdSistemaSei,
        $numIdPerfil,
        'md_carga_em_lote_sei',
        'controlador.php?acao=md_carga_em_lote_sei',
        'Carga em Lote (SEI)'
      );

      ScriptSip::adicionarItemMenu(
        $numIdSistemaSei,
        $numIdPerfil,
        $numIdMenuSei,
        $numIdItemMenuAdministracao,
        $objRecursoDTO->getNumIdRecurso(),
        'Carga em Lote',
        0
      );
    } catch (Exception $e) {
      throw new InfraException('Erro instalando o modulo Carga em Lote (SEI).', $e);
    }
  }
}

try {
  session_start();

  SessaoSip::getInstance(false);

  $objVersaoRN = new VersaoCargaEmLoteSeiRN();
  $objVersaoRN->setStrNome('SEI - MODULO CARGA EM LOTE');
  $objVersaoRN->setStrClasseModulo('SeiCargaEmLoteIntegracao');
  $objVersaoRN->setStrVersaoAtual('1.0.0');
  $objVersaoRN->setStrParametroVersao('CARGA_EM_LOTE_SEI_VERSAO');
  $objVersaoRN->setArrVersoes(array(
    '1.0.0' => 'versao_1_0_0',
  ));
  $objVersaoRN->setStrVersaoInfra('2.45.1');
  $objVersaoRN->setBolMySql(true);
  $objVersaoRN->setBolOracle(true);
  $objVersaoRN->setBolSqlServer(true);
  $objVersaoRN->setBolPostgreSql(true);
  $objVersaoRN->setBolErroVersaoInexistente(false);

  $objVersaoRN->atualizarVersao();
} catch (Exception $e) {
  echo(InfraException::inspecionar($e));
  try {
    LogSip::getInstance()->gravar(InfraException::inspecionar($e));
  } catch (Exception $e) {
  }
  exit(1);
}
?>
