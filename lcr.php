<?php

require './config.php';
dol_include_once('/lcr/lib/lcr.lib.php');
dol_include_once('/compta/facture/class/facture.class.php');

llxHeader();

$head=lcrPrepareHead();
$titre=$langs->trans('LCR');

dol_fiche_head($head, 'card', $titre, 0, $picto);

_liste();

function _liste() {
	
	global $db, $langs;
	
	$sql = 'SELECT rowid, fk_soc FROM '.MAIN_DB_PREFIX.'facture WHERE fk_mode_reglement = 52 AND type = 0';
	
	$resql = $db->query($sql);
	
	print '<table class="noborder" width="100%">';
	
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans("Parameters").'</td>';
	print '</tr>';
	
	while($res = $db->fetch_object($resql)) {
		$f = new Facture($db);
		$f->fetch($res->rowid);
		if($f->id <= 0) continue;
		print '<tr>';
		print '<td>';
		print $f->getNomUrl(1);
		print '</td>';
		print '<td>';
		print $f->getNomUrl(1);
		print '</td>';
		print '</tr>';
	}
	print '</table>';

}
