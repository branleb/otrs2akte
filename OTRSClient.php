<?php

class OTRSClient
{
  private $soapClient;

  private $user;

  private $pass;

  private $loginUserId;

  public function __construct($host, $user, $pass, $loginUser)
  {
    $this->user = $user;
    $this->pass = $pass;
    $this->soapClient = new SoapClient(
      null, 
      array(
	'location'  => $host,
	'uri'       => "Core",
	'trace'     => 1,
	'login'     => $this->user,
	'password'  => $this->pass,
	'style'     => SOAP_RPC,
	'use'       => SOAP_ENCODED
      )
    );
    
    $user = $this->remoteCall("UserObject", "GetUserData", array("User", $loginUser));
    $this->loginUserId = $user["UserID"];
  }
  
  protected function remoteCall($object, $method, $params)
  {
//     var_dump("==========================".$object."::".$method."================================");
    array_unshift($params, $this->loginUserId);
    array_unshift($params, "UserID");
    array_unshift($params, $method);
    array_unshift($params, $object);
    array_unshift($params, $this->pass);
    array_unshift($params, $this->user);
//     var_dump($params);
    $retval = $this->soapClient->__soapCall(
      "Dispatch", 
      $params
    );
//     var_dump($retval);
//     var_export($this->soapClient->__getLastRequest());
//     print PHP_EOL.PHP_EOL.PHP_EOL;
//     var_export($this->soapClient->__getLastResponse());
//     
//     var_dump("<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<");
    return $this->decodeResponse($retval);
  }

  protected function decodeResponse($response)
  {
    //! Only process Arrays of PODs
    if(is_array($response) && count($response) > 0 && is_object(current($response)) === false) {
      // Get the SOAP response into an array as key/value pairs ####
      // This is kind of janky, but what it does is parse out the #
      // detail values in the SOAP response and writes them as a key/value #
      // pair in the $data[] array. #
      $data = array();
      $i = 0;
      foreach ($response as $name => $value) { // explode the xml response
	if (false !== strpos($name, "s-gensym")) {
	  $temp[$i] = $value; 
	  $v = $temp[$i-1]; 
	  if($i % 2 != 0){ 
	    $data[$v] = $value; 
	  }
	  $i++;
	}
      }
    } else {
      $data = $response;
    }
    return $data;
  }
  
  public function getTicketId($number)
  {
    return $this->remoteCall("TicketObject", "TicketIDLookup", array(
	"TicketNumber", $number,
      )
    );
  }
  
  public function getTicketDetails($id)
  {
    return $this->remoteCall("TicketObject", "TicketGet", array(
	"TicketID", $id,
	"DynamicFields", 1,
	"Extended", 1,
      )
    );
  }
  
  public function getTicketHistory($id)
  {
    return $this->remoteCall("TicketObject", "HistoryGet", array(
	"TicketID", $id,
      )
    );
  }
  
  public function getTicketArticles($id)
  {
    return $this->remoteCall("TicketObject", "ArticleGet", array(
	"TicketID", $id,
	"DynamicFields", 1,
	"Extended", 1,
	"Order", "ASC",
      )
    );
  }
  
  public function getArticleAttachements($id)
  {
    return $this->remoteCall("TicketObject", "ArticleAttachmentIndex", array(
	"ArticleID", $id,
	"StripPlainBodyAsAttachment",3, // "StripPlainBodyAsAttachment => 3" feature to not include first attachment (not include text body and html body as attachment)
      )
    );
  }
  
  public function getArticleAttachement($id, $index)
  {
    return $this->remoteCall("TicketObject", "ArticleAttachment", array(
	"ArticleID", $id,
	"FileID", $index,
      )
    );
  }
}
