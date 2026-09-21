<?
/**
 * Modulo SIP - CARGA EM LOTE
 * Desenvolvido pelo Processo Eletrônico Nacional, produzido com o auxílio do Claude Code.
 *
 * Tem como objetivo automatizar processos administrativos repetitivos no SEI/SIP com segurança 
 * e eficiência, reduzindo o esforço manual de operadores e padronizando o carregamento de dados 
 * a partir de arquivos .csv de referência.
 * 
 * Cobre as seguintes operacoes que rodam no SIP:
 * cadastro de unidades, hierarquia, usuarios e primeiras permissoes.
 */

class MdCelSipIntegracao extends SipIntegracao {

  const ACAO = 'md_cel_lote';

  public function getNome() {
    return 'Carga em Lote (SIP)';
  }

  public function getVersao() {
    return '2.0.0';
  }

  public function getInstituicao() {
    return 'Processo Eletrônico Nacional - PEN';
  }

  public function inicializar() {
    return null;
  }

  /**
   * Ponto de extensao chamado por sip/web/controlador.php DEPOIS de toda acao nativa (ver
   * loop no default: do switch principal) - so intercepta a acao propria deste modulo
   * (self::ACAO = 'md_cel_lote'); qualquer outra acao devolve null e o controlador
   * segue seu fluxo normal. Por isso o modulo e genuinamente aditivo: nunca pode
   * sobrescrever uma acao nativa, so acrescentar uma nova.
   */
  public function processarControlador($strAcao) {
    if ($strAcao === self::ACAO) {
      require __DIR__ . '/web/md_cel_lote.php';
      return true;
    }
    return null;
  }
}

?>
