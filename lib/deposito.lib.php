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
 * \file    deposito/lib/deposito.lib.php
 * \ingroup deposito
 * \brief   Product roles of the deposit system, read from the product extrafields.
 *
 * Three roles, all set on the product card:
 * - sold product (drink): deposito_deposit = deposit product, deposito_qty = deposit units per unit sold
 * - deposit product (statiegeld): any product named in deposito_deposit of another product
 * - leeggoed product: deposito_return = 1
 *   (the returned empty itself, stocked: Dolibarr puts it back into stock at validation)
 */

/**
 * Deposit settings of a product
 *
 * @param	DoliDB	$db				Database handler
 * @param	int		$fk_product		Product id
 * @return	array{deposit:int,qty:float,return:bool}
 */
function depositoProductInfo($db, $fk_product)
{
	static $cache = array();

	$fk_product = (int) $fk_product;
	if (isset($cache[$fk_product])) {
		return $cache[$fk_product];
	}
	$info = array('deposit' => 0, 'qty' => 1.0, 'return' => false);
	if ($fk_product > 0) {
		$sql = "SELECT deposito_deposit, deposito_qty, deposito_return FROM ".$db->prefix()."product_extrafields";
		$sql .= " WHERE fk_object = ".$fk_product;
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			$info['deposit'] = (int) $obj->deposito_deposit;
			$info['qty'] = ($obj->deposito_qty === null || $obj->deposito_qty === '') ? 1.0 : (float) $obj->deposito_qty;
			$info['return'] = !empty($obj->deposito_return);
		}
		// A product that brings a deposit along is a normal sold product (keeps its own VAT),
		// even when the leeggoed fields were filled in on it by mistake
		if ($info['deposit'] > 0) {
			$info['return'] = false;
		}
	}
	$cache[$fk_product] = $info;

	return $info;
}

/**
 * Is this product a deposit product or a leeggoed product? Those lines get no VAT and no discount.
 *
 * @param	DoliDB	$db				Database handler
 * @param	int		$fk_product		Product id
 * @return	bool
 */
function depositoIsDepositLineProduct($db, $fk_product)
{
	static $cache = array();

	$fk_product = (int) $fk_product;
	if ($fk_product <= 0) {
		return false;
	}
	if (!isset($cache[$fk_product])) {
		$info = depositoProductInfo($db, $fk_product);
		if ($info['deposit'] > 0) {
			// Sold product with a deposit: never a deposit line itself
			$cache[$fk_product] = false;
		} elseif ($info['return']) {
			$cache[$fk_product] = true;
		} else {
			$sql = "SELECT fk_object FROM ".$db->prefix()."product_extrafields WHERE deposito_deposit = ".$fk_product;
			$resql = $db->query($sql." ".$db->plimit(1));
			$cache[$fk_product] = ($resql && $db->num_rows($resql) > 0);
		}
	}

	return $cache[$fk_product];
}

/**
 * Ids of all deposit products (used as deposit by a sold product)
 *
 * @param	DoliDB	$db		Database handler
 * @return	int[]
 */
function depositoDepositProductIds($db)
{
	$ids = array();
	$resql = $db->query("SELECT DISTINCT deposito_deposit as id FROM ".$db->prefix()."product_extrafields WHERE deposito_deposit > 0");
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$ids[] = (int) $obj->id;
		}
	}

	return $ids;
}

/**
 * Ids of all leeggoed products (deposito_return = 1, and no deposit product of their own)
 *
 * @param	DoliDB	$db		Database handler
 * @return	int[]
 */
function depositoReturnProductIds($db)
{
	$ids = array();
	$resql = $db->query("SELECT fk_object as id FROM ".$db->prefix()."product_extrafields WHERE deposito_return = 1 AND COALESCE(deposito_deposit, 0) <= 0");
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$ids[] = (int) $obj->id;
		}
	}

	return $ids;
}

/**
 * Prepare the tabs of the admin pages
 *
 * @return	array<array{0:string,1:string,2:string}>
 */
function depositoAdminPrepareHead()
{
	global $langs;

	$h = 0;
	$head = array();
	$head[$h][0] = dol_buildpath("/deposito/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	return $head;
}
