<?
/**
 * Script de instalacao/atualizacao do lado SEI do modulo Carga em Lote (banco do SEI).
 *
 * Estrutura no padrao dos modulos oficiais (referencia: sei_atualizar_versao_modulo_ia.php do
 * mod-sei-ia): classe *AtualizadorSeiRN extends InfraRN, switch com fallthrough sobre a versao
 * instalada e um metodo instalarv* por versao. Regras: atualizarNumeroVersao() e a ULTIMA acao de
 * cada instalarv*; versao NUNCA se edita depois de aplicada (acrescente instalarv110 e um case);
 * versao unica, igual a de getVersao() em MdCelSeiIntegracao.php e a do script do SIP.
 *
 * Recursos, perfil e menu ficam no banco do SIP: ver sip/scripts/sip_atualizar_versao_modulo_cel.php.
 * Rodar este script ANTES do do SIP.
 *
 * Uso (CLI, dentro do container httpd; pede usuario e senha de um usuario do banco com DDL):
 *   docker exec -it httpd php /opt/sei/scripts/sei_atualizar_versao_modulo_cel.php
 */
require_once dirname(__FILE__) . '/../web/SEI.php';

class MdCelAtualizadorSeiRN extends InfraRN
{

    private $numSeg = 0;
    private $versaoAtualDesteModulo = '2.0.0';
    private $nomeDesteModulo = 'MODULO CARGA EM LOTE';
    private $nomeParametroModulo = 'MD_CEL_VERSAO';
    private $historicoVersoes = ['2.0.0'];

    public function __construct()
    {
        parent::__construct();
    }

    protected function inicializarObjInfraIBanco()
    {
        return BancoSEI::getInstance();
    }

    private function inicializar($strTitulo)
    {
        session_start();
        SessaoSEI::getInstance(false);

        ini_set('max_execution_time', '0');
        ini_set('memory_limit', '-1');
        @ini_set('implicit_flush', '1');
        set_time_limit(0);
        ob_implicit_flush();

        InfraDebug::getInstance()->setBolLigado(true);
        InfraDebug::getInstance()->setBolDebugInfra(true);
        InfraDebug::getInstance()->setBolEcho(true);
        InfraDebug::getInstance()->limpar();

        $this->numSeg = InfraUtil::verificarTempoProcessamento();

        $this->logar($strTitulo);
    }

    private function logar($strMsg)
    {
        InfraDebug::getInstance()->gravar($strMsg);
        flush();
    }

    private function finalizar($strMsg = null, $bolErro = false)
    {
        if (!$bolErro) {
            $this->numSeg = InfraUtil::verificarTempoProcessamento($this->numSeg);
            $this->logar('TEMPO TOTAL DE EXECUCAO: ' . $this->numSeg . ' s');
        } else {
            $strMsg = 'ERRO: ' . $strMsg;
        }

        if ($strMsg != null) {
            $this->logar($strMsg);
        }

        InfraDebug::getInstance()->setBolLigado(false);
        InfraDebug::getInstance()->setBolDebugInfra(false);
        InfraDebug::getInstance()->setBolEcho(false);
        $this->numSeg = 0;
        die;
    }

    protected function atualizarVersaoConectado()
    {
        try {
            $this->inicializar('INICIANDO A INSTALACAO/ATUALIZACAO DO ' . $this->nomeDesteModulo . ' NO SEI VERSAO ' . SEI_VERSAO);

            //checando BDs suportados
            if (
                !(BancoSEI::getInstance() instanceof InfraMySql) &&
                !(BancoSEI::getInstance() instanceof InfraSqlServer) &&
                !(BancoSEI::getInstance() instanceof InfraPostgreSql) &&
                !(BancoSEI::getInstance() instanceof InfraOracle)
            ) {
                $this->finalizar('BANCO DE DADOS NAO SUPORTADO: ' . get_parent_class(BancoSEI::getInstance()), true);
            }

            //testando versao do framework
            $numVersaoInfraRequerida = '2.29.0';
            if (version_compare(VERSAO_INFRA, $numVersaoInfraRequerida) < 0) {
                $this->finalizar('VERSAO DO FRAMEWORK PHP INCOMPATIVEL (VERSAO ATUAL ' . VERSAO_INFRA . ', SENDO REQUERIDA VERSAO IGUAL OU SUPERIOR A ' . $numVersaoInfraRequerida . ')', true);
            }

            //checando permissoes na base de dados
            $objInfraMetaBD = new InfraMetaBD(BancoSEI::getInstance());

            if (count($objInfraMetaBD->obterTabelas('sei_teste')) == 0) {
                BancoSEI::getInstance()->executarSql('CREATE TABLE sei_teste (id ' . $objInfraMetaBD->tipoNumero() . ' null)');
            }

            BancoSEI::getInstance()->executarSql('DROP TABLE sei_teste');

            $objInfraParametro = new InfraParametro(BancoSEI::getInstance());

            $strVersaoModulo = $objInfraParametro->getValor($this->nomeParametroModulo, false);

            switch ($strVersaoModulo) {
                case '':
                    $this->instalarv200();
                    break;
                default:
                    $this->finalizar('A VERSAO MAIS ATUAL DO ' . $this->nomeDesteModulo . ' (v' . $this->versaoAtualDesteModulo . ') JA ESTA INSTALADA.');
                    break;
            }

            $this->logar('SCRIPT EXECUTADO EM: ' . date('d/m/Y H:i:s'));
            $this->finalizar('FIM');
            InfraDebug::getInstance()->setBolDebugInfra(true);
        } catch (Exception $e) {
            InfraDebug::getInstance()->setBolLigado(true);
            InfraDebug::getInstance()->setBolDebugInfra(true);
            InfraDebug::getInstance()->setBolEcho(true);
            throw new InfraException('Erro instalando/atualizando versao.', $e);
        }
    }

    /**
     * Versao 2.0.0. O modulo nao tem tabelas nem DTOs proprios: este script so registra a versao
     * em infra_parametro (MD_CEL_VERSAO) no banco do SEI. Perfil, recursos, menu e regra de
     * auditoria ficam no banco do SIP: ver sip/scripts/sip_atualizar_versao_modulo_cel.php.
     */
    protected function instalarv200()
    {
        $nmVersao = '2.0.0';

        $this->logar('EXECUTANDO A INSTALACAO/ATUALIZACAO DA VERSAO ' . $nmVersao . ' DO ' . $this->nomeDesteModulo . ' NA BASE DO SEI');

        $this->atualizarNumeroVersao($nmVersao);
    }

    /**
     * Grava o numero de versao do modulo em infra_parametro (cria o parametro na primeira vez).
     *
     * @param string $parStrNumeroVersao
     * @return void
     */
    private function atualizarNumeroVersao($parStrNumeroVersao)
    {
        $objInfraParametroBD = new InfraParametroBD(BancoSEI::getInstance());

        $objInfraParametroDTO = new InfraParametroDTO();
        $objInfraParametroDTO->retTodos();
        $objInfraParametroDTO->setStrNome($this->nomeParametroModulo);
        $objInfraParametroDTO = $objInfraParametroBD->consultar($objInfraParametroDTO);

        if ($objInfraParametroDTO == null) {
            $objInfraParametroDTO = new InfraParametroDTO();
            $objInfraParametroDTO->setStrNome($this->nomeParametroModulo);
            $objInfraParametroDTO->setStrValor($parStrNumeroVersao);
            $objInfraParametroBD->cadastrar($objInfraParametroDTO);
        } else {
            $objInfraParametroDTO->setStrValor($parStrNumeroVersao);
            $objInfraParametroBD->alterar($objInfraParametroDTO);
        }

        $this->logar('INSTALACAO/ATUALIZACAO DA VERSAO ' . $parStrNumeroVersao . ' DO ' . $this->nomeDesteModulo . ' REALIZADA COM SUCESSO NA BASE DO SEI');
    }
}

try {
    SessaoSEI::getInstance(false);
    BancoSEI::getInstance()->setBolScript(true);

    $arrConfig = ConfiguracaoSEI::getInstance()->getArrConfiguracoes();

    if (!isset($arrConfig['SEI']['Modulos'])) {
        throw new InfraException('PARAMETRO DE MODULOS NA CONFIGURACAO DO SEI NAO DECLARADO');
    } else {
        $arrModulos = $arrConfig['SEI']['Modulos'];
        if (!key_exists('MdCelSeiIntegracao', $arrModulos)) {
            throw new InfraException('MODULO CARGA EM LOTE NAO DECLARADO NA CONFIGURACAO DO SEI');
        }
    }

    if (!class_exists('MdCelSeiIntegracao')) {
        throw new InfraException('A CLASSE PRINCIPAL "MdCelSeiIntegracao" DO MODULO NAO FOI ENCONTRADA');
    }

    InfraScriptVersao::solicitarAutenticacao(BancoSEI::getInstance());
    $objVersaoSeiRN = new MdCelAtualizadorSeiRN();
    $objVersaoSeiRN->atualizarVersao();
    exit;
} catch (Exception $e) {
    echo (InfraException::inspecionar($e));
    try {
        LogSEI::getInstance()->gravar(InfraException::inspecionar($e));
    } catch (Exception $e) {
    }
    exit(1);
}
