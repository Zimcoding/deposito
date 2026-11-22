# Deposito — deposits on returnable packaging for Dolibarr

Deposito handles the deposit you charge on a returnable bottle or crate, and the refund when the empty comes back.

- **Deposit lines are added automatically.** A product can name a deposit product. When that product goes on a proposal, order, invoice or TakePOS receipt, the deposit line is added after it. The deposit line follows the quantity and disappears when the product line is deleted. Example: 6 bottles of juice give two lines, 6 × juice and 6 × bottle deposit.
- **Returned empties are just a product.** Tick **Leeggoed** on the product of the empty bottle (or crate). The cashier adds it like any other product. Deposito stores the line as a refund (−6 × €0.30), so Dolibarr's own stock logic puts the 6 empties back into stock when the receipt or invoice is validated.
- **No VAT and no discount** on deposit and leeggoed lines, even after a discount on the whole TakePOS sale.
- **Outstanding deposits.** *Billing > Customer invoices > Outstanding deposits* shows deposit charged minus empties returned, per product and per customer, in units and in money.

Works next to the Beverage module. It does not need it.

## Products

| Product | Type | Price | VAT | Deposito field |
|---|---|---|---|---|
| Drink, e.g. juice 75 cl | as usual | as usual | as usual | **Deposit product** = bottle deposit; **Deposit units per unit** if one unit holds more (6 for a 6-pack) |
| Deposit (*statiegeld*), e.g. bottle deposit | service | €0.30 | 0 % | none: it becomes a deposit product because a drink names it |
| Returned empty (*leeggoed*), e.g. empty bottle 75 cl | product with stock, no lots | €0.30 or −€0.30 | 0 % | **Leeggoed** ticked |

Deposito makes the line a refund whatever price you entered. Its form follows how Dolibarr moves the stock:

| Document | Stored as | Why |
|---|---|---|
| TakePOS receipt | −6 × €0.30 | Validation takes the quantity out of stock: taking −6 out puts 6 in. |
| Invoice, with stock change on invoice validation | −6 × €0.30 | Same. |
| Invoice without stock change, order, proposal | 6 × −€0.30 | No stock moves here, so the quantity stays readable. |
| Credit note | 6 × −€0.30 | Validation of a credit note puts the quantity into stock. |
| TakePOS receipt turned into a credit note | 6 × −€0.30 without lots, −6 × €0.30 with lots | With lots enabled TakePOS moves the stock itself and always takes the quantity out, also on a credit note. |

## Setup

1. Enable the module. It requires Products and Invoices. Proposals, Orders, TakePOS, Stocks and Accounting are optional; *Setup > Modules > Deposito* shows what each one adds.
2. Set the fields on the product cards as in the table above.
3. Check *Setup > Modules > Deposito*. It warns about, for example, a VAT rate that is not 0, a leeggoed product without stock, or a missing accounting code.

## Where the empties go

There is no separate setting. The empties go back into stock the same way your sales go out of it:
- **TakePOS:** into the terminal's warehouse, if the terminal decreases stock.
- **Invoices:** into the warehouse chosen at validation, if stock changes on invoice validation.

From there, move them to the cleaners with a normal stock transfer.

## Returns in TakePOS

| Situation | What to do | Result |
|---|---|---|
| Empties together with a purchase | One receipt with the drinks and the leeggoed | The refund is deducted from the purchase. |
| Only empties, default customer | Receipt with only leeggoed | TakePOS turns it into a credit note; cash refund. |
| Only empties, known customer with an earlier validated receipt or invoice | Receipt with only leeggoed | TakePOS turns it into a credit note linked to the earlier receipt. It stays open on the customer until it is paid out, or used against a later invoice in the back office. |
| Only empties, known customer **without** any validated receipt or invoice | Make a credit note in the back office with the leeggoed product, or put the empties on a receipt together with a purchase | TakePOS cannot link the credit note to anything and stops without a message (server answer "No previous bill for customer"). This is TakePOS behaviour, not Deposito. |

When TakePOS turns a receipt into a credit note, Deposito switches the leeggoed lines to the form in the table above, so the empties still go into stock.

## Outstanding deposits in the accounting

Deposito keeps no separate ledger. Dolibarr's own accounting does the work:

- Give the deposit product and the leeggoed product a **liability account** as *Accounting code (sale)*. Ask your accountant which account to use.
- Every charged deposit is then credited to that account and every refund debited, whether the money moved in cash, by bank transfer or as an offset against a purchase. Payments are handled as usual.
- The balance of that account is the debt towards customers for packaging they still hold. The report *Outstanding deposits* shows the same figures in units and money, and it also works without the Accounting module.

## Peppol

A leeggoed line on an invoice is a negative quantity at a positive price (−6 × €0.30), which is what Peppol requires (rule BR-27: no negative item prices). Deposit and leeggoed lines are 0 % VAT: check with your accountant and your Peppol connector which VAT category they get. Category O (not subject to VAT) cannot be combined with normal VAT lines on one invoice.

## Own till or API (for developers)

A till or integration that adds deposit lines and leeggoed lines itself sets the line extrafield `deposito_auto` to **−1** on those lines and on the product lines. Deposito then adds no deposit line and leaves quantities and prices as sent. It only forces 0 % VAT and no discount on deposit and leeggoed lines. Send leeggoed lines as −quantity × positive price (credit notes: +quantity × negative price), otherwise the stock moves the wrong way.

## How it works (for developers)

- Product extrafields: `deposito_deposit`, `deposito_qty` (on the drink), `deposito_return` (leeggoed checkbox).
- Line extrafield `deposito_auto` on `propaldet`, `commandedet` and `facturedet`: id of the product line a deposit line belongs to, or −1 (sender handles deposits).
- The trigger `interface_99_modDeposito_DepositoTriggers` handles `LINEBILL_*`, `LINEORDER_*` and `LINEPROPAL_*` (insert/modify/delete), plus `BILL_MODIFY` for the switch to a credit note. Everything runs inside the request that adds or changes the line: no extra calls.
- Leeggoed quantity on a change: a positive quantity (numpad, invoice form) means that many empties; old quantity + n (TakePOS "group same product") means n empties more.
- Copied documents (clone, order to invoice) bring copies of the old deposit lines. Those copies are removed, because the copied product line already got a fresh deposit line.
- Credit notes, down payment and situation invoices get no automatic deposit lines. Only drafts are changed.
- The deposit price is the product's selling price, or the customer's price level when multiple price levels are enabled.

## Limits

- With stock change on invoice validation, a leeggoed line on the normal invoice form shows −6 after saving. To change it, type the quantity as a positive number again; the form refuses negative quantities.
- Without stock change on invoice validation, empties on a normal invoice do not go into stock (nor do the drinks go out). Enter returned empties on a TakePOS receipt, or correct the stock by hand.
- Deposits on supplier documents are not handled.
- A Beverage contract invoice adds its own deposit lines from the Beverage setup. Do not give the contract service a deposit product, or the deposit is charged twice.
