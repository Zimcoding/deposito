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
 * \file    deposito/core/triggers/interface_99_modDeposito_DepositoTriggers.class.php
 * \ingroup deposito
 * \brief   Deposit lines follow their product line; deposit and leeggoed lines have no VAT and no discount;
 *          leeggoed lines are stored so that Dolibarr's own stock logic puts the empties back into stock.
 *
 * Rules
 * - Line of a product with a deposit inserted on a draft proposal/order/invoice (TakePOS receipts are invoices):
 *   a deposit line is added, qty = qty x deposit units per unit. Its hidden field deposito_auto = id of the product line.
 * - Product line quantity changed: its deposit line follows. Product line deleted: its deposit line goes too.
 * - A copied document (clone, order to invoice, ...) brings copies of the old deposit lines: those are removed,
 *   because a fresh deposit line was made for the copied product line just before.
 * - Leeggoed line (product with checkbox Leeggoed): always a refund. Stored as -qty x positive price where
 *   validation takes the quantity out of stock (TakePOS receipts; invoices with stock change on validation):
 *   taking -qty out puts it in. Everywhere else +qty x negative price (credit notes put +qty in; orders,
 *   proposals and invoices without stock change keep a readable positive quantity).
 *   TakePOS turns a negative receipt into a credit note: without lots the lines are switched to +qty then;
 *   with lots TakePOS takes qty out itself, also on a credit note, so they stay -qty.
 * - Deposit and leeggoed lines are forced to 0% VAT and 0% discount, also after a TakePOS discount on the whole sale.
 * - deposito_auto = -1 on a line: the sender (own till, API) handles deposits itself. No deposit line is added
 *   and the sign is left alone; only VAT and discount are forced to 0 on deposit and leeggoed lines.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
dol_include_once('/deposito/lib/deposito.lib.php');

/**
 *  Triggers of module Deposito
 */
class InterfaceDepositoTriggers extends DolibarrTriggers
{
	/**
	 * Value of deposito_auto set by a sender that handles deposits itself
	 */
	const SENDER_HANDLES = -1;

	/**
	 * @var bool	True while this trigger changes lines itself (no reaction to its own changes)
	 */
	private static $busy = false;

	/**
	 * Line trigger prefix => document data
	 * @var array<string,array{table:string,fk:string,class:string,file:string,module:string}>
	 */
	private static $documents = array(
		'LINEBILL' => array('table' => 'facturedet', 'fk' => 'fk_facture', 'class' => 'Facture', 'file' => '/compta/facture/class/facture.class.php', 'module' => 'invoice'),
		'LINEORDER' => array('table' => 'commandedet', 'fk' => 'fk_commande', 'class' => 'Commande', 'file' => '/commande/class/commande.class.php', 'module' => 'order'),
		'LINEPROPAL' => array('table' => 'propaldet', 'fk' => 'fk_propal', 'class' => 'Propal', 'file' => '/comm/propal/class/propal.class.php', 'module' => 'propal'),
	);

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "financial";
		$this->description = "Deposito triggers: deposit lines and leeggoed lines.";
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'fa-recycle';
	}

	/**
	 * Function called when a Dolibarr business event is done.
	 *
	 * @param string 		$action 	Event action code
	 * @param CommonObject 	$object 	Object
	 * @param User 			$user 		Object user
	 * @param Translate 	$langs 		Object langs
	 * @param Conf 			$conf 		Object conf
	 * @return int<-1,1>              	Return integer <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('deposito') || self::$busy) {
			return 0;
		}

		if ($action == 'BILL_MODIFY') {
			return $this->switchToCreditNote($object);
		}

		if (!preg_match('/^(LINEBILL|LINEORDER|LINEPROPAL)_(INSERT|MODIFY|UPDATE|DELETE)$/', $action, $reg)) {
			return 0;
		}
		$doc = self::$documents[$reg[1]];
		$event = ($reg[2] == 'UPDATE' ? 'MODIFY' : $reg[2]);

		$lineid = (int) $object->id;
		$fk_product = (int) $object->fk_product;
		dol_syslog(__METHOD__." ".$action." line ".$lineid." product ".$fk_product, LOG_DEBUG);
		if ($lineid <= 0 || $fk_product <= 0) {
			return 0;
		}
		$marker = empty($object->array_options['options_deposito_auto']) ? 0 : (int) $object->array_options['options_deposito_auto'];

		// Deposit or leeggoed line
		if (depositoIsDepositLineProduct($this->db, $fk_product)) {
			if ($event == 'DELETE') {
				return 0;
			}
			dol_syslog(__METHOD__." ".$action." deposit/leeggoed line ".$lineid." product ".$fk_product." marker ".$marker." qty ".$object->qty." discount ".$object->remise_percent, LOG_DEBUG);
			$document = $this->fetchDocument($doc, $object, $lineid);
			if (!$document || (int) $document->status != 0) {
				dol_syslog(__METHOD__." ".$action." line ".$lineid.": document not found or not draft", LOG_DEBUG);
				return 0;
			}
			$creditnote = ($reg[1] == 'LINEBILL' && (int) $document->type == Facture::TYPE_CREDIT_NOTE);
			if ($event == 'INSERT' && $marker > 0 && $this->isSale($reg[1], $document)) {
				// Copy of an automatic deposit line: the copied product line already got a fresh one
				dol_syslog(__METHOD__." remove copied deposit line ".$lineid, LOG_DEBUG);
				return $this->deleteLines($doc, array($lineid));
			}
			$mode = 0;
			$qty = null;
			if (depositoProductInfo($this->db, $fk_product)['return'] && $marker != self::SENDER_HANDLES) {
				// A leeggoed line is always a refund. Its form depends on how the stock moves at validation:
				// -qty x +price where the quantity is taken out of stock (taking -6 out = 6 in),
				// +qty x -price everywhere else (credit notes put +qty in; no stock move: keep the quantity readable)
				$mode = (!$creditnote && $this->takesQtyOutAtValidation($reg[1], $document)) ? -1 : 1;
				if ($mode == -1 && $event == 'MODIFY') {
					$qty = $this->leeggoedQtyAfterChange($object);
				}
			}
			return $this->fixLine($doc, $lineid, $mode, $qty);
		}

		// Product line (drink)
		$info = depositoProductInfo($this->db, $fk_product);
		if ($event == 'DELETE') {
			$fk = $doc['fk'];
			return $this->deleteLines($doc, array_keys($this->depositLinesOf($doc, $lineid, empty($object->$fk) ? 0 : (int) $object->$fk)));
		}
		if ($info['deposit'] <= 0) {
			dol_syslog(__METHOD__." ".$action." line ".$lineid.": product ".$fk_product." has no deposit product", LOG_DEBUG);
			return 0;
		}
		if ($marker == self::SENDER_HANDLES) {
			dol_syslog(__METHOD__." ".$action." line ".$lineid.": sender handles deposits", LOG_DEBUG);
			return 0;
		}

		$document = $this->fetchDocument($doc, $object, $lineid);
		if (!$document || (int) $document->status != 0 || !$this->isSale($reg[1], $document)) {
			dol_syslog(__METHOD__." ".$action." line ".$lineid.": document not found, not draft or not a standard invoice", LOG_DEBUG);
			return 0;
		}
		$qty = (float) $object->qty * $info['qty'];
		dol_syslog(__METHOD__." ".$action." line ".$lineid.": deposit product ".$info['deposit']." qty ".$qty, LOG_DEBUG);

		if ($event == 'INSERT') {
			return $this->addDepositLine($reg[1], $document, $info['deposit'], $qty, $lineid);
		}

		// MODIFY: the deposit line follows the quantity
		foreach ($this->depositLinesOf($doc, $lineid, (int) $document->id) as $depositlineid => $oldqty) {
			if ((float) price2num($oldqty - $qty, 'MS') != 0) {
				if ($this->fixLine($doc, $depositlineid, 0, $qty) < 0) {
					return -1;
				}
			}
		}
		return 1;
	}

	/**
	 * Proposals, orders, standard and replacement invoices get automatic deposit lines.
	 * Credit notes, down payment and situation invoices do not.
	 *
	 * @param	string			$prefix		Trigger prefix
	 * @param	CommonObject	$document	Proposal, order or invoice
	 * @return	bool
	 */
	private function isSale($prefix, $document)
	{
		if ($prefix == 'LINEBILL' && !in_array((int) $document->type, array(Facture::TYPE_STANDARD, Facture::TYPE_REPLACEMENT))) {
			return false;
		}
		return true;
	}

	/**
	 * Does validating this sale take the line quantities out of stock?
	 * - TakePOS receipt: yes (Dolibarr's validation, or TakePOS's own loop when lots are enabled; both take qty out)
	 * - other invoice: only with the setting "decrease stock on invoice validation"
	 * - order, proposal: no
	 *
	 * @param	string			$prefix		Trigger prefix
	 * @param	CommonObject	$document	Proposal, order or invoice
	 * @return	bool
	 */
	private function takesQtyOutAtValidation($prefix, $document)
	{
		if ($prefix != 'LINEBILL' || !isModEnabled('stock')) {
			return false;
		}
		return (self::isPosSale($document) || getDolGlobalString('STOCK_CALCULATE_ON_BILL'));
	}

	/**
	 * Was this document made by a point of sale module (TakePOS, or FluxPos which works the same way)?
	 *
	 * @param	CommonObject	$document	Proposal, order or invoice
	 * @return	bool
	 */
	private static function isPosSale($document)
	{
		return in_array((string) $document->module_source, array('takepos', 'fluxpos'), true);
	}

	/**
	 * Quantity of a leeggoed line on a sale after a change. It is stored negative. The till sends:
	 * - a positive quantity (numpad, invoice form): that many empties, so -qty
	 * - the old quantity + n (TakePOS "group same product" adds the click to the stored -qty): n empties more
	 *
	 * @param	CommonObjectLine	$line	Line from the trigger (with oldline when Dolibarr provides it)
	 * @return	float
	 */
	private function leeggoedQtyAfterChange($line)
	{
		$new = (float) $line->qty;
		if ($new > 0) {
			return -$new;
		}
		$old = (isset($line->oldline) && is_object($line->oldline)) ? (float) $line->oldline->qty : null;
		if ($old !== null && $old < 0 && $new > $old) {
			return $old - ($new - $old);
		}
		return $new;
	}

	/**
	 * TakePOS turns a negative receipt into a credit note just before validating it (saving the invoice).
	 * Without lots, Dolibarr's validation puts +qty of a credit note into stock, so leeggoed lines go from
	 * -qty x price to +qty x -price. With lots, TakePOS moves the stock itself and always takes qty out,
	 * also on a credit note: then the lines stay -qty x price.
	 * The lines in memory are changed too, because validation works with them.
	 *
	 * @param	Facture		$invoice	Invoice being saved
	 * @return	int<-1,1>
	 */
	private function switchToCreditNote($invoice)
	{
		if (!($invoice instanceof Facture) || (int) $invoice->status != 0 || (int) $invoice->type != Facture::TYPE_CREDIT_NOTE) {
			return 0;
		}
		if (self::isPosSale($invoice) && isModEnabled('productbatch')) {
			return 0;
		}
		$doc = self::$documents['LINEBILL'];

		$sql = "SELECT d.rowid, d.fk_product, e.deposito_auto FROM ".$this->db->prefix()."facturedet as d";
		$sql .= " LEFT JOIN ".$this->db->prefix()."facturedet_extrafields as e ON e.fk_object = d.rowid";
		$sql .= " WHERE d.fk_facture = ".((int) $invoice->id)." AND d.qty < 0";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}
		$changed = array();
		while ($obj = $this->db->fetch_object($resql)) {
			if ((int) $obj->deposito_auto != self::SENDER_HANDLES && depositoProductInfo($this->db, (int) $obj->fk_product)['return']) {
				$changed[] = (int) $obj->rowid;
			}
		}
		foreach ($changed as $lineid) {
			if ($this->fixLine($doc, $lineid, 1) < 0) {
				return -1;
			}
		}
		if (!empty($changed) && !empty($invoice->lines)) {
			foreach ($invoice->lines as $line) {
				if (in_array((int) $line->id, $changed)) {
					$line->qty = abs((float) $line->qty);
					$line->subprice = -abs((float) $line->subprice);
					$line->multicurrency_subprice = -abs((float) $line->multicurrency_subprice);
				}
			}
		}
		return empty($changed) ? 0 : 1;
	}

	/**
	 * Load the document a line belongs to
	 *
	 * @param	array{table:string,fk:string,class:string,file:string,module:string}	$doc	Document data
	 * @param	CommonObjectLine	$line		Line from the trigger
	 * @param	int					$lineid		Line id
	 * @return	CommonObject|null
	 */
	private function fetchDocument($doc, $line, $lineid)
	{
		if (!isModEnabled($doc['module'])) {
			return null;
		}
		$fk = $doc['fk'];
		$id = empty($line->$fk) ? 0 : (int) $line->$fk;
		if ($id <= 0) {
			$resql = $this->db->query("SELECT ".$fk." as id FROM ".$this->db->prefix().$doc['table']." WHERE rowid = ".((int) $lineid));
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$id = (int) $obj->id;
			}
		}
		if ($id <= 0) {
			return null;
		}
		require_once DOL_DOCUMENT_ROOT.$doc['file'];
		$class = $doc['class'];
		$document = new $class($this->db);
		if ($document->fetch($id) <= 0) {
			return null;
		}
		return $document;
	}

	/**
	 * Add the deposit line for a product line, at 0% VAT and without discount
	 *
	 * @param	string			$prefix			Trigger prefix
	 * @param	CommonObject	$document		Proposal, order or invoice (draft)
	 * @param	int				$fk_deposit		Deposit product
	 * @param	float			$qty			Quantity of deposit
	 * @param	int				$parentlineid	Product line the deposit belongs to
	 * @return	int<-1,1>
	 */
	private function addDepositLine($prefix, $document, $fk_deposit, $qty, $parentlineid)
	{
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		$product = new Product($this->db);
		if ($product->fetch($fk_deposit) <= 0) {
			dol_syslog(__METHOD__." deposit product ".$fk_deposit." not found", LOG_WARNING);
			return 0;
		}
		$pu = (float) $product->price;
		if (getDolGlobalString('PRODUIT_MULTIPRICES') && $document->socid > 0) {
			$document->fetch_thirdparty();
			$level = empty($document->thirdparty->price_level) ? 1 : (int) $document->thirdparty->price_level;
			if (isset($product->multiprices[$level])) {
				$pu = (float) $product->multiprices[$level];
			}
		}
		$options = array('options_deposito_auto' => (int) $parentlineid);
		$type = (int) $product->type;

		self::$busy = true;
		if ($prefix == 'LINEBILL') {
			$result = $document->addline('', $pu, $qty, 0, 0, 0, $fk_deposit, 0, '', '', 0, 0, 0, 'HT', 0, $type, -1, 0, '', 0, 0, null, 0, '', $options);
		} elseif ($prefix == 'LINEORDER') {
			$result = $document->addline('', $pu, $qty, 0, 0, 0, $fk_deposit, 0, 0, 0, 'HT', 0, '', '', $type, -1, 0, 0, null, 0, '', $options);
		} else {
			$result = $document->addline('', $pu, $qty, 0, 0, 0, $fk_deposit, 0, 'HT', 0, 0, $type, -1, 0, 0, 0, 0, '', '', '', $options);
		}
		self::$busy = false;

		if ($result < 0) {
			dol_syslog(__METHOD__." addline of deposit failed (".$result."): ".$document->error.' '.implode(', ', (array) $document->errors), LOG_ERR);
			$this->error = $document->error;
			$this->errors = array_merge($this->errors, (array) $document->errors);
			return -1;
		}
		return 1;
	}

	/**
	 * Deposit lines of a product line
	 *
	 * @param	array{table:string,fk:string,class:string,file:string,module:string}	$doc	Document data
	 * @param	int		$parentlineid	Product line id
	 * @param	int		$docid			Document id (0 = unknown)
	 * @return	array<int,float>		Deposit line id => qty
	 */
	private function depositLinesOf($doc, $parentlineid, $docid = 0)
	{
		$lines = array();
		$sql = "SELECT d.rowid, d.qty FROM ".$this->db->prefix().$doc['table']." as d";
		$sql .= " INNER JOIN ".$this->db->prefix().$doc['table']."_extrafields as e ON e.fk_object = d.rowid";
		$sql .= " WHERE e.deposito_auto = ".((int) $parentlineid);
		if ($docid > 0) {
			$sql .= " AND d.".$doc['fk']." = ".((int) $docid);
		}
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$lines[(int) $obj->rowid] = (float) $obj->qty;
			}
		}
		return $lines;
	}

	/**
	 * Delete deposit lines. Plain SQL: the calling deleteline/addline recalculates the document totals afterwards.
	 *
	 * @param	array{table:string,fk:string,class:string,file:string,module:string}	$doc	Document data
	 * @param	int[]	$lineids	Line ids
	 * @return	int<-1,1>
	 */
	private function deleteLines($doc, $lineids)
	{
		foreach ($lineids as $lineid) {
			$sql1 = "DELETE FROM ".$this->db->prefix().$doc['table']."_extrafields WHERE fk_object = ".((int) $lineid);
			$sql2 = "DELETE FROM ".$this->db->prefix().$doc['table']." WHERE rowid = ".((int) $lineid);
			if (!$this->db->query($sql1) || !$this->db->query($sql2)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		return 1;
	}

	/**
	 * Put a deposit or leeggoed line right: 0% VAT, no discount, sign of quantity and price, totals.
	 * Plain SQL: the calling addline/updateline/validate recalculates the document totals afterwards.
	 *
	 * @param	array{table:string,fk:string,class:string,file:string,module:string}	$doc	Document data
	 * @param	int			$lineid		Line id
	 * @param	int			$mode		0 = keep signs, -1 = leeggoed on a sale (-qty, +price), 1 = leeggoed on a credit note (+qty, -price)
	 * @param	float|null	$newqty		New quantity, null = keep
	 * @return	int<-1,1>
	 */
	private function fixLine($doc, $lineid, $mode, $newqty = null)
	{
		$sql = "SELECT qty, subprice, multicurrency_subprice, tva_tx, localtax1_tx, localtax2_tx, remise_percent";
		$sql .= " FROM ".$this->db->prefix().$doc['table']." WHERE rowid = ".((int) $lineid);
		$resql = $this->db->query($sql);
		if (!$resql || !($obj = $this->db->fetch_object($resql))) {
			return 0;
		}
		$qty = ($newqty === null) ? (float) $obj->qty : (float) $newqty;
		$subprice = (float) $obj->subprice;
		$mcsubprice = (float) $obj->multicurrency_subprice;
		if ($mode != 0) {
			$qty = $mode * abs($qty);
			$subprice = -$mode * abs($subprice);
			$mcsubprice = -$mode * abs($mcsubprice);
		}
		$unchanged = ($qty == (float) $obj->qty && $subprice == (float) $obj->subprice && $mcsubprice == (float) $obj->multicurrency_subprice);
		if ($unchanged && (float) $obj->tva_tx == 0 && (float) $obj->localtax1_tx == 0 && (float) $obj->localtax2_tx == 0 && (float) $obj->remise_percent == 0) {
			return 0;
		}
		$total = (float) price2num($qty * $subprice, 'MT');
		$mctotal = (float) price2num($qty * $mcsubprice, 'MT');

		$sql = "UPDATE ".$this->db->prefix().$doc['table']." SET";
		$sql .= " qty = ".((float) price2num($qty, 'MS')).", subprice = ".((float) price2num($subprice, 'MU')).", multicurrency_subprice = ".((float) price2num($mcsubprice, 'MU')).",";
		$sql .= " tva_tx = 0, vat_src_code = '', localtax1_tx = 0, localtax2_tx = 0, remise_percent = 0,";
		$sql .= " total_ht = ".$total.", total_tva = 0, total_localtax1 = 0, total_localtax2 = 0, total_ttc = ".$total.",";
		$sql .= " multicurrency_total_ht = ".$mctotal.", multicurrency_total_tva = 0, multicurrency_total_ttc = ".$mctotal;
		$sql .= " WHERE rowid = ".((int) $lineid);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		dol_syslog(__METHOD__." line ".$lineid." set to qty ".$qty." x ".$subprice.", 0% VAT, no discount (was discount ".$obj->remise_percent.")", LOG_DEBUG);
		return 1;
	}
}
