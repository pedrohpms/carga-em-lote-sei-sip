<?
/**
 * CargaEmLoteRN (SEI)
 *
 * Orquestra a leitura do mesmo .csv de unidades usado pelo modulo SIP (Sprint 1) e chama
 * diretamente as classes de regra de negocio ja existentes no SEI (UnidadeRN, ContatoRN,
 * EmailUnidadeRN, OrgaoRN, UfRN, CidadeRN) para completar o cadastro de unidades com dados
 * complementares: endereco, telefone, site, CNPJ e lista de e-mails.
 *
 * Diferente do modulo SIP (operacoes de criacao, "pular se ja existe"), esta e uma operacao
 * de ATUALIZACAO de dados que normalmente ja existem (a unidade e o Contato vinculado sao
 * criados nativamente/via replicacao SIP->SEI quando a unidade e cadastrada). Decisao
 * confirmada com o usuario: csv e fonte de verdade, sempre atualiza e reporta OK, nunca
 * "pulado".
 *
 * ContatoRN::alterarRN0323Controlado() e UnidadeRN::alterarRN0132Controlado() usam um padrao
 * de mesclagem (isSetX() antes de usar getter) que preenche automaticamente com o valor atual
 * do banco qualquer atributo nao setado explicitamente - ao contrario do que se viu no modulo
 * SIP (Sprint 1), aqui NAO e preciso setar todo campo do DTO para evitar "Atributo [X] nao
 * recebeu valor" (confirmado lendo o corpo dos dois metodos antes de escrever este codigo).
 */
class CargaEmLoteRN extends InfraRN {

  const STA_OK = 'OK';
  const STA_PULADO = 'PULADO';
  const STA_ERRO = 'ERRO';

  // Tamanho de lote para processamento particionado (varias requisicoes HTTP curtas em vez
  // de uma unica requisicao longa) - existe porque o timeout que interrompe uma carga grande
  // NAO e o do PHP (max_execution_time=0 neste laboratorio) e sim o do servidor web/proxy na
  // frente dele, que o modulo nao controla (e so codigo acrescentado a uma instalacao SEI/SIP
  // ja existente - cf. instrucoes.txt). Calibrado empiricamente no modulo SIP (mesma ordem de
  // grandeza de custo por linha): ~2,1s por linha - 50 linhas ficam em ~105s, com folga
  // confortavel sob o teto de 300s configurado no laboratorio. Ajuste pra baixo se a
  // instalacao real tiver um timeout mais agressivo na frente do PHP (proxy reverso,
  // balanceador, etc. - o modulo nao tem como detectar isso sozinho).
  const TAMANHO_LOTE = 50;

  protected function inicializarObjInfraIBanco(): InfraIBanco {
    return BancoSEI::getInstance();
  }

  // ---------------------------------------------------------------------
  // Resolucao de referencias (sigla/nome do .csv -> id interno)
  // ---------------------------------------------------------------------

  private function resolverOrgao(string $strSigla): OrgaoDTO {
    $dto = new OrgaoDTO();
    $dto->setBolExclusaoLogica(false);
    $dto->setStrSigla(trim($strSigla));
    $dto->retTodos();
    $objOrgaoRN = new OrgaoRN();
    $arrRet = $objOrgaoRN->listarRN1353($dto);
    if (count($arrRet) === 0) {
      throw new InfraException('Órgão "' . $strSigla . '" não encontrado.');
    }
    return $arrRet[0];
  }

  private function resolverUnidade(int $numIdOrgao, string $strSigla): ?UnidadeDTO {
    $dto = new UnidadeDTO();
    $dto->setBolExclusaoLogica(false);
    $dto->setNumIdOrgao($numIdOrgao);
    $dto->setStrSigla(trim($strSigla));
    $dto->retTodos();
    $objUnidadeRN = new UnidadeRN();
    $arrRet = $objUnidadeRN->listarRN0127($dto);
    return count($arrRet) > 0 ? $arrRet[0] : null;
  }

  private function resolverUf(string $strSigla, int $numIdPais): ?UfDTO {
    $dto = new UfDTO();
    $dto->setBolExclusaoLogica(false);
    $dto->setStrSigla(trim($strSigla));
    $dto->setNumIdPais($numIdPais);
    $dto->retTodos();
    $objUfRN = new UfRN();
    $arrRet = $objUfRN->listarRN0401($dto);
    return count($arrRet) > 0 ? $arrRet[0] : null;
  }

  private function resolverCidade(string $strNome, int $numIdUf): ?CidadeDTO {
    $dto = new CidadeDTO();
    $dto->setBolExclusaoLogica(false);
    $dto->setStrNome(trim($strNome));
    $dto->setNumIdUf($numIdUf);
    $dto->retTodos();
    $objCidadeRN = new CidadeRN();
    $arrRet = $objCidadeRN->listarRN0410($dto);
    return count($arrRet) > 0 ? $arrRet[0] : null;
  }

  private function resolverPais(string $strNome): PaisDTO {
    $dto = new PaisDTO();
    $dto->setBolExclusaoLogica(false);
    $dto->setStrNome(trim($strNome));
    $dto->retTodos();
    $objPaisRN = new PaisRN();
    $arrRet = $objPaisRN->listar($dto);
    if (count($arrRet) === 0) {
      throw new InfraException('País "' . $strNome . '" não encontrado.');
    }
    return $arrRet[0];
  }

  // Expressao sozinha nao e unica em Cargo (ex.: "Cidadao" existe para M e para F) -
  // resolve pelo par Expressao+StaGenero, mesma chave usada por CargoRN::validarCargoUnico.
  private function resolverCargo(string $strExpressao, string $strStaGenero): CargoDTO {
    $dto = new CargoDTO();
    $dto->setBolExclusaoLogica(false);
    $dto->setStrExpressao(trim($strExpressao));
    $dto->setStrStaGenero($strStaGenero);
    $dto->retTodos();
    $objCargoRN = new CargoRN();
    $arrRet = $objCargoRN->listarRN0302($dto);
    if (count($arrRet) === 0) {
      throw new InfraException('Cargo "' . $strExpressao . '" não encontrado para o gênero informado (cadastre antes em Administração > Contatos > Cargos).');
    }
    return $arrRet[0];
  }

  private function resolverCategoria(string $strNome): CategoriaDTO {
    $dto = new CategoriaDTO();
    $dto->setBolExclusaoLogica(false);
    $dto->setStrNome(trim($strNome));
    $dto->retTodos();
    $objCategoriaRN = new CategoriaRN();
    $arrRet = $objCategoriaRN->listar($dto);
    if (count($arrRet) === 0) {
      throw new InfraException('Categoria "' . $strNome . '" não encontrada (cadastre antes em Administração > Contatos > Categorias).');
    }
    return $arrRet[0];
  }

  private function resolverTitulo(string $strExpressao): TituloDTO {
    $dto = new TituloDTO();
    $dto->setBolExclusaoLogica(false);
    $dto->setStrExpressao(trim($strExpressao));
    $dto->retTodos();
    $objTituloRN = new TituloRN();
    $arrRet = $objTituloRN->listar($dto);
    if (count($arrRet) === 0) {
      throw new InfraException('Título "' . $strExpressao . '" não encontrado (cadastre antes em Administração > Contatos > Títulos).');
    }
    return $arrRet[0];
  }

  // ---------------------------------------------------------------------
  // Leitura de CSV (mesmo padrao do modulo SIP)
  // ---------------------------------------------------------------------

  // Despacha pela extensao do arquivo temporario (preservada no upload - ver
  // carga_em_lote_form.php, processarUpload() com bolArquivoTemporarioIdentificado=true) -
  // csv/xlsx/ods convergem para o mesmo formato de retorno (array de
  // array('linha'=>N,'campos'=>[...])), entao nenhum processarXxxControlado() precisou mudar.
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

  // Le o arquivo inteiro (csv/xlsx/ods) e devolve so a fatia [offset, offset+limite) junto
  // com o total de linhas de dado do arquivo inteiro - usado por todo processarXxxControlado()
  // pra suportar processamento particionado em lotes (ver TAMANHO_LOTE acima e
  // carga_em_lote_form.php, que controla o laco de recarregamentos automaticos). Reler o
  // arquivo inteiro a cada lote e barato (poucos milhares de linhas, no maximo) perto do
  // custo real, que e a gravacao no banco por linha.
  private function lerLote(string $strCaminhoArquivo, int $numOffset, ?int $numLimite): array {
    $arrTodasLinhas = $this->lerCsv($strCaminhoArquivo);
    $numTotal = count($arrTodasLinhas);
    $arrLote = ($numLimite === null) ? array_slice($arrTodasLinhas, $numOffset) : array_slice($arrTodasLinhas, $numOffset, $numLimite);
    return array('linhas' => $arrLote, 'total' => $numTotal);
  }

  // ---------------------------------------------------------------------
  // Dados complementares de unidade (macro 3.dadosUnidadesSEI)
  // Colunas do csv (mesmo exemploUnidades.csv da Sprint 1):
  // 0-Seq,1-orgaoUnidade,2-siglaUnidade,3-descricaoUnidade,4-superiorNaHierarquia,
  // 5-emailUnidade,6-usaEnderecoDoOrgao?,7-enderecoUnidade,8-complementoEndereco,
  // 9-bairroUnidade,10-UFUnidade,11-cidadeUnidade,12-CEPUnidade,13-CNPJUnidade,
  // 14-telefoneUnidade,15-siteUnidade
  // ---------------------------------------------------------------------

  protected function processarUnidadesComplementarControlado(array $arrParametros): array {
    $strCaminhoArquivo = $arrParametros['csv'];
    $arrLoteInfo = $this->lerLote($strCaminhoArquivo, $arrParametros['offset'] ?? 0, $arrParametros['limite'] ?? null);
    $arrResultado = array();
    foreach ($arrLoteInfo['linhas'] as $arrLinha) {
      $numLinha = $arrLinha['linha'];
      $c = $arrLinha['campos'];
      try {
        $strSiglaOrgao = $c[1] ?? '';
        $strSiglaUnidade = $c[2] ?? '';
        $strEmail = $c[5] ?? '';
        $strUsaEnderecoOrgao = strtoupper(trim($c[6] ?? ''));
        $strEndereco = $c[7] ?? '';
        $strComplemento = $c[8] ?? '';
        $strBairro = $c[9] ?? '';
        $strUf = $c[10] ?? '';
        $strCidade = $c[11] ?? '';
        $strCep = $c[12] ?? '';
        $strCnpj = $c[13] ?? '';
        $strTelefone = $c[14] ?? '';
        $strSite = $c[15] ?? '';

        if ($strSiglaOrgao === '' || $strSiglaUnidade === '') {
          throw new InfraException('Linha incompleta (órgão/sigla obrigatórios).');
        }

        $objOrgaoDTO = $this->resolverOrgao($strSiglaOrgao);

        $objUnidadeDTO = $this->resolverUnidade($objOrgaoDTO->getNumIdOrgao(), $strSiglaUnidade);
        if ($objUnidadeDTO === null) {
          throw new InfraException('Unidade "' . $strSiglaUnidade . '" não encontrada no SEI (rode a carga de unidades do módulo SIP antes).');
        }
        if (!$objUnidadeDTO->getNumIdContato()) {
          throw new InfraException('Unidade "' . $strSiglaUnidade . '" não tem contato vinculado (caso não esperado, não tratado nesta versão).');
        }

        $numIdPais = PaisINT::buscarIdPaisBrasil();

        $numIdUf = null;
        if ($strUf !== '') {
          $objUfDTO = $this->resolverUf($strUf, $numIdPais);
          if ($objUfDTO === null) {
            throw new InfraException('UF "' . $strUf . '" não encontrada.');
          }
          $numIdUf = $objUfDTO->getNumIdUf();
        }

        $numIdCidade = null;
        if ($strCidade !== '' && $numIdUf !== null) {
          $objCidadeDTO = $this->resolverCidade($strCidade, $numIdUf);
          if ($objCidadeDTO === null) {
            throw new InfraException('Cidade "' . $strCidade . '" não encontrada na UF "' . $strUf . '".');
          }
          $numIdCidade = $objCidadeDTO->getNumIdCidade();
        }

        // Contato "reservado do sistema" (tipo_contato "Unidades <ORGAO>") so aceita alteracao
        // com StaOperacao=REPLICACAO - mesmo sinalizador que UnidadeRN::alterarRN0132Controlado
        // usa internamente quando toca no Contato da unidade.
        $objContatoDTO = new ContatoDTO();
        $objContatoDTO->setNumIdContato($objUnidadeDTO->getNumIdContato());
        if ($strEndereco !== '') {
          $objContatoDTO->setStrEndereco($strEndereco);
        }
        if ($strComplemento !== '') {
          $objContatoDTO->setStrComplemento($strComplemento);
        }
        if ($strBairro !== '') {
          $objContatoDTO->setStrBairro($strBairro);
        }
        if ($numIdUf !== null) {
          $objContatoDTO->setNumIdUf($numIdUf);
        }
        if ($numIdCidade !== null) {
          $objContatoDTO->setNumIdCidade($numIdCidade);
        }
        $objContatoDTO->setNumIdPais($numIdPais);
        if ($strCep !== '') {
          $objContatoDTO->setStrCep($strCep);
        }
        if ($strCnpj !== '') {
          $objContatoDTO->setStrCnpj(InfraUtil::formatarCnpj($strCnpj));
        }
        if ($strTelefone !== '') {
          $objContatoDTO->setStrTelefoneComercial($strTelefone);
        }
        if ($strSite !== '') {
          $objContatoDTO->setStrSitioInternet($strSite);
        }
        $objContatoDTO->setStrStaOperacao('REPLICACAO');

        if ($strUsaEnderecoOrgao === 'S') {
          $objContatoDTO->setStrSinEnderecoAssociado('S');
          $objContatoDTO->setNumIdContatoAssociado($objOrgaoDTO->getNumIdContato());
        } else {
          $objContatoDTO->setStrSinEnderecoAssociado('N');
        }

        $objContatoRN = new ContatoRN();
        $objContatoRN->alterarRN0323($objContatoDTO);

        // Lista de e-mails: EmailUnidadeRN nao tem "cadastrar se nao existir" embutido, e
        // UnidadeRN::alterarRN0132Controlado apaga e recria a lista inteira quando recebe o
        // array - por isso carregamos a lista atual e so ACRESCENTAMOS o e-mail do csv (nunca
        // setamos so o novo sozinho, senao apagaria os demais).
        if ($strEmail !== '') {
          $objEmailUnidadeDTOConsulta = new EmailUnidadeDTO();
          $objEmailUnidadeDTOConsulta->retTodos();
          $objEmailUnidadeDTOConsulta->setNumIdUnidade($objUnidadeDTO->getNumIdUnidade());

          $objEmailUnidadeRN = new EmailUnidadeRN();
          $arrObjEmailAtual = $objEmailUnidadeRN->listar($objEmailUnidadeDTOConsulta);

          $bolJaTemEmail = false;
          foreach ($arrObjEmailAtual as $objEmailExistente) {
            if (strcasecmp($objEmailExistente->getStrEmail(), $strEmail) === 0) {
              $bolJaTemEmail = true;
              break;
            }
          }

          if (!$bolJaTemEmail) {
            $objNovoEmailDTO = new EmailUnidadeDTO();
            $objNovoEmailDTO->setNumIdEmailUnidade(null);
            $objNovoEmailDTO->setStrEmail($strEmail);
            $objNovoEmailDTO->setStrDescricao('Carga em lote');
            $objNovoEmailDTO->setNumSequencia(count($arrObjEmailAtual) + 1);
            $arrObjEmailAtual[] = $objNovoEmailDTO;

            $objUnidadeDTOAlterar = new UnidadeDTO();
            $objUnidadeDTOAlterar->setNumIdUnidade($objUnidadeDTO->getNumIdUnidade());
            $objUnidadeDTOAlterar->setArrObjEmailUnidadeDTO($arrObjEmailAtual);

            $objUnidadeRN = new UnidadeRN();
            $objUnidadeRN->alterarRN0132($objUnidadeDTOAlterar);
          }
        }

        $arrResultado[] = $this->linhaResultado($numLinha, self::STA_OK, 'Unidade "' . $strSiglaUnidade . '" atualizada.');
      } catch (Exception $e) {
        $arrResultado[] = $this->linhaResultado($numLinha, self::STA_ERRO, $this->obterMensagemErro($e));
      }
    }
    return array('resultado' => $arrResultado, 'total' => $arrLoteInfo['total'], 'processadas' => count($arrLoteInfo['linhas']));
  }


  // ---------------------------------------------------------------------
  // Contato de usuarios (macro 6.cargaContatoUsuarios)
  // Colunas do csv (exemploContatoUsuarios.csv - SEM coluna de orgao):
  // 0-Seq,1-siglaUsuario,2-generoUsuario,3-usaEnderecoDoOrgao,4-enderecoUsuario,
  // 5-complemEndUsuario,6-bairroUsuario,7-paisUsuario,8-ufUsuario,9-cidadeUsuario,
  // 10-cepUsuario,11-cargoUsuario,12-categoriaUsuario,13-funcaoUsuario,14-tituloUsuario,
  // 15-cpfUsuario,16-rgUsuario,17-orgaoExpRgUsuario,18-dataNascUsuario,19-matriculaUsuario,
  // 20-matOabUsuario,21-passaporteUsuario,22-paisPassaporteUsuario,
  // 23-telefoneComercialUsuario,24-telefoneCelularUsuario,25-telefoneResidencialUsuario,
  // 26-conjugeUsuario,27-emailUsuario,28-obsUsuario
  //
  // Sem coluna de orgao no csv: resolve por sigla em todos os orgaos; se a sigla existir em
  // mais de um orgao, reporta erro na linha em vez de adivinhar (decisao confirmada com o
  // usuario, ja que sigla de usuario e unica por orgao, nao globalmente).
  // ---------------------------------------------------------------------

  protected function processarContatoUsuariosControlado(array $arrParametros): array {
    $strCaminhoArquivo = $arrParametros['csv'];
    $arrLoteInfo = $this->lerLote($strCaminhoArquivo, $arrParametros['offset'] ?? 0, $arrParametros['limite'] ?? null);
    $arrResultado = array();
    foreach ($arrLoteInfo['linhas'] as $arrLinha) {
      $numLinha = $arrLinha['linha'];
      $c = $arrLinha['campos'];
      try {
        $strSigla = $c[1] ?? '';
        $strGenero = strtoupper(trim($c[2] ?? ''));
        $strUsaEnderecoOrgao = strtoupper(trim($c[3] ?? ''));
        $strEndereco = $c[4] ?? '';
        $strComplemento = $c[5] ?? '';
        $strBairro = $c[6] ?? '';
        $strPais = $c[7] ?? '';
        $strUf = $c[8] ?? '';
        $strCidade = $c[9] ?? '';
        $strCep = $c[10] ?? '';
        $strCargo = $c[11] ?? '';
        $strCategoria = $c[12] ?? '';
        $strFuncao = $c[13] ?? '';
        $strTitulo = $c[14] ?? '';
        $strCpf = $c[15] ?? '';
        $strRg = $c[16] ?? '';
        $strOrgaoExpRg = $c[17] ?? '';
        $strDataNasc = $c[18] ?? '';
        $strMatricula = $c[19] ?? '';
        $strMatOab = $c[20] ?? '';
        $strPassaporte = $c[21] ?? '';
        $strPaisPassaporte = $c[22] ?? '';
        $strTelComercial = $c[23] ?? '';
        $strTelCelular = $c[24] ?? '';
        $strTelResidencial = $c[25] ?? '';
        $strConjuge = $c[26] ?? '';
        $strEmail = $c[27] ?? '';
        $strObs = $c[28] ?? '';

        if ($strSigla === '') {
          throw new InfraException('Linha incompleta (sigla do usuário obrigatória).');
        }

        $dtoUsuario = new UsuarioDTO();
        $dtoUsuario->setBolExclusaoLogica(false);
        $dtoUsuario->setStrSigla(trim($strSigla));
        $dtoUsuario->retNumIdContato();
        $dtoUsuario->retNumIdContatoOrgao();
        $dtoUsuario->retStrSiglaOrgao();
        $objUsuarioRN = new UsuarioRN();
        $arrUsuarios = $objUsuarioRN->listarRN0490($dtoUsuario);

        if (count($arrUsuarios) === 0) {
          throw new InfraException('Usuário "' . $strSigla . '" não encontrado.');
        }
        if (count($arrUsuarios) > 1) {
          $arrSiglasOrgao = array_map(function ($objDTO) { return $objDTO->getStrSiglaOrgao(); }, $arrUsuarios);
          throw new InfraException('Usuário "' . $strSigla . '" existe em mais de um órgão (' . implode(', ', $arrSiglasOrgao) . ') - csv sem coluna de órgão não suporta este caso.');
        }
        $objUsuarioDTO = $arrUsuarios[0];

        if (!$objUsuarioDTO->getNumIdContato()) {
          throw new InfraException('Usuário "' . $strSigla . '" não tem contato vinculado (caso não esperado, não tratado nesta versão).');
        }

        $numIdPais = PaisINT::buscarIdPaisBrasil();
        if ($strPais !== '' && strcasecmp(trim($strPais), 'Brasil') !== 0) {
          $numIdPais = $this->resolverPais($strPais)->getNumIdPais();
        }

        $numIdUf = null;
        if ($strUf !== '') {
          $objUfDTO = $this->resolverUf($strUf, $numIdPais);
          if ($objUfDTO === null) {
            throw new InfraException('UF "' . $strUf . '" não encontrada.');
          }
          $numIdUf = $objUfDTO->getNumIdUf();
        }

        $numIdCidade = null;
        if ($strCidade !== '' && $numIdUf !== null) {
          $objCidadeDTO = $this->resolverCidade($strCidade, $numIdUf);
          if ($objCidadeDTO === null) {
            throw new InfraException('Cidade "' . $strCidade . '" não encontrada na UF "' . $strUf . '".');
          }
          $numIdCidade = $objCidadeDTO->getNumIdCidade();
        }

        $numIdPaisPassaporte = null;
        if ($strPassaporte !== '') {
          $numIdPaisPassaporte = $numIdPais;
          if ($strPaisPassaporte !== '' && strcasecmp(trim($strPaisPassaporte), 'Brasil') !== 0) {
            $numIdPaisPassaporte = $this->resolverPais($strPaisPassaporte)->getNumIdPais();
          }
        }

        $numIdCargo = null;
        if ($strCargo !== '') {
          $numIdCargo = $this->resolverCargo($strCargo, $strGenero)->getNumIdCargo();
        }

        $numIdCategoria = null;
        if ($strCategoria !== '') {
          $numIdCategoria = $this->resolverCategoria($strCategoria)->getNumIdCategoria();
        }

        $numIdTitulo = null;
        if ($strTitulo !== '') {
          $numIdTitulo = $this->resolverTitulo($strTitulo)->getNumIdTitulo();
        }

        // ContatoRN::alterarRN0323Controlado preenche do banco qualquer atributo nao setado
        // (padrao isSetX() antes do getter) - so precisamos setar o que muda, nao o DTO
        // inteiro (confirmado lendo o corpo do metodo antes de escrever este codigo).
        $objContatoDTO = new ContatoDTO();
        $objContatoDTO->setNumIdContato($objUsuarioDTO->getNumIdContato());
        if ($strGenero !== '') {
          $objContatoDTO->setStrStaGenero($strGenero);
        }
        if ($strEndereco !== '') {
          $objContatoDTO->setStrEndereco($strEndereco);
        }
        if ($strComplemento !== '') {
          $objContatoDTO->setStrComplemento($strComplemento);
        }
        if ($strBairro !== '') {
          $objContatoDTO->setStrBairro($strBairro);
        }
        if ($numIdUf !== null) {
          $objContatoDTO->setNumIdUf($numIdUf);
        }
        if ($numIdCidade !== null) {
          $objContatoDTO->setNumIdCidade($numIdCidade);
        }
        $objContatoDTO->setNumIdPais($numIdPais);
        if ($strCep !== '') {
          $objContatoDTO->setStrCep($strCep);
        }
        if ($numIdCargo !== null) {
          $objContatoDTO->setNumIdCargo($numIdCargo);
        }
        if ($numIdCategoria !== null) {
          $objContatoDTO->setNumIdCategoria($numIdCategoria);
        }
        if ($numIdTitulo !== null) {
          $objContatoDTO->setNumIdTitulo($numIdTitulo);
        }
        if ($strFuncao !== '') {
          $objContatoDTO->setStrFuncao($strFuncao);
        }
        if ($strCpf !== '') {
          $objContatoDTO->setDblCpf($strCpf);
        }
        if ($strRg !== '') {
          $objContatoDTO->setDblRg($strRg);
        }
        if ($strOrgaoExpRg !== '') {
          $objContatoDTO->setStrOrgaoExpedidor($strOrgaoExpRg);
        }
        if ($strDataNasc !== '') {
          $objContatoDTO->setDtaNascimento($strDataNasc);
        }
        if ($strMatricula !== '') {
          $objContatoDTO->setStrMatricula($strMatricula);
        }
        if ($strMatOab !== '') {
          $objContatoDTO->setStrMatriculaOab($strMatOab);
        }
        if ($strPassaporte !== '') {
          $objContatoDTO->setStrNumeroPassaporte($strPassaporte);
        }
        if ($numIdPaisPassaporte !== null) {
          $objContatoDTO->setNumIdPaisPassaporte($numIdPaisPassaporte);
        }
        if ($strTelComercial !== '') {
          $objContatoDTO->setStrTelefoneComercial($strTelComercial);
        }
        if ($strTelCelular !== '') {
          $objContatoDTO->setStrTelefoneCelular($strTelCelular);
        }
        if ($strTelResidencial !== '') {
          $objContatoDTO->setStrTelefoneResidencial($strTelResidencial);
        }
        if ($strConjuge !== '') {
          $objContatoDTO->setStrConjuge($strConjuge);
        }
        if ($strEmail !== '') {
          $objContatoDTO->setStrEmail($strEmail);
        }
        if ($strObs !== '') {
          $objContatoDTO->setStrObservacao($strObs);
        }
        // tipo_contato "Usuarios <ORGAO>" e reservado do sistema (mesmo padrao de Unidades) -
        // REPLICACAO evita bloqueio de alteracao de campos protegidos.
        $objContatoDTO->setStrStaOperacao('REPLICACAO');

        if ($strUsaEnderecoOrgao === 'S') {
          $objContatoDTO->setStrSinEnderecoAssociado('S');
          $objContatoDTO->setNumIdContatoAssociado($objUsuarioDTO->getNumIdContatoOrgao());
        } else {
          $objContatoDTO->setStrSinEnderecoAssociado('N');
        }

        $objContatoRN = new ContatoRN();
        $objContatoRN->alterarRN0323($objContatoDTO);

        $arrResultado[] = $this->linhaResultado($numLinha, self::STA_OK, 'Usuário "' . $strSigla . '" atualizado.');
      } catch (Exception $e) {
        $arrResultado[] = $this->linhaResultado($numLinha, self::STA_ERRO, $this->obterMensagemErro($e));
      }
    }
    return array('resultado' => $arrResultado, 'total' => $arrLoteInfo['total'], 'processadas' => count($arrLoteInfo['linhas']));
  }

  // InfraException lancada via lancarValidacoes()/lancarValidacao() (padrao das RN nativas
  // do SEI para erros de regra de negocio) tem getMessage() VAZIO - o texto fica em
  // getArrObjInfraValidacao(), exposto via __toString(). Sem isso, erro de validacao
  // nativa aparecia como linha ERRO sem mensagem nenhuma (bug real, visto testando contra
  // o container real).
  // ---------------------------------------------------------------------
  // Assuntos da Tabela de Assuntos (macro 7.cargaAssuntos)
  // Colunas do csv (exemploAssuntos.csv):
  // 0-Index,1-CodigoEstruturado,2-NomeAssunto,3-chkEstrutural,4-PrazoCorrente,5-PrazoIntermed,
  // 6-Destinacao,7-Obs
  //
  // Diferente das operacoes de Unidade/Usuario (atualizacao), esta e uma operacao de CRIACAO
  // (mesmo padrao "contar antes de cadastrar, pula se ja existe" da Sprint 1/SIP) - assuntos
  // nao tem contrapartida replicada do SIP.
  //
  // A hierarquia e implicita no CodigoEstruturado (ex.: "020.01.01" abaixo de "020.01") - nao
  // ha FK de assunto pai a resolver, cada linha e independente.
  // ---------------------------------------------------------------------

  // Sem tabela informada, usa a marcada como atual (SinAtual='S', sempre existe exatamente
  // uma - invariante validado por TabelaAssuntosRN::validarStrSinAtual). Com tabela informada
  // (pelo Nome - unico, validado nativamente em TabelaAssuntosRN::validarStrNome - mesma
  // convencao de resolver por identificador humano ja usada nas demais operacoes do modulo,
  // nunca por id interno do banco), usa a tabela escolhida - cobre o caso de o orgao estar
  // preparando uma tabela nova (proxima TTD) ainda nao marcada como atual.
  private function resolverTabelaAssuntos(?string $strNomeTabela): TabelaAssuntosDTO {
    $dto = new TabelaAssuntosDTO();
    $dto->setBolExclusaoLogica(false);
    $dto->retTodos();
    if ($strNomeTabela === null || trim($strNomeTabela) === '') {
      $dto->setStrSinAtual('S');
    } else {
      $dto->setStrNome(trim($strNomeTabela));
    }
    $objTabelaAssuntosRN = new TabelaAssuntosRN();
    $arrRet = $objTabelaAssuntosRN->listar($dto);
    if (count($arrRet) === 0) {
      if ($strNomeTabela === null || trim($strNomeTabela) === '') {
        throw new InfraException('Nenhuma Tabela de Assuntos marcada como atual foi encontrada.');
      }
      throw new InfraException('Tabela de Assuntos "' . $strNomeTabela . '" não encontrada.');
    }
    return $arrRet[0];
  }

  // Arquivo real do repositorio de macros usa "Guarda"/"Eliminacao" (sem acento, sem
  // "Permanente") - mapeamento tolerante por prefixo, sem acento, para nao depender de qual
  // variante exata o usuario usar no proprio csv.
  private function resolverStaDestinacao(string $strDestinacao): string {
    $strNormalizado = strtolower(trim($strDestinacao));
    if (function_exists('iconv')) {
      $strSemAcento = @iconv('ISO-8859-1', 'ASCII//TRANSLIT', $strNormalizado);
      if ($strSemAcento !== false) {
        $strNormalizado = $strSemAcento;
      }
    }
    if (strpos($strNormalizado, 'guarda') === 0) {
      return AssuntoRN::$TD_GUARDA_PERMANENTE;
    }
    if (strpos($strNormalizado, 'elimina') === 0) {
      return AssuntoRN::$TD_ELIMINACAO;
    }
    throw new InfraException('Destinação "' . $strDestinacao . '" não reconhecida (use "Guarda" ou "Eliminação").');
  }

  // InfraRN::__call() (magia de despacho publico, codigo do core) so aceita um segundo
  // parametro quando ele e uma InfraException - qualquer outro tipo cai no "Tipo invalido
  // para o segundo parametro", entao os dois dados desta operacao (arquivo + tabela opcional)
  // precisam vir empacotados num unico array, ao contrario das demais operacoes (um parametro
  // so).
  protected function processarAssuntosControlado(array $arrParametros): array {
    $strCaminhoArquivo = $arrParametros['csv'];
    $strNomeTabela = $arrParametros['nomeTabela'] ?? null;
    $arrLoteInfo = $this->lerLote($strCaminhoArquivo, $arrParametros['offset'] ?? 0, $arrParametros['limite'] ?? null);
    $arrResultado = array();
    $objTabelaAssuntosDTO = $this->resolverTabelaAssuntos($strNomeTabela);

    foreach ($arrLoteInfo['linhas'] as $arrLinha) {
      $numLinha = $arrLinha['linha'];
      $c = $arrLinha['campos'];
      try {
        $strCodigo = trim($c[1] ?? '');
        $strNome = trim($c[2] ?? '');
        $strChkEstrutural = strtoupper(trim($c[3] ?? ''));
        $strPrazoCorrente = trim($c[4] ?? '');
        $strPrazoIntermed = trim($c[5] ?? '');
        $strDestinacao = trim($c[6] ?? '');
        $strObs = trim($c[7] ?? '');

        if ($strCodigo === '' || $strNome === '') {
          throw new InfraException('Linha incompleta (código/nome do assunto obrigatórios).');
        }

        $strSinEstrutural = ($strChkEstrutural === 'S') ? 'S' : 'N';

        $objAssuntoDTOFiltro = new AssuntoDTO();
        $objAssuntoDTOFiltro->setBolExclusaoLogica(false);
        $objAssuntoDTOFiltro->setNumIdTabelaAssuntos($objTabelaAssuntosDTO->getNumIdTabelaAssuntos());
        $objAssuntoDTOFiltro->setStrCodigoEstruturado($strCodigo);
        $objAssuntoRN = new AssuntoRN();
        if ($objAssuntoRN->contarRN0249($objAssuntoDTOFiltro) > 0) {
          $arrResultado[] = $this->linhaResultado($numLinha, self::STA_PULADO, 'Assunto "' . $strCodigo . '" já existe.');
          continue;
        }

        $objAssuntoDTO = new AssuntoDTO();
        $objAssuntoDTO->setNumIdAssunto(null);
        $objAssuntoDTO->setNumIdTabelaAssuntos($objTabelaAssuntosDTO->getNumIdTabelaAssuntos());
        $objAssuntoDTO->setStrCodigoEstruturado($strCodigo);
        $objAssuntoDTO->setStrDescricao($strNome);
        $objAssuntoDTO->setStrSinEstrutural($strSinEstrutural);
        if ($strSinEstrutural === 'N') {
          if ($strPrazoCorrente === '' || $strPrazoIntermed === '' || $strDestinacao === '') {
            throw new InfraException('Assunto "' . $strCodigo . '" não é estrutural - prazo corrente, prazo intermediário e destinação são obrigatórios.');
          }
          $objAssuntoDTO->setNumPrazoCorrente($strPrazoCorrente);
          $objAssuntoDTO->setNumPrazoIntermediario($strPrazoIntermed);
          $objAssuntoDTO->setStrStaDestinacao($this->resolverStaDestinacao($strDestinacao));
        }
        // Operacao de CRIACAO (nao alteracao) - diferente das duas operacoes anteriores,
        // aqui nao ha valor previo a preservar; AssuntoBD::cadastrar() chama o getter de
        // Observacao internamente, entao precisa estar setado (mesmo que null) sempre.
        $objAssuntoDTO->setStrObservacao($strObs !== '' ? $strObs : null);
        $objAssuntoDTO->setStrSinAtivo('S');

        $objAssuntoRN->cadastrarRN0259($objAssuntoDTO);

        $arrResultado[] = $this->linhaResultado($numLinha, self::STA_OK, 'Assunto "' . $strCodigo . '" cadastrado.');
      } catch (Exception $e) {
        $arrResultado[] = $this->linhaResultado($numLinha, self::STA_ERRO, $this->obterMensagemErro($e));
      }
    }
    return array('resultado' => $arrResultado, 'total' => $arrLoteInfo['total'], 'processadas' => count($arrLoteInfo['linhas']));
  }

  // ---------------------------------------------------------------------
  // Tipos de Processo (macro 8.cargaTiposDeProcesso)
  // Colunas do csv (exemploTiposDeProcesso.csv):
  // 0-Seq,1-Nome,2-descricao,3-sugestaoDeAssuntos,4-restringirAosOrgaos,
  // 5-restringirAsUnidades,6-niveisDeAcessoPermitidos,7-nivelDeAcessoSugerido,8-grauSigilo,
  // 9-sugestaoHipoteseLegal,10-exclusivoOuvidoria,11-permiteContatoAnonimo,
  // 12-ProcessoUnicoPorInteressado,13-InternoDoSistema
  //
  // Operacao de CRIACAO (mesmo padrao "contar antes de cadastrar, pula se ja existe" das
  // operacoes de Unidades/SIP e Assuntos) - Tipo de Processo nao tem contrapartida replicada
  // do SIP. Duplicidade verificada pelo mesmo par (Nome, SinOuvidoria) usado nativamente em
  // AssuntoRN::validarStrNomeRN0272... na verdade TipoProcedimentoRN::validarStrNomeRN0272
  // (nome da classe correto - unicidade e por Nome+SinOuvidoria, nao Nome sozinho).
  //
  // Diferente de Contato/Assunto (que tem merge isSetX() ou aceitam null "de graca" em
  // varios campos), TipoProcedimentoRN::cadastrarRN0265Controlado valida TODOS os campos
  // sempre, mesmo os "opcionais" (a propria validacao interna chama o getter e so entao seta
  // null se vazio) - por isso aqui, ao contrario das duas operacoes anteriores desta sprint,
  // TODOS os campos do DTO principal precisam ser setados explicitamente, nunca deixados sem
  // set (confirmado lendo o corpo de cada validarXxx antes de escrever este codigo).
  // ---------------------------------------------------------------------

  private function parseListaPontoVirgula(string $strLista): array {
    if (trim($strLista) === '') {
      return array();
    }
    $arrItens = array();
    foreach (explode(';', $strLista) as $strItem) {
      $strItemTrim = trim($strItem);
      if ($strItemTrim !== '') {
        $arrItens[] = $strItemTrim;
      }
    }
    return $arrItens;
  }

  // csv usa "PUB"/"RES"/"SIG" (README) - mapeados para os codigos internos de
  // ProtocoloRN ('0'/'1'/'2', constantes $NA_PUBLICO/$NA_RESTRITO/$NA_SIGILOSO).
  private function resolverStaNivelAcesso(string $strToken): string {
    switch (strtoupper(trim($strToken))) {
      case 'PUB':
        return ProtocoloRN::$NA_PUBLICO;
      case 'RES':
        return ProtocoloRN::$NA_RESTRITO;
      case 'SIG':
        return ProtocoloRN::$NA_SIGILOSO;
      default:
        throw new InfraException('Nível de acesso "' . $strToken . '" não reconhecido (use PUB, RES ou SIG).');
    }
  }

  // csv usa textos como "SIM" nos exemplos reais, mas o README descreve "S" - aceita as duas
  // formas (mesma tolerancia ja usada para o texto de Destinacao dos Assuntos), qualquer outra
  // coisa (inclusive vazio) e' tratado como nao marcado.
  private function resolverSinalizadorSimNao(string $strValor): string {
    $strNormalizado = strtoupper(trim($strValor));
    return ($strNormalizado === 'S' || $strNormalizado === 'SIM') ? 'S' : 'N';
  }

  // O assunto sugerido precisa ter uma linha em assunto_proxy (so existe para assuntos folha
  // da tabela ATUAL - mesma tabela usada por padrao na operacao de Assuntos) -
  // RelTipoProcedimentoAssuntoRN::cadastrarRN0285Controlado resolve o proxy sozinho a partir
  // do IdAssunto, entao so precisamos achar o IdAssunto aqui.
  private function resolverAssuntoPorCodigo(string $strCodigo, int $numIdTabelaAssuntosAtual): AssuntoDTO {
    $dto = new AssuntoDTO();
    $dto->setBolExclusaoLogica(false);
    $dto->setNumIdTabelaAssuntos($numIdTabelaAssuntosAtual);
    $dto->setStrCodigoEstruturado(trim($strCodigo));
    $dto->retTodos();
    $objAssuntoRN = new AssuntoRN();
    $arrRet = $objAssuntoRN->listarRN0247($dto);
    if (count($arrRet) === 0) {
      throw new InfraException('Assunto "' . $strCodigo . '" não encontrado na Tabela de Assuntos atual (cadastre antes pela carga de Assuntos).');
    }
    return $arrRet[0];
  }

  // Formato usado nos dados nativos (confirmado consultando a tabela hipotese_legal): "Nome
  // (Base legal)" - ex.: "Protocolo Pendente de Analise de Restricao (Art. 6o, III, da Lei no
  // 12.527/2011)". Resolve pelos dois campos juntos (Nome + BaseLegal) para nao depender de
  // Nome ser globalmente unico.
  private function resolverHipoteseLegal(string $strTexto): HipoteseLegalDTO {
    if (!preg_match('/^(.*)\s\(([^()]*)\)$/', trim($strTexto), $arrMatch)) {
      throw new InfraException('Hipótese legal "' . $strTexto . '" não está no formato esperado ("Nome (Base legal)").');
    }
    $dto = new HipoteseLegalDTO();
    $dto->setBolExclusaoLogica(false);
    $dto->setStrNome(trim($arrMatch[1]));
    $dto->setStrBaseLegal(trim($arrMatch[2]));
    $dto->retTodos();
    $objHipoteseLegalRN = new HipoteseLegalRN();
    $arrRet = $objHipoteseLegalRN->listar($dto);
    if (count($arrRet) === 0) {
      throw new InfraException('Hipótese legal "' . $strTexto . '" não encontrada.');
    }
    return $arrRet[0];
  }

  protected function processarTiposProcessoControlado(array $arrParametros): array {
    $strCaminhoArquivo = $arrParametros['csv'];
    $arrLoteInfo = $this->lerLote($strCaminhoArquivo, $arrParametros['offset'] ?? 0, $arrParametros['limite'] ?? null);
    $arrResultado = array();
    $objTabelaAssuntosDTO = $this->resolverTabelaAssuntos(null);

    foreach ($arrLoteInfo['linhas'] as $arrLinha) {
      $numLinha = $arrLinha['linha'];
      $c = $arrLinha['campos'];
      try {
        $strNome = trim($c[1] ?? '');
        $strDescricao = trim($c[2] ?? '');
        $strSugestaoAssuntos = $c[3] ?? '';
        $strRestringirOrgaos = $c[4] ?? '';
        $strRestringirUnidades = $c[5] ?? '';
        $strNiveisPermitidos = $c[6] ?? '';
        $strNivelSugerido = trim($c[7] ?? '');
        $strGrauSigilo = strtoupper(trim($c[8] ?? ''));
        $strHipoteseLegal = trim($c[9] ?? '');
        $strExclusivoOuvidoria = $c[10] ?? '';
        $strContatoAnonimo = $c[11] ?? '';
        $strProcessoUnico = $c[12] ?? '';
        $strInternoSistema = $c[13] ?? '';

        if ($strNome === '') {
          throw new InfraException('Linha incompleta (nome do tipo de processo obrigatório).');
        }

        $strSinOuvidoria = $this->resolverSinalizadorSimNao($strExclusivoOuvidoria);

        // Duplicidade: mesmo par (Nome, SinOuvidoria) validado nativamente em
        // TipoProcedimentoRN::validarStrNomeRN0272.
        $objTipoProcedimentoDTOFiltro = new TipoProcedimentoDTO();
        $objTipoProcedimentoDTOFiltro->setBolExclusaoLogica(false);
        $objTipoProcedimentoDTOFiltro->setStrNome($strNome);
        $objTipoProcedimentoDTOFiltro->setStrSinOuvidoria($strSinOuvidoria);
        $objTipoProcedimentoRN = new TipoProcedimentoRN();
        if ($objTipoProcedimentoRN->contarRN0270($objTipoProcedimentoDTOFiltro) > 0) {
          $arrResultado[] = $this->linhaResultado($numLinha, self::STA_PULADO, 'Tipo de Processo "' . $strNome . '" já existe.');
          continue;
        }

        // Assuntos sugeridos
        $arrObjRelTipoProcedimentoAssuntoDTO = array();
        $numSequenciaAssunto = 1;
        foreach ($this->parseListaPontoVirgula($strSugestaoAssuntos) as $strCodigoAssunto) {
          $objAssuntoDTO = $this->resolverAssuntoPorCodigo($strCodigoAssunto, $objTabelaAssuntosDTO->getNumIdTabelaAssuntos());
          $objRelDTO = new RelTipoProcedimentoAssuntoDTO();
          $objRelDTO->setNumIdAssunto($objAssuntoDTO->getNumIdAssunto());
          $objRelDTO->setNumSequencia($numSequenciaAssunto);
          $arrObjRelTipoProcedimentoAssuntoDTO[] = $objRelDTO;
          $numSequenciaAssunto++;
        }

        // Restricoes de orgao/unidade: restringirAsUnidades detalha por orgao
        // ("ORGAO:UNIDADE1|UNIDADE2;ORGAO2:UNIDADE3"), restringirAosOrgaos e a lista mestra -
        // um orgao presente so em restringirAosOrgaos vira uma unica restricao "orgao inteiro"
        // (IdUnidade nulo); um orgao com detalhamento em restringirAsUnidades vira uma
        // restricao por unidade listada. Uniao das duas colunas usada como lista de orgaos,
        // para nao depender de qual das duas o operador preencheu.
        $arrUnidadesPorOrgao = array();
        foreach ($this->parseListaPontoVirgula($strRestringirUnidades) as $strGrupoOrgaoUnidades) {
          $arrPartes = explode(':', $strGrupoOrgaoUnidades, 2);
          $strSiglaOrgaoGrupo = trim($arrPartes[0]);
          $arrSiglasUnidade = isset($arrPartes[1]) ? array_filter(array_map('trim', explode('|', $arrPartes[1])), function ($s) { return $s !== ''; }) : array();
          if ($strSiglaOrgaoGrupo !== '') {
            $arrUnidadesPorOrgao[$strSiglaOrgaoGrupo] = array_values($arrSiglasUnidade);
          }
        }

        $arrSiglasOrgaoRestricao = $this->parseListaPontoVirgula($strRestringirOrgaos);
        foreach (array_keys($arrUnidadesPorOrgao) as $strSiglaOrgaoGrupo) {
          if (!in_array($strSiglaOrgaoGrupo, $arrSiglasOrgaoRestricao, true)) {
            $arrSiglasOrgaoRestricao[] = $strSiglaOrgaoGrupo;
          }
        }

        $arrObjTipoProcedRestricaoDTO = array();
        foreach ($arrSiglasOrgaoRestricao as $strSiglaOrgaoRestricao) {
          $objOrgaoDTORestricao = $this->resolverOrgao($strSiglaOrgaoRestricao);
          $arrSiglasUnidadeRestricao = $arrUnidadesPorOrgao[$strSiglaOrgaoRestricao] ?? array();

          if (count($arrSiglasUnidadeRestricao) === 0) {
            $objRestricaoDTO = new TipoProcedRestricaoDTO();
            $objRestricaoDTO->setNumIdOrgao($objOrgaoDTORestricao->getNumIdOrgao());
            $objRestricaoDTO->setNumIdUnidade(null);
            $arrObjTipoProcedRestricaoDTO[] = $objRestricaoDTO;
          } else {
            foreach ($arrSiglasUnidadeRestricao as $strSiglaUnidadeRestricao) {
              $objUnidadeDTORestricao = $this->resolverUnidade($objOrgaoDTORestricao->getNumIdOrgao(), $strSiglaUnidadeRestricao);
              if ($objUnidadeDTORestricao === null) {
                throw new InfraException('Unidade "' . $strSiglaUnidadeRestricao . '" não encontrada no órgão "' . $strSiglaOrgaoRestricao . '".');
              }
              $objRestricaoDTO = new TipoProcedRestricaoDTO();
              $objRestricaoDTO->setNumIdOrgao($objOrgaoDTORestricao->getNumIdOrgao());
              $objRestricaoDTO->setNumIdUnidade($objUnidadeDTORestricao->getNumIdUnidade());
              $arrObjTipoProcedRestricaoDTO[] = $objRestricaoDTO;
            }
          }
        }

        // Niveis de acesso permitidos (obrigatorio pelo menos um)
        $arrTokensNiveis = $this->parseListaPontoVirgula($strNiveisPermitidos);
        if (count($arrTokensNiveis) === 0) {
          throw new InfraException('Níveis de acesso permitidos não informados.');
        }
        $arrObjNivelAcessoPermitidoDTO = array();
        foreach ($arrTokensNiveis as $strTokenNivel) {
          $objNivelDTO = new NivelAcessoPermitidoDTO();
          $objNivelDTO->setNumIdNivelAcessoPermitido(null);
          $objNivelDTO->setStrStaNivelAcesso($this->resolverStaNivelAcesso($strTokenNivel));
          $arrObjNivelAcessoPermitidoDTO[] = $objNivelDTO;
        }

        if ($strNivelSugerido === '') {
          throw new InfraException('Sugestão para o nível de acesso não informada.');
        }
        $strStaNivelAcessoSugestao = $this->resolverStaNivelAcesso($strNivelSugerido);

        $numIdHipoteseLegalSugestao = null;
        if ($strHipoteseLegal !== '') {
          $numIdHipoteseLegalSugestao = $this->resolverHipoteseLegal($strHipoteseLegal)->getNumIdHipoteseLegal();
        }

        $objTipoProcedimentoDTO = new TipoProcedimentoDTO();
        $objTipoProcedimentoDTO->setNumIdTipoProcedimento(null);
        $objTipoProcedimentoDTO->setNumIdHipoteseLegalSugestao($numIdHipoteseLegalSugestao);
        $objTipoProcedimentoDTO->setNumIdPlanoTrabalho(null);
        $objTipoProcedimentoDTO->setStrNome($strNome);
        $objTipoProcedimentoDTO->setStrDescricao($strDescricao !== '' ? $strDescricao : null);
        $objTipoProcedimentoDTO->setStrStaNivelAcessoSugestao($strStaNivelAcessoSugestao);
        $objTipoProcedimentoDTO->setStrStaGrauSigiloSugestao($strGrauSigilo !== '' ? $strGrauSigilo : null);
        $objTipoProcedimentoDTO->setStrSinAtivo('S');
        $objTipoProcedimentoDTO->setStrSinInterno($this->resolverSinalizadorSimNao($strInternoSistema));
        $objTipoProcedimentoDTO->setStrSinOuvidoria($strSinOuvidoria);
        $objTipoProcedimentoDTO->setStrSinOuvidoriaAnonimo($this->resolverSinalizadorSimNao($strContatoAnonimo));
        $objTipoProcedimentoDTO->setStrSinIndividual($this->resolverSinalizadorSimNao($strProcessoUnico));
        $objTipoProcedimentoDTO->setArrObjRelTipoProcedimentoAssuntoDTO($arrObjRelTipoProcedimentoAssuntoDTO);
        $objTipoProcedimentoDTO->setArrObjTipoProcedRestricaoDTO($arrObjTipoProcedRestricaoDTO);
        $objTipoProcedimentoDTO->setArrObjNivelAcessoPermitidoDTO($arrObjNivelAcessoPermitidoDTO);

        $objTipoProcedimentoRN->cadastrarRN0265($objTipoProcedimentoDTO);

        $arrResultado[] = $this->linhaResultado($numLinha, self::STA_OK, 'Tipo de Processo "' . $strNome . '" cadastrado.');
      } catch (Exception $e) {
        $arrResultado[] = $this->linhaResultado($numLinha, self::STA_ERRO, $this->obterMensagemErro($e));
      }
    }
    return array('resultado' => $arrResultado, 'total' => $arrLoteInfo['total'], 'processadas' => count($arrLoteInfo['linhas']));
  }

  private function obterMensagemErro(Exception $e): string {
    $strMensagem = ($e instanceof InfraException) ? (string)$e : $e->getMessage();
    return $strMensagem !== '' ? $strMensagem : get_class($e);
  }

  private function linhaResultado(int $numLinha, string $strStatus, string $strMensagem): array {
    return array('linha' => $numLinha, 'status' => $strStatus, 'mensagem' => $strMensagem);
  }
}

?>
