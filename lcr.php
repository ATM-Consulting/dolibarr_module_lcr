<?php
/* Copyright (C) 2002-2005 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004      Eric Seigne          <eric.seigne@ryxeo.com>
 * Copyright (C) 2004-2014 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012 Regis Houssin        <regis.houssin@capnetworks.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 * Note: Page can be call with param mode=sendremind to bring feature to send
 * remind by emails.
 */

/**
 *		\file       htdocs/compta/facture/impayees.php
 *		\ingroup    facture
 *		\brief      Page to list and build liste of unpaid invoices
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
dol_include_once('/lcr/lib/lcr.lib.php');

$langs->load("mails");
$langs->load("bills");

$id = (GETPOST('facid','int') ? GETPOST('facid','int') : GETPOST('id','int'));
$action = GETPOST('action','alpha');
$option = GETPOST('option');
$mode=GETPOST('mode');
$builddoc_generatebutton=GETPOST('builddoc_generatebutton');

// Security check
if (isset($user->societe_id)) $socid=$user->societe_id;
$result = restrictedArea($user,'facture',$id,'');

$diroutputpdf=$conf->lcr->dir_output;
if ($user->hasRight('societe','client','read') || isset($socid)) $diroutputpdf.='/private/'.$user->id;	// If user has no permission to see all, output dir is specific to user

$resultmasssend='';


/*
 * Action
 */

// Send remind email
if ($action == 'presend' && GETPOST('cancel'))
{
	$action='';
	if (GETPOST('models')=='facture_relance') $mode='sendmassremind';	// If we made a cancel from submit email form, this means we must be into mode=sendmassremind
}

if ($action == 'presend' && GETPOST('sendmail'))
{
	if (GETPOST('models')=='facture_relance') $mode='sendmassremind';	// If we made a cancel from submit email form, this means we must be into mode=sendmassremind

	if (!isset($user->email))
	{
		$error++;
		setEventMessage("NoSenderEmailDefined");
	}

	$countToSend = count($_POST['toSend']);
	if (empty($countToSend))
	{
		$error++;
		setEventMessage("InvoiceNotChecked","warnings");
	}

	if (! $error)
	{
		$nbsent = 0;
		$nbignored = 0;

		for ($i = 0; $i < $countToSend; $i++)
		{
			$object = new Facture($db);
			$result = $object->fetch($_POST['toSend'][$i]);

			if ($result > 0)	// Invoice was found
			{
				if ($object->statut != 1)
				{
					continue; // Payment done or started or canceled
				}

				// Read document
				// TODO Use future field $object->fullpathdoc to know where is stored default file
				// TODO If not defined, use $object->modelpdf (or defaut invoice config) to know what is template to use to regenerate doc.
				$filename=dol_sanitizeFileName($object->ref).'.pdf';
				$filedir=$conf->facture->dir_output . '/' . dol_sanitizeFileName($object->ref);
				$file = $filedir . '/' . $filename;
				$mime = 'application/pdf';

				if (dol_is_file($file))
				{
					$object->fetch_thirdparty();
					$sendto = $object->thirdparty->email;

					if (empty($sendto)) $nbignored++;

					if (dol_strlen($sendto))
					{
						$langs->load("commercial");
						$from = $user->getFullName($langs) . ' <' . $user->email .'>';
						$replyto = $from;
						$subject = GETPOST('subject');
						$message = GETPOST('message');
						$sendtocc = GETPOST('sentocc');

						$substitutionarray=array(
							'__ID__' => $object->id,
							'__EMAIL__' => $object->thirdparty->email,
							'__CHECK_READ__' => '<img src="'.DOL_MAIN_URL_ROOT.'/public/emailing/mailing-read.php?tag='.$obj2->tag.'&securitykey='.urlencode(getDolGlobalString('MAILING_EMAIL_UNSUBSCRIBE_KEY')).'" width="1" height="1" style="width:1px;height:1px" border="0"/>',
							//'__LASTNAME__' => $obj2->lastname,
							//'__FIRSTNAME__' => $obj2->firstname,
							'__REF__' => $object->ref,
							'__REFCLIENT__' => $object->thirdparty->name
						);

						$message=make_substitutions($message, $substitutionarray);

						$actiontypecode='AC_FAC';
						$actionmsg=$langs->transnoentities('MailSentBy').' '.$from.' '.$langs->transnoentities('To').' '.$sendto.".\n";
						if ($message)
						{
							$actionmsg.=$langs->transnoentities('MailTopic').": ".$subject."\n";
							$actionmsg.=$langs->transnoentities('TextUsedInTheMessageBody').":\n";
							$actionmsg.=$message;
						}

						// Create form object
						$attachedfiles=array('paths'=>array($file), 'names'=>array($filename), 'mimes'=>array($mime));
						$filepath = $attachedfiles['paths'];
						$filename = $attachedfiles['names'];
						$mimetype = $attachedfiles['mimes'];

						// Send mail
						require_once(DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php');
						$mailfile = new CMailFile($subject,$sendto,$from,$message,$filepath,$mimetype,$filename,$sendtocc,'',$deliveryreceipt,-1);
						if ($mailfile->error)
						{
							$resultmasssend.='<div class="error">'.$mailfile->error.'</div>';
						}
						else
						{
							//$result=$mailfile->sendfile();
							if ($result)
							{
								$resultmasssend.=$langs->trans('MailSuccessfulySent',$mailfile->getValidAddress($from,2),$mailfile->getValidAddress($sendto,2));		// Must not contain "

								$error=0;

								// Initialisation donnees
								$object->sendtoid		= 0;
								$object->actiontypecode	= $actiontypecode;
								$object->actionmsg		= $actionmsg;  // Long text
								$object->actionmsg2		= $actionmsg2; // Short text
								$object->fk_element		= $object->id;
								$object->elementtype	= $object->element;

								// Appel des triggers
								include_once(DOL_DOCUMENT_ROOT . "/core/class/interfaces.class.php");
								$interface=new Interfaces($db);
								$result=$interface->run_triggers('BILL_SENTBYMAIL',$object,$user,$langs,$conf);
								if ($result < 0) { $error++; $this->errors=$interface->errors; }
								// Fin appel triggers

								if (! $error)
								{
									$resultmasssend.=$langs->trans("MailSent").': '.$sendto."<br>\n";
								}
								else
								{
									dol_print_error($db);
								}
								$nbsent++;

							}
							else
							{
								$langs->load("other");
								if ($mailfile->error)
								{
									$resultmasssend.=$langs->trans('ErrorFailedToSendMail',$from,$sendto);
									$resultmasssend.='<br><div class="error">'.$mailfile->error.'</div>';
								}
								else
								{
									$resultmasssend.='<div class="warning">No mail sent. Feature is disabled by option MAIN_DISABLE_ALL_MAILS</div>';
								}
							}
						}
					}
				}
				else
				{
					$nbignored++;
					$langs->load("other");
					$resultmasssend.='<div class="error">'.$langs->trans('ErrorCantReadFile',$file).'</div>';
					dol_syslog('Failed to read file: '.$file);
					break ;
				}
			}
		}

		if ($nbsent)
		{
			$action='';	// Do not show form post if there was at least one successfull sent
			setEventMessage($nbsent. '/'.$countToSend.' '.$langs->trans("RemindSent"));
		}
		else
		{
			setEventMessage($langs->trans("NoRemindSent"), 'warnings');
		}
	}
}


if ($action == "builddoc" && $user->hasRight('facture','lire')  && ! GETPOST('button_search') && !empty($builddoc_generatebutton))
{
	if (is_array($_POST['toGenerate']))
	{
	    $arrayofinclusion=array();
	    foreach($_POST['toGenerate'] as $tmppdf) $arrayofinclusion[]=preg_quote($tmppdf.'.pdf','/');
		$factures = dol_dir_list($conf->facture->dir_output,'all',1,implode('|',$arrayofinclusion),'\.meta$|\.png','date',SORT_DESC);

		// liste les fichiers
		$files = array();
		$factures_bak = $factures ;
		foreach($_POST['toGenerate'] as $basename)
		{
			foreach($factures as $facture)
			{
				if(strstr($facture["name"],$basename))
				{
					$files[] = $conf->facture->dir_output.'/'.$basename.'/'.$facture["name"];
				}
			}
		}

        // Define output language (Here it is not used because we do only merging existing PDF)
        $outputlangs = $langs;
        $newlang='';
        if (getDolGlobalString('MAIN_MULTILANGS') && empty($newlang) && GETPOST('lang_id')) $newlang=GETPOST('lang_id');
        if (getDolGlobalString('MAIN_MULTILANGS') && empty($newlang)) $newlang=! empty($object->thirdparty->default_lang) ? $object->thirdparty->default_lang : $object->client->default_lang;
        if (! empty($newlang))
        {
            $outputlangs = new Translate("",$conf);
            $outputlangs->setDefaultLang($newlang);
        }

        // Create empty PDF
        $pdf=pdf_getInstance();
        if (class_exists('TCPDF'))
        {
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
        }
        $pdf->SetFont(pdf_getPDFFont($outputlangs));

        if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) $pdf->SetCompression(false);

		dol_include_once('/lcr/core/modules/lcr/modules_lcr.php');

		//$doc = new generic_pdf_lcr($db);
		$TtoGenerate = $_REQUEST['toGenerate'];
		$object = new Facture($db);
		$result = lcr_pdf_create($db, $object, 'generic_lcr', $outputlangs, '', '', '', $TtoGenerate);

		// Add all others
		/*foreach($files as $file)
		{
			// Charge un document PDF depuis un fichier.
			$pagecount = $pdf->setSourceFile($file);
			for ($i = 1; $i <= $pagecount; $i++)
			{
				$tplidx = $pdf->importPage($i);
				$s = $pdf->getTemplatesize($tplidx);
				$pdf->AddPage($s['h'] > $s['w'] ? 'P' : 'L');
				$pdf->useTemplate($tplidx);
			}
		}*/

		// Create output dir if not exists
		dol_mkdir($diroutputpdf);

		// Save merged file
		$filename=strtolower(dol_sanitizeFileName($langs->transnoentities("Unpaid")));
		if ($option=='late') $filename.='_'.strtolower(dol_sanitizeFileName($langs->transnoentities("Late")));
		$pagecount = 0;
		if ($pagecount)
		{
			$now=dol_now();
			$file=$diroutputpdf.'/'.$filename.'_'.dol_print_date($now,'dayhourlog').'.pdf';
			$pdf->Output($file,'F');
			if (getDolGlobalString('MAIN_UMASK'))
			@chmod($file, octdec(getDolGlobalString('MAIN_UMASK')));
		}
	}

} elseif (isset($_POST['toGenerate']) && is_array($_POST['toGenerate']) && isset($_REQUEST['generateCSV'])) {

	generateCSV();

}

// Remove file
if ($action == 'remove_file')
{
	require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

	$langs->load("other");
	$upload_dir = $diroutputpdf;
	$file = $upload_dir . '/' . GETPOST('file');
	$ret=dol_delete_file($file,0,0,0,'');
	if ($ret) setEventMessage($langs->trans("FileWasRemoved", GETPOST('urlfile')));
	else setEventMessage($langs->trans("ErrorFailToDeleteFile", GETPOST('urlfile')), 'errors');
	$action='';
}



/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);

$langs->load('lcr@lcr');

$title=$langs->trans("lcrTitle");
if ($option=='late') $title=$langs->trans("lcrTitle");

llxHeader('',$title);

$dolibarr_version = (float) DOL_VERSION;
?>
<script type="text/javascript">
$(document).ready(function() {
	var version = <?php echo $dolibarr_version; ?>;
	$("#checkall").click(function() {
		$(".checkformerge").attr('checked', true);
	});
	$("#checknone").click(function() {
		$(".checkformerge").attr('checked', false);
	});
	$("#checkallsend").click(function() {
		$(".checkforsend").attr('checked', true);
	});
	$("#checknonesend").click(function() {
		$(".checkforsend").attr('checked', false);
	});
	if(version < 4) {
		$("#model").parent().children().hide();
	} else {
		$('#model').parent().find('.hideonsmartphone').remove();
		$('#model').remove();
	}
});
</script>
<?php

$now=dol_now();

$search_ref = GETPOST("search_ref");
$search_refcustomer=GETPOST('search_refcustomer');
$search_societe = GETPOST("search_societe");
$search_montant_ht = GETPOST("search_montant_ht");
$search_montant_ttc = GETPOST("search_montant_ttc");
$late = GETPOST("late");

$sortfield = GETPOST("sortfield",'alpha');
$sortorder = GETPOST("sortorder",'alpha');
$page = GETPOST("page",'int');
if ($page == -1 || empty($page)) { $page = 0; }
$offset = $conf->liste_limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;
if (! $sortfield) $sortfield="f.date_lim_reglement";
if (! $sortorder) $sortorder="ASC";

$limit = $conf->liste_limit;
$ref_field = (floatval(DOL_VERSION) < 10.0 ? 'facnumber' : 'ref');
$total_field = (floatval(DOL_VERSION) < 10.0 ? 'total' : 'total_ht');
$tva_field = (floatval(DOL_VERSION) < 10.0 ? 'tva' : 'total_tva');

$sql = "SELECT s.nom, s.rowid as socid, s.email";
$sql.= ", f.rowid as facid, f.".$ref_field.", f.ref_client, f.increment, f.".$total_field." as total_ht, f.".$tva_field." as total_tva, f.total_ttc, f.localtax1, f.localtax2, f.revenuestamp";
$sql.= ", f.datef as df, f.date_lim_reglement as datelimite";
$sql.= ", f.paye as paye, f.fk_statut, f.type";
$sql.= ", sum(pf.amount) as am";
if ($user->hasRight('societe','client','read')  && ! $socid) $sql .= ", sc.fk_soc, sc.fk_user ";
$sql.= " FROM ".$db->prefix()."societe as s";
if (! $user->hasRight('societe','client','voir')  && ! $socid) $sql .= ", ".$db->prefix()."societe_commerciaux as sc";
$sql.= ",".$db->prefix()."facture as f";
$sql.= " LEFT JOIN ".$db->prefix()."paiement_facture as pf ON f.rowid=pf.fk_facture ";
$sql.= " WHERE f.fk_soc = s.rowid";
$sql.= " AND f.entity = ".$conf->entity;
$sql.= " AND f.type IN (0,1,3) AND f.fk_statut = 1";
if(getDolGlobalString('LCR_PAIEMENT_MODE'))
	$sql.= " AND fk_mode_reglement = ".getDolGlobalString('LCR_PAIEMENT_MODE');
else
	$sql.= " AND fk_mode_reglement = 52";
$sql.= " AND f.paye = 0";
//if ($option == 'late') $sql.=" AND f.date_lim_reglement < '".$db->idate(dol_now() - $conf->facture->client->warning_delay)."'";
if (! $user->hasRight('societe','client','voir') && ! $socid) $sql .= " AND s.rowid = sc.fk_soc AND sc.fk_user = " .$user->id;
if (! empty($socid)) $sql .= " AND s.rowid = ".$socid;
if (GETPOST('filtre'))
{
	$filtrearr = explode(",", GETPOST('filtre'));
	foreach ($filtrearr as $fil)
	{
		$filt = explode(":", $fil);
		$sql .= " AND " . $filt[0] . " = " . $filt[1];
	}
}
if ($search_ref)         $sql .= " AND f.".$ref_field." LIKE '%".$db->escape($search_ref)."%'";
if ($search_refcustomer) $sql .= " AND f.ref_client LIKE '%".$db->escape($search_refcustomer)."%'";
if ($search_societe)     $sql .= " AND s.nom LIKE '%".$db->escape($search_societe)."%'";
if ($search_montant_ht)  $sql .= " AND f.".$total_field." = '".$db->escape($search_montant_ht)."'";
if ($search_montant_ttc) $sql .= " AND f.total_ttc = '".$db->escape($search_montant_ttc)."'";
if (GETPOST('sf_ref'))   $sql .= " AND f.".$ref_field." LIKE '%".$db->escape(GETPOST('sf_ref'))."%'";
$sql.= " GROUP BY s.nom, s.rowid, s.email, f.rowid, f.".$ref_field.", f.increment, f.".$total_field.", f.".$tva_field.", f.total_ttc, f.localtax1, f.localtax2, f.revenuestamp, f.datef, f.date_lim_reglement, f.paye, f.fk_statut, f.type ";
if (! $user->hasRight('societe','client','voir') && ! $socid) $sql .= ", sc.fk_soc, sc.fk_user ";
$sql.= " ORDER BY ";
$listfield=explode(',',$sortfield);
foreach ($listfield as $key => $value) $sql.=$listfield[$key]." ".$sortorder.",";
$sql.= " f.".$ref_field." DESC";

//$sql .= $db->plimit($limit+1,$offset);

$resql = $db->query($sql);
if ($resql)
{
	$num = $db->num_rows($resql);

	if (! empty($socid))
	{
		$soc = new Societe($db);
		$soc->fetch($socid);
	}

	$param="";
	$param.=(! empty($socid)?"&amp;socid=".$socid:"");
	$param.=(! empty($option)?"&amp;option=".$option:"");
	if ($search_ref)         $param.='&amp;search_ref='.urlencode($search_ref);
    	if ($search_refcustomer) $param.='&amp;search_ref='.urlencode($search_refcustomer);
	if ($search_societe)     $param.='&amp;search_societe='.urlencode($search_societe);
	if ($search_montant_ht)  $param.='&amp;search_montant_ht='.urlencode($search_montant_ht);
	if ($search_montant_ttc) $param.='&amp;search_montant_ttc='.urlencode($search_montant_ttc);
	if ($late)               $param.='&amp;late='.urlencode($late);

	$urlsource=$_SERVER['PHP_SELF'].'?sortfield='.$sortfield.'&sortorder='.$sortorder;
	$urlsource.=str_replace('&amp;','&',$param);

	$titre=(! empty($socid)?$langs->trans("BillsCustomersUnpaidForCompany",$soc->nom):$langs->trans("lcrTitle"));
	if ($option == 'late') $titre.=' ('.$langs->trans("Late").')';
	else $titre.=' ('.$langs->trans("All").')';

	$link='';
	if (empty($option)) $link='<a href="'.$_SERVER["PHP_SELF"].'?option=late">'.$langs->trans("ShowUnpaidLateOnly").'</a>';
	elseif ($option == 'late') $link='<a href="'.$_SERVER["PHP_SELF"].'">'.$langs->trans("ShowUnpaidAll").'</a>';
	print_fiche_titre($titre,$link);
	//print_barre_liste($titre,$page,$_SERVER["PHP_SELF"],$param,$sortfield,$sortorder,'',0);	// We don't want pagination on this page

	dol_htmloutput_mesg($mesg);

	print '<form id="form_unpaid" method="POST" action="'.$_SERVER["PHP_SELF"].'?sortfield='. $sortfield .'&sortorder='. $sortorder .'">';

	if (! empty($mode) && $action == 'presend')
	{
		include_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
		$formmail = new FormMail($db);

		print '<br>';
		print_fiche_titre($langs->trans("SendRemind"),'','').'<br>';

		$topicmail="MailTopicSendRemindUnpaidInvoices";
		$modelmail="facture_relance";

		// Cree l'objet formulaire mail
		include_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
		$formmail = new FormMail($db);
		$formmail->withform=-1;
		$formmail->fromtype = 'user';
		$formmail->fromid   = $user->id;
		$formmail->fromname = $user->getFullName($langs);
		$formmail->frommail = $user->email;
		$formmail->withfrom=1;
		$liste=array();
		$formmail->withto=$langs->trans("AllRecipientSelectedForRemind");
		$formmail->withtofree=0;
		$formmail->withtoreadonly=1;
		$formmail->withtocc=1;
		$formmail->withtoccc=getDolGlobalString('MAIN_EMAIL_USECCC');
		$formmail->withtopic=$langs->transnoentities($topicmail, '__FACREF__', '__REFCLIENT__');
		$formmail->withfile=$langs->trans("EachInvoiceWillBeAttachedToEmail");
		$formmail->withbody=1;
		$formmail->withdeliveryreceipt=1;
		$formmail->withcancel=1;
		// Tableau des substitutions
		//$formmail->substit['__FACREF__']='';
		$formmail->substit['__SIGNATURE__']=$user->signature;
		//$formmail->substit['__REFCLIENT__']='';
		$formmail->substit['__PERSONALIZED__']='';
		$formmail->substit['__CONTACTCIVNAME__']='';

		// Tableau des parametres complementaires du post
		$formmail->param['action']=$action;
		$formmail->param['models']=$modelmail;
		$formmail->param['facid']=$object->id;
		$formmail->param['returnurl']=$_SERVER["PHP_SELF"].'?id='.$object->id;

		print $formmail->get_form();
		print '<br>'."\n";
	}

	print '<input type="hidden" name="token" value="'.$_SESSION['newtoken'].'">';
	print '<input type="hidden" name="mode" value="'.$mode.'">';
	if ($late) print '<input type="hidden" name="late" value="'.dol_escape_htmltag($late).'">';

	if ($resultmasssend)
	{
		print '<br><strong>'.$langs->trans("ResultOfMassSending").':</strong><br>'."\n";
		print $langs->trans("Selected").': '.$countToSend."\n<br>";
		print $langs->trans("Ignored").': '.$nbignored."\n<br>";
		print $langs->trans("Sent").': '.$nbsent."\n<br>";
		//print $resultmasssend;
		print '<br>';
	}

	$i = 0;
	print '<table class="liste" width="100%">';
	print '<tr class="liste_titre">';
	print_liste_field_titre($langs->trans("Ref"),$_SERVER["PHP_SELF"],"f.$ref_field","",$param,"",$sortfield,$sortorder);
    	print_liste_field_titre($langs->trans('RefCustomer'),$_SERVER["PHP_SELF"],'f.ref_client','',$param,'',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("Date"),$_SERVER["PHP_SELF"],"f.datef","",$param,'align="center"',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("DateDue"),$_SERVER["PHP_SELF"],"f.date_lim_reglement","",$param,'align="center"',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("Company"),$_SERVER["PHP_SELF"],"s.nom","",$param,"",$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("AmountHT"),$_SERVER["PHP_SELF"],"f.$total_field","",$param,'align="right"',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("Taxes"),$_SERVER["PHP_SELF"],"f.$tva_field","",$param,'align="right"',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("AmountTTC"),$_SERVER["PHP_SELF"],"f.total_ttc","",$param,'align="right"',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("Received"),$_SERVER["PHP_SELF"],"am","",$param,'align="right"',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("Rest"),$_SERVER["PHP_SELF"],"","",$param,'align="right"',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("Status"),$_SERVER["PHP_SELF"],"fk_statut,paye,am","",$param,'align="right"',$sortfield,$sortorder);
	if (empty($mode))
	{
		print_liste_field_titre($langs->trans("Concat LCR"),$_SERVER["PHP_SELF"],"","",$param,'align="center"',$sortfield,$sortorder);
	}
	else
	{
		print_liste_field_titre($langs->trans("Remind"),$_SERVER["PHP_SELF"],"","",$param,'align="center"',$sortfield,$sortorder);
	}
	print "</tr>\n";

	// Lignes des champs de filtre
	print '<tr class="liste_titre">';
	// Ref
	print '<td class="liste_titre">';
	print '<input class="flat" size="10" type="text" name="search_ref" value="'.$search_ref.'"></td>';
        print '<td class="liste_titre">';
        print '<input class="flat" size="6" type="text" name="search_refcustomer" value="'.$search_refcustomer.'">';
        print '</td>';
	print '<td class="liste_titre">&nbsp;</td>';
	print '<td class="liste_titre">&nbsp;</td>';
	print '<td class="liste_titre" align="left"><input class="flat" type="text" size="10" name="search_societe" value="'.dol_escape_htmltag($search_societe).'"></td>';
	print '<td class="liste_titre" align="right"><input class="flat" type="text" size="8" name="search_montant_ht" value="'.dol_escape_htmltag($search_montant_ht).'"></td>';
	print '<td class="liste_titre">&nbsp;</td>';
	print '<td class="liste_titre" align="right"><input class="flat" type="text" size="8" name="search_montant_ttc" value="'.dol_escape_htmltag($search_montant_ttc).'"></td>';
	print '<td class="liste_titre">&nbsp;</td>';
	print '<td class="liste_titre">&nbsp;</td>';
	print '<td class="liste_titre" align="right">';
	print '<input type="image" class="liste_titre" name="button_search" src="'.img_picto($langs->trans("Search"),'search.png','','',1).'" value="'.dol_escape_htmltag($langs->trans("Search")).'" title="'.dol_escape_htmltag($langs->trans("Search")).'">';
	print '</td>';
	if (empty($mode))
	{
		print '<td class="liste_titre" align="center">';
		if ($conf->use_javascript_ajax) print '<a href="#" id="checkall">'.$langs->trans("All").'</a> / <a href="#" id="checknone">'.$langs->trans("None").'</a>';
		print '</td>';
	}
	else
	{
		print '<td class="liste_titre" align="center">';
		if ($conf->use_javascript_ajax) print '<a href="#" id="checkallsend">'.$langs->trans("All").'</a> / <a href="#" id="checknonesend">'.$langs->trans("None").'</a>';
		print '</td>';
	}
	print "</tr>\n";

	if ($num > 0)
	{
		$var=True;
		$total_ht=0;
		$total_tva=0;
		$total_ttc=0;
		$total_paid=0;

		$facturestatic=new Facture($db);

		while ($i < $num)
		{
			$objp = $db->fetch_object($resql);
			$date_limit=$db->jdate($objp->datelimite);

			$var=!$var;

			print "<tr ".$bc[$var].">";
			$classname = "impayee";

			print '<td class="nowrap">';

			$facturestatic->id=$objp->facid;
			$facturestatic->ref=$objp->$ref_field;
			$facturestatic->type=$objp->type;

			print '<table class="nobordernopadding"><tr class="nocellnopadd">';

			// Ref
			print '<td class="nobordernopadding nowrap">';
			print $facturestatic->getNomUrl(1);
			print '</td>';

			// Warning picto
			print '<td width="20" class="nobordernopadding nowrap">';
			if ($date_limit < ($now - $conf->facture->client->warning_delay) && ! $objp->paye && $objp->fk_statut == 1) print img_warning($langs->trans("Late"));
			print '</td>';

			// PDF Picto
			print '<td width="16" align="right" class="nobordernopadding hideonsmartphone">';
            $filename=dol_sanitizeFileName($objp->$ref_field);
			$filedir=$conf->facture->dir_output . '/' . dol_sanitizeFileName($objp->$ref_field);
			print $formfile->getDocumentsLink($facturestatic->element, $filename, $filedir);
            print '</td>';

			print '</tr></table>';

			print "</td>\n";

	                // Customer ref
	                print '<td class="nowrap">';
	                print $objp->ref_client;
	                print '</td>';

			print '<td class="nowrap" align="center">'.dol_print_date($db->jdate($objp->df),'day').'</td>'."\n";
			print '<td class="nowrap" align="center">'.dol_print_date($db->jdate($objp->datelimite),'day').'</td>'."\n";

			print '<td><a href="'.DOL_URL_ROOT.'/comm/'.((float)DOL_VERSION > 3.6 ? 'card' : 'fiche').'.php?socid='.$objp->socid.'">'.img_object($langs->trans("ShowCompany"),"company").' '.dol_trunc($objp->nom,28).'</a></td>';

			print '<td align="right">'.price($objp->total_ht).'</td>';
			print '<td align="right">'.price($objp->total_tva);
			$tx1=price2num($objp->localtax1);
			$tx2=price2num($objp->localtax2);
			$revenuestamp=price2num($objp->revenuestamp);
			if (! empty($tx1) || ! empty($tx2) || ! empty($revenuestamp)) print '+'.price($tx1 + $tx2 + $revenuestamp);
			print '</td>';
			print '<td align="right">'.price($objp->total_ttc).'</td>';
			print '<td align="right">';
			$cn=$facturestatic->getSumCreditNotesUsed();
			$dep=$facturestatic->getSumDepositsUsed();
			print price($objp->am + $cn + $dep);
			print '</td>';

			// Remain to receive
			print '<td align="right">'.price($objp->total_ttc-$objp->am-$cn-$dep).'</td>';

			// Status of invoice
			print '<td align="right" class="nowrap">';
			print $facturestatic->LibStatut($objp->paye,$objp->fk_statut,5,$objp->am);
			print '</td>';

			if (empty($mode))
			{
				// Checkbox to merge
				print '<td align="center">';
				print '<input id="cb'.$objp->facid.'" class="flat checkformerge" type="checkbox" name="toGenerate[]" value="'.$objp->$ref_field.'">';
				print '</td>' ;
			}
			else
			{
				// Checkbox to send remind
				print '<td class="nowrap" align="center">';
				if ($objp->email) print '<input class="flat checkforsend" type="checkbox" name="toSend[]" value="'.$objp->facid.'">';
				else print img_picto($langs->trans("NoEMail"), 'warning.png');
				print '</td>' ;
			}

			print "</tr>\n";
			$total_ht+=$objp->total_ht;
			$total_tva+=($objp->total_tva + $tx1 + $tx2 + $revenuestamp);
			$total_ttc+=$objp->total_ttc;
			$total_paid+=$objp->am + $cn + $dep;

			$i++;
		}

		print '<tr class="liste_total">';
		print '<td colspan="5" align="left">'.$langs->trans("Total").'</td>';
		print '<td align="right"><b>'.price($total_ht).'</b></td>';
		print '<td align="right"><b>'.price($total_tva).'</b></td>';
		print '<td align="right"><b>'.price($total_ttc).'</b></td>';
		print '<td align="right"><b>'.price($total_paid).'</b></td>';
		print '<td align="right"><b>'.price($total_ttc - $total_paid).'</b></td>';
		print '<td align="center">&nbsp;</td>';
		print '<td align="center">&nbsp;</td>';
		print "</tr>\n";
	}

	print "</table>";


	if (empty($mode))
	{
		/*
		 * Show list of available documents
		 */
		$filedir=$diroutputpdf;
		$genallowed=$user->hasRight('facture', 'lire');
		$delallowed=$user->hasRight('facture', 'lire');

		print '<br>';
		print '<input type="hidden" name="option" value="'.$option.'">';
		// We disable multilang because we concat already existing pdf.
		//echo $filedir;exit;
		$formfile->show_documents('lcr','',$filedir,$urlsource,$genallowed,$delallowed,'',1,1,0,48,1,$param,$langs->trans("Fichier LCR générés"),$langs->trans("Fusion LCR"));

		?>

			<script type="text/javascript">

				$("#builddoc_generatebutton").parent().append(' <input class="button" type="SUBMIT" name="generateCSV" value="Générer CSV">');

			</script>

		<?php

	}
	else
	{
		if ($action != 'presend')
		{
			print '<div class="tabsAction">';
			print '<a href="'.$_SERVER["PHP_SELF"].'?mode=sendremind&action=presend" class="butAction" name="buttonsendremind" value="'.dol_escape_htmltag($langs->trans("SendRemind")).'">'.$langs->trans("SendRemind").'</a>';
			print '</div>';
			print '<br>';
		}
	}

	print '</form>';

	$db->free($resql);
}
else dol_print_error($db,'');


llxFooter();
$db->close();
