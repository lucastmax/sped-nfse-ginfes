<?php

namespace NFePHP\NFSeGinfes;

use NFePHP\Common\Certificate;
use NFePHP\Common\Validator;
use NFePHP\NFSeGinfes\Common\Signer;
use NFePHP\NFSeGinfes\Common\Tools as BaseTools;

/** Operations defined by the GISS ABRASF 2.04 schemas. */
class Tools extends BaseTools
{
    const ERRO_EMISSAO = 1;
    const SERVICO_NAO_CONCLUIDO = 2;

    protected $xsdpath;

    public function __construct($config, Certificate $cert)
    {
        parent::__construct($config, $cert);
        $this->xsdpath = realpath(__DIR__ . '/../storage/schemes');
    }

    /** Sends a batch synchronously (one to 50 RPS). */
    public function recepcionarLoteRps($arps, $lote)
    {
        return $this->enviarLote($arps, $lote, true);
    }

    /** Sends a batch asynchronously (one to 50 RPS). */
    public function enviarLoteRps($arps, $lote)
    {
        return $this->enviarLote($arps, $lote, false);
    }

    /** Generates an NFSe synchronously from one RPS. */
    public function gerarNfse(RpsInterface $rps)
    {
        $rps->config($this->config);
        $xml = $this->envelope(
            'GerarNfseEnvio',
            'gerar-nfse-envio-v2_04.xsd',
            $this->localRps($rps->render())
        );
        $xml = $this->sign($xml, 'InfDeclaracaoPrestacaoServico', 'Id', 'Rps');
        return $this->dispatch($xml, 'GerarNfse', 'gerar-nfse-envio-v2_04.xsd');
    }

    /**
     * Legacy status query. There is no equivalent operation in the 2.04 schema set.
     */
    public function consultarSituacaoLote($protocolo)
    {
        $xml = '<ConsultarSituacaoLoteRpsEnvio '
            . 'xmlns="http://www.ginfes.com.br/servico_consultar_situacao_lote_rps_envio_v03.xsd" '
            . 'xmlns:tipos="http://www.ginfes.com.br/tipos_v03.xsd">'
            . $this->prestadorXml(false)
            . '<Protocolo>' . $this->escape($protocolo) . '</Protocolo>'
            . '</ConsultarSituacaoLoteRpsEnvio>';
        $xml = $this->sign($xml, 'ConsultarSituacaoLoteRpsEnvio', '');
        return $this->dispatch($xml, 'ConsultarSituacaoLoteRpsV3', 'servico_consultar_situacao_lote_rps_envio_v03.xsd');
    }

    public function consultarLoteRps($protocolo)
    {
        $body = $this->prestadorXml()
            . '<Protocolo>' . $this->escape($protocolo) . '</Protocolo>';
        $xml = $this->envelope('ConsultarLoteRpsEnvio', 'consultar-lote-rps-envio-v2_04.xsd', $body);
        $xml = $this->sign($xml, 'ConsultarLoteRpsEnvio', '');
        return $this->dispatch($xml, 'ConsultarLoteRps', 'consultar-lote-rps-envio-v2_04.xsd');
    }

    /** Compatibility alias for the service-provided query. */
    public function consultarNfse($dini, $dfim, $tomadorCnpj = null, $tomadorCpf = null, $tomadorIM = null, $pagina = 1)
    {
        $tomador = null;
        if ($tomadorCnpj || $tomadorCpf) {
            $tomador = (object) ['cnpj' => $tomadorCnpj, 'cpf' => $tomadorCpf, 'im' => $tomadorIM];
        }
        return $this->consultarNfseServicoPrestado($dini, $dfim, $pagina, $tomador);
    }

    public function consultarNfsePorRps($numero, $serie, $tipo)
    {
        $body = '<IdentificacaoRps><tipos:Numero>' . $this->escape($numero) . '</tipos:Numero>'
            . '<tipos:Serie>' . $this->escape($serie) . '</tipos:Serie>'
            . '<tipos:Tipo>' . $this->escape($tipo) . '</tipos:Tipo></IdentificacaoRps>'
            . $this->prestadorXml();
        $xml = $this->envelope('ConsultarNfseRpsEnvio', 'consultar-nfse-rps-envio-v2_04.xsd', $body);
        $xml = $this->sign($xml, 'ConsultarNfseRpsEnvio', '');
        return $this->dispatch($xml, 'ConsultarNfsePorRps', 'consultar-nfse-rps-envio-v2_04.xsd');
    }

    public function consultarNfseFaixa($numeroInicial, $numeroFinal, $pagina = 1)
    {
        $body = $this->prestadorXml()
            . '<Faixa><NumeroNfseInicial>' . $this->escape($numeroInicial) . '</NumeroNfseInicial>'
            . '<NumeroNfseFinal>' . $this->escape($numeroFinal) . '</NumeroNfseFinal></Faixa>'
            . '<Pagina>' . $this->escape($pagina) . '</Pagina>';
        $xml = $this->envelope('ConsultarNfseFaixaEnvio', 'consultar-nfse-faixa-envio-v2_04.xsd', $body);
        $xml = $this->sign($xml, 'ConsultarNfseFaixaEnvio', '');
        return $this->dispatch($xml, 'ConsultarNfsePorFaixa', 'consultar-nfse-faixa-envio-v2_04.xsd');
    }

    /**
     * Query services provided. $criterio may be "emissao", "competencia", or a NFSe number.
     */
    public function consultarNfseServicoPrestado(
        $inicial,
        $final = null,
        $pagina = 1,
        $tomador = null,
        $intermediario = null,
        $criterio = 'emissao'
    ) {
        $body = $this->prestadorXml() . $this->filtroXml($inicial, $final, $criterio)
            . $this->pessoaXml('Tomador', $tomador)
            . $this->pessoaXml('Intermediario', $intermediario)
            . '<Pagina>' . $this->escape($pagina) . '</Pagina>';
        $xml = $this->envelope(
            'ConsultarNfseServicoPrestadoEnvio',
            'consultar-nfse-servico-prestado-envio-v2_04.xsd',
            $body
        );
        $xml = $this->sign($xml, 'ConsultarNfseServicoPrestadoEnvio', '');
        return $this->dispatch(
            $xml,
            'ConsultarNfseServicoPrestado',
            'consultar-nfse-servico-prestado-envio-v2_04.xsd'
        );
    }

    /**
     * Query services received. Optional parties are objects with cpf/cnpj and im properties.
     */
    public function consultarNfseServicoTomado(
        $inicial,
        $final = null,
        $pagina = 1,
        $prestador = null,
        $tomador = null,
        $intermediario = null,
        $criterio = 'emissao'
    ) {
        $body = $this->pessoaXml('Consulente', $this->config)
            . $this->filtroXml($inicial, $final, $criterio)
            . $this->pessoaXml('Prestador', $prestador)
            . $this->pessoaXml('Tomador', $tomador)
            . $this->pessoaXml('Intermediario', $intermediario)
            . '<Pagina>' . $this->escape($pagina) . '</Pagina>';
        $xml = $this->envelope(
            'ConsultarNfseServicoTomadoEnvio',
            'consultar-nfse-servico-tomado-envio-v2_04.xsd',
            $body
        );
        return $this->dispatch(
            $xml,
            'ConsultarNfseServicoTomado',
            'consultar-nfse-servico-tomado-envio-v2_04.xsd'
        );
    }

    public function cancelarNfse($numero, $codigo = self::ERRO_EMISSAO, $id = null, $versao = '2.04')
    {
        return $this->cancelarNfseV204($numero, $codigo, $id);
    }

    public function cancelarNfseV204($numero, $codigo = self::ERRO_EMISSAO, $id = null)
    {
        $id = $id ?: 'C' . $numero;
        $pedido = $this->pedidoCancelamentoXml($numero, $codigo, $id);
        $xml = $this->envelope('CancelarNfseEnvio', 'cancelar-nfse-envio-v2_04.xsd', '<Pedido>' . $pedido . '</Pedido>');
        $xml = $this->sign($xml, 'InfPedidoCancelamento', 'Id', 'Pedido');
        return $this->dispatch($xml, 'CancelarNfse', 'cancelar-nfse-envio-v2_04.xsd');
    }

    /** Backwards-compatible aliases now use the current schema. */
    public function cancelarNfseV3($numero, $codigo = self::ERRO_EMISSAO, $id = null)
    {
        return $this->cancelarNfseV204($numero, $codigo, $id);
    }

    public function cancelarNfseV2($numero)
    {
        return $this->cancelarNfseV204($numero, self::ERRO_EMISSAO);
    }

    public function substituirNfse(RpsInterface $rps, $numero, $codigo = self::ERRO_EMISSAO, $id = null)
    {
        $id = $id ?: 'S' . $numero;
        $rps->config($this->config);
        $body = '<SubstituicaoNfse Id="' . $this->escape($id) . '"><Pedido>'
            . $this->pedidoCancelamentoXml($numero, $codigo, 'C' . $numero)
            . '</Pedido>' . $this->localRps($rps->render()) . '</SubstituicaoNfse>';
        // The supplied XSD declares this (unusual) target namespace.
        $xml = $this->envelope(
            'SubstituirNfseEnvio',
            'substituir-nfse-envio-v2_04.xsd',
            $body,
            'gerar-nfse-resposta-v2_04.xsd'
        );
        $xml = $this->sign($xml, 'InfPedidoCancelamento', 'Id', 'Pedido');
        $xml = $this->sign($xml, 'InfDeclaracaoPrestacaoServico', 'Id', 'Rps');
        $xml = $this->sign($xml, 'SubstituicaoNfse', 'Id', 'SubstituirNfseEnvio');
        return $this->dispatch($xml, 'SubstituirNfse', 'substituir-nfse-envio-v2_04.xsd');
    }

    protected function enviarLote($arps, $lote, $sincrono)
    {
        $quantidade = count($arps);
        if ($quantidade < 1 || $quantidade > 50) {
            throw new \InvalidArgumentException('O lote deve conter entre 1 e 50 RPS.');
        }
        $lista = '';
        foreach ($arps as $rps) {
            if (!$rps instanceof RpsInterface) {
                throw new \InvalidArgumentException('Todos os itens do lote devem implementar RpsInterface.');
            }
            $rps->config($this->config);
            $fragment = trim($rps->render());
            $fragment = preg_replace(
                '/^<tipos:Rps>/',
                '<tipos:Rps xmlns:tipos="http://www.giss.com.br/tipos-v2_04.xsd">',
                $fragment,
                1
            );
            $fragment = $this->sign(
                $fragment,
                'InfDeclaracaoPrestacaoServico',
                'Id',
                'Rps'
            );
            $lista .= $this->withoutXmlDeclaration($fragment);
        }
        $root = $sincrono ? 'EnviarLoteRpsSincronoEnvio' : 'EnviarLoteRpsEnvio';
        $schema = $sincrono ? 'enviar-lote-rps-sincrono-envio-v2_04.xsd' : 'enviar-lote-rps-envio-v2_04.xsd';
        $operation = $sincrono ? 'RecepcionarLoteRpsSincrono' : 'RecepcionarLoteRps';
        $body = '<LoteRps Id="' . $this->escape($lote) . '" versao="2.04">'
            . '<tipos:NumeroLote>' . $this->escape($lote) . '</tipos:NumeroLote>'
            . $this->prestadorXml(true)
            . '<tipos:QuantidadeRps>' . $quantidade . '</tipos:QuantidadeRps>'
            . '<tipos:ListaRps>' . $lista . '</tipos:ListaRps></LoteRps>';
        $xml = $this->envelope($root, $schema, $body);
        $xml = $this->sign($xml, 'LoteRps', 'Id', $root);
       // dd($xml);
        return $this->dispatch($xml, $operation, $schema);
    }

    protected function pedidoCancelamentoXml($numero, $codigo, $id)
    {
        return '<tipos:InfPedidoCancelamento Id="' . $this->escape($id) . '">'
            . '<tipos:IdentificacaoNfse><tipos:Numero>' . $this->escape($numero) . '</tipos:Numero>'
            . '<tipos:CpfCnpj><tipos:Cnpj>' . $this->escape($this->config->cnpj) . '</tipos:Cnpj></tipos:CpfCnpj>'
            . '<tipos:InscricaoMunicipal>' . $this->escape($this->config->im) . '</tipos:InscricaoMunicipal>'
            . '<tipos:CodigoMunicipio>' . $this->escape($this->config->cmun) . '</tipos:CodigoMunicipio>'
            . '</tipos:IdentificacaoNfse><tipos:CodigoCancelamento>' . $this->escape($codigo)
            . '</tipos:CodigoCancelamento></tipos:InfPedidoCancelamento>';
    }

    protected function filtroXml($inicial, $final, $criterio)
    {
        if ($criterio === 'numero') {
            return '<NumeroNfse>' . $this->escape($inicial) . '</NumeroNfse>';
        }
        $tag = $criterio === 'competencia' ? 'PeriodoCompetencia' : 'PeriodoEmissao';
        return '<' . $tag . '><DataInicial>' . $this->escape($inicial) . '</DataInicial>'
            . '<DataFinal>' . $this->escape($final) . '</DataFinal></' . $tag . '>';
    }

    protected function prestadorXml($tipos = false)
    {
        return $this->pessoaXml('Prestador', $this->config, $tipos);
    }

    protected function pessoaXml($tag, $pessoa, $tipos = false)
    {
        if (!is_object($pessoa)) {
            return '';
        }
        $outerPrefix = $tipos ? 'tipos:' : '';
        $prefix = 'tipos:';
        $cnpj = isset($pessoa->cnpj) ? $pessoa->cnpj : null;
        $cpf = isset($pessoa->cpf) ? $pessoa->cpf : null;
        $im = isset($pessoa->im) ? $pessoa->im : (isset($pessoa->inscricaomunicipal) ? $pessoa->inscricaomunicipal : null);
        $xml = '<' . $outerPrefix . $tag . '><' . $prefix . 'CpfCnpj>';
        if ($cnpj) {
            $xml .= '<' . $prefix . 'Cnpj>' . $this->escape($cnpj) . '</' . $prefix . 'Cnpj>';
        } else {
            $xml .= '<' . $prefix . 'Cpf>' . $this->escape($cpf) . '</' . $prefix . 'Cpf>';
        }
        $xml .= '</' . $prefix . 'CpfCnpj>';
        if ($im !== null && $im !== '') {
            $xml .= '<' . $prefix . 'InscricaoMunicipal>' . $this->escape($im) . '</' . $prefix . 'InscricaoMunicipal>';
        }
        return $xml . '</' . $outerPrefix . $tag . '>';
    }

    protected function envelope($root, $schema, $body, $namespace = null)
    {
        $namespace = $namespace ?: $schema;
        return '<' . $root . ' xmlns="http://www.giss.com.br/' . $namespace . '" '
            . 'xmlns:tipos="http://www.giss.com.br/tipos-v2_04.xsd">' . $body . '</' . $root . '>';
    }

    /** Changes only the declaration wrapper to the operation's local namespace. */
    protected function localRps($xml)
    {
        $xml = trim($xml);
        $xml = preg_replace('/^<tipos:Rps>/', '<Rps>', $xml, 1);
        return preg_replace('/<\/tipos:Rps>$/', '</Rps>', $xml, 1);
    }

    protected function withoutXmlDeclaration($xml)
    {
        return trim(preg_replace('/^<\?xml[^?]+\?>\s*/', '', $xml));
    }

    public function sign($xml, $tag, $attribute, $parent = null)
    {
        return Signer::sign(
            $this->certificate,
            $xml,
            $tag,
            $attribute,
            OPENSSL_ALGO_SHA1,
            [true, false, null, null],
            $parent
        );
    }

    protected function dispatch($xml, $operation, $schema)
    {
        $xml = str_replace(['<?xml version="1.0"?>', '<?xml version="1.0" encoding="UTF-8"?>'], '', $xml);
        Validator::isValid($xml, $this->xsdpath . DIRECTORY_SEPARATOR . $schema);
        return $this->send($xml, $operation);
    }

    /** Builds the SOAP payload with the header supplied by the 2.04 package. */
    protected function createSoapRequest($message, $operation)
    {
        if ($operation === 'ConsultarSituacaoLoteRpsV3') {
            return parent::createSoapRequest($message, $operation);
        }
        $cabecalho = '<ns2:cabecalho versao="2.04" '
            . 'xmlns:ns2="http://www.giss.com.br/cabecalho-v2_04.xsd">'
            . '<ns2:versaoDados>2.04</ns2:versaoDados></ns2:cabecalho>';
        $request = $operation . 'Request';
        return '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soapenv:Header/><soapenv:Body><nfse:' . $request
            . ' xmlns:nfse="http://nfse.abrasf.org.br">'
            . '<nfseCabecMsg xmlns="">' . $this->escape($cabecalho) . '</nfseCabecMsg>'
            . '<nfseDadosMsg xmlns="">' . $this->escape($message) . '</nfseDadosMsg>'
            . '</nfse:' . $request . '></soapenv:Body></soapenv:Envelope>';
    }

    protected function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
