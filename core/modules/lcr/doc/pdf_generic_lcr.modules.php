<?php

dol_include_once('/core/modules/facture/modules_facture.php');
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';

class pdf_generic_lcr extends ModelePDFFactures
{

	var $db;
	var $name;
	var $description;
	var $type;

	var $phpmin = array(4, 3, 0); // Minimum version of PHP required by module
	var $version = 'dolibarr';

	var $page_largeur;
	var $page_hauteur;
	var $format;
	var $marge_gauche;
	var $marge_droite;
	var $marge_haute;
	var $marge_basse;

	var $emetteur;    // Objet societe qui emet

	function __construct($db)
	{
		global $conf, $langs, $mysoc;

		$langs->load("main");
		$langs->load("bills");

		$this->db = $db;
		$this->name = "Facture Epoxy 3000";
		$this->description = $langs->trans('PDFCrabeDescription');

		// Dimension page pour format A4
		$this->type = 'pdf';
		$formatarray = pdf_getFormat();
		$this->page_largeur = $formatarray['width'];
		$this->page_hauteur = $formatarray['height'];
		$this->format = array($this->page_largeur, $this->page_hauteur);
		$this->marge_gauche = getDolGlobalInt('MAIN_PDF_MARGIN_LEFT', 10);
		$this->marge_droite = getDolGlobalInt('MAIN_PDF_MARGIN_RIGHT', 10);
		$this->marge_haute = getDolGlobalInt('MAIN_PDF_MARGIN_TOP', 10);
		$this->marge_basse = getDolGlobalInt('MAIN_PDF_MARGIN_BOTTOM', 10);

		$this->option_logo = 1;                    // Affiche logo
		$this->option_tva = 1;                     // Gere option tva FACTURE_TVAOPTION
		$this->option_modereg = 1;                 // Affiche mode reglement
		$this->option_condreg = 1;                 // Affiche conditions reglement
		$this->option_codeproduitservice = 1;      // Affiche code produit-service
		$this->option_multilang = 1;               // Dispo en plusieurs langues
		$this->option_escompte = 1;                // Affiche si il y a eu escompte
		$this->option_credit_note = 1;             // Support credit notes
		$this->option_freetext = 1;                   // Support add of a personalised text
		$this->option_draft_watermark = 1;           // Support add of a watermark on drafts

		$this->franchise = !$mysoc->tva_assuj;

		// Get source company
		$this->emetteur = $mysoc;
		if (empty($this->emetteur->country_code)) $this->emetteur->country_code = substr($langs->defaultlang, -2);    // By default, if was not defined

		// Define position of columns
		$this->posxdesc = $this->marge_gauche + 1;
		$this->posxtva = 112;
		$this->posxup = 126;
		$this->posxqty = 145;
		$this->posxdiscount = 162;
		$this->postotalht = 174;
		if (getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT', 0)) $this->posxtva = $this->posxup;
		$this->posxpicture = $this->posxtva - getDolGlobalInt('MAIN_DOCUMENTS_WITH_PICTURE_WIDTH', 20); // width of images		if ($this->page_largeur < 210) // To work with US executive format
		{
			$this->posxpicture -= 20;
			$this->posxtva -= 20;
			$this->posxup -= 20;
			$this->posxqty -= 20;
			$this->posxdiscount -= 20;
			$this->postotalht -= 20;
		}

		$this->tva = array();
		$this->localtax1 = array();
		$this->localtax2 = array();
		$this->atleastoneratenotnull = 0;
		$this->atleastonediscount = 0;
	}


	function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{

		global $langs, $conf, $hookmanager;

		if (!is_object($outputlangs)) $outputlangs = $langs;
		// For backward compatibility with FPDF, force output charset to ISO, because FPDF expect text to be encoded in ISO
		if (getDolGlobalInt('MAIN_USE_FPDF', 0)) $outputlangs->charset_output = 'ISO-8859-1';

		$outputlangs->load("main");
		$outputlangs->load("dict");
		$outputlangs->load("companies");
		$outputlangs->load("bills");
		$outputlangs->load("products");

		if ($conf->facture->dir_output) {
			$dir = $conf->lcr->dir_output . "/";
			$file = $dir . "" . 'lcr_' . date('YmdHis') . ".pdf";

			if (!file_exists($dir)) {
				if (dol_mkdir($dir) < 0) {
					$this->error = $langs->transnoentities("ErrorCanNotCreateDir", $dir);
					return 0;
				}
			}

			if (file_exists($dir)) {
				$pdf = pdf_getInstance($this->format);
				$pdf->SetAutoPageBreak(1, 0);

				$this->_showLCR($pdf, $object, $outputlangs, $object->TtoGenerate);

				$pdf->Close();

				$pdf->Output($file, 'F');

				// Add pdfgeneration hook
				if (!is_object($hookmanager)) {
					include_once DOL_DOCUMENT_ROOT . '/core/class/hookmanager.class.php';
					$hookmanager = new HookManager($this->db);
				}
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters = array('file' => $file, 'object' => $object, 'outputlangs' => $outputlangs);
				global $action;
				$reshook = $hookmanager->executeHooks('afterPDFCreation', $parameters, $this, $action);    // Note that $action and $object may have been modified by some hooks

				if ($umask = getDolGlobalString('MAIN_UMASK'))
					@chmod($file, octdec($umask));

				return 1;   // Pas d'erreur
			} else {
				$this->error = $langs->trans("ErrorCanNotCreateDir", $dir);
				return 0;
			}
		} else {
			$this->error = $langs->trans("ErrorConstantNotDefined", "FAC_OUTPUTDIR");
			return 0;
		}
		$this->error = $langs->trans("ErrorUnknown");
		return 0;   // Erreur par defaut
	}

	function _pagehead(&$pdf, $object, $showaddress, $outputlangs)
	{
		global $conf, $langs;

		$outputlangs->load("main");
		$outputlangs->load("bills");
		$outputlangs->load("propal");
		$outputlangs->load("companies");

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $default_font_size + 3);

		$posy = $this->marge_haute;
		$posx = $this->page_largeur - $this->marge_droite - 100;

		$pdf->SetXY($this->marge_gauche, $posy);

		$text = $this->emetteur->name;
		$pdf->MultiCell(100, 4, $outputlangs->convToOutputCharset($text), 0, 'L');

		$pdf->SetFont('', 'B', $default_font_size + 3);
		$pdf->SetXY($posx, $posy);
		$pdf->SetTextColor(0, 0, 60);
		$title = $outputlangs->transnoentities("Invoice");
		if ($object->type == 1) $title = $outputlangs->transnoentities("InvoiceReplacement");
		if ($object->type == 2) $title = $outputlangs->transnoentities("InvoiceAvoir");
		if ($object->type == 3) $title = $outputlangs->transnoentities("InvoiceDeposit");
		if ($object->type == 4) $title = $outputlangs->transnoentities("InvoiceProFormat");
		$pdf->MultiCell(100, 3, $title, '', 'R');

		$pdf->SetFont('', 'B', $default_font_size);

		$posy += 5;
		$pdf->SetXY($posx, $posy);
		$pdf->SetTextColor(0, 0, 60);
		$pdf->MultiCell(100, 4, $outputlangs->transnoentities("Ref") . " : " . $outputlangs->convToOutputCharset($object->ref), '', 'R');

		$posy += 1;
		$pdf->SetFont('', '', $default_font_size - 2);

		if ($object->ref_client) {
			$posy += 4;
			$pdf->SetXY($posx, $posy);
			$pdf->SetTextColor(0, 0, 60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("RefCustomer") . " : " . $outputlangs->convToOutputCharset($object->ref_client), '', 'R');
		}

		if ($object->type == 0 && $objectidnext) {
			$objectreplacing = new Facture($this->db);
			$objectreplacing->fetch($objectidnext);

			$posy += 3;
			$pdf->SetXY($posx, $posy);
			$pdf->SetTextColor(0, 0, 60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("ReplacementByInvoice") . ' : ' . $outputlangs->convToOutputCharset($objectreplacing->ref), '', 'R');
		}
		if ($object->type == 1) {
			$objectreplaced = new Facture($this->db);
			$objectreplaced->fetch($object->fk_facture_source);

			$posy += 4;
			$pdf->SetXY($posx, $posy);
			$pdf->SetTextColor(0, 0, 60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("ReplacementInvoice") . ' : ' . $outputlangs->convToOutputCharset($objectreplaced->ref), '', 'R');
		}
		if ($object->type == 2) {
			$objectreplaced = new Facture($this->db);
			$objectreplaced->fetch($object->fk_facture_source);

			$posy += 3;
			$pdf->SetXY($posx, $posy);
			$pdf->SetTextColor(0, 0, 60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("CorrectionInvoice") . ' : ' . $outputlangs->convToOutputCharset($objectreplaced->ref), '', 'R');
		}

		$posy += 4;
		$pdf->SetXY($posx, $posy);
		$pdf->SetTextColor(0, 0, 60);
		$pdf->MultiCell(100, 3, $outputlangs->transnoentities("DateInvoice") . " : " . dol_print_date($object->date, "day", false, $outputlangs), '', 'R');

		if ($object->type != 2) {
			$posy += 3;
			$pdf->SetXY($posx, $posy);
			$pdf->SetTextColor(0, 0, 60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("DateEcheance") . " : " . dol_print_date($object->date_lim_reglement, "day", false, $outputlangs, true), '', 'R');
		}

		if ($object->client->code_client) {
			$posy += 3;
			$pdf->SetXY($posx, $posy);
			$pdf->SetTextColor(0, 0, 60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("CustomerCode") . " : " . $outputlangs->transnoentities($object->client->code_client), '', 'R');
		}

		$posy += 1;

		if ($showaddress) {

			// Show sender
			$posy = 40;
			$posx = $this->marge_gauche;
			if (getDolGlobalInt('MAIN_INVERT_SENDER_RECIPIENT', 0)) {
				$posx = $this->page_largeur - $this->marge_droite - 80;
			}
			$hautcadre = 38;

			// Show sender frame
			$pdf->SetTextColor(0, 0, 0);
			$pdf->SetFont('', '', $default_font_size - 2);
			$pdf->SetXY($posx, $posy - 5);
			$pdf->MultiCell(66, 5, $outputlangs->transnoentities("BillFrom") . ":", 0, 'L');
			$pdf->SetXY($posx, $posy);
			$pdf->SetFillColor(230, 230, 230);
			$pdf->MultiCell(92, $hautcadre, "", 0, 'R', 1);
			$pdf->SetTextColor(0, 0, 60);

			// Show sender name
			$pdf->SetXY($posx + 2, $posy + 3);
			$pdf->SetFont('', 'B', $default_font_size);
			$pdf->MultiCell(90, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
			$posy = $pdf->getY();

			// Show sender information
			$pdf->SetXY($posx + 2, $posy);
			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->MultiCell(90, 4, $carac_emetteur, 0, 'L');


			// If BILLING contact defined on invoice, we use it
			$usecontact = false;

			// Recipient name
			if (!empty($usecontact)) {
				// On peut utiliser le nom de la societe du contact
				if (getDolGlobalInt('MAIN_USE_COMPANY_NAME_OF_CONTACT', 0)) {
					$socname = $object->contact->socname;
				} else {
					$socname = $object->client->nom;
				}
				$carac_client_name = $outputlangs->convToOutputCharset($socname);
			} else {
				$carac_client_name = $outputlangs->convToOutputCharset($object->client->nom);
			}

			$carac_client = pdf_build_address($outputlangs, $this->emetteur, (!empty($object->thirdparty) ? $object->thirdparty : $object->client), ($usecontact ? $object->contact : ''), $usecontact, 'target');

			// Show recipient
			$widthrecbox = 92;
			if ($this->page_largeur < 210) $widthrecbox = 84;    // To work with US executive format
			$posy = 40;
			$posx = $this->page_largeur - $this->marge_droite - $widthrecbox;
			if (getDolGlobalInt('MAIN_INVERT_SENDER_RECIPIENT', 0)) {
				$posx = $this->marge_gauche;
			}
			// Show recipient frame
			$pdf->SetTextColor(0, 0, 0);
			$pdf->SetFont('', '', $default_font_size - 2);
			$pdf->SetXY($posx + 2, $posy - 5);
			$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("BillTo") . ":", 0, 'L');
			$pdf->Rect($posx, $posy, $widthrecbox, $hautcadre);

			// Show recipient name
			$pdf->SetXY($posx + 2, $posy + 3);
			$pdf->SetFont('', 'B', $default_font_size);
			$pdf->MultiCell($widthrecbox, 4, $carac_client_name, 0, 'L');

			// Show recipient information
			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->SetXY($posx + 2, $posy + 4 + (dol_nboflines_bis($carac_client_name, 50) * 4));
			$pdf->MultiCell($widthrecbox, 4, $carac_client, 0, 'L');
		}

		$pdf->SetTextColor(0, 0, 0);
	}

	function _showLCR($pdf, $object, $outputlangs, $TtoGenerate)
	{
		global $db, $conf;

		//Gestion LCR
		$pdf->AddPage();
		$posy = 50;
		$pdf->SetDrawColor(0, 0, 0);

		if (is_array($TtoGenerate) && !empty($TtoGenerate)) {
			$default_font_size = pdf_getPDFFontSize($outputlangs);
			$nb_facture = count($TtoGenerate);

			foreach ($TtoGenerate as $ii => $ref_piece) {

				$f = new Facture($db);
				$f->fetch('', $ref_piece);
				$f->fetch_thirdparty();
				$object = &$f;

				// Compatibilité Dolibarr >= 4.0
				if (empty($object->client) && !empty($object->thirdparty)) {
					$object->client = $object->thirdparty;
				}

				if (getDolGlobalInt('LCR_USE_REST_TO_PAY', 0)) {
					$deja_regle = $object->getSommePaiement();
					$creditnoteamount = $object->getSumCreditNotesUsed();
					$depositsamount = $object->getSumDepositsUsed();
					$resteapayer = price2num($object->total_ttc - $deja_regle - $creditnoteamount - $depositsamount, 'MT');
				} else {
					$resteapayer = price2num($object->total_ttc);
				}


				// ENTETE
				if (getDolGlobalInt('LCR_GENERATE_ONE_PER_PAGE_WITH_ADDRESS', 0)) {
					$this->_pagehead($pdf, $object, 1, $outputlangs);
					$curx = $this->marge_gauche;

					$heightforinfotot = 50;    // Height reserved to output the info and total part
					$heightforfreetext = getDolGlobalInt('MAIN_PDF_FREETEXT_HEIGHT', 5);    // Height reserved to output the free text on last page
					$heightforfooter = $this->marge_basse + 8;    // Height reserved to output the footer (value include bottom margin)

					$bottomlasttab = $this->page_hauteur - $heightforinfotot - $heightforfreetext - $heightforfooter + 10;
					$cury = $bottomlasttab;

					$pdf->SetLineStyle(array('dash' => '1,1', 'color' => array(200, 200, 200)));
					$pdf->Line($curx, $cury - 11, $this->page_largeur - $this->marge_droite, $cury - 11);
					$pdf->SetLineStyle(array('dash' => 0, 'color' => array(0, 0, 0)));
				} else {
					$curx = $this->marge_gauche;
					$cury = $posy - 30;
				}


				$pdf->SetFont('', '', $default_font_size - 1);
				$pdf->writeHTMLCell(53, 20, 10, $cury - 8, $outputlangs->convToOutputCharset('MERCI DE NOUS RETOURNER LA PRESENTE TRAITE SOUS 8 JOURS.'), 0, 1, false, true, 'J', true);

				$pdf->SetFont('', '', $default_font_size - 3);
				$pdf->writeHTMLCell(45, 20, 68, $cury - 8, $outputlangs->convToOutputCharset('Contre cette LETTRE DE CHANGE STIPULEE SANS FRAIS') . '<br>' . $outputlangs->convToOutputCharset('Veuillez payer la somme indiquée ci-dessous à l\'ordre de'), 0, 1, false, true, 'J', true);

				$pdf->SetFont('', '', $default_font_size - 2);
				$adresse = $outputlangs->convToOutputCharset(getDolGlobalString('MAIN_INFO_SOCIETE_NOM', '')) . '<br>' .
					nl2br(getDolGlobalString('MAIN_INFO_SOCIETE_ADDRESS', '')) . '<br>' .
					$outputlangs->convToOutputCharset(getDolGlobalString('MAIN_INFO_SOCIETE_ZIP', '') . ' ' . getDolGlobalString('MAIN_INFO_SOCIETE_TOWN', ''));				$pdf->writeHTMLCell(50, 20, 120, $cury - 8, $adresse, 0, 1, false, true, 'J', true);

				//Affichage code monnaie
				$pdf->SetXY(180, $cury + 1);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 7);
				$pdf->Cell(18, 0, "Code Monnaie", 0, 1, 'C');
				$pdf->SetXY(180, $cury + 5);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 14);
				$pdf->Cell(18, 0, $outputlangs->trans($conf->currency), 0, 0, 'C');

				//Affichage lieu / date
				//$town = !empty($this->emetteur->town) ? $this->emetteur->town : $this->emetteur->ville;
				$town = getDolGlobalString('MAIN_INFO_SOCIETE_TOWN', '');

				$cury += 5;
				$pdf->SetXY(30, $cury);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 8);
				$pdf->Cell(15, 0, "A " . $outputlangs->convToOutputCharset($town) . ", le", 0, 1, 'R');

				// jolie fleche ...
				$curx = 43;
				$cury += 2;
				$largeur_cadre = 5;
				$pdf->Line($curx + $largeur_cadre, $cury, $curx + $largeur_cadre + 5, $cury);
				$pdf->Line($curx + $largeur_cadre + 5, $cury, $curx + $largeur_cadre + 5, $cury + 2);
				$pdf->Line($curx + $largeur_cadre + 4, $cury + 2, $curx + $largeur_cadre + 6, $cury + 2);
				$pdf->Line($curx + $largeur_cadre + 4, $cury + 2, $curx + $largeur_cadre + 5, $cury + 3);
				$pdf->Line($curx + $largeur_cadre + 6, $cury + 2, $curx + $largeur_cadre + 5, $cury + 3);
				// fin jolie fleche

				//Affichage toute la ligne qui commence par "montant pour controle" ...
				$curx = $this->marge_gauche;
				$cury += 5;
				$hauteur_cadre = 8;
				$largeur_cadre = 27;
				$pdf->SetXY($curx, $cury);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 7);
				$pdf->Cell($largeur_cadre, 0, "Montant pour contrôle", 0, 0, 'C');
				$pdf->Line($curx, $cury, $curx, $cury + $hauteur_cadre);
				$pdf->Line($curx, $cury + $hauteur_cadre, $curx + $largeur_cadre, $cury + $hauteur_cadre);
				$pdf->Line($curx + $largeur_cadre, $cury, $curx + $largeur_cadre, $cury + $hauteur_cadre);
				$pdf->SetXY($curx, $cury + 4);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 8);
				$pdf->Cell($largeur_cadre, 0, price($resteapayer), 0, 0, 'C');

				$curx = $curx + $largeur_cadre + 5;
				$hauteur_cadre = 8;
				$largeur_cadre = 25;
				$pdf->SetXY($curx, $cury);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 7);
				$pdf->Cell($largeur_cadre, 0, "Date de création", 0, 0, 'C');
				$pdf->Line($curx, $cury, $curx, $cury + $hauteur_cadre);
				$pdf->Line($curx, $cury + $hauteur_cadre, $curx + $largeur_cadre, $cury + $hauteur_cadre);
				$pdf->Line($curx + $largeur_cadre, $cury, $curx + $largeur_cadre, $cury + $hauteur_cadre);
				$pdf->SetXY($curx, $cury + 4);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 8);
				$pdf->Cell($largeur_cadre, 0, dol_print_date($object->date, "day", false, $outputlangs), 0, 0, 'C');

				$curx = $curx + $largeur_cadre + 5;
				$hauteur_cadre = 8;
				$largeur_cadre = 25;
				$pdf->SetXY($curx, $cury);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 7);
				$pdf->Cell($largeur_cadre, 0, "Echéance", 0, 0, 'C');
				$pdf->Line($curx, $cury, $curx, $cury + $hauteur_cadre);
				$pdf->Line($curx, $cury + $hauteur_cadre, $curx + $largeur_cadre, $cury + $hauteur_cadre);
				$pdf->Line($curx + $largeur_cadre, $cury, $curx + $largeur_cadre, $cury + $hauteur_cadre);
				$pdf->SetXY($curx, $cury + 4);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 8);
				$pdf->Cell($largeur_cadre, 0, dol_print_date($object->date_lim_reglement, "day"), 0, 0, 'C');

				$curx = $curx + $largeur_cadre + 5;
				$hauteur_cadre = 8;
				$largeur_cadre = 75;
				$pdf->SetXY($curx, $cury - 1);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 7);
				$pdf->Cell($largeur_cadre, 0, "LCR Seulement", 0, 0, 'C');

				$largeurportioncadre = 30;
				$pdf->Line($curx, $cury, $curx, $cury + $hauteur_cadre);
				$pdf->Line($curx, $cury + $hauteur_cadre, $curx + $largeurportioncadre, $cury + $hauteur_cadre);
				$curx += $largeurportioncadre;
				$pdf->Line($curx, $cury + 2, $curx, $cury + $hauteur_cadre);

				$curx += 10;
				$largeurportioncadre = 6;
				$pdf->Line($curx, $cury + 2, $curx, $cury + $hauteur_cadre);
				$pdf->Line($curx, $cury + $hauteur_cadre, $curx + $largeurportioncadre, $cury + $hauteur_cadre);
				$curx += $largeurportioncadre;
				$pdf->Line($curx, $cury + 2, $curx, $cury + $hauteur_cadre);

				$curx += 3;
				$largeurportioncadre = 6;
				$pdf->Line($curx, $cury + 2, $curx, $cury + $hauteur_cadre);
				$pdf->Line($curx, $cury + $hauteur_cadre, $curx + $largeurportioncadre, $cury + $hauteur_cadre);
				$curx += $largeurportioncadre;
				$pdf->Line($curx, $cury + 2, $curx, $cury + $hauteur_cadre);

				$curx += 3;
				$largeurportioncadre = 12;
				$pdf->Line($curx, $cury + 2, $curx, $cury + $hauteur_cadre);
				$pdf->Line($curx, $cury + $hauteur_cadre, $curx + $largeurportioncadre, $cury + $hauteur_cadre);
				$curx += $largeurportioncadre;
				$pdf->Line($curx, $cury, $curx, $cury + $hauteur_cadre);

				$curx += 3;
				$hauteur_cadre = 8;
				$largeur_cadre = 30;
				$pdf->SetXY($curx, $cury);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 7);
				$pdf->Cell($largeur_cadre, 0, "Montant", 0, 0, 'C');
				$pdf->Line($curx, $cury, $curx, $cury + $hauteur_cadre);
				$pdf->Line($curx, $cury + $hauteur_cadre, $curx + $largeur_cadre, $cury + $hauteur_cadre);
				$pdf->Line($curx + $largeur_cadre, $cury, $curx + $largeur_cadre, $cury + $hauteur_cadre);
				$pdf->SetXY($curx, $cury + 4);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 8);
				$pdf->Cell($largeur_cadre, 0, price($resteapayer), 0, 0, 'C');

				$cury = $cury + $hauteur_cadre + 3;
				$curx = 20;
				$hauteur_cadre = 6;
				$largeur_cadre = 70;
				$pdf->Line($curx, $cury, $curx, $cury + $hauteur_cadre);
				$pdf->Line($curx, $cury, $curx + $largeur_cadre / 5, $cury);
				$pdf->Line($curx, $cury + $hauteur_cadre, $curx + $largeur_cadre / 5, $cury + $hauteur_cadre);

				$pdf->Line($curx + $largeur_cadre, $cury, $curx + $largeur_cadre, $cury + $hauteur_cadre);
				$pdf->Line($curx + $largeur_cadre, $cury, $curx + $largeur_cadre * 4 / 5, $cury);
				$pdf->Line($curx + $largeur_cadre, $cury + $hauteur_cadre, $curx + $largeur_cadre * 4 / 5, $cury + $hauteur_cadre);
				$pdf->SetXY($curx, $cury + 1.5);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 8);
				$pdf->Cell($largeur_cadre, 1, $outputlangs->convToOutputCharset($object->ref), 0, 0, 'C');

				$curx = $curx + $largeur_cadre + 15;
				$largeur_cadre = 50;
				$pdf->Line($curx, $cury, $curx, $cury + $hauteur_cadre);
				$pdf->Line($curx, $cury, $curx + $largeur_cadre / 5, $cury);
				$pdf->Line($curx, $cury + $hauteur_cadre, $curx + $largeur_cadre / 5, $cury + $hauteur_cadre);

				$pdf->Line($curx + $largeur_cadre, $cury, $curx + $largeur_cadre, $cury + $hauteur_cadre);
				$pdf->Line($curx + $largeur_cadre, $cury, $curx + $largeur_cadre * 4 / 5, $cury);
				$pdf->Line($curx + $largeur_cadre, $cury + $hauteur_cadre, $curx + $largeur_cadre * 4 / 5, $cury + $hauteur_cadre);
				$pdf->SetXY($curx, $cury + 2);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 8);
				// MB leave blank
				//$pdf->Cell($largeur_cadre, 0, "Réf ",0,0,C);

				$curx = $curx + $largeur_cadre + 10;
				$largeur_cadre = 30;
				$pdf->Line($curx, $cury, $curx, $cury + $hauteur_cadre);
				$pdf->Line($curx, $cury, $curx + $largeur_cadre / 5, $cury);
				$pdf->Line($curx, $cury + $hauteur_cadre, $curx + $largeur_cadre / 5, $cury + $hauteur_cadre);

				$pdf->Line($curx + $largeur_cadre, $cury, $curx + $largeur_cadre, $cury + $hauteur_cadre);
				$pdf->Line($curx + $largeur_cadre, $cury, $curx + $largeur_cadre * 4 / 5, $cury);
				$pdf->Line($curx + $largeur_cadre, $cury + $hauteur_cadre, $curx + $largeur_cadre * 4 / 5, $cury + $hauteur_cadre);
				$pdf->SetXY($curx, $cury + 2);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 8);
				// MB leave blank
				//$pdf->Cell($largeur_cadre, 0, "R�f ",0,0,C);

				// RIB client
				$cury = $cury + $hauteur_cadre + 3;
				$largeur_cadre = 70;
				$hauteur_cadre = 6;
				$sql = "SELECT rib.fk_soc, rib.domiciliation, rib.code_banque, rib.code_guichet, rib.number, rib.cle_rib";
				$sql .= " FROM " . $db->prefix() . "societe_rib as rib";
				$sql .= " WHERE rib.fk_soc = " . $object->client->id;
				$sql .= ' ORDER BY default_rib DESC LIMIT 1'; // On veux en priorité le RIB par défaut si jamais on tombe sur le cas de +sieurs RIB mais pas de default alors on en prend qu'un

				$resql = $this->db->query($sql);
				if ($resql) {
					$num = $this->db->num_rows($resql);
					$i = 0;
					while ($i <= $num) {
						$cpt = $this->db->fetch_object($resql);

						if ($cpt) {
							$curx = $this->marge_gauche;
							$pdf->Line($curx, $cury, $curx + $largeur_cadre, $cury);
							$pdf->Line($curx, $cury, $curx, $cury + $hauteur_cadre);
							$pdf->Line($curx + 22, $cury, $curx + 22, $cury + $hauteur_cadre - 2);
							$pdf->Line($curx + 35, $cury, $curx + 35, $cury + $hauteur_cadre - 2);
							$pdf->Line($curx + 60, $cury, $curx + 60, $cury + $hauteur_cadre - 2);
							$pdf->Line($curx + 70, $cury, $curx + 70, $cury + $hauteur_cadre);
							$pdf->SetXY($curx + 5, $cury + $hauteur_cadre - 4);
							$pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 8);
							if ($cpt->code_banque && $cpt->code_guichet && $cpt->number && $cpt->cle_rib) {
								$pdf->Cell($largeur_cadre, 1, $cpt->code_banque . "             " . $cpt->code_guichet . "         " . $cpt->number . "        " . $cpt->cle_rib, 0, 0, 'L');
								$pdf->SetXY($curx, $cury + $hauteur_cadre - 1);
								$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 6);
								$pdf->Cell($largeur_cadre, 1, "Code établissement    Code guichet           N° de compte            Cl RIB", 0, 0, 'L');
								$curx = 136;
								$largeur_cadre = 68;
								$pdf->SetXY($curx, $cury - 1);
								$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 6);
								$pdf->Cell($largeur_cadre, 1, "Domiciliation bancaire", 0, 0, 'C');
								$pdf->SetXY($curx, $cury + 2);
								$pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 8);
							}
							if ($cpt->domiciliation) {
								$pdf->Cell($largeur_cadre, 5, $outputlangs->convToOutputCharset($cpt->domiciliation), 1, 0, 'C');
							}
						}
						$i++;
					}
				}

				$cury = $cury + $hauteur_cadre + 3;
				$curx = $this->marge_gauche;
				$largeur_cadre = 20;
				$pdf->SetXY($curx, $cury);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 6);
				$pdf->Cell($largeur_cadre, 1, "Acceptation ou aval", 0, 0, 'L');
				// jolie fl�che ...
				$cury += 2;
				$pdf->Line($curx + $largeur_cadre, $cury, $curx + $largeur_cadre + 5, $cury);
				$pdf->Line($curx + $largeur_cadre + 5, $cury, $curx + $largeur_cadre + 5, $cury + 2);
				$pdf->Line($curx + $largeur_cadre + 4, $cury + 2, $curx + $largeur_cadre + 6, $cury + 2);
				$pdf->Line($curx + $largeur_cadre + 4, $cury + 2, $curx + $largeur_cadre + 5, $cury + 3);
				$pdf->Line($curx + $largeur_cadre + 6, $cury + 2, $curx + $largeur_cadre + 5, $cury + 3);
				// fin jolie fl�che

				$curx += 50;
				$largeur_cadre = 20;
				$hauteur_cadre = 6;

				// Signature du tireur
				$pdf->SetXY($curx + $largeur_cadre * 5, $cury);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 6);
				$pdf->MultiCell($largeur_cadre * 2, 4, "Signature du tireur", 0, 'C');

				$pdf->Line(0, $cury + 40, $this->page_largeur, $cury + 40);
				$pdf->SetXY($curx + 100, $cury + 36);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 6);
				$pdf->MultiCell(50, 4, "Ne rien inscrire au dessous de cette ligne", 0, 'R');

				// Coordonnées du tiré
				$pdf->SetXY($curx, $cury);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 6);
				$pdf->MultiCell($largeur_cadre, $hauteur_cadre, "Nom\n et Adresse\n du tiré", 0, 'R');
				$pdf->SetXY($curx + $largeur_cadre + 2, $cury - 0.5);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 8);
				$arrayidcontact = $object->getIdContact('external', 'BILLING');
				$carac_client = $outputlangs->convToOutputCharset($object->client->nom);
				$carac_client .= "\n" . $outputlangs->convToOutputCharset(!empty($object->client->address) ? $object->client->address : $object->client->adresse);
				$carac_client .= "\n" . $outputlangs->convToOutputCharset(!empty($object->client->zip) ? $object->client->zip : $object->client->cp) . " " . $outputlangs->convToOutputCharset(!empty($object->client->town) ? $object->client->town : $object->client->ville) . "\n";
				$pdf->MultiCell($largeur_cadre * 2.5, $hauteur_cadre, $carac_client, 1, 'C');

				// N° Siren
				$cury = $pdf->GetY() + 4; // Le précédent MultiCell change le Y courant. On rajoute 4 pour le démarrer systématiquement le cadre SIREN en dessous
				$pdf->SetXY($curx, $cury);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 6);
				$pdf->MultiCell($largeur_cadre, 4, "N° SIREN du tiré", 0, 'R');
				$pdf->SetXY($curx + $largeur_cadre + 2, $cury - 0.5);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 8);
				$pdf->MultiCell($largeur_cadre * 2.5, 4, $outputlangs->convToOutputCharset(empty($object->client->siren) ? $object->client->idprof1 : $object->client->siren), 1, 'C');

				if (getDolGlobalInt('LCR_GENERATE_ONE_PER_PAGE_WITH_ADDRESS', 0)) {
					// New page
					if ($ii < $nb_facture - 1) {
						$pdf->AddPage();
					}

				} else {
					$posy += 96;
					$ii++;
					$res_modulo = $ii % 3;
					if ($res_modulo == 0) {
						$pdf->AddPage();
						$posy = 50;
					}
				}

			}
		}
		//fin mb
	}
}
