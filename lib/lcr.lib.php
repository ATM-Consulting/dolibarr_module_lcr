<?php
/* <one line to give the program's name and a brief idea of what it does.>
 * Copyright (C) 2015 ATM Consulting <support@atm-consulting.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *	\file		lib/lcr.lib.php
 *	\ingroup	lcr
 *	\brief		This file is an example module library
 *				Put some comments here
 */

function lcrPrepareHead()
{
    global $langs, $conf;

    $langs->load("lcr@lcr");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/lcr/lcr.php", 1);
    $head[$h][1] = $langs->trans("LCR");
    $head[$h][2] = 'lcr';
    $h++;

    // Show more tabs from modules
    // Entries must be declared in modules descriptor with line
    //$this->tabs = array(
    //	'entity:+tabname:Title:@lcr:/lcr/mypage.php?id=__ID__'
    //); // to add new tab
    //$this->tabs = array(
    //	'entity:-tabname:Title:@lcr:/lcr/mypage.php?id=__ID__'
    //); // to remove a tab
    complete_head_from_modules($conf, $langs, $object, $head, $h, 'lcr');

    return $head;
}

function lcrAdminPrepareHead()
{
    global $langs, $conf;

    $langs->load("lcr@lcr");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/lcr/admin/lcr_setup.php", 1);
    $head[$h][1] = $langs->trans("Parameters");
    $head[$h][2] = 'settings';
    $h++;
    $head[$h][0] = dol_buildpath("/lcr/admin/lcr_about.php", 1);
    $head[$h][1] = $langs->trans("About");
    $head[$h][2] = 'about';
    $h++;

    // Show more tabs from modules
    // Entries must be declared in modules descriptor with line
    //$this->tabs = array(
    //	'entity:+tabname:Title:@lcr:/lcr/mypage.php?id=__ID__'
    //); // to add new tab
    //$this->tabs = array(
    //	'entity:-tabname:Title:@lcr:/lcr/mypage.php?id=__ID__'
    //); // to remove a tab
    complete_head_from_modules($conf, $langs, $object, $head, $h, 'lcr');

    return $head;
}

function generateCSV() {
	
	global $db, $conf;
	
	$TFactRef = $_REQUEST['toGenerate'];
	
	// Création et attribution droits fichier
	$dir = $conf->lcr->dir_output;
	$filename = 'lcr_'.date('YmdHis').'.csv';
	$f = fopen($dir.'/'.$filename, 'w+');
	chmod($dir.'/'.$filename, 0777);
	
	$TTitle = array(
						'Code client'
						,'Raison sociale'
						,'Adresse 1'
						,'Adresse 2'
						,'Code postal'
						,'Ville'
						,'Téléphone'
						,'Référence'
						,'SIREN'
						,'RIB'
						,'Agence'
						,'Montant'
						,'Monnaie'
						,'Accepté'
						,'Référence'
						,'Date de création'
						,'Date d\'échéance'
					);
	
	fputcsv($f, $TTitle, ';');
	
	$fact = new Facture($db);
	$s = new Societe($db);
		
	foreach($TFactRef as $ref_fact) {

		if($fact->fetch('', $ref_fact) > 0 && $s->fetch($fact->socid) > 0) {
			
			$rib = $s->get_all_rib();
			
			fputcsv(
					$f
					,array(
							$s->code_client
							,$s->name
							,$s->address
							,'' // Adresse 2
							,$s->zip
							,$s->town
							,$s->phone
							,$ref_fact
							,$s->idprof1
							,$rib[0]->iban
							,'' // Agence
							,price($fact->total_ttc-$fact->getSommePaiement())
							,'E'
							,1
							,$ref_fact
							,date('d/m/Y', $fact->date)
							,date('d/m/Y', $fact->date_lim_reglement)
						  )
					, ';'
				);
		}
		
	}
	
	fclose($f);
	
}
