# CHANGELOG DEPOSITO FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 0.2.5

- FluxPos receipts are treated like TakePOS receipts: leeggoed lines get the same quantity sign and stock handling (module_source `fluxpos` was ignored before).

## 0.2.4

- Menu entry Outstanding deposits moved under Billing > Customer invoices (was at the bottom of the Billing menu).
- The report needs the permission "Read the report of outstanding deposits" (Users & Groups > Permissions > Deposito); it is not granted automatically.

**After updating:** disable and enable the module once, so the menu entry moves.

## 0.2.3

- The log lines added in 0.2.2 are DEBUG level again (no log line per receipt line at INFO).

## 0.2.2

- Diagnostics: the trigger logs (dolibarr.log, level INFO, search "DepositoTriggers") every line event it receives and what it does with deposit and leeggoed lines, to find why a line discount on a deposit line is not reset.

## 0.2.1

- Fix: a TakePOS receipt with only empties (turned into a credit note by TakePOS) took the empties out of stock when lots are enabled. With lots TakePOS moves the stock itself and always takes the quantity out, so the leeggoed lines now stay −quantity there. Without lots they are still switched to +quantity, because Dolibarr's own validation puts credit-note quantities in.
- Invoices without stock change on validation, orders and proposals: leeggoed lines are no longer turned into a negative quantity; they stay quantity × negative price (still a refund). The negative quantity is only used where it moves the stock: TakePOS receipts and invoices with stock change on validation.

## 0.2.0

Returned empties are now simply a stocked product with the checkbox **Leeggoed**.
- New product checkbox Leeggoed replaces "Return of deposit" (link). Products that had it filled in get the checkbox ticked when the module is enabled again.
- Removed: product field "Empty into stock" and the empties warehouse setting. Deposito no longer moves stock itself.
- Leeggoed lines are stored as −quantity × positive price on receipts, invoices, orders and proposals, whatever the price sign on the product. Dolibarr's own stock logic then puts the empties back into stock at validation. Typing 6 on the TakePOS numpad gives −6; a second click with "group same product" adds one more.
- When TakePOS turns a negative receipt into a credit note, leeggoed lines are switched to +quantity × negative price, so the empties still go into stock.
- Line extrafield `deposito_auto` = −1: the sender (own till, API) handles deposits itself; Deposito adds nothing and keeps the signs, but still forces 0 % VAT and no discount.
- Report per product: deposit products and leeggoed products, also counting credit notes.
- README: the four return situations in TakePOS, including "No previous bill for customer".

**After updating:** disable and enable the module once, so the old fields are converted.

## 0.1.2

- Fix: a sold product with "Return of deposit" filled in too was treated as a return line. It got 0 % VAT and no deposit line. Now "Deposit product" wins: the product keeps its own VAT and brings its deposit along. Only the deposit and return lines are 0 % VAT.
- The setup page warns about products that have both a deposit product and return fields.
- When a product line changes, only the deposit lines of that same document follow (before, lines in other documents with the same parent id could follow too).

## 0.1.1

Diagnostics for "no deposit lines in TakePOS":
- Setup page shows whether the Deposito trigger is registered, and how many products use each deposit product.
- The trigger logs (dolibarr.log) why a line gets no deposit line, and the error when adding the deposit line fails.

## 0.1.0

First version.
- Product extrafields: deposit product and deposit units per unit (on the drink); return of deposit and empty into stock (on the return product).
- Deposit lines added automatically on proposals, orders, invoices and TakePOS receipts. They follow the quantity of the product line and are deleted with it. Copies of deposit lines in copied documents are replaced.
- Deposit and return lines forced to 0 % VAT and 0 % discount.
- Validating an invoice puts the returned empties into the empties warehouse; setting it back to draft takes them out again.
- Report Billing > Outstanding deposits, per deposit product and per customer.
- Setup page: empties warehouse, check of the modules used and of the deposit and return products.
- Languages: English, Dutch, French, German.
