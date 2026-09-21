<?
/**
 * CargaEmLoteRN
 *
 * Orquestra a leitura de arquivos .csv (mesmo layout dos exemplos de
 * github.com/pengovbr/macros-sei-sip) e chama diretamente as classes de regra de negocio
 * ja existentes no SIP (UnidadeRN, RelHierarquiaUnidadeRN, UsuarioRN, PermissaoRN) para
 * cadastrar unidades, hierarquia, usuarios e primeiras permissoes em lote.
 *
 * Segue o padrao InfraRN (metodo *Controlado = uma transacao). Registros ja existentes sao
 * pulados (nunca sobrescritos), verificados com o mesmo padrao de pre-checagem
 * (contar/consultar) que as proprias *RN ja usam internamente. O resultado de cada linha
 * (OK, pulado ou erro) volta no relatorio da tela.
 *
 * Transacao por linha: as operacoes publicas (processarX) NAO abrem transacao. Cada uma percorre
 * as linhas do lote e chama processarXLinha(), que o InfraRN::__call() despacha para
 * processarXLinhaControlado(), dentro de uma transacao propria. O try/catch fica por fora
 * dela: a linha com erro faz rollback so de si mesma, entra no relatorio como "erro" e nao
 * interrompe as demais.
 *
 * Por que nao uma transacao para o lote inteiro: o InfraRN::__call() so abre transacao se o
 * banco ainda nao esta em uma, e so quem abriu confirma ou cancela. Com o lote inteiro numa
 * transacao, as RN do core chamadas por dentro entram nela, e o catch por linha engole o erro
 * sem rollback. A linha que falha depois de gravar algo deixa esse resultado parcial no banco
 * (reproduzido no laboratorio em 2026-09-21, no modulo SEI: e-mail invalido na carga de
 * Dados Complementares de Unidade deixava o endereco gravado e a linha marcada como "erro").
 */
class CargaEmLoteRN extends InfraRN {

  const STA_OK = 'OK';
  const STA_PULADO = 'PULADO';
  const STA_ERRO = 'ERRO';

  // Tamanho de lote para processamento particionado (varias requisicoes HTTP curtas em vez
  // de uma unica requisicao longa) - existe porque o timeout que interrompe uma carga grande
  // NAO e o do PHP (max_execution_time=0 neste laboratorio) e sim o do servidor web/proxy na
  // frente dele, que o modulo nao controla (e so codigo acrescentado a uma instalacao SEI/SIP
  // ja existente - cf. instrucoes.txt). Calibrado empiricamente neste laboratorio: ~2,1s por
  // usuario (cadastro + primeira permissao juntos) - 50 usuarios ficam em ~105s, com folga
  // confortavel sob o teto de 300s configurado aqui. Ajuste este valor pra baixo se a
  // instalacao real tiver um timeout mais agressivo na frente do PHP (proxy reverso,
  // balanceador, etc. - o modulo nao tem como detectar isso sozinho).
  const TAMANHO_LOTE = 50;

  protected function inicializarObjInfraIBanco(): InfraIBanco {
    return BancoSip::getInstance();
  }

  // ---------------------------------------------------------------------
  // Resolucao de referencias (sigla/nome do .csv -> id interno)
  //
  // Guia de leitura: todo dado do csv chega como texto (sigla de orgao, nome de perfil etc.)
  // e precisa virar o Id interno que as *RN nativas esperam. Cada resolverX() abaixo segue o
  // mesmo padrao - monta um DTO de filtro e chama listar() - mas dois comportamentos
  // diferentes em caso de "nao encontrado": resolverOrgao()/resolverSistemaSei()/
  // resolverPerfil() LANCAM excecao (sao referencias que precisam existir de antemao, nao
  // criadas por este modulo); resolverUnidade()/resolverUsuario() devolvem null (quem chama
  // decide se isso e erro ou motivo pra pular a linha - por exemplo, "unidade nao encontrada"
  // vira erro na carga de hierarquia, mas so retorna null pra virar um novo cadastro na
  // carga de unidades).
  // ---------------------------------------------------------------------

  /**
   * Retorna o SistemaDTO do SEI (sigla 'SEI') cadastrado no SIP, de onde vem o
   * IdSistema (usado em PermissaoDTO) e o IdHierarquia (usado em RelHierarquiaUnidadeDTO) -
   * nunca fixo, sempre resolvido em tempo de execucao (pode variar entre instalacoes).
   */
  private function resolverSistemaSei(): SistemaDTO {
    $dto = new SistemaDTO();
    $dto->setStrSigla('SEI');
    $dto->retTodos();
    $objSistemaRN = new SistemaRN();
    $arrRet = $objSistemaRN->listar($dto);
    if (count($arrRet) === 0) {
      throw new InfraException('Sistema "SEI" não encontrado no cadastro de Sistemas do SIP.');
    }
    return $arrRet[0];
  }

  private function resolverOrgao(string $strSigla): OrgaoDTO {
    $dto = new OrgaoDTO();
    $dto->setStrSigla(trim($strSigla));
    $dto->retTodos();
    $objOrgaoRN = new OrgaoRN();
    $arrRet = $objOrgaoRN->listar($dto);
    if (count($arrRet) === 0) {
      throw new InfraException('Órgão "' . $strSigla . '" não encontrado.');
    }
    return $arrRet[0];
  }

  private function resolverUnidade(int $numIdOrgao, string $strSigla): ?UnidadeDTO {
    $dto = new UnidadeDTO();
    $dto->setNumIdOrgao($numIdOrgao);
    $dto->setStrSigla(trim($strSigla));
    $dto->retTodos();
    $objUnidadeRN = new UnidadeRN();
    $arrRet = $objUnidadeRN->listar($dto);
    return count($arrRet) > 0 ? $arrRet[0] : null;
  }

  private function resolverUsuario(int $numIdOrgao, string $strSigla): ?UsuarioDTO {
    $dto = new UsuarioDTO();
    $dto->setNumIdOrgao($numIdOrgao);
    $dto->setStrSigla(trim($strSigla));
    $dto->retTodos();
    $objUsuarioRN = new UsuarioRN();
    $arrRet = $objUsuarioRN->listar($dto);
    return count($arrRet) > 0 ? $arrRet[0] : null;
  }

  private function resolverPerfil(int $numIdSistema, string $strNome): PerfilDTO {
    $dto = new PerfilDTO();
    $dto->setNumIdSistema($numIdSistema);
    $dto->setStrNome(trim($strNome));
    $dto->retTodos();
    $objPerfilRN = new PerfilRN();
    $arrRet = $objPerfilRN->listar($dto);
    if (count($arrRet) === 0) {
      throw new InfraException('Perfil "' . $strNome . '" não encontrado para o sistema informado.');
    }
    return $arrRet[0];
  }

  // ---------------------------------------------------------------------
  // Leitura de CSV
  // ---------------------------------------------------------------------

  /**
   * Le um .csv/.xlsx/.ods (cabecalho na primeira linha, que e descartado) e converte cada
   * campo de UTF-8 (formato recomendado pelo README do macros-sei-sip, gerado pelo Planilhas
   * Google, ou nativo de .xlsx/.ods) para ISO-8859-1 (codificacao nativa do SEI/SIP),
   * normalizando pra forma composta (NFC) antes de converter - evita problema de comparacao
   * exata por acentuacao em forma decomposta. Validado com nomes acentuados reais (ex.:
   * "Leocadio Macambira", "Ursula Trigueirinho") em cargas de centenas de linhas.
   */
  // Despacha pela extensao do arquivo temporario (preservada no upload - ver
  // carga_em_lote_form.php, processarUpload() com bolArquivoTemporarioIdentificado=true) -
  // csv/xlsx/ods convergem para o mesmo formato de retorno (array de
  // array('linha'=>N,'campos'=>[...])), entao nenhum processarXxx() precisou mudar.
  private function lerCsv(string $strCaminhoArquivo): array {
    $strExtensao = strtolower(pathinfo($strCaminhoArquivo, PATHINFO_EXTENSION));
    switch ($strExtensao) {
      case 'xlsx':
        return $this->lerPlanilha($strCaminhoArquivo, 'Excel2007');
      case 'ods':
        return $this->lerPlanilha($strCaminhoArquivo, 'OOCalc');
      case 'csv':
      case '':
        return $this->lerCsvPuro($strCaminhoArquivo);
      default:
        throw new InfraException('Formato de arquivo ".' . $strExtensao . '" não suportado (use .csv, .xlsx ou .ods).');
    }
  }

  private function lerCsvPuro(string $strCaminhoArquivo): array {
    $arrLinhas = array();
    $resArquivo = fopen($strCaminhoArquivo, 'r');
    if ($resArquivo === false) {
      throw new InfraException('Não foi possível abrir o arquivo "' . $strCaminhoArquivo . '".');
    }
    // Cabecalho e a "linha 0" (nao entra no relatorio); a primeira linha de dado e a linha 1.
    $numLinha = -1;
    while (($arrCampos = fgetcsv($resArquivo, 0, ',')) !== false) {
      $numLinha++;
      if ($numLinha === 0) {
        continue; // cabecalho
      }
      $arrCampos = array_map(function ($strValor) {
        $strValor = trim((string)$strValor);
        // Normaliza para forma composta (NFC) antes de converter para ISO-8859-1: a mesma letra
        // acentuada pode chegar em bytes diferentes (forma composta vs decomposta) dependendo de
        // onde o .csv foi gerado, o que quebraria comparacao exata (ex.: nome de perfil). So
        // normaliza se a extensao intl estiver disponivel - senao, segue sem normalizar.
        if (class_exists('Normalizer')) {
          $strValor = Normalizer::normalize($strValor, Normalizer::FORM_C);
        }
        return mb_convert_encoding($strValor, 'ISO-8859-1', 'UTF-8');
      }, $arrCampos);
      $arrLinhas[] = array('linha' => $numLinha, 'campos' => $arrCampos);
    }
    fclose($resArquivo);
    return $arrLinhas;
  }

  // PHPExcel ja vem vendorizado e autoloaded pelo proprio framework
  // (infra/infra_php/Infra.php:181-186, infraAutoLoad() cai pra PHPExcel_Autoloader::Load()
  // quando a classe nao e encontrada nos caminhos nativos) - nenhum require_once necessario,
  // mesma forma que as classes RN/DTO nativas sao usadas neste arquivo.
  private function lerPlanilha(string $strCaminhoArquivo, string $strTipoLeitor): array {
    $arrLinhas = array();
    $objReader = PHPExcel_IOFactory::createReader($strTipoLeitor);
    $objReader->setReadDataOnly(true);
    $objPHPExcel = $objReader->load($strCaminhoArquivo);
    $arrLinhasPlanilha = $objPHPExcel->getActiveSheet()->toArray(null, true, true, false);
    // Mesmo tratamento (trim + NFC + conversao pra ISO-8859-1) do caminho csv - PHPExcel
    // devolve strings PHP nativas em UTF-8 (xlsx/ods armazenam texto em XML UTF-8), entao o
    // restante do pipeline (que espera ISO-8859-1, mesma convencao do resto do codigo-fonte
    // do SEI/SIP) funciona sem diferenciar a origem do dado.
    $numLinha = -1;
    foreach ($arrLinhasPlanilha as $arrCampos) {
      $numLinha++;
      if ($numLinha === 0) {
        continue; // cabecalho
      }
      $arrCampos = array_map(function ($strValor) {
        $strValor = trim((string)$strValor);
        if (class_exists('Normalizer')) {
          $strValor = Normalizer::normalize($strValor, Normalizer::FORM_C);
        }
        return mb_convert_encoding($strValor, 'ISO-8859-1', 'UTF-8');
      }, $arrCampos);
      $arrLinhas[] = array('linha' => $numLinha, 'campos' => $arrCampos);
    }
    return $arrLinhas;
  }

  // ---------------------------------------------------------------------
  // 1. cargaUnidades
  // Colunas do csv (README): 0-Seq,1-orgaoUnidade,2-siglaUnidade,3-descricaoUnidade,...
  // ---------------------------------------------------------------------

  /**
   * Cadastra unidades (operacao de CRIACAO): uma linha por unidade, pula (STA_PULADO) se a
   * sigla ja existir no orgao, cadastra (STA_OK) caso contrario. Nao mexe em hierarquia -
   * isso e responsabilidade de processarHierarquia() logo abaixo.
   *
   * Recebe as linhas ja lidas (e, quando chamado em lote, ja fatiadas) - quem le o
   * arquivo e fatia por offset/limite e o metodo combinado que chama este (mesmo arquivo e
   * relido uma vez por lote, mas isso e barato - o que demora e a gravacao no banco, nao a
   * leitura do csv/xlsx/ods).
   */
  public function processarUnidades(array $arrLinhas): array {
    $arrResultado = [];
    foreach ($arrLinhas as $arrLinha) {
      try {
        $arrResultado[] = $this->processarUnidadesLinha(['linha' => $arrLinha['linha'], 'campos' => $arrLinha['campos']]);
      } catch (Exception $e) {
        $arrResultado[] = $this->linhaResultado($arrLinha['linha'], self::STA_ERRO, $this->obterMensagemErro($e));
      }
    }
    return $arrResultado;
  }

  /** Uma linha de processarUnidades(), em transacao propria (ver docblock da classe). */
  protected function processarUnidadesLinhaControlado(array $arrParametros): array {
    $numLinha = $arrParametros['linha'];
    $c = $arrParametros['campos'];
    $strSiglaOrgao = $c[1] ?? '';
    $strSigla = $c[2] ?? '';
    $strDescricao = $c[3] ?? '';

    if ($strSiglaOrgao === '' || $strSigla === '' || $strDescricao === '') {
      throw new InfraException('Linha incompleta (órgão/sigla/descrição obrigatórios).');
    }

    $objOrgaoDTO = $this->resolverOrgao($strSiglaOrgao);

    if ($this->resolverUnidade($objOrgaoDTO->getNumIdOrgao(), $strSigla) !== null) {
      return $this->linhaResultado($numLinha, self::STA_PULADO, 'Unidade "' . $strSigla . '" já existe.');
    }

    $objUnidadeDTO = new UnidadeDTO();
    $objUnidadeDTO->setNumIdOrgao($objOrgaoDTO->getNumIdOrgao());
    $objUnidadeDTO->setStrIdOrigem('');
    $objUnidadeDTO->setStrSigla($strSigla);
    $objUnidadeDTO->setStrDescricao($strDescricao);
    $objUnidadeDTO->setStrSinGlobal('N');
    $objUnidadeDTO->setStrSinAtivo('S');

    $objUnidadeRN = new UnidadeRN();
    $objUnidadeRN->cadastrar($objUnidadeDTO);

    return $this->linhaResultado($numLinha, self::STA_OK, 'Unidade "' . $strSigla . '" cadastrada.');
  }

  /**
   * Atalho pedido pelo usuario depois de testar as duas operacoes separadas: como e o mesmo
   * .csv, cadastra as unidades e ja posiciona na hierarquia numa unica passada, sem precisar
   * de dois uploads.
   *
   * Ponto de entrada publico (chamado pela tela; um unico parametro, empacotado num array,
   * como nos demais): 'csv' (obrigatorio), 'offset'/'limite' (opcionais -
   * processamento particionado em lotes, usado pela tela pra nao estourar o timeout do
   * servidor web numa carga grande; omitidos = processa o arquivo inteiro de uma vez, mesmo
   * comportamento de antes). Retorna 'resultado' (relatorio linha a linha do lote),
   * 'total' (linhas de dado no arquivo inteiro) e 'processadas' (quantas linhas este lote
   * cobriu) - a tela soma 'processadas' ao offset pra saber onde continuar no proximo
   * recarregamento automatico.
   */
  public function processarUnidadesEHierarquia(array $arrParametros): array {
    $strCaminhoArquivo = $arrParametros['csv'];
    $numOffset = $arrParametros['offset'] ?? 0;
    $numLimite = $arrParametros['limite'] ?? null;

    $arrTodasLinhas = $this->lerCsv($strCaminhoArquivo);
    $numTotal = count($arrTodasLinhas);
    $arrLote = ($numLimite === null) ? array_slice($arrTodasLinhas, $numOffset) : array_slice($arrTodasLinhas, $numOffset, $numLimite);

    $arrResultadoUnidades = $this->processarUnidades($arrLote);
    $arrResultadoHierarquia = $this->processarHierarquia($arrLote);
    $arrResultado = array_merge($arrResultadoUnidades, $arrResultadoHierarquia);

    return array(
      'resultado' => $arrResultado,
      'total' => $numTotal,
      'processadas' => count($arrLote),
      'resumoPorOperacao' => array(
        array('rotulo' => 'unidade(s)', 'tally' => $this->tally($arrResultadoUnidades)),
        array('rotulo' => 'posição(ões) na hierarquia', 'tally' => $this->tally($arrResultadoHierarquia)),
      ),
    );
  }

  // ---------------------------------------------------------------------
  // 2. hierarquia
  // Usa as mesmas colunas 1-orgaoUnidade,2-siglaUnidade,4-superiorNaHierarquia do csv de
  // unidades. O CSV precisa estar ordenado de cima para baixo (unidades "raiz" primeiro) -
  // mesma limitacao documentada no README do repositorio original; este metodo NAO reordena
  // as linhas, so processa na ordem em que aparecem no arquivo.
  // ---------------------------------------------------------------------

  /**
   * Posiciona unidades ja existentes na hierarquia "SEI" do SIP (operacao de CRIACAO do
   * vinculo, nao da unidade em si - ver processarUnidades() acima). Pula
   * (STA_PULADO) se o vinculo ja existir, cadastra (STA_OK) caso contrario. Da erro se a
   * unidade informada na coluna "superior" ainda nao estiver na hierarquia - por isso o csv
   * precisa vir ordenado de cima para baixo (raizes primeiro).
   */
  public function processarHierarquia(array $arrLinhas): array {
    $arrResultado = [];
    $objSistemaSeiDTO = $this->resolverSistemaSei();

    foreach ($arrLinhas as $arrLinha) {
      try {
        $arrResultado[] = $this->processarHierarquiaLinha(['linha' => $arrLinha['linha'], 'campos' => $arrLinha['campos'], 'sistema' => $objSistemaSeiDTO]);
      } catch (Exception $e) {
        $arrResultado[] = $this->linhaResultado($arrLinha['linha'], self::STA_ERRO, $this->obterMensagemErro($e));
      }
    }
    return $arrResultado;
  }

  /** Uma linha de processarHierarquia(), em transacao propria (ver docblock da classe). */
  protected function processarHierarquiaLinhaControlado(array $arrParametros): array {
    $numLinha = $arrParametros['linha'];
    $c = $arrParametros['campos'];
    $objSistemaSeiDTO = $arrParametros['sistema'];
    $numIdHierarquia = $objSistemaSeiDTO->getNumIdHierarquia();
    $strSiglaOrgao = $c[1] ?? '';
    $strSigla = $c[2] ?? '';
    $strSuperior = $c[4] ?? '';

    if ($strSiglaOrgao === '' || $strSigla === '') {
      throw new InfraException('Linha incompleta (órgão/sigla obrigatórios).');
    }

    $objOrgaoDTO = $this->resolverOrgao($strSiglaOrgao);
    $objUnidadeDTO = $this->resolverUnidade($objOrgaoDTO->getNumIdOrgao(), $strSigla);
    if ($objUnidadeDTO === null) {
      throw new InfraException('Unidade "' . $strSigla . '" não encontrada (rode a carga de unidades antes).');
    }

    $numIdUnidadePai = null;
    if ($strSuperior !== '') {
      $objUnidadePaiDTO = $this->resolverUnidade($objOrgaoDTO->getNumIdOrgao(), $strSuperior);
      if ($objUnidadePaiDTO === null) {
        throw new InfraException('Unidade superior "' . $strSuperior . '" ainda não está na hierarquia (processe as linhas de cima para baixo).');
      }
      $numIdUnidadePai = $objUnidadePaiDTO->getNumIdUnidade();
    }

    $dtoConsulta = new RelHierarquiaUnidadeDTO();
    $dtoConsulta->setNumIdHierarquia($numIdHierarquia);
    $dtoConsulta->setNumIdUnidade($objUnidadeDTO->getNumIdUnidade());
    $dtoConsulta->setBolExclusaoLogica(false);
    $dtoConsulta->retTodos();
    $objRelRN = new RelHierarquiaUnidadeRN();
    if ($objRelRN->consultar($dtoConsulta) !== null) {
      return $this->linhaResultado($numLinha, self::STA_PULADO, 'Unidade "' . $strSigla . '" já consta na hierarquia.');
    }

    $objRelDTO = new RelHierarquiaUnidadeDTO();
    $objRelDTO->setNumIdHierarquia($numIdHierarquia);
    $objRelDTO->setNumIdUnidade($objUnidadeDTO->getNumIdUnidade());
    $objRelDTO->setNumIdUnidadePai($numIdUnidadePai);
    // IdHierarquiaPai e um campo separado de IdHierarquia (visto em
    // rel_hierarquia_unidade_cadastro.php) - mesma hierarquia quando ha pai, null se raiz.
    $objRelDTO->setNumIdHierarquiaPai($numIdUnidadePai !== null ? $numIdHierarquia : null);
    $objRelDTO->setStrSinAtivo('S');
    $objRelDTO->setDtaDataInicio(date('d/m/Y'));
    $objRelDTO->setDtaDataFim(''); // sempre setado (mesmo vazio), ver nota da Acao Usuarios

    $objRelRN->cadastrar($objRelDTO);

    return $this->linhaResultado($numLinha, self::STA_OK, 'Unidade "' . $strSigla . '" posicionada na hierarquia.');
  }

  // ---------------------------------------------------------------------
  // 3. cargaUsuarios
  // Colunas (README): 0-Index,1-Orgao,2-Sigla,3-Nome,4-NomeSocial,5-CPF,6-E-mail,
  //                    7-unidadePrimeiraPermissao,8-perfilPrimeiraPermissao
  // Cadastra so o usuario (sem a permissao - ver processarPermissoes).
  // ---------------------------------------------------------------------

  /**
   * Cadastra usuarios (operacao de CRIACAO): uma linha por usuario, pula (STA_PULADO) se a
   * sigla ja existir no orgao, cadastra (STA_OK) caso contrario. So cadastra o usuario -
   * a primeira permissao (necessaria pra ele conseguir acessar o SEI) e concedida
   * separadamente por processarPermissoes() logo abaixo.
   */
  public function processarUsuarios(array $arrLinhas): array {
    $arrResultado = [];
    foreach ($arrLinhas as $arrLinha) {
      try {
        $arrResultado[] = $this->processarUsuariosLinha(['linha' => $arrLinha['linha'], 'campos' => $arrLinha['campos']]);
      } catch (Exception $e) {
        $arrResultado[] = $this->linhaResultado($arrLinha['linha'], self::STA_ERRO, $this->obterMensagemErro($e));
      }
    }
    return $arrResultado;
  }

  /** Uma linha de processarUsuarios(), em transacao propria (ver docblock da classe). */
  protected function processarUsuariosLinhaControlado(array $arrParametros): array {
    $numLinha = $arrParametros['linha'];
    $c = $arrParametros['campos'];
    $strSiglaOrgao = $c[1] ?? '';
    $strSigla = $c[2] ?? '';
    $strNome = $c[3] ?? '';
    $strNomeSocial = $c[4] ?? '';
    $strCpf = $c[5] ?? '';
    $strEmail = $c[6] ?? '';

    if ($strSiglaOrgao === '' || $strSigla === '' || $strNome === '') {
      throw new InfraException('Linha incompleta (órgão/sigla/nome obrigatórios).');
    }

    $objOrgaoDTO = $this->resolverOrgao($strSiglaOrgao);

    if ($this->resolverUsuario($objOrgaoDTO->getNumIdOrgao(), $strSigla) !== null) {
      return $this->linhaResultado($numLinha, self::STA_PULADO, 'Usuário "' . $strSigla . '" já existe.');
    }

    // "Atributo [X] nao recebeu valor" (visto na carga de unidades e de novo aqui com
    // IdUsuario) acontece quando algum codigo interno chama um getter de um atributo que
    // nunca foi setado (InfraDTO.php:1375) - nao e regra generica de cadastrar(), e
    // especifico de cada *RN/*BD internamente. Seguindo o mesmo padrao de
    // usuario_cadastro.php, que sempre seta todos os campos, inclusive a PK como null.
    $objUsuarioDTO = new UsuarioDTO();
    $objUsuarioDTO->setNumIdUsuario(null);
    $objUsuarioDTO->setNumIdOrgao($objOrgaoDTO->getNumIdOrgao());
    $objUsuarioDTO->setStrIdOrigem('');
    $objUsuarioDTO->setStrSigla($strSigla);
    $objUsuarioDTO->setStrNome($strNome);
    $objUsuarioDTO->setStrNomeSocial($strNomeSocial);
    $objUsuarioDTO->setDblCpf($strCpf !== '' ? InfraUtil::formatarCpf($strCpf) : '');
    $objUsuarioDTO->setStrEmail($strEmail);
    $objUsuarioDTO->setStrSinAtivo('S');

    $objUsuarioRN = new UsuarioRN();
    $objUsuarioRN->cadastrar($objUsuarioDTO);

    return $this->linhaResultado($numLinha, self::STA_OK, 'Usuário "' . $strSigla . '" cadastrado.');
  }

  /**
   * Atalho pedido pelo usuario depois de testar as duas operacoes separadas (mesmo padrao
   * ja usado para unidades+hierarquia): e o mesmo .csv (colunas
   * unidadePrimeiraPermissao/perfilPrimeiraPermissao), entao cadastra o usuario e ja concede
   * a primeira permissao numa unica passada, sem precisar de dois uploads.
   *
   * Mesmo contrato de parametros/retorno de processarUnidadesEHierarquia() (ver
   * comentario la) - processamento particionado em lotes via 'offset'/'limite'.
   */
  public function processarUsuariosEPermissoes(array $arrParametros): array {
    $strCaminhoArquivo = $arrParametros['csv'];
    $numOffset = $arrParametros['offset'] ?? 0;
    $numLimite = $arrParametros['limite'] ?? null;

    $arrTodasLinhas = $this->lerCsv($strCaminhoArquivo);
    $numTotal = count($arrTodasLinhas);
    $arrLote = ($numLimite === null) ? array_slice($arrTodasLinhas, $numOffset) : array_slice($arrTodasLinhas, $numOffset, $numLimite);

    $arrResultadoUsuarios = $this->processarUsuarios($arrLote);
    $arrResultadoPermissoes = $this->processarPermissoes($arrLote);
    $arrResultado = array_merge($arrResultadoUsuarios, $arrResultadoPermissoes);

    return array(
      'resultado' => $arrResultado,
      'total' => $numTotal,
      'processadas' => count($arrLote),
      'resumoPorOperacao' => array(
        array('rotulo' => 'usuário(s)', 'tally' => $this->tally($arrResultadoUsuarios)),
        array('rotulo' => 'permissão(ões)', 'tally' => $this->tally($arrResultadoPermissoes)),
      ),
    );
  }

  // ---------------------------------------------------------------------
  // 4. primeirasPermissoes
  // Usa as colunas 1-Orgao,2-Sigla,7-unidadePrimeiraPermissao,8-perfilPrimeiraPermissao do
  // mesmo csv de usuarios.
  //
  // Tipo de permissao: fixo em 1 (Nao Delegavel) - confirmado com o usuario que esse campo nao
  // tem uso relevante para o SEI (e usado pelo TRF4 em outros sistemas integrados), e as 11
  // permissoes ja existentes no SEI deste laboratorio usam esse mesmo valor.
  // ---------------------------------------------------------------------

  const ID_TIPO_PERMISSAO_PADRAO = 1;

  /**
   * Concede a um usuario ja existente um perfil numa unidade (operacao de CRIACAO da
   * permissao, nao do usuario - ver processarUsuarios() acima). Pula
   * (STA_PULADO) se o usuario ja possuir aquele perfil naquela unidade, cadastra (STA_OK)
   * caso contrario. Tipo de permissao sempre "Nao Delegavel" (ver constante
   * ID_TIPO_PERMISSAO_PADRAO) - o campo existe no SIP mas nao tem uso relevante para o SEI.
   */
  public function processarPermissoes(array $arrLinhas): array {
    $arrResultado = [];
    $objSistemaSeiDTO = $this->resolverSistemaSei();

    foreach ($arrLinhas as $arrLinha) {
      try {
        $arrResultado[] = $this->processarPermissoesLinha(['linha' => $arrLinha['linha'], 'campos' => $arrLinha['campos'], 'sistema' => $objSistemaSeiDTO]);
      } catch (Exception $e) {
        $arrResultado[] = $this->linhaResultado($arrLinha['linha'], self::STA_ERRO, $this->obterMensagemErro($e));
      }
    }
    return $arrResultado;
  }

  /** Uma linha de processarPermissoes(), em transacao propria (ver docblock da classe). */
  protected function processarPermissoesLinhaControlado(array $arrParametros): array {
    $numLinha = $arrParametros['linha'];
    $c = $arrParametros['campos'];
    $objSistemaSeiDTO = $arrParametros['sistema'];
    $strSiglaOrgao = $c[1] ?? '';
    $strSiglaUsuario = $c[2] ?? '';
    $strSiglaUnidade = $c[7] ?? '';
    $strNomePerfil = $c[8] ?? '';

    if ($strSiglaOrgao === '' || $strSiglaUsuario === '' || $strSiglaUnidade === '' || $strNomePerfil === '') {
      throw new InfraException('Linha incompleta (órgão/usuário/unidade/perfil obrigatórios).');
    }

    $objOrgaoDTO = $this->resolverOrgao($strSiglaOrgao);

    $objUsuarioDTO = $this->resolverUsuario($objOrgaoDTO->getNumIdOrgao(), $strSiglaUsuario);
    if ($objUsuarioDTO === null) {
      throw new InfraException('Usuário "' . $strSiglaUsuario . '" não encontrado (rode a carga de usuários antes).');
    }

    $objUnidadeDTO = $this->resolverUnidade($objOrgaoDTO->getNumIdOrgao(), $strSiglaUnidade);
    if ($objUnidadeDTO === null) {
      throw new InfraException('Unidade "' . $strSiglaUnidade . '" não encontrada.');
    }

    $objPerfilDTO = $this->resolverPerfil($objSistemaSeiDTO->getNumIdSistema(), $strNomePerfil);

    $dtoConsulta = new PermissaoDTO();
    $dtoConsulta->setNumIdPerfil($objPerfilDTO->getNumIdPerfil());
    $dtoConsulta->setNumIdSistema($objSistemaSeiDTO->getNumIdSistema());
    $dtoConsulta->setNumIdUnidade($objUnidadeDTO->getNumIdUnidade());
    $dtoConsulta->setNumIdUsuario($objUsuarioDTO->getNumIdUsuario());
    $dtoConsulta->retTodos();
    $objPermissaoRN = new PermissaoRN();
    if ($objPermissaoRN->consultar($dtoConsulta) !== null) {
      return $this->linhaResultado($numLinha, self::STA_PULADO, 'Usuário "' . $strSiglaUsuario . '" já possui o perfil "' . $strNomePerfil . '" na unidade "' . $strSiglaUnidade . '".');
    }

    $objPermissaoDTO = new PermissaoDTO();
    $objPermissaoDTO->setNumIdPerfil($objPerfilDTO->getNumIdPerfil());
    $objPermissaoDTO->setNumIdSistema($objSistemaSeiDTO->getNumIdSistema());
    $objPermissaoDTO->setNumIdUnidade($objUnidadeDTO->getNumIdUnidade());
    $objPermissaoDTO->setNumIdUsuario($objUsuarioDTO->getNumIdUsuario());
    $objPermissaoDTO->setNumIdTipoPermissao(self::ID_TIPO_PERMISSAO_PADRAO);
    $objPermissaoDTO->setStrSinSubunidades('N');
    $objPermissaoDTO->setDtaDataInicio(date('d/m/Y'));
    $objPermissaoDTO->setDtaDataFim(''); // sempre setado (mesmo vazio), ver nota acima

    $objPermissaoRN->cadastrar($objPermissaoDTO);

    // Conceitualmente, uma "permissao" no SIP e a concessao de um PERFIL numa UNIDADE
    // (apontado pelo usuario) - a mensagem reflete isso, nao so "permissao concedida".
    return $this->linhaResultado($numLinha, self::STA_OK, 'Perfil "' . $strNomePerfil . '" concedido a "' . $strSiglaUsuario . '" na unidade "' . $strSiglaUnidade . '".');
  }

  /**
   * Texto do erro de uma linha. Recebe a excecao que sai de processarXLinha(): o
   * InfraRN::__call() a reembrulha numa InfraException de mensagem generica, e o motivo real
   * esta em getPrevious(). Uma InfraException lancada via lancarValidacoes()/lancarValidacao()
   * (padrao das RN nativas para erro de regra de negocio) tem getMessage() VAZIO: o texto fica
   * em getArrObjInfraValidacao(), exposto por __toString(). Sem isso o erro apareceria na
   * linha do relatorio sem mensagem.
   */
  private function obterMensagemErro(Exception $e): string {
    $objErro = $e->getPrevious() ?? $e;
    $strMensagem = ($objErro instanceof InfraException) ? (string)$objErro : $objErro->getMessage();
    return $strMensagem !== '' ? $strMensagem : get_class($objErro);
  }

  private function linhaResultado(int $numLinha, string $strStatus, string $strMensagem): array {
    return array('linha' => $numLinha, 'status' => $strStatus, 'mensagem' => $strMensagem);
  }

  /**
   * Conta OK/pulado/erro de um array de linhaResultado() - usado pelas operacoes combinadas
   * (unidades+hierarquia, usuarios+permissoes) pra mostrar um resumo separado por
   * sub-operacao em vez de um total unico misturando as duas (ex.: "400 cadastrado(s)" quando
   * na verdade sao 200 usuarios + 200 permissoes) - achado do usuario testando a carga de
   * usuarios e permissoes contra o container real.
   */
  private function tally(array $arrResultado): array {
    return array(
      'ok' => count(array_filter($arrResultado, function ($r) { return $r['status'] === self::STA_OK; })),
      'pulado' => count(array_filter($arrResultado, function ($r) { return $r['status'] === self::STA_PULADO; })),
      'erro' => count(array_filter($arrResultado, function ($r) { return $r['status'] === self::STA_ERRO; })),
    );
  }
}

?>
