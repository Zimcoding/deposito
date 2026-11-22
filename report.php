<?php
/* Copyright (C) 2026		Wim Rappé					<wim.rappe@fruitdas.be>
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
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *	\file       deposito/report.php
 *	\ingroup    deposito
 *	\brief      Deposits still outstanding (deposit charged minus deposit given back) on validated invoices,
 *	            per deposit product and per customer. This is the debt towards customers for packaging they still have.
 *	            Units: + for a line with a positive amount (deposit charged), - for a negative one (return, credit note).
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/deposito/lib/deposito.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Form $form
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("bills", "products", "companies", "deposito@deposito"));

if (!isModEnabled('deposito') || !$user->hasRight('deposito', 'read')) {
	accessforbidden();
}

$dateto = dol_mktime(23, 59, 59, GETPOSTINT('datetomonth'), GETPOSTINT('datetoday'), GETPOSTINT('datetoyear'));
$fk_deposit = GETPOSTINT('fk_deposit');

// Deposit products (charged, +) and leeggoed products (refunded, -)
$depositids = array_unique(array_merge(depositoDepositProductIds($db), depositoReturnProductIds($db)));


/*
 * Data: deposit and leeggoed lines of validated invoices (not abandoned)
 */

$units = "SUM(CASE WHEN d.total_ht < 0 THEN -ABS(d.qty) ELSE ABS(d.qty) END)";

$from = " FROM ".$db->prefix()."facturedet as d";
$from .= " INNER JOIN ".$db->prefix()."facture as f ON f.rowid = d.fk_facture";
$from .= " WHERE f.entity IN (".getEntity('invoice').") AND f.fk_statut IN (1, 2)";
$from .= " AND d.fk_product IN (".(empty($depositids) ? "0" : $db->sanitize(implode(',', $depositids))).")";
if ($dateto) {
	$from .= " AND f.datef <= '".$db->idate($dateto)."'";
}
if ($fk_deposit > 0) {
	$from .= " AND d.fk_product = ".((int) $fk_deposit);
}

$perdeposit = array();
$sql = "SELECT d.fk_product as fk_deposit, ".$units." as units, SUM(d.total_ht) as amount".$from." GROUP BY d.fk_product";
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
}
while ($resql && ($obj = $db->fetch_object($resql))) {
	$perdeposit[(int) $obj->fk_deposit] = array('units' => (float) $obj->units, 'amount' => (float) $obj->amount);
}

$percustomer = array();
$sql = "SELECT f.fk_soc, ".$units." as units, SUM(d.total_ht) as amount".$from." GROUP BY f.fk_soc";
$sql .= " HAVING SUM(d.total_ht) <> 0 ORDER BY amount DESC";
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
}
while ($resql && ($obj = $db->fetch_object($resql))) {
	$percustomer[(int) $obj->fk_soc] = array('units' => (float) $obj->units, 'amount' => (float) $obj->amount);
}


/*
 * View
 */

llxHeader('', $langs->trans("DepositoReport"), '', '', 0, 0, '', '', '', 'mod-deposito page-report');

print load_fiche_titre($langs->trans("DepositoReport"), '', 'fa-recycle');
print '<span class="opacitymedium">'.$langs->trans("DepositoReportHelp").'</span><br><br>';

// Filters
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';
print '<div class="inline-block marginrightonly">'.$langs->trans("DateTo").' '.$form->selectDate($dateto ? $dateto : -1, 'dateto', 0, 0, 1, '', 1, 0).'</div>';
$options = array();
foreach ($depositids as $id) {
	$product = new Product($db);
	if ($product->fetch($id) > 0) {
		$options[$id] = $product->ref.' - '.$product->label;
	}
}
print '<div class="inline-block marginrightonly">'.$langs->trans("Product")." ".$form->selectarray('fk_deposit', $options, $fk_deposit, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</div>';
print '<input type="submit" class="button small" value="'.$langs->trans("Refresh").'">';
print '</form><br>';

// Per deposit product
print load_fiche_titre($langs->trans("DepositoPerDeposit"), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Product").'</td><td>'.$langs->trans("DepositoAccountSell").'</td>';
print '<td class="right">'.$langs->trans("DepositoUnitsOut").'</td><td class="right">'.$langs->trans("DepositoAmountOut").'</td></tr>';
$totalunits = 0;
$totalamount = 0;
foreach ($perdeposit as $id => $row) {
	$product = new Product($db);
	print '<tr class="oddeven"><td>'.($product->fetch($id) > 0 ? $product->getNomUrl(1).' - '.dol_escape_htmltag($product->label) : '#'.$id).'</td>';
	print '<td>'.dol_escape_htmltag((string) $product->accountancy_code_sell).'</td>';
	print '<td class="right">'.price2num($row['units'], 'MS').'</td><td class="right amount">'.price($row['amount'], 0, $langs, 1, -1, -1, $conf->currency).'</td></tr>';
	$totalunits += $row['units'];
	$totalamount += $row['amount'];
}
if (empty($perdeposit)) {
	print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}
print '<tr class="liste_total"><td colspan="2">'.$langs->trans("Total").'</td><td class="right">'.price2num($totalunits, 'MS').'</td>';
print '<td class="right">'.price($totalamount, 0, $langs, 1, -1, -1, $conf->currency).'</td></tr>';
print '</table><br>';

// Per customer
print load_fiche_titre($langs->trans("DepositoPerCustomer"), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Customer").'</td><td class="right">'.$langs->trans("DepositoUnitsOut").'</td><td class="right">'.$langs->trans("DepositoAmountOut").'</td></tr>';
foreach ($percustomer as $socid => $row) {
	$soc = new Societe($db);
	print '<tr class="oddeven"><td>'.($soc->fetch($socid) > 0 ? $soc->getNomUrl(1) : '#'.$socid).'</td>';
	print '<td class="right">'.price2num($row['units'], 'MS').'</td><td class="right amount">'.price($row['amount'], 0, $langs, 1, -1, -1, $conf->currency).'</td></tr>';
}
if (empty($percustomer)) {
	print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}
print '</table>';

llxFooter();
$db->close();
