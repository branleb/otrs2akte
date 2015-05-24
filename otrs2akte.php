#!/usr/bin/env php
<?php
  require_once __DIR__.DIRECTORY_SEPARATOR."OTRSClient.php";
  require_once __DIR__.DIRECTORY_SEPARATOR."OTRSTicket.php";
  error_reporting(E_ALL ^ (E_NOTICE|E_WARNING|E_STRICT));
  
  function is_debug($options)
  {
    return isset($options["d"]) == true || isset($options["debug"]) === true;
  }
  
  function debugprint($message, $options)
  {
    if(is_debug($options)) {
      print "DEBUG: ".$message.PHP_EOL; 
    }
  }

  $shortopts = "h:u:p:i:n:l:d";
  $longopts = array(
	  "host:",
	  "user:",
	  "pass:",
	  "id:",
	  "number:",
	  "login:",
	  "debug",
	  "with-links",
	  "with-links-merged",
  );
  $options = getopt($shortopts, $longopts);
  if(is_debug($options)) {
    error_reporting(E_ALL);
  }

  //! OTRS Soap URL
  $host = isset($options["h"]) ? $options["h"] : null;
  $host = isset($options["host"]) ? $options["host"] : $host;

  //! SOAP Username
  $user = isset($options["u"]) ? $options["u"] : null;
  $user = isset($options["user"]) ? $options["user"] : $user;

  //! SOAP Passworf
  $pass = isset($options["p"]) ? $options["p"] : null;
  $pass = isset($options["pass"]) ? $options["pass"] : $pass;
  
  //! OTRS Ticket ID
  $ticketId = isset($options["i"]) ? $options["i"] : null;
  $ticketId = isset($options["id"]) ? $options["id"] : $ticketId;
  
  //! OTRS Ticket Number
  $ticketNumber = isset($options["n"]) ? $options["n"] : null;
  $ticketNumber = isset($options["number"]) ? $options["number"] : $ticketNumber;
  
  //! OTRS Login User name
  $loginUser = isset($options["l"]) ? $options["l"] : null;
  $loginUser = isset($options["login"]) ? $options["login"] : $loginUser;
  
  //! Linked Tickets
  $handleLinkedTickets = isset($options["with-links-merged"]) ? "merge" : (isset($options["with-links"]) ? "links" : "no");
  
  if($host === null) {
    echo "Missing OTRS host".PHP_EOL;
    exit -1;
  }
  if($user === null) {
    echo "Missing OTRS user".PHP_EOL;
    exit -1;
  }
  if($pass === null) {
    echo "Missing OTRS pass".PHP_EOL;
    exit -1;
  }
  if($ticketId === null && $ticketNumber === null) {
    echo "Missing OTRS Ticket ID or TicketNumber".PHP_EOL;
    exit -1;
  }
  if($loginUser === null) {
    echo "Missing OTRS Login User".PHP_EOL;
    exit -1;
  }
  
  $otrs = new OTRSClient($host, $user, $pass, $loginUser);
  
  if($ticketId === null) {
    $ticketNumber = number_format($ticketNumber, 0, '.', ''); 
    $ticketId = $otrs->getTicketId($ticketNumber);
  }
  
  $ticket = new OTRSTicket($otrs, $ticketId);
  
  $exportDestination = implode(DIRECTORY_SEPARATOR, array("tmp", "otrs-export",$ticketId, time()));
  mkdir($exportDestination, 0700, true);
  $out = $ticket->export($exportDestination, $handleLinkedTickets);
  foreach($out as $index => $file) {
    print "Export file#".$index.": ".$file.PHP_EOL;
  }
//   var_export($out);
  