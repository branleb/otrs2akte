<?php

interface ChildContainer
{
  public function getChildren();
  public function getChild($childPath);
  public function addChild(Child $child);
  public function hasChild($childPath);
  public function hasChildren();
  public function getChildrenRecusive($pathstr);
}


interface Child
{
  public function getParent();
  public function getPath();
}

class MailContainer implements ChildContainer
{
  protected $children = array();
  
  protected $resource;
  
  protected $file;

  public function __construct($resource, $file)
  {
    $this->resource = $resource;
    $this->file = $file;
  }
      
  public function getChildren()
  {
    return $this->children;
  }
      
  public function hasChildren()
  {
    return (count($this->children) > 0);
  }
  
  public function getChild($childPath)
  {
    foreach($this->children as $child) {
      if($childPath === $child->getPath()) {
	return $child;
      }
    }
    return null;
  }
  
  public function addChild(Child $child)
  {
    if($child->getParent() == $this) {
      $this->children[] = $child;
    }
  }
  
  public function hasChild($childPath)
  {
    foreach($this->children as $child) {
      if($childPath === $child->getPath()) {
	return true;
      }
    }
    return false;
  }
  
  public function getChildrenRecusive($pathstr)
  {
    $path = explode('.', $pathstr);
    $child = $this->getChild(array_shift($path));
    if($child instanceof ChildContainer) {
      return $child->getChildrenRecusive(implode('.', $path));
    } else {
      if($child instanceof Child && $child->getPath() === $pathstr) {
	return $child;
      } else {
	return null;
      }
    }
  }
  
  protected function getFileContents()
  {
    return file_get_contents($this->file);
  }
}

class MailPart extends MailContainer implements Child
{
  protected $pathId;
  
  
  public function __construct($pathId, ChildContainer $parent, $resource, $file)
  {
    parent::__construct($resource, $file);
    $this->pathId = $pathId;
    $this->parent = $parent;
    $this->parent->addChild($this);
  }
  
  public function getPath()
  {
    return ($this->parent instanceof MailPart) ? $this->parent->getPath().'.'.$this->pathId : $this->pathId;
  }
  
  public function getParent()
  {
    return $this->parent;
  }
  
  public function getChild($childPath)
  {
    foreach($this->children as $child) {
      if($this->getPath().'.'.$childPath === $child->getPath()) {
	return $child;
      }
    }
    return null;
  }

  public function hasChild($childPath)
  {
    foreach($this->children as $child) {
      if($this->getPath().'.'.$childPath === $child->getPath()) {
	return true;
      }
    }
    return false;
  }
  
  protected function getPartResource()
  {
    return mailparse_msg_get_part($this->resource, $this->getPath());
  }
  
  protected function getPartData($key)
  {
    $data = mailparse_msg_get_part_data($this->getPartResource());
//     var_dump($data);
    return $data[$key];
  }
  
  protected function getMailData($key)
  {
    $data = mailparse_msg_get_part_data($this->resource);
    return $data[$key];
  }
  
  protected function getPartHeader($header)
  {
    $headers = $this->getPartData('headers');
    return $headers[$header];
  }
  
  protected function getMailHeader($header)
  {
    $headers = $this->getMailData('headers');
    return $headers[$header];
  }
  
  protected function getHeaderRecursive($header)
  {
    if(is_null($this->getPartHeader($header))) {
      if($this->parent instanceof MailPart) {
	return $this->parent->getHeaderRecursive($header);
      } else {
	return $this->getMailHeader($header);
      }
    } else {
      return $this->getPartHeader($header);
    }
  }
  
  public function getFromHeader()
  {
    return $this->getHeaderRecursive('from');
  }
  
  public function getDateHeader()
  {
    return $this->getHeaderRecursive('date');
  }
  
  public function getDateTimestamp()
  {
    //! DateTime class doesn't accept all date() parameters.
    //! 'r' for rfc message datetime format ist rejected.
    //! therefore build an custom equivalent
    return DateTime::createFromFormat('D, j M Y H:i:s P', $this->getDateHeader())->getTimestamp();
  }
  
  public function getToHeader()
  {
    return $this->getHeaderRecursive('to');
  }
  
  public function getCcHeader()
  {
    return $this->getHeaderRecursive('cc');
  }
  
  public function getSubjectHeader()
  {
    return $this->getHeaderRecursive('subject');
  }
 
  public function getContentType()
  {
    return $this->getPartData('content-type');
  }
  
  public function getCharset()
  {
    return $this->getPartData('charset');
  }
  
  public function getPartBodyContent()
  {
    $body = substr($this->getFileContents(), $this->getPartData('starting-pos-body'), $this->getPartData('ending-pos-body'));
    $encoding = $this->getPartData('transfer-encoding');
    switch($encoding) {
      case 'base64';
	return base64_decode($body);
	break;
      case 'quoted-printable';
	return quoted_printable_decode($body);
	break;
      default:
	var_dump('Unknown Transfer Encoding Error');
	var_dump($encoding);
	return $body;
	break;
    }
  }
  
  public function isAlternativePart()
  {
    if($this->parent instanceof MailPart) {
      return (array_pop(explode('/', $this->getPartData('content-type'))) === 'alternative');
    } else {
      return (array_pop(explode('/', $this->getMailData('content-type'))) === 'alternative');
    }
//       $partres = $this->getPartResource();
//       var_dump($this->resource);
//       var_dump($this->getPath());
//       var_dump($partres);
//       var_dump(mailparse_msg_get_part_data($partres));
//       var_dump($this->getPartData('content-type'));
//       var_dump($this->getMailData('content-type'));
//       var_dump(explode('/', $this->getPartData('content-type')));
//       var_dump(explode('/', $this->getMailData('content-type')));
//       var_dump(array_pop(explode('/', $this->getPartData('content-type'))));
//       var_dump();
//       var_dump($this->getPartBodyContent());
//     }
  }
    
  public function isSignedAlternativePart()
  {
    $subpart = '';
    if($this->parent instanceof MailPart) {
      $subpart = array_pop(explode('/', $this->getPartData('content-type')));
    } else {
      $subpart = array_pop(explode('/', $this->getMailData('content-type')));
    }
    switch($subpart) {
      case 'signed':
	return true;
	break;
      case 'mixed':
	var_dump('0=============================');
	$siblings = $this->getParent()->getChildren();
	if(count($siblings) !== 2) {
	    var_dump('2=============================');
	    var_dump(count($siblings));
	    var_dump($siblings);
	  return false;
	} else {
	    var_dump('0=============================');
	  foreach($siblings as $sibling) {
	    if($sibling == $this) {
	      continue;
	    } else {
	    var_dump('0=============================');
	      var_dump($sibling->getPartData('content-type'));
	    }
	  }
	}
	break;
      default:
	return false;
	break;
    }
  }
}

class MailParser
{
  protected $mail;

  protected $resource;

  protected $structure = array();

  public function __construct($mail)
  {
    $this->mail = $mail;
    $this->resource = mailparse_msg_parse_file($this->mail);
    $this->loadStructure();
  }
  
  protected function addToStructure(ChildContainer $structure, $toAdd)
  {
    $path = array_shift($toAdd);
    if($structure->hasChild($path)) {
      $this->addToStructure($structure->getChild($path), $toAdd);
    } else {
      $elem = new MailPart($path, $structure, $this->resource, $this->mail);
      if(isset($toAdd[0])) {
	$this->addToStructure($elem, $toAdd);
      }
    }
  }
  
  protected function loadStructure()
  {
    $structure = mailparse_msg_get_structure($this->resource);
    
    $this->structure = new MailContainer($this->resource, $this->mail);
    foreach($structure as $key => $elem) {
      $this->addToStructure($this->structure, explode('.', $elem));  
    }
    
//     var_export($this->structure);
//     foreach($structure as $key => $elem) {
//       $structure[$key] = explode($elem);
//     }

//     
//         $resource = mailparse_msg_parse_file($attachment->LocalFile);
//     $structure = mailparse_msg_get_structure($resource);
//     $header = array();
//     $messagedata = array();
//     foreach($structure as $partpath) {
//       echo "========================== Part $partpath ============================".PHP_EOL;
//       $part = mailparse_msg_get_part($resource, $partpath);
//       $partdata = mailparse_msg_get_part_data($part);
//       var_dump($partdata['content-type']);
//       var_dump($partdata);
//       $messagedata[$partpath] = substr(file_get_contents($attachment->LocalFile), $partdata['starting-pos-body'], $partdata['ending-pos-body']);
//       $messagedata[$partpath] = $partdata['headers'];
//     }
// //     var_dump(mailparse_msg_get_structure($resource));
//     var_dump(mailparse_msg_get_part_data($resource));
  }
  
  public function getPart($path)
  {
    return $this->structure->getChildrenRecusive($path);
  }
  
  public function getEndParts()
  {
    $parts = array();
    $partsToCheck = $this->structure->getChildren();
    foreach($partsToCheck as $part) {
      if($part->hasChildren()) {
	foreach($part->getChildren() as $partToAdd) {
	  $partsToCheck[] = $partToAdd;
	}
      } else {
	$parts[] = $part;
      }
    }
    return $parts;
  }

}