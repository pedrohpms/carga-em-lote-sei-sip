<?
/**
 * Script de instalação/atualização do lado SIP do módulo Carga em Lote (banco do SIP): perfil
 * MD_CEL, recursos por operação, itens de menu e regra de auditoria.
 *
 * O pacote traz um módulo para cada sistema (SEI e SIP) e todos os recursos ficam no banco do
 * SIP, então este único script instala nos dois sistemas: no sistema SEI, a tela e as 4 cargas do
 * módulo SEI; no sistema SIP, a tela e as 4 cargas do módulo SIP. Rodar DEPOIS do
 * sei/scripts/sei_atualizar_versao_modulo_cel.php.
 *
 * Estrutura no padrão dos módulos oficiais (referência: sip_atualizar_versao_modulo_ia.php do
 * mod-sei-ia): classe *AtualizadorSipRN extends InfraRN, switch sobre a versão instalada e um
 * método instalarv* por versão. atualizarNumeroVersao() é a ÚLTIMA ação de cada instalarv*.
 *
 * A versão 2.0.0 substitui o instalador anterior (scripts/instalar.php, versão 1.0.0, parâmetros
 * CARGA_EM_LOTE_VERSAO e CARGA_EM_LOTE_SEI_VERSAO). Se encontrar o perfil e o recurso da versão
 * anterior, RENOMEIA (não recria), preservando as permissões já concedidas e o item de menu. Não
 * apaga nada: os dois parâmetros antigos ficam no banco, sem uso, e podem ser removidos à mão.
 *
 * Uso (CLI, dentro do container httpd; pede usuário e senha de um usuário do banco do SIP):
 *   docker exec -it httpd php /opt/sip/scripts/sip_atualizar_versao_modulo_cel.php
 */
require_once dirname(__FILE__) . '/../web/Sip.php';

class MdCelAtualizadorSipRN extends InfraRN
{

    private $numSeg = 0;
    private $versaoAtualDesteModulo = '2.0.0';
    private $nomeDesteModulo = 'MÓDULO CARGA EM LOTE';
    private $nomeParametroModulo = 'MD_CEL_VERSAO';
    private $historicoVersoes = ['2.0.0'];

    private $strPerfil = 'MD_CEL';
    private $strRecursoTela = 'md_cel_lote';

    // Recurso => descrição. Um por operação: quem monta o perfil escolhe quais cargas cada operador roda.
    // Todos são de escrita, então todos entram na regra de auditoria (a tela, md_cel_lote, não entra).
    private $arrRecursosSei = [
        'md_cel_unidade_alterar' => 'Carga em Lote (SEI): dados complementares de unidade',
        'md_cel_contato_alterar' => 'Carga em Lote (SEI): contato de usuários',
        'md_cel_assunto_cadastrar' => 'Carga em Lote (SEI): assuntos',
        'md_cel_tipo_procedimento_cadastrar' => 'Carga em Lote (SEI): tipos de processo',
    ];
    private $arrRecursosSip = [
        'md_cel_unidade_cadastrar' => 'Carga em Lote (SIP): unidades',
        'md_cel_hierarquia_cadastrar' => 'Carga em Lote (SIP): hierarquia',
        'md_cel_usuario_cadastrar' => 'Carga em Lote (SIP): usuários',
        'md_cel_permissao_cadastrar' => 'Carga em Lote (SIP): primeiras permissões',
    ];

    public function __construct()
    {
        parent::__construct();
    }

    protected function inicializarObjInfraIBanco()
    {
        return BancoSip::getInstance();
    }

    protected function inicializar($strTitulo)
    {
        session_start();
        SessaoSip::getInstance(false);

        ini_set('max_execution_time', '0');
        ini_set('memory_limit', '-1');
        @ini_set('implicit_flush', '1');
        ob_implicit_flush();

        InfraDebug::getInstance()->setBolLigado(true);
        InfraDebug::getInstance()->setBolDebugInfra(true);
        InfraDebug::getInstance()->setBolEcho(true);
        InfraDebug::getInstance()->limpar();

        $this->numSeg = InfraUtil::verificarTempoProcessamento();

        $this->logar($strTitulo);
    }

    protected function logar($strMsg)
    {
        InfraDebug::getInstance()->gravar($strMsg);
        flush();
    }

    protected function finalizar($strMsg = null, $bolErro = false)
    {
        if (!$bolErro) {
            $this->numSeg = InfraUtil::verificarTempoProcessamento($this->numSeg);
            $this->logar('TEMPO TOTAL DE EXECUÇÃO: ' . $this->numSeg . ' s');
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
            $this->inicializar('INICIANDO A INSTALAÇÃO/ATUALIZAÇÃO DO ' . $this->nomeDesteModulo . ' NO SIP VERSÃO ' . SIP_VERSAO);

            //checando BDs suportados
            if (
                !(BancoSip::getInstance() instanceof InfraMySql) &&
                !(BancoSip::getInstance() instanceof InfraSqlServer) &&
                !(BancoSip::getInstance() instanceof InfraPostgreSql) &&
                !(BancoSip::getInstance() instanceof InfraOracle)
            ) {
                $this->finalizar('BANCO DE DADOS NÃO SUPORTADO: ' . get_parent_class(BancoSip::getInstance()), true);
            }

            //testando versao do framework
            $numVersaoInfraRequerida = '2.29.0';
            if (version_compare(VERSAO_INFRA, $numVersaoInfraRequerida) < 0) {
                $this->finalizar('VERSÃO DO FRAMEWORK PHP INCOMPATÍVEL (VERSÃO ATUAL ' . VERSAO_INFRA . ', SENDO REQUERIDA VERSÃO IGUAL OU SUPERIOR A ' . $numVersaoInfraRequerida . ')', true);
            }

            //checando permissoes na base de dados
            $objInfraMetaBD = new InfraMetaBD(BancoSip::getInstance());

            if (count($objInfraMetaBD->obterTabelas('sip_teste')) == 0) {
                BancoSip::getInstance()->executarSql('CREATE TABLE sip_teste (id ' . $objInfraMetaBD->tipoNumero() . ' null)');
            }
            BancoSip::getInstance()->executarSql('DROP TABLE sip_teste');

            $objInfraParametro = new InfraParametro(BancoSip::getInstance());

            $strVersaoModulo = $objInfraParametro->getValor($this->nomeParametroModulo, false);

            switch ($strVersaoModulo) {
                case '':
                    $this->instalarv200();
                    break;
                default:
                    $this->finalizar('A VERSÃO MAIS ATUAL DO ' . $this->nomeDesteModulo . ' (v' . $this->versaoAtualDesteModulo . ') JÁ ESTÁ INSTALADA.');
                    break;
            }

            $this->logar('SCRIPT EXECUTADO EM: ' . date('d/m/Y H:i:s'));
            $this->finalizar('FIM');
            InfraDebug::getInstance()->setBolDebugInfra(true);
        } catch (Exception $e) {
            InfraDebug::getInstance()->setBolLigado(true);
            InfraDebug::getInstance()->setBolDebugInfra(true);
            InfraDebug::getInstance()->setBolEcho(true);
            throw new InfraException('Erro instalando/atualizando versão.', $e);
        }
    }

    /**
     * Versão 2.0.0: perfil MD_CEL, recursos por operação, itens de menu e regra de auditoria, nos
     * sistemas SEI (menu dentro de Administração) e SIP (menu na raiz, com ícone). Idempotente:
     * cada objeto só é criado se ainda não existir.
     */
    protected function instalarv200()
    {
        $nmVersao = '2.0.0';

        $this->logar('EXECUTANDO A INSTALAÇÃO/ATUALIZAÇÃO DA VERSÃO ' . $nmVersao . ' DO ' . $this->nomeDesteModulo . ' NA BASE DO SIP');

        $this->migrarInstalacaoAnterior();

        $this->instalarNoSistema('SEI', $this->arrRecursosSei, true, null);
        $this->instalarNoSistema('SIP', $this->arrRecursosSip, false, 'carga.svg');

        $this->atualizarNumeroVersao($nmVersao);
    }

    /**
     * Instala perfil, recursos, item de menu e regra de auditoria em um sistema.
     *
     * @param string $strSiglaSistema SEI ou SIP
     * @param array $arrRecursos recurso => descrição, das cargas do módulo daquele sistema
     * @param bool $bolDentroDeAdministracao true: item dentro de Administração; false: na raiz do menu
     * @param string|null $strIcone só o nível raiz do menu mostra ícone
     */
    private function instalarNoSistema($strSiglaSistema, $arrRecursos, $bolDentroDeAdministracao, $strIcone)
    {
        $numIdSistema = $this->obterIdSistema($strSiglaSistema);
        $numIdMenu = $this->obterIdMenuPrincipal($numIdSistema);
        $numIdItemMenuPai = $bolDentroDeAdministracao ? $this->obterIdItemMenuAdministracao($numIdSistema) : null;

        $this->logar('CRIANDO PERFIL ' . $this->strPerfil . ' NO SISTEMA ' . $strSiglaSistema);
        $numIdPerfil = $this->adicionarPerfil($numIdSistema, $this->strPerfil, 'Operador da Carga em Lote (' . $strSiglaSistema . ')')->getNumIdPerfil();

        $this->logar('CRIANDO e VINCULANDO RECURSO DA TELA A PERFIL - Carga em Lote (' . $strSiglaSistema . ') EM ' . $this->strPerfil);
        $objRecursoDTO = $this->adicionarRecursoPerfil($numIdSistema, $numIdPerfil, $this->strRecursoTela, null, 'Carga em Lote (' . $strSiglaSistema . ')');

        $this->logar('CRIANDO e VINCULANDO ITEM DE MENU A PERFIL - Carga em Lote (' . $strSiglaSistema . ')');
        $this->adicionarItemMenu($numIdSistema, $numIdPerfil, $numIdMenu, $numIdItemMenuPai, $objRecursoDTO->getNumIdRecurso(), 'Carga em Lote', 0, $strIcone);

        $this->logar('CRIANDO e VINCULANDO RECURSOS DAS CARGAS A PERFIL EM ' . $this->strPerfil);
        foreach ($arrRecursos as $strNomeRecurso => $strDescricao) {
            $this->adicionarRecursoPerfil($numIdSistema, $numIdPerfil, $strNomeRecurso, null, $strDescricao);
        }

        $this->_cadastrarAuditoria($numIdSistema, array_map(function ($strNome) {
            return "'" . $strNome . "'";
        }, array_keys($arrRecursos)));
    }

    /**
     * Instalação da versão 1.0.0 (scripts/instalar.php, InfraScriptVersao): perfil "Carga em Lote
     * (SEI)" e recurso md_carga_em_lote_sei no sistema SEI; perfil "Carga em Lote" e recurso
     * md_carga_em_lote no sistema SIP. Renomear preserva os ids, então permissões concedidas,
     * item de menu e vínculos do perfil continuam valendo.
     */
    private function migrarInstalacaoAnterior()
    {
        $arrAnteriores = [
            ['SEI', 'Carga em Lote (SEI)', 'md_carga_em_lote_sei'],
            ['SIP', 'Carga em Lote', 'md_carga_em_lote'],
        ];

        foreach ($arrAnteriores as $arrItem) {
            $numIdSistema = $this->obterIdSistema($arrItem[0]);
            $this->renomearPerfil($numIdSistema, $arrItem[1], $this->strPerfil, 'Operador da Carga em Lote (' . $arrItem[0] . ')');
            $this->renomearRecurso($numIdSistema, $arrItem[2], $this->strRecursoTela, 'Carga em Lote (' . $arrItem[0] . ')');
        }
    }

    private function renomearPerfil($numIdSistema, $strNomeAntigo, $strNomeNovo, $strDescricao)
    {
        $objPerfilRN = new PerfilRN();

        $objPerfilDTO = new PerfilDTO();
        $objPerfilDTO->setBolExclusaoLogica(false);
        $objPerfilDTO->retNumIdPerfil();
        $objPerfilDTO->setNumIdSistema($numIdSistema);
        $objPerfilDTO->setStrNome($strNomeNovo);
        if ($objPerfilRN->consultar($objPerfilDTO) != null) {
            return;
        }

        $objPerfilDTO = new PerfilDTO();
        $objPerfilDTO->setBolExclusaoLogica(false);
        $objPerfilDTO->retTodos();
        $objPerfilDTO->setNumIdSistema($numIdSistema);
        $objPerfilDTO->setStrNome($strNomeAntigo);
        $objPerfilDTO = $objPerfilRN->consultar($objPerfilDTO);

        if ($objPerfilDTO != null) {
            $this->logar('RENOMEANDO PERFIL "' . $strNomeAntigo . '" PARA "' . $strNomeNovo . '"');
            $objPerfilDTO->setStrNome($strNomeNovo);
            $objPerfilDTO->setStrDescricao($strDescricao);
            $objPerfilRN->alterar($objPerfilDTO);
        }
    }

    private function renomearRecurso($numIdSistema, $strNomeAntigo, $strNomeNovo, $strDescricao)
    {
        $objRecursoRN = new RecursoRN();

        $objRecursoDTO = new RecursoDTO();
        $objRecursoDTO->setBolExclusaoLogica(false);
        $objRecursoDTO->retNumIdRecurso();
        $objRecursoDTO->setNumIdSistema($numIdSistema);
        $objRecursoDTO->setStrNome($strNomeNovo);
        if ($objRecursoRN->consultar($objRecursoDTO) != null) {
            return;
        }

        $objRecursoDTO = new RecursoDTO();
        $objRecursoDTO->setBolExclusaoLogica(false);
        $objRecursoDTO->retNumIdRecurso();
        $objRecursoDTO->setNumIdSistema($numIdSistema);
        $objRecursoDTO->setStrNome($strNomeAntigo);
        $objRecursoDTO = $objRecursoRN->consultar($objRecursoDTO);

        if ($objRecursoDTO != null) {
            $this->logar('RENOMEANDO RECURSO "' . $strNomeAntigo . '" PARA "' . $strNomeNovo . '"');
            $objRecursoDTO->setStrNome($strNomeNovo);
            $objRecursoDTO->setStrDescricao($strDescricao);
            $objRecursoDTO->setStrCaminho('controlador.php?acao=' . $strNomeNovo);
            $objRecursoRN->alterar($objRecursoDTO);
        }
    }

    private function obterIdSistema($strSigla)
    {
        $objSistemaDTO = new SistemaDTO();
        $objSistemaDTO->retNumIdSistema();
        $objSistemaDTO->setStrSigla($strSigla);

        $objSistemaRN = new SistemaRN();
        $objSistemaDTO = $objSistemaRN->consultar($objSistemaDTO);

        if ($objSistemaDTO == null) {
            throw new InfraException('Sistema ' . $strSigla . ' não encontrado.');
        }

        return $objSistemaDTO->getNumIdSistema();
    }

    private function obterIdMenuPrincipal($numIdSistema)
    {
        $objMenuDTO = new MenuDTO();
        $objMenuDTO->retNumIdMenu();
        $objMenuDTO->setNumIdSistema($numIdSistema);
        $objMenuDTO->setStrNome('Principal');

        $objMenuRN = new MenuRN();
        $objMenuDTO = $objMenuRN->consultar($objMenuDTO);

        if ($objMenuDTO == null) {
            throw new InfraException('Menu Principal do sistema (id ' . $numIdSistema . ') não encontrado.');
        }

        return $objMenuDTO->getNumIdMenu();
    }

    private function obterIdItemMenuAdministracao($numIdSistema)
    {
        $objItemMenuDTO = new ItemMenuDTO();
        $objItemMenuDTO->retNumIdItemMenu();
        $objItemMenuDTO->setNumIdSistema($numIdSistema);
        $objItemMenuDTO->setStrRotulo('Administração');

        $objItemMenuRN = new ItemMenuRN();
        $objItemMenuDTO = $objItemMenuRN->consultar($objItemMenuDTO);

        if ($objItemMenuDTO == null) {
            throw new InfraException('Item de menu Administração do sistema (id ' . $numIdSistema . ') não encontrado.');
        }

        return $objItemMenuDTO->getNumIdItemMenu();
    }

    private function adicionarPerfil($numIdSistema, $strNome, $strDescricao)
    {
        $objPerfilRN = new PerfilRN();

        $objPerfilDTO = new PerfilDTO();
        $objPerfilDTO->setBolExclusaoLogica(false);
        $objPerfilDTO->retNumIdPerfil();
        $objPerfilDTO->setNumIdSistema($numIdSistema);
        $objPerfilDTO->setStrNome($strNome);
        $objPerfilDTO = $objPerfilRN->consultar($objPerfilDTO);

        if ($objPerfilDTO == null) {
            $objPerfilDTO = new PerfilDTO();
            $objPerfilDTO->setNumIdPerfil(null);
            $objPerfilDTO->setNumIdSistema($numIdSistema);
            $objPerfilDTO->setStrNome($strNome);
            $objPerfilDTO->setStrDescricao($strDescricao);
            $objPerfilDTO->setStrSinCoordenado('N');
            $objPerfilDTO->setStrSinAtivo('S');
            $objPerfilDTO->setStrSin2Fatores('N');
            $objPerfilDTO = $objPerfilRN->cadastrar($objPerfilDTO);
        }

        return $objPerfilDTO;
    }

    private function adicionarRecursoPerfil($numIdSistema, $numIdPerfil, $strNome, $strCaminho = null, $strDescricao = null)
    {

        $objRecursoDTO = new RecursoDTO();
        $objRecursoDTO->retNumIdRecurso();
        $objRecursoDTO->setNumIdSistema($numIdSistema);
        $objRecursoDTO->setStrNome($strNome);

        $objRecursoRN = new RecursoRN();
        $objRecursoDTO = $objRecursoRN->consultar($objRecursoDTO);

        if ($objRecursoDTO == null) {
            $objRecursoDTO = new RecursoDTO();
            $objRecursoDTO->setNumIdRecurso(null);
            $objRecursoDTO->setNumIdSistema($numIdSistema);
            $objRecursoDTO->setStrNome($strNome);
            $objRecursoDTO->setStrDescricao($strDescricao);

            if ($strCaminho == null) {
                $objRecursoDTO->setStrCaminho('controlador.php?acao=' . $strNome);
            } else {
                $objRecursoDTO->setStrCaminho($strCaminho);
            }
            $objRecursoDTO->setStrSinAtivo('S');
            $objRecursoDTO = $objRecursoRN->cadastrar($objRecursoDTO);
        }

        if ($numIdPerfil != null) {
            $objRelPerfilRecursoDTO = new RelPerfilRecursoDTO();
            $objRelPerfilRecursoDTO->setNumIdSistema($numIdSistema);
            $objRelPerfilRecursoDTO->setNumIdPerfil($numIdPerfil);
            $objRelPerfilRecursoDTO->setNumIdRecurso($objRecursoDTO->getNumIdRecurso());

            $objRelPerfilRecursoRN = new RelPerfilRecursoRN();

            if ($objRelPerfilRecursoRN->contar($objRelPerfilRecursoDTO) == 0) {
                $objRelPerfilRecursoRN->cadastrar($objRelPerfilRecursoDTO);
            }
        }

        return $objRecursoDTO;
    }

    private function adicionarItemMenu($numIdSistema, $numIdPerfil, $numIdMenu, $numIdItemMenuPai, $numIdRecurso, $strRotulo, $numSequencia, $strIcone = null)
    {

        $objItemMenuDTO = new ItemMenuDTO();
        $objItemMenuDTO->retNumIdItemMenu();
        $objItemMenuDTO->setNumIdMenu($numIdMenu);

        if ($numIdItemMenuPai == null) {
            $objItemMenuDTO->setNumIdMenuPai(null);
            $objItemMenuDTO->setNumIdItemMenuPai(null);
        } else {
            $objItemMenuDTO->setNumIdMenuPai($numIdMenu);
            $objItemMenuDTO->setNumIdItemMenuPai($numIdItemMenuPai);
        }

        $objItemMenuDTO->setNumIdSistema($numIdSistema);
        $objItemMenuDTO->setNumIdRecurso($numIdRecurso);
        $objItemMenuDTO->setStrRotulo($strRotulo);

        $objItemMenuRN = new ItemMenuRN();
        $objItemMenuDTO = $objItemMenuRN->consultar($objItemMenuDTO);

        if ($objItemMenuDTO == null) {
            $objItemMenuDTO = new ItemMenuDTO();
            $objItemMenuDTO->setNumIdItemMenu(null);
            $objItemMenuDTO->setNumIdMenu($numIdMenu);

            if ($numIdItemMenuPai == null) {
                $objItemMenuDTO->setNumIdMenuPai(null);
                $objItemMenuDTO->setNumIdItemMenuPai(null);
            } else {
                $objItemMenuDTO->setNumIdMenuPai($numIdMenu);
                $objItemMenuDTO->setNumIdItemMenuPai($numIdItemMenuPai);
            }

            $objItemMenuDTO->setNumIdSistema($numIdSistema);
            $objItemMenuDTO->setNumIdRecurso($numIdRecurso);
            $objItemMenuDTO->setStrRotulo($strRotulo);
            $objItemMenuDTO->setStrDescricao(null);
            $objItemMenuDTO->setNumSequencia($numSequencia);
            $objItemMenuDTO->setStrSinNovaJanela('N');
            $objItemMenuDTO->setStrSinAtivo('S');
            $objItemMenuDTO->setStrIcone($strIcone);

            $objItemMenuDTO = $objItemMenuRN->cadastrar($objItemMenuDTO);
        }

        if ($numIdPerfil != null && $numIdRecurso != null) {
            $objRelPerfilRecursoDTO = new RelPerfilRecursoDTO();
            $objRelPerfilRecursoDTO->setNumIdSistema($numIdSistema);
            $objRelPerfilRecursoDTO->setNumIdPerfil($numIdPerfil);
            $objRelPerfilRecursoDTO->setNumIdRecurso($numIdRecurso);

            $objRelPerfilRecursoRN = new RelPerfilRecursoRN();

            if ($objRelPerfilRecursoRN->contar($objRelPerfilRecursoDTO) == 0) {
                $objRelPerfilRecursoRN->cadastrar($objRelPerfilRecursoDTO);
            }

            $objRelPerfilItemMenuDTO = new RelPerfilItemMenuDTO();
            $objRelPerfilItemMenuDTO->setNumIdPerfil($numIdPerfil);
            $objRelPerfilItemMenuDTO->setNumIdSistema($numIdSistema);
            $objRelPerfilItemMenuDTO->setNumIdRecurso($numIdRecurso);
            $objRelPerfilItemMenuDTO->setNumIdMenu($numIdMenu);
            $objRelPerfilItemMenuDTO->setNumIdItemMenu($objItemMenuDTO->getNumIdItemMenu());

            $objRelPerfilItemMenuRN = new RelPerfilItemMenuRN();

            if ($objRelPerfilItemMenuRN->contar($objRelPerfilItemMenuDTO) == 0) {
                $objRelPerfilItemMenuRN->cadastrar($objRelPerfilItemMenuDTO);
            }
        }

        return $objItemMenuDTO;
    }

    /**
     * Cria (se não existir) a regra de auditoria MD_CEL no sistema e liga a ela os recursos
     * informados. Passar SÓ recursos de escrita, já entre aspas simples; recurso _listar,
     * _consultar ou _selecionar NUNCA entra. Ao final replica a regra para o sistema.
     *
     * @param int $numIdSistema
     * @param string[] $arrAuditoria nomes de recurso entre aspas simples
     * @return void
     */
    private function _cadastrarAuditoria($numIdSistema, $arrAuditoria)
    {
        $this->logar('CRIANDO REGRA DE AUDITORIA PARA OS RECURSOS DE ESCRITA DO MÓDULO');

        $objRegraAuditoriaDTO = new RegraAuditoriaDTO();
        $objRegraAuditoriaDTO->retNumIdRegraAuditoria();
        $objRegraAuditoriaDTO->setNumIdSistema($numIdSistema);
        $objRegraAuditoriaDTO->setStrDescricao('MD_CEL');

        $objRegraAuditoriaRN = new RegraAuditoriaRN();
        $countRgAuditoria = $objRegraAuditoriaRN->contar($objRegraAuditoriaDTO);
        $objRegraAuditoriaDTO = $objRegraAuditoriaRN->consultar($objRegraAuditoriaDTO);

        if ($countRgAuditoria == 0) {
            $objRegraAuditoriaDTO2 = new RegraAuditoriaDTO();
            $objRegraAuditoriaDTO2->retNumIdRegraAuditoria();
            $objRegraAuditoriaDTO2->setNumIdRegraAuditoria(null);
            $objRegraAuditoriaDTO2->setStrSinAtivo('S');
            $objRegraAuditoriaDTO2->setNumIdSistema($numIdSistema);
            $objRegraAuditoriaDTO2->setArrObjRelRegraAuditoriaRecursoDTO([]);
            $objRegraAuditoriaDTO2->setStrDescricao('MD_CEL');

            $objRegraAuditoriaDTO = $objRegraAuditoriaRN->cadastrar($objRegraAuditoriaDTO2);
        }

        $rs = BancoSip::getInstance()->consultarSql(
            'select id_recurso from recurso where id_sistema=' . $numIdSistema . ' and nome in (' . implode(', ', $arrAuditoria) . ')'
        );

        foreach ($rs as $recurso) {
            $numContar = BancoSip::getInstance()->consultarSql(
                'select count(*) as total from rel_regra_auditoria_recurso where id_regra_auditoria=' . $objRegraAuditoriaDTO->getNumIdRegraAuditoria() . ' and id_sistema=' . $numIdSistema . ' and id_recurso=' . $recurso['id_recurso']
            );
            if ($numContar[0]['total'] == 0) {
                BancoSip::getInstance()->executarSql('insert into rel_regra_auditoria_recurso (id_regra_auditoria, id_sistema, id_recurso) values (' . $objRegraAuditoriaDTO->getNumIdRegraAuditoria() . ', ' . $numIdSistema . ', ' . $recurso['id_recurso'] . ')');
            }
        }

        $objReplicacaoRegraAuditoriaDTO = new ReplicacaoRegraAuditoriaDTO();
        $objReplicacaoRegraAuditoriaDTO->setStrStaOperacao('A');
        $objReplicacaoRegraAuditoriaDTO->setNumIdRegraAuditoria($objRegraAuditoriaDTO->getNumIdRegraAuditoria());

        $objSistemaRN = new SistemaRN();
        $objSistemaRN->replicarRegraAuditoria($objReplicacaoRegraAuditoriaDTO);
    }

    /**
     * Grava o número de versão do módulo em infra_parametro do SIP (cria o parâmetro na primeira vez).
     *
     * @param string $parStrNumeroVersao
     * @return void
     */
    private function atualizarNumeroVersao($parStrNumeroVersao)
    {
        $objInfraParametroBD = new InfraParametroBD(BancoSip::getInstance());

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

        $this->logar('ATUALIZAÇÃO DA VERSÃO ' . $parStrNumeroVersao . ' DO ' . $this->nomeDesteModulo . ' REALIZADA COM SUCESSO NA BASE DO SIP');
    }
}

try {
    SessaoSip::getInstance(false);
    BancoSip::getInstance()->setBolScript(true);

    InfraScriptVersao::solicitarAutenticacao(BancoSip::getInstance());
    $objVersaoSipRN = new MdCelAtualizadorSipRN();
    $objVersaoSipRN->atualizarVersao();
    exit;
} catch (Exception $e) {
    echo (InfraException::inspecionar($e));
    try {
        LogSip::getInstance()->gravar(InfraException::inspecionar($e));
    } catch (Exception $e) {
    }
    exit(1);
}
