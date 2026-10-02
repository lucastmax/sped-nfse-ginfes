<?php

namespace NFePHP\NFSeGinfes\Common;

use DOMNode;
use NFePHP\Common\DOMImproved as Dom;
use stdClass;

/** Builds an ABRASF/GISS 2.04 RPS XML fragment. */
class Factory
{
    protected $std;
    protected $dom;
    protected $rps;
    protected $config;

    public function __construct(stdClass $std)
    {
        $this->std = $std;
        $this->dom = new Dom('1.0', 'UTF-8');
        $this->dom->preserveWhiteSpace = false;
        $this->dom->formatOutput = false;
        $this->rps = $this->dom->createElement('tipos:Rps');
    }

    public function addConfig($config)
    {
        $this->config = $config;
    }

    public function render()
    {
        $inf = $this->dom->createElement('tipos:InfDeclaracaoPrestacaoServico');
        $this->id($inf, $this->get($this->std, 'id', $this->defaultRpsId()));
        $rps = $this->dom->createElement('tipos:Rps');
        $this->id($rps, $this->get($this->std, 'rpsid'));
        $this->identificacao($rps, $this->get($this->std, 'identificacaorps'));
        $this->add($rps, 'DataEmissao', $this->date($this->get($this->std, 'dataemissao')), true);
        $this->add($rps, 'Status', $this->get($this->std, 'status', 1), true);
        $this->identificacao($rps, $this->get($this->std, 'rpssubstituido'), 'RpsSubstituido');
        $inf->appendChild($rps);
        $this->add(
            $inf,
            'Competencia',
            $this->date($this->get($this->std, 'competencia', $this->get($this->std, 'dataemissao'))),
            true
        );
        $this->servico($inf);
        $this->prestador($inf);
        $this->tomador($inf);
        $this->intermediario($inf);
        $this->construcao($inf);
        $this->add($inf, 'RegimeEspecialTributacao', $this->get($this->std, 'regimeespecialtributacao'));
        $this->add($inf, 'OptanteSimplesNacional', $this->get($this->std, 'optantesimplesnacional'), true);
        $this->add($inf, 'IncentivoFiscal', $this->get($this->std, 'incentivofiscal', $this->get($this->std, 'incentivadorcultural')), true);
        $this->evento($inf);
        $this->add($inf, 'InformacoesComplementares', $this->get($this->std, 'informacoescomplementares'));
        $this->deducoes($inf);
        $this->rps->appendChild($inf);
        $this->dom->appendChild($this->rps);
        return str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $this->dom->saveXML());
    }

    protected function identificacao(DOMNode $parent, $data, $tag = 'IdentificacaoRps')
    {
        if (!is_object($data)) return;
        $node = $this->node($tag);
        $this->add($node, 'Numero', $this->get($data, 'numero'), true);
        $this->add($node, 'Serie', $this->get($data, 'serie'), true);
        $this->add($node, 'Tipo', $this->get($data, 'tipo'), true);
        $parent->appendChild($node);
    }

    protected function servico(DOMNode $parent)
    {
        $serv = $this->get($this->std, 'servico');
        if (!is_object($serv)) return;
        $val = $this->get($serv, 'valores', new stdClass());
        $node = $this->node('Servico');
        $values = $this->node('Valores');
        $valorDeducoes = $this->get($val, 'valordeducoes');
        if ($valorDeducoes === null) {
            $valorServicos = (float) $this->get($val, 'valorservicos', 0);
            $baseCalculo = $this->get($val, 'basecalculo');
            $valorDeducoes = $baseCalculo === null ? 0 : max(0, $valorServicos - (float) $baseCalculo);
        }
        foreach (
            [
                'ValorServicos' => 'valorservicos',
                'ValorPis' => 'valorpis',
                'ValorCofins' => 'valorcofins',
                'ValorInss' => 'valorinss',
                'ValorIr' => 'valorir',
                'ValorCsll' => 'valorcsll',
                'ValorIss' => 'valoriss'
            ] as $tag => $prop
        ) {
            $this->add($values, $tag, $this->money($this->get($val, $prop)), $tag === 'ValorServicos');
        }
        $valorServicos = $values->firstChild;
        if ($valorServicos) {
            $this->insertAfter($values, 'ValorDeducoes', $this->money($valorDeducoes), $valorServicos);
        }
        $valorIss = $this->lastElementByName($values, 'ValorIss');
        if ($valorIss) {
            $this->insertBefore($values, 'ValTotTributos', $this->money($this->get($val, 'valtottributos', 0)), $valorIss);
        } else {
            $this->add($values, 'ValTotTributos', $this->money($this->get($val, 'valtottributos', 0)));
        }
        $this->add($values, 'Aliquota', $this->aliquota($this->get($val, 'aliquota')));
        $this->add(
            $values,
            'DescontoIncondicionado',
            $this->money($this->get($val, 'descontoincondicionado'))
        );
        $this->add(
            $values,
            'DescontoCondicionado',
            $this->money($this->get($val, 'descontocondicionado'))
        );
        $this->tributos($values, $val);
        $this->ibscbs($values, $val);
        $node->appendChild($values);
        $issRetido = $this->get($serv, 'issretido', $this->get($val, 'issretido'));
        $this->add($node, 'IssRetido', $issRetido, true);
        foreach (
            [
                'ItemListaServico' => 'itemlistaservico',
                'CodigoCnae' => 'codigocnae',
                'CodigoTributacaoMunicipio' => 'codigotributacaomunicipio'
            ] as $tag => $prop
        ) {
            $this->add($node, $tag, $this->get($serv, $prop), in_array($tag, ['ItemListaServico', 'Discriminacao', 'CodigoMunicipio'], true));
        }
        $responsavelRetencao = $this->get($serv, 'responsavelretencao');
        if (($responsavelRetencao === null || $responsavelRetencao === '') && (string) $issRetido === '1') {
            $responsavelRetencao = 1;
        }
        if ($responsavelRetencao !== null && $responsavelRetencao !== '') {
            $itemLista = $this->lastElementByName($node, 'ItemListaServico');
            $this->insertBefore($node, 'ResponsavelRetencao', $responsavelRetencao, $itemLista);
        }
        $this->add($node, 'CodigoNbs', $this->get($serv, 'codigonbs', $this->get($this->get($val, 'ibscbs'), 'nbs')));
        foreach (['Discriminacao' => 'discriminacao', 'CodigoMunicipio' => 'codigomunicipio'] as $tag => $prop) {
            $this->add($node, $tag, $this->get($serv, $prop), in_array($tag, ['Discriminacao', 'CodigoMunicipio'], true));
        }
        $this->add($node, 'CodigoPais', $this->get($serv, 'codigopais', '0076'));
        $this->add($node, 'ExigibilidadeISS', $this->get($serv, 'exigibilidadeiss', $this->get($this->std, 'naturezaoperacao')), true);
        foreach (
            [
                'IdentifNaoExigibilidade' => 'identifnaoexigibilidade',
                'NumeroProcesso' => 'numeroprocesso'
            ] as $tag => $prop
        ) {
            $this->add($node, $tag, $this->get($serv, $prop));
        }
        $numeroProcesso = $this->lastElementByName($node, 'NumeroProcesso');
        $municipioIncidencia = $this->get($serv, 'municipioincidencia', $this->get($serv, 'codigomunicipio'));
        if ($numeroProcesso) {
            $this->insertBefore($node, 'MunicipioIncidencia', $municipioIncidencia, $numeroProcesso);
        } else {
            $this->add($node, 'MunicipioIncidencia', $municipioIncidencia);
        }
        $this->comext($node, $this->get($serv, 'comext', $this->get($this->std, 'comercioexterior')));
        $parent->appendChild($node);
    }

    protected function tributos(DOMNode $parent, $val)
    {
        $data = $this->get($val, 'trib');
        $pis = $this->get($this->get($data, 'tribfed', $this->get($val, 'tribfed')), 'piscofins', $this->get($data, 'tribfed', $this->get($val, 'tribfed')));
        $tot = $this->get($data, 'tottrib', $this->get($val, 'tottrib'));
        $trib = $this->node('trib');
        if (is_object($pis)) {
            $fed = $this->node('tribFed');
            $pc = $this->node('piscofins');
            foreach (['CST' => 'cst', 'vBCPisCofins' => 'vbcpiscofins', 'pAliqPis' => 'paliqpis', 'pAliqCofins' => 'paliqcofins', 'vPis' => 'vpis', 'vCofins' => 'vcofins', 'tpRetPisCofins' => 'tpretpiscofins'] as $tag => $prop) $this->add($pc, $tag, $this->get($pis, $prop), $tag === 'CST');
            $fed->appendChild($pc);
            $trib->appendChild($fed);
        }

        $t = $this->node('totTrib');
        if (is_object($tot)) {
            $percentuais = $this->get($tot, 'ptottrib');
            if (!is_object($percentuais)
                && $this->get($tot, 'ptottribfed') !== null
            ) {
                // Mantém compatibilidade com o formato antigo, no qual os
                // três percentuais eram informados diretamente em totTrib.
                $percentuais = $tot;
            }
            $simplesNacional = $this->get($tot, 'ptottribsn');
            $indicador = $this->get($tot, 'indtottrib');
            if (is_object($percentuais)) {
                $p = $this->node('pTotTrib');
                foreach (['pTotTribFed' => 'ptottribfed', 'pTotTribEst' => 'ptottribest', 'pTotTribMun' => 'ptottribmun'] as $tag => $prop) {
                    $this->add($p, $tag, $this->get($percentuais, $prop), true);
                }
                $t->appendChild($p);
            } elseif ($simplesNacional !== null && $simplesNacional !== '') {
                $this->add($t, 'pTotTribSN', $simplesNacional, true);
            } elseif ($indicador !== null && $indicador !== '') {
                $this->add($t, 'indTotTrib', $indicador, true);
            } else {
                $this->add($t, 'indTotTrib', 0, true);
            }
        } else {
            // Decreto 8.264/2014: indica que os percentuais aproximados
            // de tributos não serão destacados no RPS.
            $this->add($t, 'indTotTrib', 0, true);
        }
        $trib->appendChild($t);
        $parent->appendChild($trib);
    }

    protected function ibscbs(DOMNode $parent, $val)
    {
        $d = $this->get($val, 'ibscbs');
        
        // if (!is_object($d)) return;
        
        $n = $this->node('IBSCBS');
        $this->add($n, 'finNFSe', $this->get($d, 'finnfse', $this->get($d, 'tsfinnfse')), true);
        $this->add($n, 'indFinal', $this->get($d, 'indfinal', $this->get($d, 'tsindfinal')), true);
        $this->add($n, 'cIndOp', $this->get($d, 'cindop', $this->get($d, 'tscindop')));
        $this->add($n, 'tpOper', $this->get($d, 'tpoper'));
        $refs = $this->get($d, 'grefnfse');
        if ($refs !== null) {
            $g = $this->node('gRefNFSe');
            foreach ($this->items($this->get($refs, 'refnfse', $refs)) as $ref) $this->add($g, 'refNFSe', $ref, true);
            $n->appendChild($g);
        }
        $this->add($n, 'tpEnteGov', $this->get($d, 'tpentegov'));
        $this->add($n, 'indDest', $this->get($d, 'inddest', $this->get($d, 'tsinddest')), true);
        $vd = $this->get($d, 'valores', $d);
        $v = $this->node('valores');
        $this->reembolso($v, $this->get($vd, 'greerepres'));
        $tr = $this->node('trib');
        $g = $this->node('gIBSCBS');
        $tax = $this->get($this->get($vd, 'trib'), 'gibscbs', $vd);
        $this->add($g, 'CST', $this->get($tax, 'cst', $this->get($d, 'tscst')), true);
        $this->add($g, 'cClassTrib', $this->get($tax, 'cclasstrib', $this->get($d, 'tscclasstrib')), true);
        $tr->appendChild($g);
        $v->appendChild($tr);
        $this->add($v, 'cLocalidadeIncid', $this->get($vd, 'clocalidadeincid', $this->get($d, 'clocalidadeincid')), true);
        $this->add($v, 'pRedutor', $this->get($vd, 'predutor', $this->get($d, 'predutor', '0.00')), true);
        $this->add($v, 'vBC', $this->get($vd, 'vbc'));
        $n->appendChild($v);
        $parent->appendChild($n);
    }

    protected function reembolso(DOMNode $parent, $data)
    {
        if (!is_object($data)) return;
        $g = $this->node('gReeRepRes');
        foreach ($this->items($this->get($data, 'documentos')) as $d) {
            if (!is_object($d)) continue;
            $doc = $this->node('documentos');
            $dd = $this->get($d, 'dfenacional');
            if (is_object($dd)) {
                $x = $this->node('dFeNacional');
                $this->add($x, 'tipoChaveDFe', $this->get($dd, 'tipochavedfe'), true);
                $this->add($x, 'chaveDFe', $this->get($dd, 'chavedfe'), true);
                $doc->appendChild($x);
            }
            foreach (['dtEmiDoc' => 'dtemidoc', 'dtCompDoc' => 'dtcompdoc', 'tpReeRepRes' => 'tpreerepres', 'xTpReeRepRes' => 'xtpreerepres', 'vlrReeRepRes' => 'vlrreerepres'] as $tag => $prop) {
                $value = $this->get($d, $prop);
                if ($tag === 'dtEmiDoc' || $tag === 'dtCompDoc') {
                    $value = $this->date($value);
                }
                $this->add($doc, $tag, $value, $tag !== 'xTpReeRepRes');
            }
            $g->appendChild($doc);
        }
        $parent->appendChild($g);
    }

    protected function prestador(DOMNode $parent)
    {
        $d = $this->get($this->std, 'prestador', isset($this->config) ? $this->config : null);
        if (!is_object($d)) return;
        $n = $this->node('Prestador');
        $this->cpfCnpj($n, $d);
        $this->add($n, 'InscricaoMunicipal', $this->get($d, 'inscricaomunicipal', $this->get($d, 'im')));
        $parent->appendChild($n);
    }

    protected function tomador(DOMNode $parent)
    {
        $d = $this->get($this->std, 'tomadorservico', $this->get($this->std, 'tomador'));
        if (!is_object($d)) return;
        $n = $this->node('TomadorServico');
        if ($this->get($d, 'cpf') !== null || $this->get($d, 'cnpj') !== null) {
            $i = $this->node('IdentificacaoTomador');
            $this->cpfCnpj($i, $d);
            $this->add($i, 'InscricaoMunicipal', $this->get($d, 'inscricaomunicipal', $this->get($d, 'im')));
            $n->appendChild($i);
        }
        $this->add($n, 'NifTomador', $this->get($d, 'niftomador'));
        $this->add($n, 'RazaoSocial', $this->get($d, 'razaosocial'));
        $this->endereco($n, $this->get($d, 'endereco'));
        $this->contato($n, $d);
        $parent->appendChild($n);
    }

    protected function intermediario(DOMNode $parent)
    {
        $d = $this->get($this->std, 'intermediario', $this->get($this->std, 'intermediarioservico'));
        if (!is_object($d)) return;
        $n = $this->node('Intermediario');
        $i = $this->node('IdentificacaoIntermediario');
        $this->cpfCnpj($i, $d);
        $this->add($i, 'InscricaoMunicipal', $this->get($d, 'inscricaomunicipal', $this->get($d, 'im')));
        $n->appendChild($i);
        $this->add($n, 'RazaoSocial', $this->get($d, 'razaosocial'));
        $this->add($n, 'CodigoMunicipio', $this->get($d, 'codigomunicipio'));
        $parent->appendChild($n);
    }

    protected function cpfCnpj(DOMNode $parent, $d)
    {
        $n = $this->node('CpfCnpj');
        $cnpj = $this->get($d, 'cnpj');
        $this->add($n, $cnpj !== null ? 'Cnpj' : 'Cpf', $cnpj !== null ? $cnpj : $this->get($d, 'cpf'), true);
        $parent->appendChild($n);
    }

    protected function endereco(DOMNode $parent, $d)
    {
        if (!is_object($d)) return;
        $n = $this->node('Endereco');
        foreach (['Endereco' => 'endereco', 'Numero' => 'numero', 'Complemento' => 'complemento', 'Bairro' => 'bairro', 'CodigoMunicipio' => 'codigomunicipio', 'Uf' => 'uf', 'Cep' => 'cep'] as $tag => $prop) $this->add($n, $tag, $this->get($d, $prop), in_array($tag, ['Endereco', 'Numero', 'Bairro', 'CodigoMunicipio', 'Uf', 'Cep'], true));
        $parent->appendChild($n);
    }

    protected function contato(DOMNode $parent, $d)
    {
        $c = $this->get($d, 'contato', $d);
        $telefone = trim((string) $this->get($c, 'telefone', ''));
        $email = trim((string) $this->get($c, 'email', ''));
        if ($telefone === '' && $email === '') {
            return;
        }
        $n = $this->node('Contato');
        $this->add($n, 'Telefone', $telefone);
        $this->add($n, 'Email', $email);
        $parent->appendChild($n);
    }

    protected function construcao(DOMNode $parent)
    {
        $d = $this->get($this->std, 'construcaocivil');
        if (!is_object($d)) return;
        $n = $this->node('ConstrucaoCivil');
        $this->add($n, 'CodigoObra', $this->get($d, 'codigoobra'));
        $this->add($n, 'Art', $this->get($d, 'art'));
        $parent->appendChild($n);
    }

    protected function evento(DOMNode $parent)
    {
        $d = $this->get($this->std, 'evento');
        if (!is_object($d)) return;
        $n = $this->node('Evento');
        $this->add($n, 'IdentificacaoEvento', $this->get($d, 'identificacaoevento'));
        $this->add($n, 'DescricaoEvento', $this->get($d, 'descricaoevento'));
        $parent->appendChild($n);
    }

    protected function comext(DOMNode $parent, $d)
    {
        if (!is_object($d)) return;
        $n = $this->node('comExt');
        foreach (['mdPrestacao' => 'mdprestacao', 'vincPrest' => 'vincprest', 'tpMoeda' => 'tpmoeda', 'vServMoeda' => 'vservmoeda', 'mecAFComexP' => 'mecafcomexp', 'mecAFComexT' => 'mecafcomext', 'movTempBens' => 'movtempbens', 'nDI' => 'ndi', 'nRE' => 'nre', 'mdic' => 'mdic'] as $tag => $prop) $this->add($n, $tag, $this->get($d, $prop), !in_array($tag, ['nDI', 'nRE'], true));
        $parent->appendChild($n);
    }

    protected function deducoes(DOMNode $parent)
    {
        foreach ($this->items($this->get($this->std, 'deducao')) as $d) {
            if (!is_object($d)) continue;
            $n = $this->node('Deducao');
            $this->add($n, 'TipoDeducao', $this->get($d, 'tipodeducao'), true);
            $this->add($n, 'DescricaoDeducao', $this->get($d, 'descricaodeducao'));
            $dd = $this->get($d, 'identificacaodocumentodeducao');
            $nf = $this->get($dd, 'identificacaonfse');
            if (is_object($nf)) {
                $dn = $this->node('IdentificacaoDocumentoDeducao');
                $nn = $this->node('IdentificacaoNfse');
                $this->add($nn, 'CodigoMunicipioGerador', $this->get($nf, 'codigomunicipiogerador'), true);
                $this->add($nn, 'NumeroNfse', $this->get($nf, 'numeronfse'), true);
                $this->add($nn, 'CodigoVerificacao', $this->get($nf, 'codigoverificacao'));
                $dn->appendChild($nn);
                $n->appendChild($dn);
            }
            $fd = $this->get($this->get($d, 'dadosfornecedor'), 'identificacaofornecedor');
            if (is_object($fd)) {
                $f = $this->node('DadosFornecedor');
                $i = $this->node('IdentificacaoFornecedor');
                $this->cpfCnpj($i, $fd);
                $f->appendChild($i);
                $n->appendChild($f);
            }
            $this->add($n, 'DataEmissao', $this->date($this->get($d, 'dataemissao')), true);
            $this->add($n, 'ValorDedutivel', $this->money($this->get($d, 'valordedutivel')), true);
            $this->add($n, 'ValorUtilizadoDeducao', $this->money($this->get($d, 'valorutilizadodeducao')), true);
            $parent->appendChild($n);
        }
    }

    protected function add(DOMNode $parent, $tag, $value, $required = false)
    {
        if (($value === null || $value === '') && !$required) return null;
        return $this->dom->addChild($parent, 'tipos:' . $tag, $value, $required);
    }
    protected function node($tag)
    {
        return $this->dom->createElement('tipos:' . $tag);
    }
    protected function get($data, $prop, $default = null)
    {
        return is_object($data) && property_exists($data, $prop) ? $data->{$prop} : $default;
    }
    protected function money($value)
    {
        return $value === null || $value === '' ? null : number_format((float)$value, 2, '.', '');
    }

    protected function aliquota($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    protected function lastElementByName(DOMNode $parent, $name)
    {
        $found = null;
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement
                && ($child->localName === $name || $child->nodeName === 'tipos:' . $name)
            ) {
                $found = $child;
            }
        }
        return $found;
    }

    protected function insertBefore(DOMNode $parent, $tag, $value, DOMNode $reference = null)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $node = $this->node($tag);
        $node->appendChild($this->dom->createTextNode((string) $value));
        return $parent->insertBefore($node, $reference);
    }

    protected function insertAfter(DOMNode $parent, $tag, $value, DOMNode $reference)
    {
        if ($reference->nextSibling) {
            return $this->insertBefore($parent, $tag, $value, $reference->nextSibling);
        }
        return $this->insertBefore($parent, $tag, $value);
    }
    protected function date($value)
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches)) {
            return $matches[0];
        }
        return $value;
    }
    protected function items($value)
    {
        return $value === null ? [] : (is_array($value) ? $value : [$value]);
    }
    protected function id(DOMNode $node, $id)
    {
        if ($id !== null && $id !== '') $node->setAttribute('Id', $id);
    }

    protected function defaultRpsId()
    {
        $identificacao = $this->get($this->std, 'identificacaorps');
        if (!is_object($identificacao)) {
            return null;
        }
        $value = 'RPS' . $this->get($identificacao, 'numero', '')
            . $this->get($identificacao, 'serie', '');
        return preg_replace('/[^A-Za-z0-9_.-]/', '', $value);
    }
}
