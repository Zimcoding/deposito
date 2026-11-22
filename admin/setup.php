<?php
/* Copyright (C) 2026		Wim Rappé					<wim.rappe@fruitdas.be>
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
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    deposito/admin/setup.php
 * \ingroup deposito
 * \brief   Deposito setup: check of the modules used and of the deposit and leeggoed products.
 *          There is nothing to set here: everything is set on the product cards.
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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT."/product/class/product.class.php";
dol_include_once('/deposito/lib/deposito.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Form $form
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "products", "deposito@deposito"));

if (!$user->admin) {
	accessforbidden();
}


/*
 * View
 */

llxHeader('', $langs->trans("DepositoSetup"), '', '', 0, 0, '', '', '', 'mod-deposito page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("DepositoSetup"), $linkback, 'title_setup');

print dol_get_fiche_head(depositoAdminPrepareHead(), 'settings', '', -1, 'fa-recycle');

print '<span class="opacitymedium">'.$langs->trans("DepositoSetupHelp").'</span><br><br>';

// Modules used: what works and what does not
$modules = array(
	'invoice' => 'DepositoModInvoice',
	'takepos' => 'DepositoModTakePos',
	'order' => 'DepositoModOrder',
	'propal' => 'DepositoModPropal',
	'stock' => 'DepositoModStock',
	'accounting' => 'DepositoModAccounting',
);
print load_fiche_titre($langs->trans("DepositoChecksModules"), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Module").'</td><td class="center">'.$langs->trans("Status").'</td><td>'.$langs->trans("Description").'</td></tr>';
foreach ($modules as $module => $key) {
	$on = isModEnabled($module);
	print '<tr class="oddeven"><td>'.$langs->trans($key).'</td>';
	print '<td class="center">'.($on ? img_picto($langs->trans("Enabled"), 'tick') : '<span class="opacitymedium">'.$langs->trans("Disabled").'</span>').'</td>';
	print '<td>'.$langs->trans($key.($on ? 'On' : 'Off')).'</td></tr>';
}
// The deposit lines come from the trigger: it is registered when the module is enabled
$triggeron = !empty($conf->modules_parts['triggers']) && is_array($conf->modules_parts['triggers']) && isset($conf->modules_parts['triggers']['deposito']);
print '<tr class="oddeven"><td>'.$langs->trans("DepositoTrigger").'</td>';
print '<td class="center">'.($triggeron ? img_picto($langs->trans("Enabled"), 'tick') : img_warning($langs->trans("Disabled"))).'</td>';
print '<td>'.$langs->trans($triggeron ? "DepositoTriggerOn" : "DepositoTriggerOff").'</td></tr>';
print '</table><br>';

// Deposit and leeggoed products
print load_fiche_titre($langs->trans("DepositoChecksProducts"), '', '');

// Sold products with the Leeggoed checkbox too: the checkbox is ignored, show them so they can be cleaned
$mixed = array();
$resql = $db->query("SELECT fk_object FROM ".$db->prefix()."product_extrafields WHERE deposito_deposit > 0 AND deposito_return = 1");
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$product = new Product($db);
		if ($product->fetch((int) $obj->fk_object) > 0) {
			$mixed[] = $product->getNomUrl(1);
		}
	}
}
if (!empty($mixed)) {
	print info_admin($langs->trans("DepositoProblemMixed").'<br>'.implode(', ', $mixed), 0, 0, 'warning');
}

$rows = array();
foreach (depositoDepositProductIds($db) as $id) {
	$rows[$id] = 'DepositoRoleDeposit';
}
foreach (depositoReturnProductIds($db) as $id) {
	$rows[$id] = 'DepositoRoleReturn';
}
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Product").'</td><td>'.$langs->trans("DepositoRole").'</td><td class="right">'.$langs->trans("DepositoPrice").'</td>';
print '<td class="center">'.$langs->trans("VAT").'</td><td>'.$langs->trans("DepositoAccountSell").'</td><td>'.$langs->trans("DepositoProblems").'</td></tr>';
if (empty($rows)) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans("DepositoNoProducts").'</span></td></tr>';
}
foreach ($rows as $id => $role) {
	$product = new Product($db);
	if ($product->fetch($id) <= 0) {
		print '<tr class="oddeven"><td colspan="5">#'.$id.'</td><td class="error">'.$langs->trans("DepositoProductMissing").'</td></tr>';
		continue;
	}
	$problems = array();
	if ((float) $product->tva_tx != 0) {
		$problems[] = $langs->trans("DepositoProblemVat");
	}
	$nblinked = 0;
	if ($role == 'DepositoRoleDeposit') {
		$resqlcount = $db->query("SELECT COUNT(*) as nb FROM ".$db->prefix()."product_extrafields WHERE deposito_deposit = ".((int) $id));
		if ($resqlcount && ($objcount = $db->fetch_object($resqlcount))) {
			$nblinked = (int) $objcount->nb;
		}
		if ((float) $product->price < 0) {
			$problems[] = $langs->trans("DepositoProblemDepositNegative");
		}
		if ($product->type == Product::TYPE_PRODUCT && isModEnabled('stock')) {
			$problems[] = $langs->trans("DepositoProblemNotService");
		}
	} elseif ($product->type != Product::TYPE_PRODUCT && isModEnabled('stock')) {
		$problems[] = $langs->trans("DepositoProblemReturnService");
	}
	if (isModEnabled('accounting') && empty($product->accountancy_code_sell)) {
		$problems[] = $langs->trans("DepositoProblemNoAccount");
	}
	print '<tr class="oddeven">';
	print '<td>'.$product->getNomUrl(1).' - '.dol_escape_htmltag($product->label).'</td>';
	print '<td>'.$langs->trans($role).($role == 'DepositoRoleDeposit' ? ' <span class="opacitymedium">('.$langs->trans("DepositoLinkedProducts", $nblinked).')</span>' : '').'</td>';
	print '<td class="right">'.price($product->price).'</td>';
	print '<td class="center">'.vatrate($product->tva_tx, true).'</td>';
	print '<td>'.dol_escape_htmltag($product->accountancy_code_sell).'</td>';
	print '<td>'.(empty($problems) ? img_picto('', 'tick') : '<span class="warning">'.implode('<br>', $problems).'</span>').'</td>';
	print '</tr>';
}
print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
