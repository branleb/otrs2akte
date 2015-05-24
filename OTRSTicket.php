<?php
require_once __DIR__.DIRECTORY_SEPARATOR."OTRSClient.php";
require_once __DIR__.DIRECTORY_SEPARATOR."MailParser.php";
  
class OTRSTicket
{
  private $id;

  private $otrs;

  public function __construct(OTRSClient $otrs, $id)
  {
    $this->id = $id;
    $this->otrs = $otrs;
  }
  
  private function br2nl($text)
  {
    return preg_replace('/\<br(\s*)?\/?\>/i', "\n", $text);
  }
  
  protected function addAttachment(&$attachments, stdClass $attachment)
  {
    $filetype = $this->getAttachmentFiletype($attachment);
    if($this->isContainerType($filetype)) {
      $handler = 'export'.$filetype.'Container';
      $attachmentsToInsert = call_user_func_array(array($this, $handler), array(&$attachment));
      foreach($attachmentsToInsert as $attachmentToInsert) {
	$this->addAttachment($attachments, $attachmentToInsert);
      }
    } else {
      $attachment->IndexPos = count($attachments);
//       var_dump(count($attachments));
//       var_dump($attachment);
      $attachments[] = $attachment;
    }
  }

  protected function sortAttachments(stdClass $attachment1, stdClass $attachment2)
  {
    if(isset($attachment1->Timestamp) && isset($attachment2->Timestamp)) { // Compare by Time (Mailsort!)
      if($attachment1->Timestamp === $attachment2->Timestamp) {
	return 0;
      } else {
	if ($attachment1->Timestamp > $attachment2->Timestamp) {
	  return 1;
	} else {
	  return -1;
	}
      }
    } else { // Compare via Appearance Order
      if($attachment1->IndexPos === $attachment2->IndexPos) {
	return 0;
      } else {
	if ($attachment1->IndexPos > $attachment2->IndexPos) {
	  return 1;
	} else {
	  return -1;
	}
      }
    }
  }
  
  public function export($basedir, $handleLinkedTickets = "no")
  {
    $ids = array($this->id);
    if($handleLinkedTickets != "no") {
      $tickets = $this->otrs->getLinkedTickets($this->id);
      foreach($tickets->Ticket->Normal->Source as $ticket => $active) {
	$ids[] = $ticket;
      }
    }
    
    $retval = array();
    if($handleLinkedTickets == "merge") {
	$retval[] = $this->exportMergeTo($basedir, $ids, $handleLinkedTickets);
    } else {
      foreach($ids as $id) {
	$retval[] = $this->exportSingleTo($basedir, $id, $handleLinkedTickets);
      }
    }
    return $retval;
  }
    
  protected function exportMergeTo($basedir, array $ids, $handleLinkedTickets)
  {
    $articles = array();
    foreach($ids as $id) {
      $articles["Ticket_".$id] = $this->otrs->getTicketArticles($id);
      if($articles["Ticket_".$id] instanceof stdClass) {
	$articles["Ticket_".$id] = array($articles["Ticket_".$id]);
      }
      foreach($articles["Ticket_".$id] as $key => $article) {
	if($article->StateType === "merged") {
	  unset($articles["Ticket_".$id][$key]);
	}
      }
    }
    
    reset($ids);
    return $this->exportTo($basedir, current($id), $this->flattenArticles($articles));
  }
    
  protected function flattenArticles(array $articles)
  {
    $flattened = array();
    foreach($articles as $ticketIndex => $ticketArticles) {
      foreach($ticketArticles as $index => $article) {
	$flattened[] = $article;
      }
    }
    
    usort($flattened, array($this, "sortArticles"));
    return $flattened;
  }

  protected function sortArticles(stdClass $article1, stdclass $article2)
  {
    $time1 = DateTime::createFromFormat("Y-m-d H:i:s", $article1->Created);
    $time2 = DateTime::createFromFormat("Y-m-d H:i:s", $article2->Created);
    
    if($time1->getTimestamp() == $time2->getTimestamp()) {
      return 0;
    } else {
      if($time1->getTimestamp() > $time2->getTimestamp()) {
	return 1;
      } else {
	return -1;
      }
    }
  }
    
  protected function exportSingleTo($basedir, $id, $handleLinkedTickets)
  {
    $articles = $this->otrs->getTicketArticles($id);
    if($articles instanceof stdClass) {
      $articles = array($articles);
    }
    return $this->exportTo($basedir, $id, $articles);
  }
  
  protected function exportTo($basedir, $id, array $articles)
  {
    $articleData = array();
    foreach($articles as $index => $article) {
      $articleData[$index] = $this->exportArticle($basedir, $article, $index);
    }
    
    foreach($articleData as $article) {
      //! Clone and iterate over the clones because the original structure is beeing changed!
      $attachments = $article->attachments;
      $article->attachments = array();
      foreach($attachments as $index => $attachment) {
	$this->addAttachment($article->attachments, $attachment);
      }
      
      usort($article->attachments, array($this, 'sortAttachments'));
    }
   
    return $this->exportToTex($basedir, $id, $articleData);
  }
    
  
  protected function exportToTex($basedir, $id, $articleData)
  {
    $ticket = $this->otrs->getTicketDetails($id);
    $ticketFile = tempnam($basedir, "TICKET_".$id."_");
    rename($ticketFile, $ticketFile.'.tex');
    $ticketFile = $ticketFile.'.tex';
    
    $data = <<<'EOT'
\documentclass[a4paper,11pt]{scrartcl}
\usepackage{etoolbox}
\usepackage{pdfpages}
\usepackage{verbatim}
% XELATEX OBSOLETES THIS
%\usepackage{ucs}
%\usepackage[utf8x]{inputenc}
\usepackage[ngerman]{babel}
\usepackage[T1]{fontenc}
\usepackage[babel,german=guillemets]{csquotes}
\usepackage{fancyhdr}
\usepackage{lmodern}
\usepackage{sourcesanspro}
% hyperref seems to be buggy. generated links and pdfbookmarks are crap, aiming to low
% \usepackage{hyperref}
% \usepackage{hyperxmp}
% MAYBE STILL REQUIRED? SEEMS NOT TO WORK WITH XELATEX
%\usepackage[anythingbreaks]{breakurl}
\usepackage[a4paper,nohead,includeall,includemp,left=20mm,right=5mm,top=20mm,bottom=20mm]{geometry} % Für debugging: showframe
\usepackage{enumerate}
\usepackage{titling}
\usepackage{listings}
\usepackage{color}
\usepackage{everypage}

\lstset{literate=
  {á}{{\'a}}1 {é}{{\'e}}1 {í}{{\'i}}1 {ó}{{\'o}}1 {ú}{{\'u}}1
  {Á}{{\'A}}1 {É}{{\'E}}1 {Í}{{\'I}}1 {Ó}{{\'O}}1 {Ú}{{\'U}}1
  {à}{{\`a}}1 {è}{{\`e}}1 {ì}{{\`i}}1 {ò}{{\`o}}1 {ù}{{\`u}}1
  {À}{{\`A}}1 {È}{{\'E}}1 {Ì}{{\`I}}1 {Ò}{{\`O}}1 {Ù}{{\`U}}1
  {ä}{{\"a}}1 {ë}{{\"e}}1 {ï}{{\"i}}1 {ö}{{\"o}}1 {ü}{{\"u}}1
  {Ä}{{\"A}}1 {Ë}{{\"E}}1 {Ï}{{\"I}}1 {Ö}{{\"O}}1 {Ü}{{\"U}}1
  {â}{{\^a}}1 {ê}{{\^e}}1 {î}{{\^i}}1 {ô}{{\^o}}1 {û}{{\^u}}1
  {Â}{{\^A}}1 {Ê}{{\^E}}1 {Î}{{\^I}}1 {Ô}{{\^O}}1 {Û}{{\^U}}1
  {œ}{{\oe}}1 {Œ}{{\OE}}1 {æ}{{\ae}}1 {Æ}{{\AE}}1 {ß}{{\ss}}1
  {ç}{{\c c}}1 {Ç}{{\c C}}1 {ø}{{\o}}1 {å}{{\r a}}1 {Å}{{\r A}}1
  {€}{{\EUR}}1 {£}{{\pounds}}1 {§}{{\S}}1
}

\definecolor{mygreen}{rgb}{0,0.6,0}
\definecolor{mygray}{rgb}{0.5,0.5,0.5}
\definecolor{mymauve}{rgb}{0.58,0,0.82}

\lstset{ %
  backgroundcolor=\color{white},   % choose the background color; you must add \usepackage{color} or \usepackage{xcolor}
  basicstyle=\footnotesize,        % the size of the fonts that are used for the code
  breakatwhitespace=false,         % sets if automatic breaks should only happen at whitespace
  breaklines=true,                 % sets automatic line breaking
  captionpos=b,                    % sets the caption-position to bottom
  commentstyle=\color{mygreen},    % comment style
%  deletekeywords={...},            % if you want to delete keywords from the given language
  escapeinside={\%*}{*)},          % if you want to add LaTeX within your code
  extendedchars=true,              % lets you use non-ASCII characters; for 8-bits encodings only, does not work with UTF-8
  frame=single,                    % adds a frame around the code
  keepspaces=true,                 % keeps spaces in text, useful for keeping indentation of code (possibly needs columns=flexible)
  keywordstyle=\color{blue},       % keyword style
  language=Octave,                 % the language of the code
%   otherkeywords={*,...},            % if you want to add more keywords to the set
  numbers=left,                    % where to put the line-numbers; possible values are (none, left, right)
  numbersep=5pt,                   % how far the line-numbers are from the code
  numberstyle=\tiny\color{mygray}, % the style that is used for the line-numbers
  rulecolor=\color{black},         % if not set, the frame-color may be changed on line-breaks within not-black text (e.g. comments (green here))
  showspaces=false,                % show spaces everywhere adding particular underscores; it overrides 'showstringspaces'
  showstringspaces=false,          % underline spaces within strings only
  showtabs=false,                  % show tabs within strings adding particular underscores
  stepnumber=2,                    % the step between two line-numbers. If it's 1, each line will be numbered
  stringstyle=\color{mymauve},     % string literal style
  tabsize=2,                       % sets default tabsize to 2 spaces
  title=\lstname                   % show the filename of files included with \lstinputlisting; also try caption instead of title
}

\topmargin 0pt
\oddsidemargin 0pt
\evensidemargin 0pt
\headheight 25pt
\fancyhf{}
\fancyhead[L]{Note}
EOT;
var_dump($id);
var_dump($ticket);
$DokumentIdentifier = isset($ticket['DynamicField_Aktenzeichen']) ? $ticket['DynamicField_Aktenzeichen'] : $ticket['TicketNumber'];
$data .= '\title{Aktenexport von '.($DokumentIdentifier).'}'.PHP_EOL;
$data .= '\author{otrs2akte \thanks{otrs2akte CC-BY-SA Florian Zumkeller-Quast}}'.PHP_EOL;
$data .= '\date{\today{}}'.PHP_EOL;
$data .= <<<'EOT'
%\hypersetup{
%    linktocpage=true,
%    linktoc=all,
%    naturalnames=true,
%    hypertexnames=false,
%    breaklinks=true,
%    colorlinks,
%    citecolor=black,
%    filecolor=black,
%    linkcolor=black,
%    urlcolor=black,
%    pdfinfo={  
%      Title={\thetitle},
%      Subject={\thetitle},
%      Keywords={\thetitle},
%      pdfdisplaydoctitle={\thetitle},
%      Author={otrs2akte CC-BY-SA Florian Zumkeller-Quast},
%      Producer={otrs2akte CC-BY-SA Florian Zumkeller-Quast}
%    },
%    pdfcreator={otrs2akte CC-BY-SA Florian Zumkeller-Quast},
%    pdflang={de}
%} 

\newcounter{includepagecounter}
\setcounter{includepagecounter}{0}

\begin{document}
\pagestyle{plain}
\makeatletter
\def\@xobeysp{\ }
\let\verbatim@nolig@list\empty
\appto\verbatim@font{\raggedright}

%\renewcommannd{\headrulewidth}{0pt}
% \renewcommand{\footrulewidth}{0pt}
%\newcommand\tabrotate[1]{\begin{turn}{90}\rlap{#1}\end{turn}}
%\newcommand\marginnote*[1]{\marginpar[\hfill]{\relax}}
%\newcommand\filenote*[1]{\leavevmode\marginnote \tabrotate{#1}}
\newcommand\filenote[1]{%
  \stepcounter{includepagecounter}%
  \fancyhead[L]{#1 [Page \arabic{includepagecounter}]}%
  \thispagestyle{fancy}%
}%

\makeatother
% \normalspacedchars{}
% \ExplSyntaxOn
% \tex_chardef:D \c_fifty = 50 ~
\maketitle
\clearpage
\begingroup
%\catcode'\_=12
%\catcode'\-=12
\tableofcontents
\endgroup 
\clearpage


EOT;
    foreach($articleData as $index => $article) {
//       var_dump('Mail von '.$article->From.' am '.$article->Created);
      $data .= '\addcontentsline{toc}{section}{Mail von '.$this->escapeTocForTex($article->From).' am '.$article->Created.'}'.PHP_EOL;
      $data .= '\begingroup'.PHP_EOL;
//       var_dump($article->Charset);
//       var_dump($article->ContentCharset);
//       $data .= '\inputencoding{'.$article->Charset.'}'.PHP_EOL;
      $data .= '\verbatiminput{'.$article->File.'}'.PHP_EOL;
      $data .= '\endgroup'.PHP_EOL;
//       $data .= '\unicodecombine'.PHP_EOL;
      $data .= '\noindent\rule[3ex]{\textwidth}{2ex}'.PHP_EOL;
      if(count($article->attachments) > 0) {
	$data .= 'Attachments'.PHP_EOL;
	$data .= '\hrule'.PHP_EOL;
	$data .= '\begin{enumerate}'.PHP_EOL;
	$attachdata = '';
	foreach($article->attachments as $attachment) {
	  $this->importAttachmentToTex($data, $attachdata, $attachment);
	}
	$data .= '\end{enumerate}'.PHP_EOL;
	$data .= $attachdata;
      }
      $data .= '\clearpage'.PHP_EOL;
      $data .= '\pagestyle{plain}%'.PHP_EOL;
      $data .= PHP_EOL;
    }
    
    $data .= '\end{document}'.PHP_EOL;    
    
    $handle = fopen($ticketFile, "w");
    
    fwrite($handle, $data);
    fclose($handle);
    
    return $ticketFile;
  }
  
  protected function escapeCharForTex($string, $char)
  {
    return str_replace($char, '\\string'.$char, $string);
  }
  
  protected function escapeFilenameForTex($filename)
  {
    $filename = $this->escapeCharForTex($filename, '_');
    $filename = str_replace('#', '\\#', $filename);
    return $filename;
  }
  
  protected function escapeContentslineForTex($toc)
  {
    return str_replace('\\\\#', '\\#', $this->escapeTocForTex($this->escapeTocForTex($toc)));
  }
  
  protected function escapeTocForTex($toc)
  {
    $toc = str_replace('<', '\textless{}', str_replace('>', '\textgreater{}', $toc));
    $toc = $this->escapeCharForTex($toc, '"');
    $toc = $this->escapeCharForTex($toc, '-');
    $toc = $this->escapeFilenameForTex($toc);
    return $toc;
  }
  
  protected function importAttachmentToTex(& $texCode, & $attachmentTexCode, stdClass $attachment)
  {
    $texCode .= '\item{'.$this->escapeTocForTex($attachment->Filename).' ('.$attachment->Filesize.')}'.PHP_EOL;
    $filetype = $this->getAttachmentFiletype($attachment);
    if(is_null($filetype) === false) {
      $handler = 'import'.$filetype.'ToTex';
//       var_dump("Call Filetype Handler");
//       var_dump($attachment->Filename);
//       var_dump($handler);
//       var_dump($attachment);
      call_user_func_array(array($this, $handler), array(&$texCode, &$attachmentTexCode, &$attachment));
//       var_dump($attachmentTexCode);
    } else {
      var_dump("Unknown Filetype Error");
      var_dump($attachment->Filename);
      var_dump($attachment->ContentType);
    }
    $texCode .= '\hrule'.PHP_EOL;
  }
  
  protected function importTexToTex(& $texCode, & $attachmentTexCode, stdClass $attachment)
  {
    $this->importAttachmentToToc($attachmentTexCode, $attachment);
    $attachmentTexCode .= '\clearpage'.PHP_EOL;
    $attachmentTexCode .= '\lstinputlisting[language=TeX,caption='.$this->escapeTocForTex($attachment->Filename).']{'.$this->escapeFilenameForTex($attachment->LocalFile).'}'.PHP_EOL;
  }
  
//   protected function importEmailToTex(& $texCode, & $attachmentTexCode, stdClass $attachment)
//   {
//     var_dump($attachment);
// //     $attachment->ContentType = "text/plain";
//     $this->importPlaintextToTex($texCode, $attachmentTexCode, $attachment, false);
//   }
  
  protected function importHtmlToTex(& $texCode, & $attachmentTexCode, stdClass $attachment)
  {
    $alteredFile = tempnam(dirname($attachment->LocalFile), basename($attachment->LocalFile).'_altered_');
    file_put_contents($alteredFile, strip_tags(html_entity_decode($this->br2nl(file_get_contents($attachment->LocalFile)))));
    $attachment->LocalFile = $alteredFile;
    $attachment->ContentType = "text/plain";
    $this->importPlaintextToTex($texCode, $attachmentTexCode, $attachment, false);
  }
  
  protected function importPlaintextToTex(& $texCode, & $attachmentTexCode, stdClass $attachment, $realplaintext = true)
  {
    if($realplaintext === true) {
      $attachmentTexCode .= '\clearpage'.PHP_EOL;
    }
    $this->importAttachmentToToc($attachmentTexCode, $attachment);
    $attachmentTexCode .= '\clearpage'.PHP_EOL;
    $attachmentTexCode .= '\setcounter{includepagecounter}{0}'.PHP_EOL;
    $attachmentTexCode .= '\filenote{'.$this->escapeTocForTex($attachment->Filename).' ('.$attachment->Filesize.')}'.PHP_EOL;
    $attachmentTexCode .= '\verbatiminput{'.$this->escapeFilenameForTex($attachment->LocalFile).'}'.PHP_EOL;
  }
  
  protected function importIgnoreToTex(& $texCode, & $attachmentTexCode, stdClass $attachment)
  {
    //! Do exactly nothing
    return;
  }
  
  protected function importPdfToTex(& $texCode, & $attachmentTexCode, stdClass $attachment)
  {
    $this->importAttachmentToToc($attachmentTexCode, $attachment);
    
    //! Some Problems with some PDFs result in a required rewrite of the PDF data via ghostscript  
    $backup = $attachment->LocalFile.'-convert-backup.pdf'.
    error_reporting(E_ALL | E_STRICT | E_DEPRECATED);
    if(rename($attachment->LocalFile, $backup)) {
      shell_exec("gs -q -dNOPAUSE -dBATCH -P- -dSAFER -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -sOutputFile='".$attachment->LocalFile."' -c save pop -f '".$backup."'");
    } else {
      echo 'ERROR: Failed to move file '.$attachment->LocalFile.' to '.$backup.' and convert it.'.PHP_EOL;
    }
    //! \includepdf has problems with a missing fileextension.
    $extension = substr($attachment->LocalFile, strrpos($attachment->LocalFile, '.'));
    if($extension != '.pdf' && rename($attachment->LocalFile, $attachment->LocalFile.'.pdf')) {
      $attachment->LocalFile .= '.pdf';
    }
    
    //! Is already done by includepdf
    // $attachmentTexCode .= '\clearpage'.PHP_EOL;
    $attachmentTexCode .= '\setcounter{includepagecounter}{0}'.PHP_EOL;
    $attachmentTexCode .= '\includepdf[pages={-},scale=0.83,pagecommand=\filenote{'.$this->escapeTocForTex($attachment->Filename).' ('.$attachment->Filesize.')}]{'.$this->escapeFilenameForTex($attachment->LocalFile).'}'.PHP_EOL;
  }
  
  protected function importAttachmentToToc(& $texCode, stdClass $attachment, $level = 'subsection')
  {
    $texCode .= '\addcontentsline{toc}{'.$level.'}{'.$this->escapeContentslineForTex($attachment->Filename).'}'.PHP_EOL;
  }
  
  protected function exportMboxContainer($attachment)
  {
  }
  
  protected function exportEmailContainer($attachment)
  {
    var_dump($attachment->LocalFile);
    $mailparser = new MailParser($attachment->LocalFile);
    $partsAsAttachments = array();
    foreach($mailparser->getEndParts() as $part) {
      $partAsAttachment = new stdClass;
      $partAsAttachment->Filename = $attachment->Filename.' Part '.$part->getPath();
      $partAsAttachment->LocalFile = tempnam(dirname($attachment->LocalFile), basename($attachment->LocalFile).'_part_'.$part->getPath());
      file_put_contents($partAsAttachment->LocalFile, $part->getPartBodyContent());
      $partAsAttachment->Timestamp = $part->getDateTimestamp();
      $partAsAttachment->Created = $part->getDateHeader();
      $partAsAttachment->From = $part->getFromHeader();
      $partAsAttachment->ContentType = $part->getContentType();
      $partAsAttachment->ContentCharset = $part->getCharset();
      $partAsAttachment->Charset = $part->getCharset();
      $partsAsAttachments[] = $partAsAttachment;
      var_dump($partAsAttachment);
//       var_dump($part->getPath());
//       var_dump($part->isAlternativePart());
//       var_dump($part->isSignedAlternativePart());
//       var_dump($part->hasChildren());
//       var_dump($part->getFromHeader());
//       var_dump($part->getToHeader());
//       var_dump($part->getDateHeader());
//       var_dump($part->getDateTimestamp());
//       var_dump($part->getSubjectHeader());
//       var_dump($part->getPartBodyContent());
    }
//     var_dump($mailparser);
//     die('herpderp');
    return $partsAsAttachments; //array($attachment);
  }
  
  protected function exportZipContainer($attachment)
  {
  var_dump('#####################');
  var_dump($attachment->LocalFile);
    return array();
  }
  
  protected function exportRarContainer($attachment)
  {
    return array();
  }
  
  protected function isContainerType($filetype)
  {
    return method_exists($this, 'export'.$filetype.'Container');
  }
  
  protected function getAttachmentFiletypeByMimetype($mimetype)
  {
    switch($mimetype)
    {
      case 'text/plain':
	return 'Plaintext';
	break;
      case 'text/html':
      case 'application/xml+xhtml':
	return 'Html';
	break;
      case 'application/pdf':
      case 'application/x-pdf':
	return 'Pdf';
	break;
      case 'application/mbox':
	return 'Mbox';
	break;
      case 'message/rfc822':
	return 'Email';
	break;
      case 'application/zip':
	return 'Zip';
	break;
      case 'application/rar':
	return 'Rar';
	break;
      case 'application/pgp-signature': //! TODO Should maybe handled specially
	return 'Ignore';
	break;
      case false:
      default:
	return false;
	break;
    }
  }
  
  protected function getAttachmentFiletypeByExtension($extension)
  {
    switch($extension)
    {
      case '.txt':
	return 'Plaintext';
	break;
      case '.html':
	return 'Html';
	break;
      case '.pdf':
	return 'Pdf';
	break;
      case '.tex':
	return 'Tex';
	break;
      case '.mbox':
	return 'Mbox';
	break;
      case '.eml':
	return 'Email';
	break;
      case '.zip':
	return 'Zip';
	break;
      case '.rar':
	return 'Rar';
	break;
      case '.asc': //! TODO Should maybe handled specially
	return 'Ignore';
	break;
      case false:
      default:
	return null;
    }
  }
	
  protected function getAttachmentFiletype(stdClass $attachment)
  {
  var_dump('#####################');
//     //! ugly hotFix because some containers (e.g. mbox) seems to be constantly misdetected 
    $extension = substr($attachment->Filename, strrpos($attachment->Filename, '.'));
//     $type = $this->getAttachmentFiletypeByExtension($extension);
//     var_dump($extension);
//     var_dump($type);
//     if($this->isContainerType($type)) {
//       return $type;
//     }
    
    $fp = fopen($attachment->LocalFile, 'r');
    $offsetSecondLine = 0;
    do {
      $offsetSecondLine++;
    } while(fgetc($fp) != "\n");
    fclose($fp);
//     $data = fgets($fp);
    
    $mimeinfo =<<<EOI
#------------------------------------------------------------------------------
0	string		From 		mbox prefix
&$offsetSecondLine	string/t		Return-Path: 	mbox mail container
!:mime	application/mbox
0	string		From 		mbox prefix
&$offsetSecondLine	string/t		Delivered-To: 	mbox mail container
!:mime	application/mbox
0	string/t		From:		news or mail text
!:mime	message/rfc822

EOI;
    $mimeinfofile = tempnam(sys_get_temp_dir(), 'mimeinfo');
    file_put_contents($mimeinfofile, $mimeinfo);
    $info = new finfo(FILEINFO_MIME_TYPE, $mimeinfofile);
    var_dump($info->file($attachment->LocalFile));
    
    $type = $this->getAttachmentFiletypeByMimetype($info->file($attachment->LocalFile));
    var_dump($mimeinfofile);
    var_dump($type);
    if($type === false) {
      $type = $this->getAttachmentFiletypeByMimetype($attachment->ContentType);
      if($type === false) {
	$info = new finfo(FILEINFO_MIME_TYPE);
	$type = $this->getAttachmentFiletypeByMimetype($info->file($attachment->LocalFile));
	if($type === false) {
	  $type = $this->getAttachmentFiletypeByExtension($extension);
	}
      }
    }
    var_dump($attachment->LocalFile);
    var_dump($type);
    
  var_dump('##########====================###########');
//     unlink($mimeinfofile);
    return $type;
  }
  
  protected function exportArticle($basedir, $article, $index)
  {
    $articleFile = tempnam($basedir, "ARTICLE-".$article->ArticleID."-");
    $handle = fopen($articleFile, "w");
    
//     fwrite($handle, "InReplyTo: ");
//     fwrite($handle, $article->InReplyTo);
//     fwrite($handle, PHP_EOL);
//     fwrite($handle, "ReplyTo: ");
//     fwrite($handle, $article->ReplyTo);
//     fwrite($handle, PHP_EOL);
//     fwrite($handle, "References: ");
//     fwrite($handle, $article->References);
//     fwrite($handle, PHP_EOL);
//     fwrite($handle, PHP_EOL);
//     fwrite($handle, PHP_EOL);
    
//     fwrite($handle, "TicketID: ");
//     fwrite($handle, $article->TicketID);
//     fwrite($handle, PHP_EOL);
    fwrite($handle, "TicketNumber: ");
    fwrite($handle, $article->TicketNumber);
    fwrite($handle, PHP_EOL);
    fwrite($handle, "Title: ");
    fwrite($handle, $article->Title);
    fwrite($handle, PHP_EOL);
    fwrite($handle, "MessageID: ");
    fwrite($handle, $article->MessageID);
    fwrite($handle, PHP_EOL);
//     fwrite($handle, "ArticleID: ");
//     fwrite($handle, $article->ArticleID);
//     fwrite($handle, PHP_EOL);
//     fwrite($handle, "Created: ");
//     fwrite($handle, $article->Created);
//     fwrite($handle, PHP_EOL);
//     fwrite($handle, "IncomingTime: ");
//     fwrite($handle, $article->IncomingTime);
//     fwrite($handle, PHP_EOL);
//     fwrite($handle, "Queue: ");
//     fwrite($handle, $article->Queue);
//     fwrite($handle, PHP_EOL);
//     fwrite($handle, PHP_EOL);
//     if(isset($article->DynamicField_Aktenzeichen)) {
//       fwrite($handle, "Aktenzeichen: ");
//       fwrite($handle, $article->DynamicField_Aktenzeichen);
//       fwrite($handle, PHP_EOL);
//     }
//     
//     if(isset($article->DynamicField_Kammer)) {
//       fwrite($handle, "Kammer: ");
//       fwrite($handle, $article->DynamicField_Kammer);
//       fwrite($handle, PHP_EOL);
//     }
//     
//     if(isset($article->DynamicField_PadUrl)) {
//       fwrite($handle, "Pad URL: ");
//       fwrite($handle, $article->DynamicField_PadUrl);
//       fwrite($handle, PHP_EOL);
//     }
//     fwrite($handle, PHP_EOL);
//     fwrite($handle, PHP_EOL);
    
    fwrite($handle, "From: ");//.$article->FromRealname);
//     fwrite($handle, " <".$article->From.">");
    fwrite($handle, $article->From);
    fwrite($handle, PHP_EOL);    
    fwrite($handle, "To: ");//.$article->ToRealname);
//     fwrite($handle, " <".$article->To.">");
    fwrite($handle, $article->To);
    fwrite($handle, PHP_EOL);
    fwrite($handle, "CC: ");
    fwrite($handle, $article->Cc);
    fwrite($handle, PHP_EOL);    
    fwrite($handle, "Date: ");
    fwrite($handle, $article->Created);
    fwrite($handle, PHP_EOL);    
    fwrite($handle, "Subject: ");
    fwrite($handle, $article->Subject);
    fwrite($handle, PHP_EOL);    
    fwrite($handle, PHP_EOL);    
//     fwrite($handle, "Body: ");
    fwrite($handle, PHP_EOL);
//     if($article->ContentType == "text/html") {
//       var_dump("================================ HTML !!! =====================================");
//       $article->Body = strip_tags(html_entity_decode($this->br2nl($article->Body)));
//     }
    fwrite($handle, $article->Body);
    fwrite($handle, PHP_EOL);

    $retval = array(
		"File" => $articleFile,
		"From" => $article->From,
		"Created" => $article->Created,
		"ContentCharset" => $article->ContentCharset,
		"Charset" => $article->Charset,
		"ContentType" => $article->ContentType,
		"attachments" => array()
	      );
    $attachments = $this->otrs->getArticleAttachements($article->ArticleID);
    foreach($attachments as $attachIndex => $attachment) {
      $attachmentData = $this->exportAttachment($basedir, $article, $attachIndex);
      if(is_null($attachmentData) !== true) {
	$retval["attachments"][$attachIndex] = $attachmentData;
      }
    }
    
    fwrite($handle, PHP_EOL);    
    fwrite($handle, PHP_EOL);  
    fwrite($handle, PHP_EOL);    
    fwrite($handle, PHP_EOL);    
//     fwrite($handle, "Attachments: ");
//     fwrite($handle, PHP_EOL);
//     fwrite($handle, PHP_EOL);
//     foreach($retval["attachments"] as $index => $attachment) {
//       fwrite($handle, $index.". ".$attachment->Filename);
//       fwrite($handle, " (".$attachment->ContentType);
//       fwrite($handle, "; ".$attachment->Filesize.")");
//       fwrite($handle, " [".basename($attachment->LocalFile)."]");
//       fwrite($handle, PHP_EOL);
//     }   
    
    fclose($handle);
    
    return (object)$retval;
  }
  
  protected function exportAttachment($basedir, $article, $index)
  {
    $attachmentData = $this->otrs->getArticleAttachement($article->ArticleID, $index);
    if($attachmentData["ContentAlternative"] == 1) {
      return null;
    }
    unset($attachmentData["Alternative"]);
    unset($attachmentData["ContentAlternative"]);
    unset($attachmentData["ContentID"]);
    unset($attachmentData["FilesizeRaw"]);
    $ext = substr($attachmentData["Filename"], strrpos($attachmentData["Filename"], '.'));
    $attachmentData["LocalFile"] = tempnam($basedir, "ATTACHMENT-".$article->ArticleID."-".$index."-");
    rename($attachmentData["LocalFile"], $attachmentData["LocalFile"].$ext);
    $attachmentData["LocalFile"] = $attachmentData["LocalFile"].$ext;
    $handle = fopen($attachmentData["LocalFile"], "wb");
    fwrite($handle, $attachmentData["Content"]);
    unset($attachmentData["Content"]);
    fclose($handle);
    $attachmentData["ContentType"] = strstr($attachmentData["ContentType"], ";", true);
    $attachmentData = (object)$attachmentData;
    return $attachmentData;
  }
}