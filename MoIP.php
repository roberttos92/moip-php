<?php

/**
 * Abstração da API do MoIP para PHP
 * @author Herberth Amaral
 * @version 0.0.1
 * @package MoIP
 */
class MoIP
{

  private $credenciais;
  private $razao;
  private $ambiente = 'sandbox';
  private $id_proprio;
  private $formas_pagamento = array('boleto'=>'BoletoBancario',
                                    'financiamento'=>'FinanciamentoBancario',
                                    'debito'=>'DebitoBancario',
                                    'cartao_credito'=>'CartaoCredito',
                                    'cartao_debito'=>'CartaoDebito',
                                    'carteira_moip'=>'CarteiraMoIP');
  private $forma_pagamento;
  private $forma_pagamento_args;
  private $pagador;
  private $resposta;
  private $valor;

  //simplexml object
  private $xml;

  function __construct()
  {
    $this->initXMLObject();
  }

  private function initXMLObject()
  {
      $this->xml = new SimpleXmlElement('<EnviarInstrucao></EnviarInstrucao>');
      $this->xml->addChild('InstrucaoUnica');
  }

  public function setCredenciais($credenciais)
  {
    if (!isset($credenciais['token']) or 
       !isset($credenciais['key']) or
       strlen($credenciais['token'])!=32 or
       strlen($credenciais['key'])!=40)
       throw new InvalidArgumentException("Credenciais inválidas");
    
    $this->credenciais = $credenciais;
    return $this;
  }

  public function setAmbiente($ambiente)
  {
    if ($ambiente!='sandbox' and $ambiente!='producao')
      throw new InvalidArgumentException("Ambiente inválido");
    $this->ambiente = $ambiente;
    return $this;
  }

  public function valida()
  {
    if (!isset($this->credenciais)  or
        !isset($this->razao) or
        !isset($this->id_proprio))
        throw new InvalidArgumentException("Dados requeridos não preenchidos. Você deve especificar as credenciais, a razão do pagamento e seu ID próprio");

    return $this;
  }

  public function setIDProprio($id)
  {
    $this->id_proprio = $id;
    return $this;
  }

  public function setRazao($razao)
  {
      $this->xml->InstrucaoUnica->addChild('Razao',$razao);
      $this->razao = $razao;
      return $this;
  }

  public function setFormaPagamento($forma,$args=null)
  {
    if(!isset($this->formas_pagamento[$forma]))
      throw new InvalidArgumentException("Forma de pagamento indisponivel");

    if($args!=null)
    {
      if (!is_array($args))
        throw InvalidArgumentException("Os parâmetros extra devem ser passados em um array");

      if($forma=='boleto')
      { 
        //argumentos possíveis: dias de expiração, instruções e logo da URL
        if (isset($args['dias_expiracao']) and isset($args['dias_expiracao']['tipo']) and isset($args['dias_expiracao']['dias']))
        {
            $this->forma_pagamento_args = $args;
        }
        else
        {
          throw new InvalidArgumentException("Parâmetros passados de forma incorreta");
        }
      }
    }
    $this->forma_pagamento = $forma;
    return $this; 
  }

  public function setPagador($pagador)
  {
    if(empty($pagador) or
      !isset($pagador['nome']) or
      !isset($pagador['login_moip']) or
      !isset($pagador['email']) or 
      !isset($pagador['celular']) or
      !isset($pagador['apelido']) or
      !isset($pagador['identidade']) or
      !isset($pagador['endereco']) or
      !isset($pagador['endereco']['logradouro']) or
      !isset($pagador['endereco']['numero']) or
      !isset($pagador['endereco']['complemento']) or
      !isset($pagador['endereco']['bairro']) or
      !isset($pagador['endereco']['cidade']) or
      !isset($pagador['endereco']['estado']) or
      !isset($pagador['endereco']['pais']) or
      !isset($pagador['endereco']['cep']) or
      !isset($pagador['endereco']['telefone'])
    )
    {
      throw new InvalidArgumentException("Dados do pagador especificados de forma incorreta");
    }
    $this->pagador = $pagador;

  }

  public function setValor($valor)
  {
    $this->valor = $valor;
    return $this;
  }

  public function getXML()
  {
      $this->xml->InstrucaoUnica->addChild('IdProprio',$this->id_proprio);
      
      if (!empty($this->valor))
      {
          $this->xml->InstrucaoUnica->addChild('Valores')
                                        ->addChild('Valor',$this->valor)
                                            ->addAttribute('moeda','BRL'); 
      }

    if (!empty($this->forma_pagamento))
    {
        $instrucao = $this->xml->InstrucaoUnica;
        $instrucao->addChild('PagamentoUnico');
        $instrucao->PagamentoUnico->addChild('Forma',$this->formas_pagamento[$this->forma_pagamento]);


      if($this->forma_pagamento=='boleto' and !empty($this->forma_pagamento_args))
      {
          $instrucao->addChild('Boleto')
                    ->addChild('DiasExpiracao',$this->forma_pagamento_args['dias_expiracao']['dias'])
                        ->addAttribute('Tipo',$this->forma_pagamento_args['dias_expiracao']['tipo']);

        if(isset($this->forma_pagamento_args['instrucoes']))
        {
          $numeroInstrucoes = 1;
          foreach($this->forma_pagamento_args['instrucoes'] as $instrucaostr)
          {
              $instrucao->Boleto->addChild('Instrucao'.$numeroInstrucoes,$instrucaostr);
              $numeroInstrucoes++;
          }
        }
      }
    }
    
    if(!empty($this->pagador))
    {

    }
    $return = $this->xml->asXML();
    $this->initXMLObject();
    return str_ireplace("\n","",$return);
  }

  public function envia($client=null)
  {
    $this->valida();
    
    if($client==null)
      $client = new MoIPClient();

    if ($this->ambiente=='sandbox')
      $url = 'https://desenvolvedor.moip.com.br/sandbox/ws/alpha/EnviarInstrucao/Unica';
    else
      $url = 'pegar url depois';

    $this->resposta = $client->send($this->credenciais['token'].':'.$this->credenciais['key'],
                                    $this->getXML(),
                                    $url);
    return $this;
  }

  public function getResposta()
  {
    if (!empty($this->resposta->erro))
      return (object) array('sucesso'=>false,'mensagem'=>$this->resposta->erro);

    $xml = new SimpleXmlElement($this->resposta->resposta);
    $return = (object) array();
    $return->sucesso = (bool)$xml->Resposta->Status=='Sucesso';
    $return->id      = (string)$xml->Resposta->ID;
    $return->token = (string)$xml->Resposta->Token;
    
    return $return;
  }
}

/**
 * Cliente HTTP "burro"
 *
 * @author Herberth Amaral
 * @version 0.0.1
 */ 
class MoIPClient
{
  function send($auth,$xml,$url='https://desenvolvedor.moip.com.br/sandbox/ws/alpha/EnviarInstrucao/Unica')
  {
    $header[] = "Authorization: Basic " . base64_encode($auth);
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL,$url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($curl, CURLOPT_USERPWD, $auth);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_USERAGENT, "Mozilla/4.0");
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $xml);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $ret = curl_exec($curl);
    $err = curl_error($curl); 
    curl_close($curl); 
    return (object) array('resposta'=>$ret,'erro'=>$err);
  }
}
?>
