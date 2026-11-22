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
 * 	\defgroup   deposito     Module Deposito
 *  \brief      Deposits on returnable packaging: a product brings its deposit line along on proposals, orders,
 *              invoices and TakePOS receipts; returned empties go into stock; report of deposits still outstanding.
 *
 *  \file       htdocs/deposito/core/modules/modDeposito.class.php
 *  \ingroup    deposito
 *  \brief      Description and activation file for module Deposito
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module Deposito
 */
class modDeposito extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		// Id > 500000 = common range, no reservation needed (https://wiki.dolibarr.org/index.php/List_of_modules_id).
		// 567890 = beverage, 567891 = productphotos. Reserve an editor range before distributing publicly.
		$this->numero = 567892;
		$this->rights_class = 'deposito';
		$this->family = 'financial';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "DepositoDescription";
		$this->descriptionlong = "DepositoDescriptionLong";
		$this->editor_name = 'Fruitdas BV';
		$this->editor_url = 'fruitdas.be';
		$this->version = '0.2.5';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-recycle';

		$this->module_parts = array(
			'triggers' => 1,	// Deposit lines on proposals/orders/invoices, sign and VAT of deposit and leeggoed lines
		);
		$this->dirs = array();
		$this->config_page_url = array("setup.php@deposito");
		$this->hidden = false;

		// Products and invoices are needed. Proposals, orders, TakePOS, stock and accounting are optional:
		// each part only works when its module is enabled (checked at run time and on the setup page).
		$this->depends = array('modProduct', 'modFacture');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("deposito@deposito");
		$this->phpmin = array(7, 1);
		$this->need_dolibarr_version = array(20, 0);
		$this->warnings_activation = array();
		$this->const = array();

		if (!isModEnabled("deposito")) {
			$conf->deposito = new stdClass();
			$conf->deposito->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();

		// Permissions
		$this->rights = array();
		$this->rights[0][0] = $this->numero.'01';
		$this->rights[0][1] = 'Read the report of outstanding deposits';
		$this->rights[0][4] = 'read';
		$this->rights[0][5] = '';

		// Menu: Billing > Customer invoices > Outstanding deposits
		$this->menu = array();
		$this->menu[] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=customers_bills',
			'type' => 'left',
			'titre' => 'DepositoReport',
			'mainmenu' => 'billing',
			'leftmenu' => 'deposito',
			'url' => '/deposito/report.php',
			'langs' => 'deposito@deposito',
			'position' => 1000,
			'enabled' => 'isModEnabled("deposito")',
			'perms' => '$user->hasRight("deposito", "read")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 *  Function called when module is enabled.
	 *  Creates the extrafields that hold the links between products, and the hidden marker on document lines.
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>           1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);

		$link = array('options' => array('Product:product/class/product.class.php' => null));
		$enabled = 'isModEnabled("deposito")';

		// On the product that is sold (a drink): which deposit comes along, and how many units of it per unit sold
		$extrafields->addExtraField('deposito_deposit', 'DepositoDeposit', 'link', 100, '', 'product', 0, 0, '', $link, 1, '', 1, 'DepositoDepositHelp', '', '', 'deposito@deposito', $enabled);
		$extrafields->addExtraField('deposito_qty', 'DepositoQty', 'double', 101, '24,8', 'product', 0, 0, '1', '', 1, '', 1, 'DepositoQtyHelp', '', '', 'deposito@deposito', $enabled);
		// On the leeggoed product (the returned empty itself, stocked): yes/no
		$extrafields->addExtraField('deposito_return', 'DepositoReturn', 'boolean', 102, '', 'product', 0, 0, '0', '', 1, '', 1, 'DepositoReturnHelp', '', '', 'deposito@deposito', $enabled);

		// 0.1.x had a link "Return of deposit" (deposito_return_of): every product with it filled in is leeggoed.
		// Its link "Empty into stock" (deposito_empty) and the empties warehouse are gone: Dolibarr moves the stock itself.
		$extrafields->fetch_name_optionals_label('product');
		if (isset($extrafields->attributes['product']['type']['deposito_return_of'])) {
			$this->db->query("UPDATE ".$this->db->prefix()."product_extrafields SET deposito_return = 1 WHERE deposito_return_of > 0 AND COALESCE(deposito_deposit, 0) <= 0");
			$extrafields->delete('deposito_return_of', 'product');
		}
		if (isset($extrafields->attributes['product']['type']['deposito_empty'])) {
			$extrafields->delete('deposito_empty', 'product');
		}
		include_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dolibarr_del_const($this->db, 'DEPOSITO_WAREHOUSE');

		// Hidden marker on document lines: id of the product line this deposit line belongs to
		foreach (array('propaldet', 'commandedet', 'facturedet') as $elementtype) {
			$extrafields->addExtraField('deposito_auto', 'DepositoAuto', 'int', 100, '10', $elementtype, 0, 0, '', '', 0, '', 0, '', '', '', 'deposito@deposito', $enabled, 0, 0);
		}

		$this->remove($options);

		return $this->_init(array(), $options);
	}

	/**
	 *  Function called when module is disabled. Extrafields and their data are kept.
	 *
	 *  @param      string	$options    Options when disabling module ('', 'noboxes')
	 *  @return     int<-1,1>           1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
