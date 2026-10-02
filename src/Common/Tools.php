<?php

namespace NFePHP\NFSeGinfes\Common;

/**
 * Auxiar Tools Class for comunications with NFSe webserver in Ginfes Standard
 *
 * @category  NFePHP
 * @package   NFePHP\NFSeGinfes
 * @copyright NFePHP Copyright (c) 2020
 * @license   http://www.gnu.org/licenses/lgpl.txt LGPLv3+
 * @license   https://opensource.org/licenses/MIT MIT
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 * @author    Cleiton Perin <cperin20 at gmail dot com>
 * @link      http://github.com/nfephp-org/sped-nfse-ginfes for the canonical source repository
 */

use NFePHP\Common\Certificate;
use NFePHP\Common\DOMImproved as Dom;
use NFePHP\NFSeGinfes\Common\Soap\SoapCurl;
use NFePHP\NFSeGinfes\Common\Soap\SoapInterface;

class Tools
{
    public $lastRequest;

    /** @var array HTTP headers used by the last SOAP request. */
    public $lastRequestHeader = [];

    protected $config;
    protected $prestador;
    protected $certificate;
    protected $wsobj;
    protected $soap;
    protected $environment;
    protected $version = "3";

    /**
     * Constructor
     * @param string $config
     * @param Certificate $cert
     */
    public function __construct($config, Certificate $cert)
    {
        $this->config = json_decode($config);
        $this->certificate = $cert;
        $this->wsobj = $this->loadWsobj($this->config->cmun);
        $this->environment = 'homologacao';
        if ($this->config->tpamb === 1) {
            $this->environment = 'producao';
        }
    }

    /**
     * load webservice parameters
     * @param string $cmun
     * @return object
     * @throws \Exception
     */
    protected function loadWsobj($cmun)
    {
        $path = realpath(__DIR__ . "/../../storage/urls_webservices.json");
        $urls = json_decode(file_get_contents($path), true);
        if (empty($urls[$cmun])) {
            throw new \Exception("Não localizado parâmetros para esse municipio.");
        }
        return (object)$urls[$cmun];
    }


    /**
     * SOAP communication dependency injection
     * @param SoapInterface $soap
     */
    public function loadSoapClass(SoapInterface $soap)
    {
        $this->soap = $soap;
    }

    public function setVersion($version)
    {
        $this->version = $version;
    }

    /**
     * Sign XML passing in content
     * @param string $content
     * @param string $tagname
     * @param string $mark
     * @return string XML signed
     */
    public function sign($content, $tagname, $mark)
    {
        $xml = Signer::sign(
            $this->certificate,
            $content,
            $tagname,
            $mark
        );
        $dom = new Dom('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($xml);
        return $dom->saveXML($dom->documentElement);
    }

    /**
     * Send message to webservice
     * @param string $message
     * @param string $operation
     * @return string XML response from webservice
     */
    public function send($message, $operation)
    {
        $action = $operation;
        $url = $this->wsobj->homologacao;
        if ($this->environment === 'producao') {
            $url = $this->wsobj->producao;
        }
        if (empty($url)) {
            throw new \Exception("Não está registrada a URL para o ambiente "
                . "de {$this->environment} desse municipio.");
        }
        $request = $this->createSoapRequest($message, $operation);
        $this->lastRequest = $request;

        $contentType = 'application/soap+xml;charset=utf-8';
        if (strpos($request, 'xmlns:nfse="http://nfse.abrasf.org.br"') !== false) {
            $action = 'http://nfse.abrasf.org.br/' . $operation;
            $contentType = 'text/xml;charset=utf-8';
        }

        if (empty($this->soap)) {
            $this->soap = new SoapCurl($this->certificate);
        }
        $msgSize = strlen($request);
        $parameters = [
            "Content-Type: $contentType",
            "SOAPAction: \"$action\"",
            "Content-length: $msgSize"
        ];
        $this->lastRequestHeader = $parameters;
        $response = (string)$this->soap->send(
            $operation,
            $url,
            $action,
            $request,
            $parameters
        );
        return $this->extractContentFromResponse($response, $operation);
    }

    /**
     * Extract xml response from CDATA outputXML tag
     * @param string $response Return from webservice
     * @return string XML extracted from response
     */
    protected function extractContentFromResponse($response, $operation)
    {
        $dom = new Dom('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($response);
        if (!empty($dom->getElementsByTagName('return')->item(0))) {
            $node = $dom->getElementsByTagName('return')->item(0);
            return $node->textContent;
        }
        if (!empty($dom->getElementsByTagName('outputXML')->item(0))) {
            $node = $dom->getElementsByTagName('outputXML')->item(0);
            return trim($node->textContent);
        }
        return $response;
    }

    /**
     * Converts a SOAP response or an extracted NFSe XML response to an array.
     * Repeated elements are represented as indexed arrays.
     *
     * @param string $response
     * @return array
     */
    public function responseToArray($response)
    {
        $xml = $this->extractContentFromResponse($response, '');
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NOBLANKS | LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        if (!$loaded || !$dom->documentElement) {
            $message = !empty($errors) ? trim($errors[0]->message) : 'conteúdo vazio';
            throw new \InvalidArgumentException('Não foi possível converter o XML de resposta: ' . $message);
        }
        return [$dom->documentElement->localName => $this->elementToArray($dom->documentElement)];
    }

    /** @return array|string */
    protected function elementToArray(\DOMElement $element)
    {
        $result = [];
        if ($element->hasAttributes()) {
            foreach ($element->attributes as $attribute) {
                if (strpos($attribute->nodeName, 'xmlns') === 0) {
                    continue;
                }
                $result['@attributes'][$attribute->localName] = $attribute->nodeValue;
            }
        }
        foreach ($element->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $name = $child->localName;
            $value = $this->elementToArray($child);
            if (!array_key_exists($name, $result)) {
                $result[$name] = $value;
                continue;
            }
            if (!is_array($result[$name]) || !array_key_exists(0, $result[$name])) {
                $result[$name] = [$result[$name]];
            }
            $result[$name][] = $value;
        }
        if (empty($result)) {
            return trim($element->textContent);
        }
        return $result;
    }

    /**
     * Build SOAP request
     * @param string $message
     * @param string $operation
     * @return string XML SOAP request
     */
    protected function createSoapRequest($message, $operation)
    {
        $cabecalho = "<ns2:cabecalho versao=\"{$this->wsobj->version}\" "
            . "xmlns:ns2=\"http://www.ginfes.com.br/cabecalho_v03.xsd\">"
            . "<versaoDados>{$this->wsobj->version}</versaoDados>"
            . "</ns2:cabecalho>";

        $ns1 = "{$this->environment}_soapns";
        $ns1 = $this->wsobj->$ns1;

        $env = "<soapenv:Envelope xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\">"
            . "<soapenv:Header/>"
            . "<soapenv:Body>"
            . "<ns1:$operation xmlns:ns1=\"{$ns1}\">";
        if ($this->version == '3') {
            $env .= "<arg0>"
                . $cabecalho
                . "</arg0>"
                . "<arg1>"
                . $message
                . "</arg1>";
        } else {
            $env .= "<arg0>"
                . $message
                . "</arg0>";
        }
        $env .= "</ns1:$operation>"
            . "</soapenv:Body>"
            . "</soapenv:Envelope>";

        return $env;
    }
}
