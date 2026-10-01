<?
/**
 * Serviço do módulo Carga em Lote no SEI para o lado SIP do mesmo módulo (0.2.0).
 *
 * WSDL publicado por controlador_ws.php?servico=md_cel_sip (MdCelSeiIntegracao::processarControladorWebServices).
 * A chamada é autenticada como a replicação do SIP: o SIP gera um identificador de uso único
 * (Replicacao::executar) e o SEI o confere no SIP (InfraSip::validarReplicacao), sem chave nova.
 * Só consulta; não altera nada no SEI.
 */

require_once __DIR__ . '/../../../../SEI.php';

class MdCelSeiSipWS extends InfraWS
{
  public function getObjInfraLog()
  {
    return LogSEI::getInstance();
  }

  /**
   * @param string $IdReplicacao identificador de uso único gerado pelo SIP
   * @param string $IdUnidades ids das unidades separados por vírgula
   * @return string JSON [id_unidade => ['processos_abertos', 'blocos_da_unidade', 'blocos_recebidos']]
   */
  public function verificarPendenciasUnidades($IdReplicacao, $IdUnidades)
  {
    try {
      $objInfraSip = new InfraSip(SessaoSEI::getInstance(false));
      $objInfraSip->validarReplicacao($IdReplicacao);

      SessaoSEI::getInstance()->simularLogin(SessaoSEI::$USUARIO_SIP, SessaoSEI::$UNIDADE_TESTE);

      $arrIdUnidade = array_values(array_filter(array_map('trim', explode(',', (string)$IdUnidades)), 'ctype_digit'));
      $objMdCelSeiPendenciaUnidadeRN = new MdCelSeiPendenciaUnidadeRN();
      return json_encode($objMdCelSeiPendenciaUnidadeRN->verificar($arrIdUnidade));
    } catch (Throwable $e) {
      $this->processarExcecao($e);
    }
    return null;
  }
}

$servidorSoap = new SoapServer(__DIR__ . '/md_cel_sip.wsdl', ['encoding' => 'ISO-8859-1']);
$servidorSoap->setClass('MdCelSeiSipWS');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $servidorSoap->handle();
}
