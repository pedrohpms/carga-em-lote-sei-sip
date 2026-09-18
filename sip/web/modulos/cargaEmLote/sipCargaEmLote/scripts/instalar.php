<?
/**
 * Script de instalacao do modulo Carga em Lote (SIP): cria (se ainda nao existirem) o
 * recurso, o item de menu e o perfil necessarios para operar o modulo, e atribui o recurso
 * e o item de menu ao perfil.
 *
 * Segue exatamente o mesmo padrao que o proprio TRF4 usa para instalar/atualizar recursos do
 * SIP (sip/scripts/atualizar_recursos_sei.php): uma subclasse de InfraScriptVersao, chamando
 * os metodos estaticos idempotentes de ScriptSip (todos fazem consultar()/contar() antes de
 * cadastrar - reexecutar este script nao duplica nem sobrescreve nada).
 *
 * Uso (dentro do container httpd, via linha de comando - InfraScriptVersao exige CLI):
 *   php /opt/sip/web/modulos/cargaEmLote/sipCargaEmLote/scripts/instalar.php
 * ou, do host:
 *   docker exec -it httpd php /opt/sip/web/modulos/cargaEmLote/sipCargaEmLote/scripts/instalar.php
 *
 * Pede usuario/senha do banco interativamente (mesmo comportamento do script de referencia).
 * Registra a versao instalada em infra_parametro (chave CARGA_EM_LOTE_VERSAO) - reexecutar
 * com a mesma versao do modulo termina com "ULTIMA VERSAO JA ESTA INSTALADA", sem alterar
 * nada; isso e o comportamento esperado do mecanismo, nao um erro do modulo.
 */

require_once __DIR__ . '/../../../../Sip.php';
require_once __DIR__ . '/../SipCargaEmLoteIntegracao.php';

class VersaoCargaEmLoteRN extends InfraScriptVersao {

  public function __construct() {
    parent::__construct();
  }

  protected function inicializarObjInfraIBanco() {
    return BancoSip::getInstance();
  }

  public function versao_1_0_0($strVersaoAtual) {
    try {
      $numIdSistemaSip = ScriptSip::obterIdSistema('SIP');

      $objPerfilDTO = ScriptSip::cadastrarPerfil(
        $numIdSistemaSip,
        'Carga em Lote',
        'Operador de Carga em Lote (SIP)',
        'S',
        'N'
      );
      $numIdPerfil = $objPerfilDTO->getNumIdPerfil();

      $numIdMenuSip = ScriptSip::obterIdMenu($numIdSistemaSip, 'Principal');

      $objRecursoDTO = ScriptSip::adicionarRecursoPerfil(
        $numIdSistemaSip,
        $numIdPerfil,
        'md_carga_em_lote',
        'controlador.php?acao=md_carga_em_lote',
        'Carga em Lote (SIP)'
      );

      ScriptSip::adicionarItemMenu(
        $numIdSistemaSip,
        $numIdPerfil,
        $numIdMenuSip,
        null,
        $objRecursoDTO->getNumIdRecurso(),
        'Carga em Lote',
        0,
        'carga.svg'
      );
    } catch (Exception $e) {
      throw new InfraException('Erro instalando o modulo Carga em Lote.', $e);
    }
  }
}

try {
  session_start();

  SessaoSip::getInstance(false);

  $objVersaoRN = new VersaoCargaEmLoteRN();
  $objVersaoRN->setStrNome('SIP - MODULO CARGA EM LOTE');
  $objVersaoRN->setStrClasseModulo('SipCargaEmLoteIntegracao');
  $objVersaoRN->setStrVersaoAtual('1.0.0');
  $objVersaoRN->setStrParametroVersao('CARGA_EM_LOTE_VERSAO');
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
