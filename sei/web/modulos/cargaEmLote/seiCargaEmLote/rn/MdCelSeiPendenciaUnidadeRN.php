<?php
/**
 * Pendências de unidades no SEI, consultadas antes de uma desativação pelo SIP (módulo Carga em Lote 0.2.0).
 *
 * Chamada pelo serviço md_cel_sip (ws/MdCelSeiSipWS.php) a pedido da tela Unidades pelo SIORG, no SIP,
 * que só desativa unidade sem pendência. A desativação de unidade no SEI não confere processos nem blocos
 * (UnidadeRN::desativarRN0484 só remove retornos programados e grupos de envio), por isso a conferência
 * fica aqui. Só lê, pelas RNs do core, na sessão simulada que o serviço abre (como a replicação do SIP).
 */
class MdCelSeiPendenciaUnidadeRN extends InfraRN
{
  protected function inicializarObjInfraIBanco()
  {
    return BancoSEI::getInstance();
  }

  /**
   * @param int[] $arrIdUnidade
   * @return array [id_unidade => ['processos_abertos' => int, 'blocos_da_unidade' => int, 'blocos_recebidos' => int]]
   *               processos_abertos: processos com andamento sem conclusão na unidade;
   *               blocos_da_unidade: blocos gerados pela unidade e ainda não concluídos;
   *               blocos_recebidos: blocos de outras unidades disponibilizados para ela e não devolvidos
   */
  protected function verificarConectado(array $arrIdUnidade): array
  {
    try {
      $objAtividadeRN = new AtividadeRN();
      $objBlocoRN = new BlocoRN();
      $objRelBlocoUnidadeRN = new RelBlocoUnidadeRN();

      $arrRet = [];
      foreach ($arrIdUnidade as $numIdUnidade) {
        $numIdUnidade = (int)$numIdUnidade;

        $objAtividadeDTO = new AtividadeDTO();
        $objAtividadeDTO->setDistinct(true);
        $objAtividadeDTO->retDblIdProtocolo();
        $objAtividadeDTO->setNumIdUnidade($numIdUnidade);
        $objAtividadeDTO->setDthConclusao(null);

        $objBlocoDTO = new BlocoDTO();
        $objBlocoDTO->retNumIdBloco();
        $objBlocoDTO->setNumIdUnidade($numIdUnidade);
        $objBlocoDTO->setStrStaEstado(BlocoRN::$TE_CONCLUIDO, InfraDTO::$OPER_DIFERENTE);

        $objRelBlocoUnidadeDTO = new RelBlocoUnidadeDTO();
        $objRelBlocoUnidadeDTO->retNumIdBloco();
        $objRelBlocoUnidadeDTO->setNumIdUnidade($numIdUnidade);
        $objRelBlocoUnidadeDTO->setNumIdUnidadeBloco($numIdUnidade, InfraDTO::$OPER_DIFERENTE);
        $objRelBlocoUnidadeDTO->setStrStaEstadoBloco(BlocoRN::$TE_DISPONIBILIZADO);
        $objRelBlocoUnidadeDTO->setStrSinRetornado('N');

        $arrRet[$numIdUnidade] = [
          'processos_abertos' => count($objAtividadeRN->listarRN0036($objAtividadeDTO)),
          'blocos_da_unidade' => $objBlocoRN->contarRN1278($objBlocoDTO),
          'blocos_recebidos' => $objRelBlocoUnidadeRN->contarRN1305($objRelBlocoUnidadeDTO),
        ];
      }
      return $arrRet;
    } catch (Exception $e) {
      throw new InfraException('Erro verificando pendências das unidades no SEI.', $e);
    }
  }
}
