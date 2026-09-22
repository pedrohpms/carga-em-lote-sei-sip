<?
/**
 * Modulo SEI - Carga em Lote
 *
 * Completa, do lado SEI, cadastros de unidades e usuarios ja criados pelo modulo SIP
 * equivalente (sigla/hierarquia/permissao ficam no SIP; os dados complementares abaixo sao
 * campos que so existem do lado SEI), chamando diretamente as classes de regra de negocio ja
 * existentes no SEI, seguindo o modelo de modulos documentado pelo TRF4: nenhum arquivo do
 * core e alterado, o modulo so acrescenta.
 *
 * Cobre as 4 operacoes que rodam no SEI (nao no SIP):
 * - Dados Complementares de Unidade (endereco, telefone, site, CNPJ, e-mails)
 * - Contato de Usuarios (endereco, cargo, CPF, RG, telefones, conjuge etc.)
 * - Assuntos (Tabela de Assuntos / CCD-TTD)
 * - Tipos de Processo (com assuntos sugeridos, restricoes de orgao/unidade, niveis de acesso)
 *
 * Toda a logica de negocio vive em rn/MdCelSeiRN.php - esta classe e so o ponto de
 * extensao que o framework do SEI reconhece (ver processarControlador() abaixo).
 */

class MdCelSeiIntegracao extends SeiIntegracao {

  const ACAO = 'md_cel_lote';

  public function getNome() {
    return 'Carga em Lote (SEI)';
  }

  public function getVersao() {
    return '0.0.2';
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
      require __DIR__ . '/web/md_cel_lote.php';
      return true;
    }
    return null;
  }
}

?>
