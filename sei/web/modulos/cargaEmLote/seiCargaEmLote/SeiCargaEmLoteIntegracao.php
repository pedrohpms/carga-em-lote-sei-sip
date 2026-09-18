<?
/**
 * Modulo SEI Carga em Lote (Sprint 2)
 *
 * Completa, do lado SEI, o cadastro de unidades ja criadas pelo modulo SIP equivalente
 * (Sprint 1): dados complementares de unidade (endereco, telefone, site, CNPJ, e-mails),
 * chamando diretamente as classes de regra de negocio ja existentes no SEI (UnidadeRN,
 * ContatoRN, EmailUnidadeRN), seguindo o modelo de modulos documentado pelo TRF4: nenhum
 * arquivo do core e alterado, o modulo so acrescenta.
 *
 * As demais operacoes que rodam no SEI (contato de usuarios, assuntos, tipos de processo)
 * devem crescer dentro desta mesma classe/rn em sprints seguintes, mesmo padrao de
 * crescimento do modulo SIP na Sprint 1.
 */

class SeiCargaEmLoteIntegracao extends SeiIntegracao {

  const ACAO = 'md_carga_em_lote_sei';

  public function getNome() {
    return 'Carga em Lote (SEI)';
  }

  public function getVersao() {
    return '1.0.0';
  }

  public function getInstituicao() {
    return 'Processo Eletrônico Nacional - PEN';
  }

  public function inicializar($strVersaoSEI) {
    return null;
  }

  public function obterDiretorioIconesMenu() {
    return __DIR__ . '/menu';
  }

  public function processarControlador($strAcao) {
    if ($strAcao === self::ACAO) {
      require __DIR__ . '/web/carga_em_lote_form.php';
      return true;
    }
    return null;
  }
}

?>
