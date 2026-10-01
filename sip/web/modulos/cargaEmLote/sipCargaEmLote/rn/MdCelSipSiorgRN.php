<?php
/**
 * Importação sob demanda de unidades do SIORG para o SIP (módulo Carga em Lote 0.2.0).
 *
 * O código informado é o do órgão (ou entidade) no SIORG. Ele corresponde ao órgão escolhido no SIP e
 * não é cadastrado como unidade. As unidades logo abaixo dele (secretarias, gabinete, assessorias)
 * entram como raízes da hierarquia do sistema SEI, e as demais abaixo das respectivas superiores.
 * Unidades de outro órgão ou entidade que apareçam na estrutura (vinculadas) ficam de fora.
 *
 * A comparação com o SIP usa o campo id_origem, onde fica o código SIORG com o prefixo da base
 * (SIORG:340703), e a sigla, e confere a posição de cada unidade já importada na hierarquia. O prefixo
 * evita confundir a unidade com outra cujo id_origem tenha código de outra origem. O cadastro usa as
 * RNs do core. Reimportar não duplica: a unidade já importada é reconhecida pelo id_origem.
 *
 * O SEI exige sigla de unidade única entre todos os órgãos (unidades ativas), e o SIP só dentro do órgão.
 * Sigla que já exista em outro órgão entra composta com a sigla do órgão no SIP (ORGAO_SIGLA, por
 * exemplo MEMP_OUVIR), padrão dos órgãos para esse caso. A composição também vale na comparação: a
 * unidade já importada com a sigla composta não é divergente, e a sigla composta já usada no órgão é
 * conflito, como a simples.
 *
 * Unidade do órgão no SIP com código SIORG que não aparece mais na estrutura do órgão é só sinalizada para
 * desativação. A desativação exige o aval do administrador em três passos na tela e só acontece sem
 * pendências no SIP (permissões, coordenadores, subunidades ativas, outras hierarquias) nem no SEI
 * (processos abertos, blocos), conferidas de novo logo antes de cada unidade (verificarPendencias).
 *
 * Permissões: a tela e a comparação usam md_cel_siorg; cada cadastro usa
 * md_cel_siorg_unidade_cadastrar (auditado). As RNs do core validam também os recursos delas
 * (unidade_cadastrar, rel_hierarquia_unidade_cadastrar): o perfil do módulo não dá escrita além
 * da que o operador já tem, como nas cargas por arquivo deste módulo.
 */
class MdCelSipSiorgRN extends InfraRN
{
  public const URL = 'MD_CEL_SIORG_URL';
  public const TIMEOUT = 'MD_CEL_SIORG_TIMEOUT';
  public const PROXY = 'MD_CEL_SIORG_PROXY';
  public const TIPOS_IGNORADOS = 'MD_CEL_SIORG_TIPOS_IGNORADOS';

  /** Valores padrão, os mesmos gravados pelo script da versão 3.1.0 no banco do SIP. */
  public const PARAMETROS = [
    self::URL => 'https://estruturaorganizacional.dados.gov.br/doc',
    self::TIMEOUT => '30',
    self::PROXY => '',
    self::TIPOS_IGNORADOS => 'unidade-colegiada',
  ];

  public const SITUACAO_NOVA = 'NOVA';
  public const SITUACAO_EXISTENTE = 'EXISTENTE';
  public const SITUACAO_DIVERGENTE = 'DIVERGENTE';
  public const SITUACAO_CONFLITO = 'CONFLITO';
  public const SITUACAO_INVALIDA = 'INVALIDA';
  /** Nova, mas abaixo de superior em conflito, inválida ou também bloqueada: a importação a recusaria. */
  public const SITUACAO_BLOQUEADA = 'BLOQUEADA';

  /** Unidades importadas por requisição na tela (cada uma cadastra no SIP e replica para o SEI). */
  public const TAMANHO_LOTE = 10;

  /** Prefixo do código SIORG no id_origem da unidade: nome da base e separador. */
  public const PREFIXO_ID_ORIGEM = 'SIORG:';

  /** id_origem da unidade SIORG de código $strCodigo (ex.: SIORG:340703). */
  public static function montarIdOrigem(string $strCodigo): string
  {
    return self::PREFIXO_ID_ORIGEM . $strCodigo;
  }

  /** Unidade do SIORG pela sigla e pelo código, como aparece nas mensagens: SANE (SIORG 342164). */
  public static function descreverUnidadeSiorg(string $strSigla, string $strCodigo): string
  {
    return ($strSigla !== '' ? $strSigla . ' ' : '') . '(SIORG ' . $strCodigo . ')';
  }

  /** Sigla composta com a do órgão no SIP (ORGAO_SIGLA), para sigla que já existe em outro órgão. */
  public static function comporSigla(string $strSiglaOrgao, string $strSigla): string
  {
    return $strSiglaOrgao . '_' . $strSigla;
  }

  protected function inicializarObjInfraIBanco()
  {
    return BancoSip::getInstance();
  }

  public static function obterParametro(string $strNome): string
  {
    $objInfraParametro = new InfraParametro(BancoSip::getInstance());
    $strValor = $objInfraParametro->getValor($strNome, false);
    return ($strValor === null || $strValor === '') ? self::PARAMETROS[$strNome] : (string)$strValor;
  }

  /** Tipos do SIORG aceitos como ponto de partida: o nó informado é um órgão ou uma entidade. */
  public const TIPOS_ORGAO = ['orgao', 'entidade'];

  /** @return string[] tipos de unidade do SIORG que a tela deixa desmarcados por padrão */
  public static function obterTiposIgnorados(): array
  {
    return array_values(array_filter(array_map('trim', explode(',', self::obterParametro(self::TIPOS_IGNORADOS)))));
  }

  /**
   * Lê a estrutura do órgão no SIORG e compara com o órgão do SIP.
   *
   * @param array $arrParametros ['id_orgao' => int, 'codigo_orgao' => string]
   * @return array ['hierarquia' => string, 'orgao' => array (codigo, sigla, nome, tipo), 'fora_do_orgao' => int,
   *               'fora_da_estrutura' => array[] (unidades ativas do órgão no SIP com código SIORG que não está
   *               na estrutura: id_unidade, codigo, sigla, nome, superior, profundidade, motivo),
   *               'unidades' => array[]], unidades de cima para baixo, sem o próprio órgão, cada uma com
   *               codigo, codigo_pai, sigla_pai, raiz (bool), tipo, sigla, nome, nivel, situacao, detalhe e sigla_sip
   *               (a gravada no SIP: a do SIORG ou a composta ORGAO_SIGLA)
   */
  protected function compararConectado(array $arrParametros): array
  {
    try {
      SessaoSip::getInstance()->validarAuditarPermissao('md_cel_siorg', __METHOD__, $arrParametros);

      $numIdOrgao = (int)$arrParametros['id_orgao'];
      $arrEstrutura = $this->lerEstrutura((string)$arrParametros['codigo_orgao']);
      $arrSiorg = $arrEstrutura['unidades'];
      $objSistemaSeiDTO = $this->obterSistemaSei();

      // Unidades do órgão no SIP, inclusive desativadas, por id, por id_origem e por sigla.
      $objUnidadeDTO = new UnidadeDTO();
      $objUnidadeDTO->setBolExclusaoLogica(false);
      $objUnidadeDTO->retNumIdUnidade();
      $objUnidadeDTO->retStrSigla();
      $objUnidadeDTO->retStrDescricao();
      $objUnidadeDTO->retStrIdOrigem();
      $objUnidadeDTO->retStrSinAtivo();
      $objUnidadeDTO->setNumIdOrgao($numIdOrgao);
      $objUnidadeRN = new UnidadeRN();
      $arrPorId = [];
      $arrPorOrigem = [];
      $arrPorSigla = [];
      foreach ($objUnidadeRN->listar($objUnidadeDTO) as $objDTO) {
        $arrPorId[(int)$objDTO->getNumIdUnidade()] = $objDTO;
        if ($objDTO->getStrIdOrigem() !== null && $objDTO->getStrIdOrigem() !== '') {
          $arrPorOrigem[$objDTO->getStrIdOrigem()] = $objDTO;
        }
        $arrPorSigla[strtoupper($objDTO->getStrSigla())] = $objDTO;
      }

      // Posição atual de cada unidade na hierarquia do sistema SEI (id da unidade => id da superior ou null).
      $objRelDTO = new RelHierarquiaUnidadeDTO();
      $objRelDTO->retNumIdUnidade();
      $objRelDTO->retNumIdUnidadePai();
      $objRelDTO->setNumIdHierarquia($objSistemaSeiDTO->getNumIdHierarquia());
      $objRelHierarquiaUnidadeRN = new RelHierarquiaUnidadeRN();
      $arrPaiAtual = [];
      foreach ($objRelHierarquiaUnidadeRN->listar($objRelDTO) as $objDTO) {
        $arrPaiAtual[(int)$objDTO->getNumIdUnidade()] = $objDTO->getNumIdUnidadePai() !== null ? (int)$objDTO->getNumIdUnidadePai() : null;
      }

      // Siglas das unidades ativas dos outros órgãos: o SEI recusa sigla repetida entre órgãos
      // (UnidadeRN::validarStrSiglaRN0957 do SEI).
      $strSiglaOrgao = $this->obterSiglaOrgao($numIdOrgao);
      $objUnidadeDTO = new UnidadeDTO();
      $objUnidadeDTO->retStrSigla();
      $objUnidadeDTO->retStrSiglaOrgao();
      $objUnidadeDTO->setNumIdOrgao($numIdOrgao, InfraDTO::$OPER_DIFERENTE);
      $arrOutrosOrgaos = [];
      foreach ($objUnidadeRN->listar($objUnidadeDTO) as $objDTO) {
        $arrOutrosOrgaos[strtoupper($objDTO->getStrSigla())] = $objDTO->getStrSiglaOrgao();
      }

      $arrContagemSigla = [];
      foreach ($arrSiorg as $arrUnidade) {
        $strChave = strtoupper($arrUnidade['sigla']);
        $arrContagemSigla[$strChave] = ($arrContagemSigla[$strChave] ?? 0) + 1;
      }

      foreach ($arrSiorg as $i => $arrUnidade) {
        $strSigla = $arrUnidade['sigla'];
        $strChave = strtoupper($strSigla);
        $strSiglaComposta = self::comporSigla($strSiglaOrgao, $strSigla);
        $strChaveComposta = strtoupper($strSiglaComposta);
        $strRaiz = $arrUnidade['raiz'] ? 'entra como raiz da hierarquia' : '';
        $arrSiorg[$i]['sigla_sip'] = $strSigla;

        $strIdOrigem = self::montarIdOrigem($arrUnidade['codigo']);

        if (isset($arrPorOrigem[$strIdOrigem])) {
          $objExistente = $arrPorOrigem[$strIdOrigem];
          $strSiglaSip = $objExistente->getStrSigla();
          $arrSiorg[$i]['sigla_sip'] = $strSiglaSip;
          $arrDiferencas = [];
          // A sigla composta (ORGAO_SIGLA) equivale à do SIORG.
          $bolSiglaEquivalente = ($strSiglaSip === $strSigla || $strSiglaSip === $strSiglaComposta);
          if (!$bolSiglaEquivalente || $objExistente->getStrDescricao() !== $arrUnidade['nome']) {
            $arrDiferencas[] = 'no SIP: ' . $strSiglaSip . ' - ' . $objExistente->getStrDescricao();
            // Atualização pela tela (atualizarItem): mantém a sigla equivalente; senão usa a do SIORG, composta
            // se já existir em outro órgão. Sigla em uso ou longa demais fica para o administrador.
            $strSiglaNova = $bolSiglaEquivalente ? $strSiglaSip : (isset($arrOutrosOrgaos[$strChave]) ? $strSiglaComposta : $strSigla);
            $strChaveNova = strtoupper($strSiglaNova);
            if ($objExistente->getStrSinAtivo() === 'S') {
              if ($bolSiglaEquivalente || (strlen($strSiglaNova) <= 30 && (!isset($arrPorSigla[$strChaveNova]) || $arrPorSigla[$strChaveNova] === $objExistente) && !isset($arrOutrosOrgaos[$strChaveNova]))) {
                $arrSiorg[$i]['atualizar'] = ['id_unidade' => (int)$objExistente->getNumIdUnidade(), 'sigla' => $strSiglaNova];
              } else {
                $arrDiferencas[] = 'a sigla ' . $strSiglaNova . ' já está em uso ou passa de 30 caracteres: atualize à mão';
              }
            }
          }
          // Unidade desativada no SIP não tem posição na hierarquia; a divergência é estar desativada.
          if ($objExistente->getStrSinAtivo() !== 'S') {
            $arrDiferencas[] = 'desativada no SIP, mas ainda está na estrutura do órgão no SIORG';
          } else {
            $strDiferencaHierarquia = $this->compararPosicao($objExistente, $arrUnidade, $arrPaiAtual, $arrPorId, $arrPorOrigem);
            if ($strDiferencaHierarquia !== '') {
              $arrDiferencas[] = $strDiferencaHierarquia;
            }
          }
          $arrSiorg[$i]['situacao'] = count($arrDiferencas) === 0 ? self::SITUACAO_EXISTENTE : self::SITUACAO_DIVERGENTE;
          $arrSiorg[$i]['detalhe'] = count($arrDiferencas) === 0 ? 'já importada' . ($strSiglaSip === $strSiglaComposta ? ' como ' . $strSiglaSip : '') : implode('; ', $arrDiferencas);
        } elseif ($strSigla === '' || strlen($strSigla) > 30 || $arrUnidade['nome'] === '') {
          $arrSiorg[$i]['situacao'] = self::SITUACAO_INVALIDA;
          $arrSiorg[$i]['detalhe'] = 'sigla vazia ou com mais de 30 caracteres, ou nome vazio';
        } elseif (isset($arrPorSigla[$strChave]) || isset($arrPorSigla[$strChaveComposta])) {
          // Busca também pela sigla composta: unidade cadastrada antes como ORGAO_SIGLA, sem o id_origem.
          $objUsada = $arrPorSigla[$strChave] ?? $arrPorSigla[$strChaveComposta];
          $arrSiorg[$i]['situacao'] = self::SITUACAO_CONFLITO;
          $arrSiorg[$i]['detalhe'] = 'sigla já usada no órgão por ' . $objUsada->getStrSigla() . ' - ' . $objUsada->getStrDescricao();
        } elseif ($arrContagemSigla[$strChave] > 1) {
          $arrSiorg[$i]['situacao'] = self::SITUACAO_CONFLITO;
          $arrSiorg[$i]['detalhe'] = 'sigla repetida ' . $arrContagemSigla[$strChave] . ' vezes nesta estrutura do SIORG';
        } elseif (isset($arrOutrosOrgaos[$strChave])) {
          $strUsada = 'sigla ' . $strSigla . ' já usada no órgão ' . $arrOutrosOrgaos[$strChave];
          if (strlen($strSiglaComposta) > 30) {
            $arrSiorg[$i]['situacao'] = self::SITUACAO_CONFLITO;
            $arrSiorg[$i]['detalhe'] = $strUsada . ', e a composta ' . $strSiglaComposta . ' passa de 30 caracteres';
          } elseif (isset($arrOutrosOrgaos[$strChaveComposta])) {
            $arrSiorg[$i]['situacao'] = self::SITUACAO_CONFLITO;
            $arrSiorg[$i]['detalhe'] = $strUsada . ', e a composta ' . $strSiglaComposta . ' no órgão ' . $arrOutrosOrgaos[$strChaveComposta];
          } else {
            $arrSiorg[$i]['situacao'] = self::SITUACAO_NOVA;
            $arrSiorg[$i]['sigla_sip'] = $strSiglaComposta;
            $arrSiorg[$i]['detalhe'] = $strUsada . ': entra como ' . $strSiglaComposta . ($strRaiz !== '' ? '; ' . $strRaiz : '');
          }
        } else {
          $arrSiorg[$i]['situacao'] = self::SITUACAO_NOVA;
          $arrSiorg[$i]['detalhe'] = $strRaiz;
        }
      }

      // Unidade nova abaixo de superior que não pode ser importada também não pode: a importação a recusaria
      // por falta da superior no SIP. A lista vem de cima para baixo, e o bloqueio desce pelos níveis.
      $arrMotivoBloqueio = [
        self::SITUACAO_CONFLITO => 'está em conflito',
        self::SITUACAO_INVALIDA => 'está inválida',
        self::SITUACAO_BLOQUEADA => 'também está bloqueada',
      ];
      $arrSituacaoPorCodigo = [];
      foreach ($arrSiorg as $i => $arrUnidade) {
        $strSituacaoPai = $arrSituacaoPorCodigo[$arrUnidade['codigo_pai']] ?? null;
        if (!$arrUnidade['raiz'] && $arrUnidade['situacao'] === self::SITUACAO_NOVA && isset($arrMotivoBloqueio[$strSituacaoPai])) {
          $arrSiorg[$i]['situacao'] = self::SITUACAO_BLOQUEADA;
          $arrSiorg[$i]['detalhe'] = 'a superior ' . self::descreverUnidadeSiorg($arrUnidade['sigla_pai'], $arrUnidade['codigo_pai']) . ' ' . $arrMotivoBloqueio[$strSituacaoPai] . ' e precisa estar no SIP antes desta';
        }
        $arrSituacaoPorCodigo[$arrUnidade['codigo']] = $arrSiorg[$i]['situacao'];
      }

      // Unidades ativas do órgão no SIP com código SIORG que não aparecem mais na estrutura do órgão: só
      // sinalizadas; a desativação depende do aval do administrador (verificarDesativacao e desativarItem).
      $arrCodigosEstrutura = array_flip(array_column($arrSiorg, 'codigo'));
      $arrCodigosVinculadas = array_flip($arrEstrutura['codigos_vinculadas']);
      $arrForaDaEstrutura = [];
      foreach ($arrPorOrigem as $strIdOrigemSip => $objDTO) {
        if (strpos((string)$strIdOrigemSip, self::PREFIXO_ID_ORIGEM) !== 0 || $objDTO->getStrSinAtivo() !== 'S') {
          continue;
        }
        $strCodigo = substr((string)$strIdOrigemSip, strlen(self::PREFIXO_ID_ORIGEM));
        if (isset($arrCodigosEstrutura[$strCodigo])) {
          continue;
        }
        if ($strCodigo === $arrEstrutura['orgao']['codigo']) {
          $strMotivo = 'é o próprio órgão no SIORG, que não é cadastrado como unidade';
        } elseif (isset($arrCodigosVinculadas[$strCodigo])) {
          $strMotivo = 'no SIORG pertence a outro órgão ou entidade (vinculada)';
        } else {
          $strMotivo = 'não aparece mais na estrutura do órgão no SIORG';
        }
        $numIdUnidade = (int)$objDTO->getNumIdUnidade();
        $numIdPai = $arrPaiAtual[$numIdUnidade] ?? null;
        $arrForaDaEstrutura[] = [
          'id_unidade' => $numIdUnidade,
          'codigo' => $strCodigo,
          'sigla' => $objDTO->getStrSigla(),
          'nome' => $objDTO->getStrDescricao(),
          'superior' => !array_key_exists($numIdUnidade, $arrPaiAtual) ? 'fora da hierarquia' : ($numIdPai === null ? 'raiz' : (isset($arrPorId[$numIdPai]) ? $arrPorId[$numIdPai]->getStrSigla() : 'unidade ' . $numIdPai)),
          'profundidade' => $this->calcularProfundidade($numIdUnidade, $arrPaiAtual),
          'motivo' => $strMotivo,
        ];
      }
      usort($arrForaDaEstrutura, function ($a, $b) {
        return strcmp($a['sigla'], $b['sigla']);
      });

      return [
        'hierarquia' => $objSistemaSeiDTO->getStrNomeHierarquia(),
        'orgao' => $arrEstrutura['orgao'],
        'fora_do_orgao' => $arrEstrutura['fora_do_orgao'],
        'fora_da_estrutura' => $arrForaDaEstrutura,
        'unidades' => $arrSiorg,
      ];
    } catch (Exception $e) {
      throw new InfraException('Erro comparando a estrutura do SIORG com o SIP.', $e);
    }
  }

  /**
   * Diferença entre a posição da unidade na hierarquia do SIP e a do SIORG, ou vazio se coincidem.
   * Unidade do primeiro nível abaixo do órgão deve ser raiz; as demais, ficar abaixo da unidade cujo
   * id_origem é o da superior no SIORG.
   */
  private function compararPosicao(UnidadeDTO $objExistente, array $arrUnidade, array $arrPaiAtual, array $arrPorId, array $arrPorOrigem): string
  {
    $numIdUnidade = (int)$objExistente->getNumIdUnidade();
    if (!array_key_exists($numIdUnidade, $arrPaiAtual)) {
      return 'fora da hierarquia do SIP';
    }
    $numIdPaiAtual = $arrPaiAtual[$numIdUnidade];
    $strPaiAtual = $numIdPaiAtual === null ? 'raiz' : 'abaixo de ' . (isset($arrPorId[$numIdPaiAtual]) ? $arrPorId[$numIdPaiAtual]->getStrSigla() : 'unidade ' . $numIdPaiAtual);

    if ($arrUnidade['raiz']) {
      return $numIdPaiAtual === null ? '' : 'no SIP está ' . $strPaiAtual . '; no SIORG é raiz';
    }

    $objPaiEsperado = $arrPorOrigem[self::montarIdOrigem($arrUnidade['codigo_pai'])] ?? null;
    if ($objPaiEsperado !== null && $numIdPaiAtual === (int)$objPaiEsperado->getNumIdUnidade()) {
      return '';
    }
    return 'no SIP está ' . $strPaiAtual . '; no SIORG fica abaixo de ' . self::descreverUnidadeSiorg($arrUnidade['sigla_pai'], $arrUnidade['codigo_pai']) . ($objPaiEsperado !== null ? ', no SIP ' . $objPaiEsperado->getStrSigla() : ', ainda não importada');
  }

  /**
   * Cadastra uma unidade do SIORG no SIP e a posiciona na hierarquia do sistema SEI, na
   * transação desta chamada.
   *
   * @param array $arrParametros ['id_orgao', 'codigo', 'codigo_pai', 'sigla_pai' (no SIORG, para mensagens), 'raiz' (bool: logo abaixo do órgão),
   *                             'sigla' (a gravada: a do SIORG ou a composta ORGAO_SIGLA), 'nome']
   * @return int id da unidade criada
   */
  protected function importarUnidadeControlado(array $arrParametros): int
  {
    try {
      SessaoSip::getInstance()->validarAuditarPermissao('md_cel_siorg_unidade_cadastrar', __METHOD__, $arrParametros);

      $objInfraException = new InfraException();
      $numIdOrgao = (int)$arrParametros['id_orgao'];

      $strIdOrigem = self::montarIdOrigem((string)$arrParametros['codigo']);
      if ($this->consultarUnidade($numIdOrgao, 'IdOrigem', $strIdOrigem) !== null) {
        $objInfraException->lancarValidacao('Unidade SIORG ' . $arrParametros['codigo'] . ' já importada.');
      }

      // Unidade logo abaixo do órgão no SIORG entra como raiz da hierarquia (sem superior).
      $numIdUnidadePai = null;
      if (!$arrParametros['raiz']) {
        $objPaiDTO = $this->consultarUnidade($numIdOrgao, 'IdOrigem', self::montarIdOrigem((string)$arrParametros['codigo_pai']));
        if ($objPaiDTO === null) {
          $objInfraException->lancarValidacao('A unidade superior ' . self::descreverUnidadeSiorg((string)($arrParametros['sigla_pai'] ?? ''), (string)$arrParametros['codigo_pai']) . ' ainda não está no SIP. Importe-a antes.');
        }
        $numIdUnidadePai = (int)$objPaiDTO->getNumIdUnidade();
      }

      $objUnidadeDTO = new UnidadeDTO();
      $objUnidadeDTO->setNumIdUnidade(null);
      $objUnidadeDTO->setNumIdOrgao($numIdOrgao);
      $objUnidadeDTO->setStrIdOrigem($strIdOrigem);
      $objUnidadeDTO->setStrSigla((string)$arrParametros['sigla']);
      $objUnidadeDTO->setStrDescricao((string)$arrParametros['nome']);
      $objUnidadeDTO->setStrSinGlobal('N');
      $objUnidadeDTO->setStrSinAtivo('S');
      $objUnidadeRN = new UnidadeRN();
      $objUnidadeDTO = $objUnidadeRN->cadastrar($objUnidadeDTO);

      // Mesmos campos usados pela carga de hierarquia (MdCelSipRN) (IdHierarquiaPai e DataFim sempre setados).
      $numIdHierarquia = (int)$this->obterSistemaSei()->getNumIdHierarquia();
      $objRelDTO = new RelHierarquiaUnidadeDTO();
      $objRelDTO->setNumIdHierarquia($numIdHierarquia);
      $objRelDTO->setNumIdUnidade($objUnidadeDTO->getNumIdUnidade());
      $objRelDTO->setNumIdUnidadePai($numIdUnidadePai);
      $objRelDTO->setNumIdHierarquiaPai($numIdUnidadePai !== null ? $numIdHierarquia : null);
      $objRelDTO->setStrSinAtivo('S');
      $objRelDTO->setDtaDataInicio(date('d/m/Y'));
      $objRelDTO->setDtaDataFim('');
      $objRelHierarquiaUnidadeRN = new RelHierarquiaUnidadeRN();
      $objRelHierarquiaUnidadeRN->cadastrar($objRelDTO);

      return (int)$objUnidadeDTO->getNumIdUnidade();
    } catch (Exception $e) {
      throw new InfraException('Erro importando a unidade SIORG ' . ($arrParametros['codigo'] ?? '') . '.', $e);
    }
  }

  /**
   * Importa as unidades escolhidas, de cima para baixo, uma transação por unidade, numa só chamada.
   * A tela usa prepararImportacao e importarItem, em lotes com progresso; este método serve a scripts.
   *
   * @param array $arrParametros ['id_orgao', 'codigo_orgao', 'codigos' => string[]]
   * @return array[] cada item: ['codigo', 'sigla', 'nome', 'sucesso' => bool, 'mensagem']
   */
  public function importarSelecionadas(array $arrParametros): array
  {
    $arrRet = [];
    foreach ($this->prepararImportacao($arrParametros)['itens'] as $arrItem) {
      $arrRet[] = $this->importarItem((int)$arrParametros['id_orgao'], $arrItem);
    }
    return $arrRet;
  }

  /**
   * Compara a estrutura do SIORG com o SIP e devolve as unidades escolhidas, na ordem de importação
   * (de cima para baixo). A estrutura é lida de novo do SIORG, para não confiar em dados vindos do
   * navegador; a unidade escolhida que não está mais como nova segue na lista e é recusada em importarItem.
   *
   * @param array $arrParametros ['id_orgao', 'codigo_orgao', 'codigos' => string[]]
   * @return array ['orgao' => órgão no SIORG, 'hierarquia' => string, 'itens' => array[]], cada item com
   *               codigo, codigo_pai, sigla_pai, raiz, sigla (a gravada no SIP), sigla_siorg, nome, situacao, detalhe
   *               e atualizar (id_unidade e sigla nova, só na divergente de sigla ou nome que a tela pode atualizar)
   */
  public function prepararImportacao(array $arrParametros): array
  {
    $arrComparacao = $this->comparar(['id_orgao' => $arrParametros['id_orgao'], 'codigo_orgao' => $arrParametros['codigo_orgao']]);
    $arrEscolhidos = array_flip(array_map('strval', $arrParametros['codigos']));
    $arrItens = [];
    foreach ($arrComparacao['unidades'] as $arrUnidade) {
      if (isset($arrEscolhidos[$arrUnidade['codigo']])) {
        $arrItens[] = [
          'codigo' => $arrUnidade['codigo'],
          'codigo_pai' => $arrUnidade['codigo_pai'],
          'sigla_pai' => $arrUnidade['sigla_pai'],
          'raiz' => $arrUnidade['raiz'],
          'sigla' => $arrUnidade['sigla_sip'],
          'sigla_siorg' => $arrUnidade['sigla'],
          'nome' => $arrUnidade['nome'],
          'situacao' => $arrUnidade['situacao'],
          'detalhe' => $arrUnidade['detalhe'],
          'atualizar' => $arrUnidade['atualizar'] ?? null,
        ];
      }
    }
    if (count($arrItens) === 0) {
      $objInfraException = new InfraException();
      $objInfraException->lancarValidacao('Nenhuma das unidades marcadas está na estrutura atual do órgão no SIORG.');
    }
    return ['orgao' => $arrComparacao['orgao'], 'hierarquia' => $arrComparacao['hierarquia'], 'itens' => $arrItens];
  }

  /**
   * Importa uma unidade preparada por prepararImportacao, na transação dela. Falha da unidade vira
   * linha do relatório, sem interromper as demais (exceção de lote da regra 20 do padrão, T9).
   *
   * @return array ['codigo', 'sigla', 'nome', 'sucesso' => bool, 'mensagem']
   */
  public function importarItem(int $numIdOrgao, array $arrItem): array
  {
    $arrRet = ['codigo' => $arrItem['codigo'], 'sigla' => $arrItem['sigla'], 'nome' => $arrItem['nome'], 'sucesso' => false, 'mensagem' => ''];
    if ($arrItem['situacao'] !== self::SITUACAO_NOVA) {
      $arrRet['mensagem'] = 'não importada: ' . $arrItem['detalhe'];
      return $arrRet;
    }
    try {
      $numIdUnidade = $this->importarUnidade([
        'id_orgao' => $numIdOrgao,
        'codigo' => $arrItem['codigo'],
        'codigo_pai' => $arrItem['codigo_pai'],
        'sigla_pai' => $arrItem['sigla_pai'],
        'raiz' => $arrItem['raiz'],
        'sigla' => $arrItem['sigla'],
        'nome' => $arrItem['nome'],
      ]);
      $arrRet['sucesso'] = true;
      $arrRet['mensagem'] = 'importada (id ' . $numIdUnidade . ')' . ($arrItem['sigla'] !== $arrItem['sigla_siorg'] ? ', sigla ' . $arrItem['sigla_siorg'] . ' no SIORG' : '');
    } catch (Exception $e) {
      $arrRet['mensagem'] = self::mensagemErro($e);
    }
    return $arrRet;
  }

  /**
   * Estrutura resumida do órgão no SIORG, com textos em ISO-8859-1.
   *
   * @return array ['orgao' => nó do órgão, 'fora_do_orgao' => int, 'codigos_vinculadas' => string[] (nós de outro
   *               órgão ou entidade, fora da lista), 'unidades' => unidades do órgão, sem ele,
   *               em ordem de cima para baixo; as logo abaixo dele têm raiz = true e nivel 0]
   */
  private function lerEstrutura(string $strCodigoOrgao): array
  {
    $objInfraException = new InfraException();
    if (!ctype_digit($strCodigoOrgao)) {
      $objInfraException->lancarValidacao('Informe o código SIORG do órgão ou entidade (só números).');
    }

    $arrJson = $this->obterJson('estrutura-organizacional/resumida', ['codigoUnidade' => $strCodigoOrgao]);

    // O SIORG só devolve estrutura de órgão ou entidade; para outro código responde sem a lista e com a
    // mensagem do motivo (ex.: "Unidade organizacional deve ser um órgão ou entidade"). É erro de uso.
    if (!isset($arrJson['unidades']) || !is_array($arrJson['unidades'])) {
      $strMensagem = mb_convert_encoding((string)($arrJson['servico']['mensagem'] ?? 'resposta sem a lista de unidades'), 'ISO-8859-1', 'UTF-8');
      $objInfraException->lancarValidacao('O SIORG recusou o código ' . $strCodigoOrgao . ': ' . $strMensagem . '. Informe o código do órgão ou entidade: ele corresponde ao órgão do SIP e as unidades logo abaixo dele entram como raízes.');
    }

    $fnCodigo = function ($strUri) {
      if ($strUri === null || $strUri === '') {
        return '';
      }
      $arrPartes = explode('/', rtrim((string)$strUri, '/'));
      return (string)end($arrPartes);
    };
    $fnTexto = function ($str) {
      return trim(mb_convert_encoding((string)$str, 'ISO-8859-1', 'UTF-8'));
    };

    $arrPorCodigo = [];
    $arrFilhos = [];
    foreach ($arrJson['unidades'] as $arrItem) {
      $strCodigo = $fnCodigo($arrItem['codigoUnidade'] ?? '');
      if ($strCodigo === '') {
        continue;
      }
      $strCodigoPai = $fnCodigo($arrItem['codigoUnidadePai'] ?? '');
      $arrPorCodigo[$strCodigo] = [
        'codigo' => $strCodigo,
        'codigo_pai' => $strCodigoPai,
        'orgao_entidade' => $fnCodigo($arrItem['codigoOrgaoEntidade'] ?? ''),
        'tipo' => $fnCodigo($arrItem['codigoTipoUnidade'] ?? ''),
        'sigla' => $fnTexto($arrItem['sigla'] ?? ''),
        'nome' => $fnTexto($arrItem['nome'] ?? ''),
        'raiz' => false,
        'nivel' => 0,
      ];
      $arrFilhos[$strCodigoPai][] = $strCodigo;
    }

    if (!isset($arrPorCodigo[$strCodigoOrgao])) {
      throw new InfraException('SIORG: o código ' . $strCodigoOrgao . ' não veio na estrutura consultada.');
    }

    $arrOrgao = $arrPorCodigo[$strCodigoOrgao];
    if (!in_array($arrOrgao['tipo'], self::TIPOS_ORGAO, true)) {
      $objInfraException->lancarValidacao('O código SIORG ' . $strCodigoOrgao . ' é de uma unidade (' . $arrOrgao['sigla'] . ' - ' . $arrOrgao['nome'] . ', tipo ' . $arrOrgao['tipo'] . '), não de um órgão ou entidade. Informe o código do órgão: ele corresponde ao órgão do SIP e as unidades logo abaixo dele entram como raízes.');
    }

    // Percurso em profundidade abaixo do órgão: pai sempre antes dos filhos, irmãos por sigla. Nó de outro
    // órgão ou entidade (vinculada) fica de fora com todos os que estão abaixo dele.
    $fnOrdenar = function (array $arrCodigos) use ($arrPorCodigo) {
      usort($arrCodigos, function ($a, $b) use ($arrPorCodigo) {
        return strcmp($arrPorCodigo[$b]['sigla'], $arrPorCodigo[$a]['sigla']);
      });
      return $arrCodigos;
    };
    $fnColetar = function ($strCodigo) use (&$fnColetar, $arrFilhos) {
      $arrCodigos = [$strCodigo];
      foreach ($arrFilhos[$strCodigo] ?? [] as $strFilho) {
        $arrCodigos = array_merge($arrCodigos, $fnColetar($strFilho));
      }
      return $arrCodigos;
    };

    $arrRet = [];
    $arrCodigosVinculadas = [];
    $arrPilha = [];
    foreach ($fnOrdenar($arrFilhos[$strCodigoOrgao] ?? []) as $strFilho) {
      $arrPilha[] = [$strFilho, 0];
    }
    while (count($arrPilha) > 0) {
      [$strCodigo, $numNivel] = array_pop($arrPilha);
      $arrUnidade = $arrPorCodigo[$strCodigo];
      if ($arrUnidade['orgao_entidade'] !== '' && $arrUnidade['orgao_entidade'] !== $strCodigoOrgao) {
        $arrCodigosVinculadas = array_merge($arrCodigosVinculadas, $fnColetar($strCodigo));
        continue;
      }
      $arrUnidade['nivel'] = $numNivel;
      $arrUnidade['raiz'] = ($numNivel === 0);
      $arrUnidade['sigla_pai'] = $arrPorCodigo[$arrUnidade['codigo_pai']]['sigla'] ?? '';
      $arrRet[] = $arrUnidade;
      foreach ($fnOrdenar($arrFilhos[$strCodigo] ?? []) as $strFilho) {
        $arrPilha[] = [$strFilho, $numNivel + 1];
      }
    }

    return [
      'orgao' => ['codigo' => $arrOrgao['codigo'], 'sigla' => $arrOrgao['sigla'], 'nome' => $arrOrgao['nome'], 'tipo' => $arrOrgao['tipo']],
      'fora_do_orgao' => count($arrCodigosVinculadas),
      'codigos_vinculadas' => $arrCodigosVinculadas,
      'unidades' => $arrRet,
    ];
  }

  /**
   * GET na API pública do SIORG; devolve o JSON decodificado (UTF-8, como veio da API). Endereço, tempo
   * limite e proxy vêm dos parâmetros do módulo; a verificação do certificado TLS fica ligada.
   */
  private function obterJson(string $strCaminho, array $arrParametros = []): array
  {
    $strUrlBase = rtrim(self::obterParametro(self::URL), '/');
    $numTimeout = max(1, (int)self::obterParametro(self::TIMEOUT));
    $strProxy = trim(self::obterParametro(self::PROXY));
    $strUrl = $strUrlBase . '/' . ltrim($strCaminho, '/');
    if (count($arrParametros) > 0) {
      $strUrl .= '?' . http_build_query($arrParametros);
    }

    $objCurl = curl_init($strUrl);
    curl_setopt_array($objCurl, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => $numTimeout,
      CURLOPT_CONNECTTIMEOUT => min(10, $numTimeout),
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_HTTPHEADER => ['Accept: application/json'],
      CURLOPT_USERAGENT => 'carga-em-lote/0.2.0 (SIP)',
    ]);
    if ($strProxy !== '') {
      curl_setopt($objCurl, CURLOPT_PROXY, $strProxy);
    }

    $strCorpo = curl_exec($objCurl);
    $strErro = curl_error($objCurl);
    $numHttp = (int)curl_getinfo($objCurl, CURLINFO_HTTP_CODE);
    curl_close($objCurl);

    $strHost = (string)parse_url($strUrlBase, PHP_URL_HOST);
    if ($strCorpo === false) {
      throw new InfraException('Falha ao acessar ' . $strHost . ': ' . $strErro);
    }
    if ($numHttp !== 200) {
      throw new InfraException($strHost . ' respondeu HTTP ' . $numHttp . '.');
    }

    $arrJson = json_decode($strCorpo, true);
    if (!is_array($arrJson)) {
      throw new InfraException($strHost . ' devolveu uma resposta que não é JSON.');
    }
    return $arrJson;
  }

  private function obterSiglaOrgao(int $numIdOrgao): string
  {
    $objOrgaoDTO = new OrgaoDTO();
    $objOrgaoDTO->setBolExclusaoLogica(false);
    $objOrgaoDTO->retStrSigla();
    $objOrgaoDTO->setNumIdOrgao($numIdOrgao);
    $objOrgaoRN = new OrgaoRN();
    $objOrgaoDTO = $objOrgaoRN->consultar($objOrgaoDTO);
    if ($objOrgaoDTO === null) {
      throw new InfraException('Órgão ' . $numIdOrgao . ' não encontrado no SIP.');
    }
    return (string)$objOrgaoDTO->getStrSigla();
  }

  private function obterSistemaSei(): SistemaDTO
  {
    $objSistemaDTO = new SistemaDTO();
    $objSistemaDTO->retNumIdSistema();
    $objSistemaDTO->retStrSigla();
    $objSistemaDTO->retStrWebService();
    $objSistemaDTO->retNumIdHierarquia();
    $objSistemaDTO->retStrNomeHierarquia();
    $objSistemaDTO->setStrSigla('SEI');
    $objSistemaRN = new SistemaRN();
    $arrObjSistemaDTO = $objSistemaRN->listar($objSistemaDTO);
    if (count($arrObjSistemaDTO) === 0) {
      throw new InfraException('Sistema "SEI" não encontrado no cadastro de Sistemas do SIP.');
    }
    return $arrObjSistemaDTO[0];
  }

  /**
   * Atualiza sigla e nome de uma unidade já importada para os do SIORG, na transação desta chamada, pela
   * UnidadeRN do core, que valida e replica para o SEI (o SEI guarda o histórico de sigla e nome).
   *
   * @param array $arrParametros ['id_orgao', 'id_unidade', 'sigla', 'nome']
   */
  protected function atualizarUnidadeControlado(array $arrParametros): void
  {
    try {
      SessaoSip::getInstance()->validarAuditarPermissao('md_cel_siorg_unidade_alterar', __METHOD__, $arrParametros);

      $objUnidadeDTO = new UnidadeDTO();
      $objUnidadeDTO->setNumIdUnidade((int)$arrParametros['id_unidade']);
      $objUnidadeDTO->setNumIdOrgao((int)$arrParametros['id_orgao']);
      $objUnidadeDTO->setStrSigla((string)$arrParametros['sigla']);
      $objUnidadeDTO->setStrDescricao((string)$arrParametros['nome']);
      $objUnidadeRN = new UnidadeRN();
      $objUnidadeRN->alterar($objUnidadeDTO);
    } catch (Exception $e) {
      throw new InfraException('Erro atualizando a unidade ' . ($arrParametros['id_unidade'] ?? '') . '.', $e);
    }
  }

  /**
   * Atualiza uma unidade preparada por prepararImportacao. Falha vira linha do relatório, como em importarItem.
   *
   * @return array ['codigo', 'sigla', 'nome', 'sucesso' => bool, 'mensagem']
   */
  public function atualizarItem(int $numIdOrgao, array $arrItem): array
  {
    $arrRet = ['codigo' => $arrItem['codigo'], 'sigla' => $arrItem['atualizar']['sigla'] ?? $arrItem['sigla'], 'nome' => $arrItem['nome'], 'sucesso' => false, 'mensagem' => ''];
    if (empty($arrItem['atualizar'])) {
      $arrRet['mensagem'] = 'não atualizada: ' . $arrItem['detalhe'];
      return $arrRet;
    }
    try {
      $this->atualizarUnidade(['id_orgao' => $numIdOrgao, 'id_unidade' => $arrItem['atualizar']['id_unidade'], 'sigla' => $arrItem['atualizar']['sigla'], 'nome' => $arrItem['nome']]);
      $arrRet['sucesso'] = true;
      $arrRet['mensagem'] = 'atualizada; antes, ' . $arrItem['detalhe'];
    } catch (Exception $e) {
      $arrRet['mensagem'] = self::mensagemErro($e);
    }
    return $arrRet;
  }

  /**
   * Verifica, no SIP e no SEI, se as unidades sinalizadas para desativação podem ser desativadas. Só lê.
   * Unidade marcada que não está mais sinalizada (fora da estrutura) é ignorada.
   *
   * @param array $arrParametros ['id_orgao', 'codigo_orgao', 'ids_unidade' => int[]]
   * @return array ['orgao' => órgão no SIORG, 'hierarquia' => string, 'unidades' => array[]], unidades na ordem
   *               de desativação (de baixo para cima), cada uma com os campos de fora_da_estrutura mais
   *               pendencias (string[], impedem a desativação), avisos (string[]) e apta (bool)
   */
  protected function verificarDesativacaoConectado(array $arrParametros): array
  {
    try {
      SessaoSip::getInstance()->validarPermissao('md_cel_siorg_unidade_desativar');

      $arrComparacao = $this->comparar(['id_orgao' => $arrParametros['id_orgao'], 'codigo_orgao' => $arrParametros['codigo_orgao']]);
      $arrSinalizadas = [];
      foreach ($arrComparacao['fora_da_estrutura'] as $arrUnidade) {
        $arrSinalizadas[$arrUnidade['id_unidade']] = $arrUnidade;
      }
      $arrIdUnidade = [];
      foreach (array_unique(array_map('intval', $arrParametros['ids_unidade'])) as $numIdUnidade) {
        if (isset($arrSinalizadas[$numIdUnidade])) {
          $arrIdUnidade[] = $numIdUnidade;
        }
      }
      if (count($arrIdUnidade) === 0) {
        $objInfraException = new InfraException();
        $objInfraException->lancarValidacao('Nenhuma das unidades marcadas está sinalizada para desativação.');
      }

      $arrPendencias = $this->verificarPendencias($arrIdUnidade, $arrIdUnidade);
      $arrRet = [];
      foreach ($arrIdUnidade as $numIdUnidade) {
        $arrRet[] = array_merge($arrSinalizadas[$numIdUnidade], $arrPendencias[$numIdUnidade]);
      }
      // De baixo para cima: a subunidade antes da superior.
      usort($arrRet, function ($a, $b) {
        return $b['profundidade'] <=> $a['profundidade'] ?: strcmp($a['sigla'], $b['sigla']);
      });
      return ['orgao' => $arrComparacao['orgao'], 'hierarquia' => $arrComparacao['hierarquia'], 'unidades' => $arrRet];
    } catch (Exception $e) {
      throw new InfraException('Erro verificando as unidades para desativação.', $e);
    }
  }

  /**
   * Desativa uma unidade sinalizada, na transação desta chamada: posição na hierarquia do sistema SEI e
   * unidade, pelas RNs do core, que replicam para o SEI. As pendências são conferidas de novo antes, sem
   * aceitar subunidade ativa (as marcadas junto já foram desativadas antes, de baixo para cima).
   *
   * @param array $arrParametros ['id_orgao', 'id_unidade']
   */
  protected function desativarUnidadeControlado(array $arrParametros): void
  {
    try {
      SessaoSip::getInstance()->validarAuditarPermissao('md_cel_siorg_unidade_desativar', __METHOD__, $arrParametros);

      $objInfraException = new InfraException();
      $numIdUnidade = (int)$arrParametros['id_unidade'];

      $objUnidadeDTO = new UnidadeDTO();
      $objUnidadeDTO->retNumIdUnidade();
      $objUnidadeDTO->setNumIdUnidade($numIdUnidade);
      $objUnidadeDTO->setNumIdOrgao((int)$arrParametros['id_orgao']);
      $objUnidadeRN = new UnidadeRN();
      if ($objUnidadeRN->consultar($objUnidadeDTO) === null) {
        $objInfraException->lancarValidacao('Unidade não encontrada no órgão ou já desativada.');
      }

      $arrVerificacao = $this->verificarPendencias([$numIdUnidade], [])[$numIdUnidade];
      if (!$arrVerificacao['apta']) {
        $objInfraException->lancarValidacao('Pendências na verificação feita antes da desativação: ' . implode('; ', $arrVerificacao['pendencias']) . '.');
      }

      $objRelHierarquiaUnidadeDTO = new RelHierarquiaUnidadeDTO();
      $objRelHierarquiaUnidadeDTO->setNumIdHierarquia((int)$this->obterSistemaSei()->getNumIdHierarquia());
      $objRelHierarquiaUnidadeDTO->setNumIdUnidade($numIdUnidade);
      $objRelHierarquiaUnidadeRN = new RelHierarquiaUnidadeRN();
      $objRelHierarquiaUnidadeRN->desativar([$objRelHierarquiaUnidadeDTO]);

      $objUnidadeDesativarDTO = new UnidadeDTO();
      $objUnidadeDesativarDTO->setNumIdUnidade($numIdUnidade);
      $objUnidadeRN->desativar([$objUnidadeDesativarDTO]);
    } catch (Exception $e) {
      throw new InfraException('Erro desativando a unidade ' . ($arrParametros['id_unidade'] ?? '') . '.', $e);
    }
  }

  /**
   * Desativa uma unidade da lista aprovada pelo administrador. Falha da unidade vira linha do relatório,
   * com a mensagem do SIP ou do SEI, sem interromper as demais (exceção de lote da regra 20 do padrão, T9).
   *
   * @param array $arrItem ['id_unidade', 'codigo', 'sigla', 'nome', 'apta', 'pendencias'] (da verificação do passo 3)
   * @return array ['codigo', 'sigla', 'nome', 'sucesso' => bool, 'mensagem']
   */
  public function desativarItem(int $numIdOrgao, array $arrItem): array
  {
    $arrRet = ['codigo' => $arrItem['codigo'], 'sigla' => $arrItem['sigla'], 'nome' => $arrItem['nome'], 'sucesso' => false, 'mensagem' => ''];
    if (isset($arrItem['apta']) && !$arrItem['apta']) {
      $arrRet['mensagem'] = 'não desativada: ' . implode('; ', $arrItem['pendencias'] ?? []);
      return $arrRet;
    }
    try {
      $this->desativarUnidade(['id_orgao' => $numIdOrgao, 'id_unidade' => (int)$arrItem['id_unidade']]);
      $arrRet['sucesso'] = true;
      $arrRet['mensagem'] = 'desativada no SIP e no SEI (id ' . (int)$arrItem['id_unidade'] . ')';
    } catch (Exception $e) {
      $arrRet['mensagem'] = self::mensagemErro($e);
    }
    return $arrRet;
  }

  /**
   * Pendências que impedem a desativação: no SIP, permissões de usuários em qualquer sistema, coordenadores
   * de unidade, subunidades ativas (exceto as marcadas junto, desativadas antes) e posição ativa em outra
   * hierarquia; no SEI, processos abertos e blocos, pelo serviço md_cel_sip do módulo.
   *
   * @param int[] $arrIdUnidade
   * @param int[] $arrIdMarcadas unidades que serão desativadas no mesmo lote
   * @return array [id_unidade => ['pendencias' => string[], 'avisos' => string[], 'apta' => bool]]
   */
  private function verificarPendencias(array $arrIdUnidade, array $arrIdMarcadas): array
  {
    $numIdHierarquiaSei = (int)$this->obterSistemaSei()->getNumIdHierarquia();
    $arrMarcadas = array_flip($arrIdMarcadas);
    $arrSei = $this->consultarPendenciasSei($arrIdUnidade);
    $objPermissaoRN = new PermissaoRN();
    $objCoordenadorUnidadeRN = new CoordenadorUnidadeRN();
    $objRelHierarquiaUnidadeRN = new RelHierarquiaUnidadeRN();

    $arrRet = [];
    foreach ($arrIdUnidade as $numIdUnidade) {
      $arrPendencias = [];
      $arrAvisos = [];

      $objPermissaoDTO = new PermissaoDTO();
      $objPermissaoDTO->retNumIdUsuario();
      $objPermissaoDTO->retStrSiglaSistema();
      $objPermissaoDTO->setNumIdUnidade($numIdUnidade);
      $arrObjPermissaoDTO = $objPermissaoRN->listar($objPermissaoDTO);
      if (count($arrObjPermissaoDTO) > 0) {
        $arrUsuariosPorSistema = [];
        foreach ($arrObjPermissaoDTO as $objDTO) {
          $arrUsuariosPorSistema[$objDTO->getStrSiglaSistema()][$objDTO->getNumIdUsuario()] = true;
        }
        $arrTexto = [];
        foreach ($arrUsuariosPorSistema as $strSistema => $arrUsuarios) {
          $arrTexto[] = $strSistema . ' (' . count($arrUsuarios) . ' usuário(s))';
        }
        $arrPendencias[] = count($arrObjPermissaoDTO) . ' permissão(ões) de usuários no SIP: ' . implode(', ', $arrTexto);
      }

      $objCoordenadorUnidadeDTO = new CoordenadorUnidadeDTO();
      $objCoordenadorUnidadeDTO->retNumIdUsuario();
      $objCoordenadorUnidadeDTO->setNumIdUnidade($numIdUnidade);
      $numCoordenadores = $objCoordenadorUnidadeRN->contar($objCoordenadorUnidadeDTO);
      if ($numCoordenadores > 0) {
        $arrPendencias[] = $numCoordenadores . ' coordenador(es) de unidade no SIP';
      }

      $objRelDTO = new RelHierarquiaUnidadeDTO();
      $objRelDTO->retNumIdUnidade();
      $objRelDTO->retStrSiglaUnidade();
      $objRelDTO->retStrNomeHierarquia();
      $objRelDTO->setNumIdUnidadePai($numIdUnidade);
      foreach ($objRelHierarquiaUnidadeRN->listar($objRelDTO) as $objDTO) {
        if (isset($arrMarcadas[(int)$objDTO->getNumIdUnidade()])) {
          $arrAvisos[] = 'a subunidade ' . $objDTO->getStrSiglaUnidade() . ' também está marcada e é desativada antes';
        } else {
          $arrPendencias[] = 'subunidade ativa ' . $objDTO->getStrSiglaUnidade() . ' na hierarquia ' . $objDTO->getStrNomeHierarquia();
        }
      }

      $objRelDTO = new RelHierarquiaUnidadeDTO();
      $objRelDTO->retStrNomeHierarquia();
      $objRelDTO->setNumIdUnidade($numIdUnidade);
      $objRelDTO->setNumIdHierarquia($numIdHierarquiaSei, InfraDTO::$OPER_DIFERENTE);
      foreach ($objRelHierarquiaUnidadeRN->listar($objRelDTO) as $objDTO) {
        $arrPendencias[] = 'ativa também na hierarquia ' . $objDTO->getStrNomeHierarquia() . ', de outro sistema';
      }

      if (isset($arrSei['erro'])) {
        $arrPendencias[] = 'não foi possível consultar o SEI: ' . $arrSei['erro'];
      } elseif (!isset($arrSei[$numIdUnidade])) {
        $arrPendencias[] = 'o SEI não informou as pendências desta unidade';
      } else {
        $arrUnidadeSei = $arrSei[$numIdUnidade];
        if ((int)$arrUnidadeSei['processos_abertos'] > 0) {
          $arrPendencias[] = (int)$arrUnidadeSei['processos_abertos'] . ' processo(s) aberto(s) na unidade no SEI';
        }
        if ((int)$arrUnidadeSei['blocos_da_unidade'] > 0) {
          $arrPendencias[] = (int)$arrUnidadeSei['blocos_da_unidade'] . ' bloco(s) da unidade não concluído(s) no SEI';
        }
        if ((int)$arrUnidadeSei['blocos_recebidos'] > 0) {
          $arrPendencias[] = (int)$arrUnidadeSei['blocos_recebidos'] . ' bloco(s) de outras unidades disponibilizado(s) para ela no SEI';
        }
      }

      $arrRet[$numIdUnidade] = ['pendencias' => $arrPendencias, 'avisos' => $arrAvisos, 'apta' => count($arrPendencias) === 0];
    }
    return $arrRet;
  }

  /**
   * Processos e blocos das unidades no SEI, pelo serviço md_cel_sip do lado SEI do módulo. A chamada usa o
   * identificador de uso único da replicação do SIP (Replicacao::executar), que o SEI confere no SIP.
   *
   * @param int[] $arrIdUnidade
   * @return array [id_unidade => ['processos_abertos', 'blocos_da_unidade', 'blocos_recebidos']] ou ['erro' => string]
   */
  private function consultarPendenciasSei(array $arrIdUnidade): array
  {
    try {
      $objSistemaDTO = $this->obterSistemaSei();
      $strWebService = (string)$objSistemaDTO->getStrWebService();
      if ($strWebService === '') {
        throw new InfraException('O sistema SEI não tem web service cadastrado no SIP.');
      }
      // O serviço do módulo fica no mesmo controlador de web services do SEI, com outro nome de serviço.
      $strWsdl = preg_replace('/([?&]servico=)[^&]*/', '${1}md_cel_sip', $strWebService, 1, $numTrocas);
      if ($numTrocas === 0) {
        $strWsdl .= (strpos($strWsdl, '?') === false ? '?' : '&') . 'servico=md_cel_sip';
      }
      $objWS = new SoapClient($strWsdl, ['encoding' => 'ISO-8859-1', 'connection_timeout' => 15]);

      $objReplicacaoServicoDTO = new ReplicacaoServicoDTO();
      $objReplicacaoServicoDTO->setNumIdSistema($objSistemaDTO->getNumIdSistema());
      $objReplicacaoServicoDTO->setStrSiglaSistema($objSistemaDTO->getStrSigla());
      $objReplicacaoServicoDTO->setObjWebService($objWS);
      $objReplicacaoServicoDTO->setStrNomeOperacao('verificarPendenciasUnidades');
      $strJson = Replicacao::getInstance()->executar($objReplicacaoServicoDTO, implode(',', array_map('intval', $arrIdUnidade)));

      $arrRet = json_decode((string)$strJson, true);
      if (!is_array($arrRet)) {
        throw new InfraException('Resposta inválida do serviço md_cel_sip do SEI.');
      }
      return $arrRet;
    } catch (Throwable $e) {
      LogSip::getInstance()->gravar(InfraException::inspecionar($e));
      $arrMensagens = [];
      for ($objErro = $e; $objErro !== null; $objErro = $objErro->getPrevious()) {
        $strMensagem = trim($objErro instanceof InfraException ? (string)$objErro->getStrDescricao() : $objErro->getMessage());
        if ($strMensagem !== '' && !in_array($strMensagem, $arrMensagens, true)) {
          $arrMensagens[] = $strMensagem;
        }
      }
      return ['erro' => implode(' ', $arrMensagens)];
    }
  }

  /** Níveis acima da unidade na hierarquia do sistema SEI (raiz = 0). */
  private function calcularProfundidade(int $numIdUnidade, array $arrPaiAtual): int
  {
    $numProfundidade = 0;
    $arrVistos = [];
    while (isset($arrPaiAtual[$numIdUnidade]) && !isset($arrVistos[$numIdUnidade])) {
      $arrVistos[$numIdUnidade] = true;
      $numIdUnidade = (int)$arrPaiAtual[$numIdUnidade];
      $numProfundidade++;
    }
    return $numProfundidade;
  }

  private function consultarUnidade(int $numIdOrgao, string $strCampo, string $strValor): ?UnidadeDTO
  {
    if ($strValor === '') {
      return null;
    }
    $objUnidadeDTO = new UnidadeDTO();
    $objUnidadeDTO->setBolExclusaoLogica(false);
    $objUnidadeDTO->retNumIdUnidade();
    $objUnidadeDTO->setNumIdOrgao($numIdOrgao);
    $objUnidadeDTO->set($strCampo, $strValor);
    $objUnidadeRN = new UnidadeRN();
    $arrObjUnidadeDTO = $objUnidadeRN->listar($objUnidadeDTO);
    return count($arrObjUnidadeDTO) > 0 ? $arrObjUnidadeDTO[0] : null;
  }

  /**
   * Mensagem curta para o relatório: as validações (a InfraException copia as da exceção interna)
   * ou, em erro inesperado, a descrição da exceção mais interna. O detalhe completo vai para o log.
   */
  private static function mensagemErro(Exception $e): string
  {
    if ($e instanceof InfraException && $e->contemValidacoes()) {
      $arrMensagens = [];
      foreach ($e->getArrObjInfraValidacao() as $objValidacao) {
        $arrMensagens[] = $objValidacao->getStrDescricao();
      }
      return implode(' ', $arrMensagens);
    }
    LogSip::getInstance()->gravar(InfraException::inspecionar($e));
    // InfraException encapsulada mantém a descrição da original.
    return 'erro inesperado: ' . ($e instanceof InfraException ? $e->getStrDescricao() : $e->getMessage());
  }
}
