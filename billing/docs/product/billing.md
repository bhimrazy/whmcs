# Billing — Product Spec (v1)

Status: draft for engineering · Owner: Billing workstream · Refines FEATURE_MAP §3
Clean-room: behaviour derived from public category docs (WHMCS, Blesta, FOSSBilling); naming/schemas are ours.

## 1. Purpose & scope

Billing owns money documents and money movement: invoices and their lines, invoice numbering, tax, adjustments
(credit/debit notes), payments/transactions, credit balance, add funds, multi-invoice payment, refunds & reversals,
payment gateways & stored pay methods, auto-capture, reminders, late fees, auto-cancellation, quotes and PDFs.
Billing does **not** decide _what_ to renew — Services/Domains call `Invoicer`; Billing reacts with `InvoicePaid` etc.

## 2. Entities & key fields

### invoices

`id, client_id, number (nullable until issued; unique), proforma_number (nullable), status, currency, issue_date, due_date, paid_at,
subtotal, discount_total, tax1_name, tax1_rate, tax1_amount, tax2_name, tax2_rate, tax2_amount, tax_compound (bool), tax_inclusive (bool),
total, amount_paid, credit_applied, adjustments_total, balance (all int minor, balance is derived & cached), payment_gateway,
client_snapshot (json: name, company, address, tax_id, custom fields shown on invoice), tax_exempt_reason (nullable: exempt|reverse_charge),
kind (standard|add_funds|multi_pay_wrapper — wrapper unused in v1, see §4.8), notes (client-visible), admin_notes, last_capture_attempt_at,
capture_attempts, reminder_log (json), late_fee_applied_at, version (int, bumps on any change → PDF cache key), timestamps`

Status enum: `draft | unpaid | payment_pending | paid | cancelled | refunded | collections`. "Overdue" is **derived**
(`unpaid && due_date < today`), never stored. **MUST**

```mermaid
stateDiagram-v2
  [*] --> draft: staff create
  [*] --> unpaid: Invoicer::issue (automation/checkout)
  draft --> unpaid: publish (number assigned, issue_date=today)
  draft --> [*]: delete (drafts only)
  unpaid --> payment_pending: gateway reports pending (e.g., bank debit)
  payment_pending --> paid: settlement callback
  payment_pending --> unpaid: failure callback
  unpaid --> paid: balance reaches 0 (payment/credit/credit note)
  unpaid --> cancelled: staff/auto-cancel/order cancel
  paid --> refunded: refunds total == amount_paid
  paid --> collections: payment reversal (chargeback)
  collections --> paid: re-payment / reversal won
  cancelled --> unpaid: staff "reopen" (SHOULD; only if no credit note was numbered, else reissue)
```

### invoice_items

`id, invoice_id, type (BillableType enum), billable_id (nullable), description, quantity, unit_amount (int minor, may be negative for discounts),
amount, taxable (bool), tax_amount (cached), period_start, period_end, sort_order`

- Unique partial index on `(type, billable_id, period_start)` for recurring billables where invoice not cancelled → the **double-invoicing guard**. A cancelled invoice keeps its rows but they no longer block (see services.md for the explicit `invoiced_through` pointer that prevents re-invoicing a cancelled period automatically). **MUST**

### invoice_adjustments (credit/debit notes)

`id, invoice_id, kind (credit|debit), number (nullable; own sequence when numbered), reason (enum: cancellation|item_cancellation|credit_applied|credit_removed|refund|late_fee|overpayment|write_off|manual), amount, taxable_amount, tax_amount, created_by, created_at`

- Issued invoices are **immutable** (lines, amounts, tax, number, issue date). Changes are expressed as adjustments; due date, gateway, notes remain editable. **MUST**
- Numbered credit-note documents with PDF: **SHOULD** (`billing.credit_notes_numbered: true`). Unnumbered ledger entries are the v1 minimum.
  Superseded for tax invoices by `vat-register.md`: numbered credit and debit notes (`tax_notes`) are the only way to change a tax invoice, and each writes one adjustment row here.
- Balance formula: `balance = total + Σ debit − Σ credit − amount_paid` (credit includes applied account credit). **MUST**

### transactions

`id, client_id, invoice_id (nullable), gateway, gateway_txn_id, type (payment|refund|chargeback|chargeback_reversal|manual_income|manual_expense), amount_in, amount_out, fee, currency, exchange_rate_to_default, status (succeeded|pending|failed), raw (json, redacted), parent_transaction_id (refunds), refundable_remaining, created_by, occurred_at, timestamps`

- Unique `(gateway, gateway_txn_id, type)` → callback idempotency. **MUST**

### credit_ledger (backs `CreditLedger`)

`id, client_id, currency, amount (signed), balance_after, reason (overpayment|add_funds|refund_to_credit|applied_to_invoice|manual|downgrade|reversal), invoice_id, transaction_id, description, staff_id, gateway, external_ref, idempotency_key, created_at`

- Client balance = latest `balance_after` (row-locked append). Negative balance allowed only via reversal. **MUST**
- Credit applied automatically (renewals, `applyCredit`) is the amount read under the client lock: credit spent concurrently lowers it, and with none left the invoice is issued unpaid (#192). **MUST**
- Once-only keys: `gateway` + `external_ref` (unique together) name a provider movement only (a refund or dispute id). A posting Mohar makes itself (an import's opening balance, later automation credits) carries `idempotency_key` instead, unique, prefixed with the poster's name (`import:run:<run>:client:<client>`). The poster reads the key under the client lock before posting; the unique index stops a writer that did not. `import` is a reserved gateway key: opening balances also carry it with `external_ref` `run:…` until the contract step (#284). (#263) **MUST**

### gateways & pay_methods

- `gateways: key (driver), display_name, is_active, show_on_order_form, sort_order, convert_to_currency (nullable), config (encrypted json), timestamps`
- `pay_methods: id, client_id, gateway, kind (card|bank|wallet), token (encrypted), brand, last4, expiry_month, expiry_year, billing_contact_id, description, is_default, failure_count, disabled_at, timestamps`. **Never** store PAN/CVV. **MUST**
- `gateway_log: id, gateway, direction (callback|request|response), invoice_id, result, payload (redacted json), created_at`. **MUST**
  Entries are deleted after `billing.gateways.log_retention_days` (180, at least 90; Settings → Payment log retention,
  security tier with a recent password) by the housekeeping task
  `billing.gateway_log_prune`, at most 100,000 a run (#49).

### quotes / quote_items

`quotes: id, number, client_id (nullable → prospect fields), subject, stage (draft|delivered|on_hold|accepted|declined|expired), currency, valid_until, proposal_text, customer_notes, admin_notes, subtotal, tax..., total, accepted_at, invoice_id, timestamps`
`quote_items: description, quantity, unit_amount, discount_percent, taxable, product_id (nullable, informational)`

## 3. Invoice generation & numbering

- `Invoicer::issue(InvoiceDraft)`: validates single currency, computes tax (§5), assigns number, snapshots client, optionally applies credit, emits `InvoiceCreated`, sends email when `notify`. All in one transaction. **MUST**
- Grouping: renewal lines for the same client, currency, gateway and due date go on **one** invoice unless the client has `separate_invoices`. **MUST**
- Numbering (`billing.number_format: "{NUMBER}"`, tokens `{NUMBER} {YEAR} {MONTH} {DAY}`, `billing.number_padding: 0`, `billing.number_increment: 1`, `billing.number_reset: never|yearly|monthly`).
  Sequence row is `SELECT … FOR UPDATE` locked; numbers assigned **only at publish** (drafts unnumbered) so the sequence is gap-free. **MUST**
- Proforma mode `billing.sequential_paid_numbering: false`: when true, unpaid documents carry `proforma_number` from a separate sequence and the legal `number` is assigned when the invoice becomes paid; `billing.set_issue_date_on_payment: true` in that mode. **SHOULD**
- Manual invoices: start as draft; due date defaults to today + `billing.invoice_days_before_due` (14). Publishing sets issue_date = today. **MUST**
- Store client snapshot always (not optional) — addresses on old invoices never change. **MUST**

## 4. Payments, credit, refunds

1. **Apply payment** (`PaymentService::record`): lock invoice row; create transaction; `amount_paid += amount`; if `balance <= 0` → `paid`, `paid_at = now`; overpayment `-balance` → credit ledger (+) and a debit adjustment of reason `overpayment` so balance ends at exactly 0. Emits `PaymentReceived`, then `InvoicePaid` once (guarded by status transition). **MUST**
2. **Partial payments** leave status `unpaid` with reduced balance; reminders and capture use `balance`. **MUST**
3. **Mark paid (staff)** without money: records a zero-amount `manual` transaction + credit adjustment for the remaining balance (reason `write_off`) — distinguishes write-offs from income in reports. **MUST**
4. **Credit balance**: `billing.credit.auto_apply_on_generate: true` applies available credit to newly generated renewal invoices; never auto-applied to `add_funds` invoices. Clients can apply credit manually to any unpaid invoice (amount ≤ min(balance, credit)). Removing applied credit = debit adjustment + ledger (+). **MUST**
5. **Add funds**: client creates an `add_funds` invoice (untaxed by default `billing.credit.tax_add_funds: false`), constraints `billing.credit.min_deposit: 10.00`, `max_deposit: 1000.00`, `max_balance: 2000.00`, `require_accepted_order: true`. On paid → ledger (+amount). **MUST**
6. **Pay multiple invoices** (mass pay): v1 uses a **payment batch** (`payment_batches: id, client_id, currency, invoice_ids, total, status`) rather than a wrapper invoice — gateway charges batch total, success allocates to invoices in due-date order; any remainder → credit. If an invoice in the batch is paid separately before settlement, its allocation goes to credit. `billing.mass_pay_enabled: true`. **SHOULD**
7. **Refunds** (per transaction): amount ≤ `refundable_remaining`; mode `gateway` (driver `refund`), `record_only`, or `to_credit` (ledger +). Creates `refund` transaction (amount_out) and a credit adjustment (reason refund) only for **paid** invoices. Full refund of all payments → `refunded`. Partial → stays `paid`. Optional `reverse_effects` flag → emits `PaymentReversed` (Services roll back due dates/suspend, Affiliates reverse commission). **MUST** (gateway/record/credit) / **SHOULD** (reverse_effects)
8. **Chargebacks/reversals** from gateway webhooks: `chargeback` transaction, invoice → `collections` (`billing.reversal.set_collections: true`), emit `PaymentReversed` (`billing.reversal.rollback_due_dates: true`); for `add_funds` invoices remove the credit (may go negative). Staff notified. **SHOULD**
    - **Credit the payment placed (#172):** a dispute or a provider refund first takes back the account credit those payments placed, then comes off what the payments can still return (invoice part). Credit first is the reverse of how the money arrived: a payment fills the invoice first and only the surplus goes to credit (#194). The credit taken back is what those payments placed: overpayments, payments of closed invoices, and staff refunds of them to credit, minus earlier gateway take-backs. Never from other credit. It is one ledger line per dispute or refund id (unique `(gateway, external_ref)`), and the balance may go negative; negative balances are the "Clients owing account credit" to-do. This applies to refunds of flagged late payments too (#183); their attempt is also flagged `refund` when the credit could not cover the refund.
    - **Money out:** the chargeback or refund row books the whole reversed amount (invoice part, credit part and any rest). A refund that only came from credit gets its own `refund` row with the provider's refund id. A dispute with no invoice part (the payment went wholly to credit, or was refunded already) leaves the invoice status alone and emits no `PaymentReversed`.
    - **Staff review:** a rest neither part covers (e.g. a dispute of a charge the provider had already refunded) is stored as `transactions.review_amount`; open ones are the "Disputes to review" to-do (shown to staff with `billing.payments.manage` and `billing.transactions.view`), cleared with "Mark reviewed" on Billing → Transactions (`billing.payments.manage`, recent password).
    - **A disputed late or credited payment (#194):** a dispute of a payment flagged "Late payments to check" closes that to-do. The attempt keeps its review note with "disputed at the provider (<id>)" added, the close is logged (`billing.payment.late_closed_by_dispute`), and anything still open is on the dispute's own to-dos.
    - **A dispute the merchant wins (#191):** the provider's dispute-won event (Stripe `charge.dispute.closed` with status `won`) books one `chargeback_reversal` row per dispute id (the unique `(gateway, gateway_txn_id, type)` index), with everything the chargeback booked out as money in. It puts back what the chargeback undid:
        - the invoice part goes back on the invoice, up to what the invoice still owes, and the payment can be refunded again by that much. This holds under either `reversal.set_collections` setting (with it off, the chargeback left the invoice Paid with the amount owing). An invoice it brings to zero is paid again (`InvoicePaid`; listeners are idempotent per invoice). An add-funds invoice gets back exactly the deposit credit the chargeback removed. An invoice that still owes more keeps its status;
        - the credit the dispute took back (its `(gateway, dispute id)` ledger line) is restored by one ledger line `(gateway, "<dispute id>:won")`, which clears a negative balance it caused. A later reversal of the same payments nets it, so it can be taken back again;
        - a rest nobody could cover is marked reviewed, since that money came back too;
        - what the invoice can no longer take (paid again or cancelled since the dispute) is never turned into credit: it is the reversal row's `review_amount`, on "Disputes to review".
        - a booked win dispatches `PaymentReinstated` after commit (#221), one for each `PaymentReversed` its chargeback fired, with the same invoice and amount (one per invoice of a combined charge; none for a dispute that took back only credit), whether or not the invoice is settled again (`InvoicePaid` only when it is); Notifications publishes it as the `payment.reinstated` webhook. A duplicate dispatches nothing.
          A win that arrives before its dispute is booked is answered 409, so the provider retries, unless the provider shows the money withdrawn and reinstated already (Stripe: both balance transactions on the dispute): then no chargeback will follow and the win is answered 200 with no money moved (#232). Under the payment lock it either reverses a chargeback that committed meanwhile, or writes a zero-amount `chargeback_reversal` row for the dispute id as the durable record of the win; a chargeback for that dispute delivered later checks it under its own payment lock and books nothing ("dispute already won; nothing booked"). The zero row is hidden from the client (invoice page, PDF, credit page), never refundable, and adds no ledger line or income. A dispute closed as lost books the chargeback if none was booked for that dispute id yet (an install not subscribed to `charge.dispute.funds_withdrawn`); otherwise it changes nothing.
    - **A refunded deposit (#223):** a refund of an `add_funds` payment (provider or staff, any mode) takes back the deposit credit: the amount refunded from the invoice, at most what the deposit still holds on that invoice (its add-funds line less the chargebacks, refunds and wins booked against it). The balance may go negative ("Clients owing account credit").
    - **A dispute without funds withdrawn (#212):** an inquiry, or any dispute the provider has not withdrawn money for yet, is a zero-amount `dispute_inquiry` transaction per dispute id, open on "Disputes to review" and hidden from the client. It moves no money and its close changes nothing; when the funds are withdrawn the chargeback is booked as above and the inquiry is marked reviewed.
    - **Lock order:** payment, then invoice, then client (the ledger). Payments, credit, refunds and reversals all lock in this order; a provider reversal locks its payments and their invoices before it takes any credit back. A payment that will post credit (overpayment, deposit paid in full, closed or held invoice) locks the client after the invoice and before its row is inserted; a payment of several invoices in one transaction locks them all first, in id order (#224, ADR 0021). Issuing an invoice that may take credit, and placing an order (Orders `PlaceOrder`), lock the client before inserting any row that references it: without that, the insert's key-share lock on the client deadlocks (PostgreSQL) with another invoice or checkout of the client applying credit (#192). Both are proven by multi-process PostgreSQL tests.
9. **Currency**: invoice currency = client currency at issue; payments in other currencies rejected unless the gateway has `convert_to_currency`, in which case the charge uses `round(balance × rate)` and the transaction stores both amounts and the rate. **MUST**

## 5. Tax

- `tax_rules: id, name, level (1|2), country (nullable = all), state (nullable = all), rate (decimal 6,3), priority`. Resolution: most specific match per level (country+state > country > all). **MUST**
- Settings: `billing.tax.enabled: false`, `billing.tax.mode: exclusive` (`inclusive`), `billing.tax.compound: false`, `billing.tax.calculation: per_line` (`per_invoice`), `billing.tax.rounding: half_up`, taxed item kinds `billing.tax.apply_to: [service, addon, domain, setup, late_fee, manual]`.
- Formulas (amounts in minor units, rounded per §rounding):
    - Exclusive: `tax1 = net × r1`; `tax2 = (compound ? net + tax1 : net) × r2`; gross = net + tax1 + tax2.
    - Inclusive (single level): `tax1 = gross × r1/(1+r1)`, `net = gross − tax1`. Inclusive with two levels non-compound: `net = gross/(1+r1+r2)`; compound: `net = gross/((1+r1)(1+r2))`; taxes derived from net, any rounding remainder assigned to net so gross is preserved. **MUST**
    - `per_line`: round tax per taxable line then sum; `per_invoice`: sum taxable nets then round once. (11 × 2.21 @20% → 4.84 vs 4.86.) **MUST**
- Exemptions: client `tax_exempt` → no tax, `tax_exempt_reason=exempt`. **MUST**
- EU VAT (`billing.vat.enabled: false`, `billing.vat.home_country`): B2B customer in another EU country with a **validated** VAT ID → 0% with `reverse_charge` note on invoice; home-country B2B still charged; B2C charged at destination-country rate (rules seeded per EU country). Format validation **MUST**; live VIES validation at checkout/profile save with result cached 30 days **SHOULD**; `billing.vat.auto_exempt_on_valid_id: true`.
- Tax rates/names are **snapshotted** on the invoice; changing a rule never alters issued invoices. Rate changes = new rule with effective date (`effective_from`, SHOULD).
- Discounts (negative lines) reduce the taxable base of their taxable parent. **MUST**

## 6. Gateways (driver contract)

Driver capabilities (declared via `capabilities()`): `redirect`, `capture`, `tokenize`, `remote_input`, `refund`, `webhook`, `pending_settlement`.

- **Manual** (bank transfer, cheque): shows instructions; staff records payment. **MUST**
- **Redirect / third-party**: `beginPayment(invoice|batch) → RedirectResponse|form`; customer returns to `return_url` (never trusted to mark paid); the **webhook/callback** is authoritative. **MUST**
- **Merchant/token**: `capture(invoice, pay_method, amount) → {status: success|pending|declined|error, txn_id, fee, decline_reason, raw}`; `storeRemote(create|update|delete)` for tokens; `remoteInput()` returns hosted-field/iframe markup so card data never touches our server. **MUST** for a sandbox token driver.
- Callback handling (MUST): verify signature/secret → validate invoice exists & belongs to gateway's client → idempotency on `(gateway, txn_id)` → amount & currency check (under-payment = partial, over = credit) → `PaymentService::record` → log to `gateway_log`. Out-of-order callbacks (refund before payment) are rejected and retried by the provider.
- Default pay method: one per client; capture uses it, falling back to the first valid method for the invoice's gateway. Changing default does not change existing invoices' gateway. **MUST**
- Gateway deactivation requires choosing a replacement gateway; invoices/services/pay methods reassigned. **SHOULD**
- `billing.clients_choose_gateway: true` — client may switch gateway on an unpaid invoice (within allowed set). **SHOULD**

## 7. Automation (daily `billing:run`, company timezone, ordered; each step idempotent & resumable)

Order within the global daily pipeline: _(Services/Domains renewal generation)_ → **1. apply late fees** → **2. auto-capture** → **3. reminders** → _(Services suspend/terminate)_ → **4. auto-cancel overdue invoices** → **5. card-expiry notices (monthly)**.

1. **Late fees** (`billing.late_fee.type: percent|fixed`, `amount: 0` = off, `minimum: 0`, `days_overdue: 7`, `base: balance|total`): once per invoice (`late_fee_applied_at`), as a debit adjustment (taxable per `apply_to`), not on add_funds. **MUST**
2. **Auto-capture** (`billing.capture.days_before_due: 1`, `billing.capture.retry_days: [3, 7]` after first failure, `billing.capture.max_failures_per_pay_method: 3` → pay method `disabled_at`): eligible = unpaid, balance > 0, gateway supports capture, client has usable pay method, order not fraud, no capture attempt today. Lock per invoice; on decline send "payment failed" email; on `pending` → `payment_pending`. **MUST**
3. **Reminders** (`billing.reminders.before_due_days: 7` (0 off), `billing.reminders.overdue_days: [1, 4, 7]`): send at most one reminder per invoice per day; record in `reminder_log` keyed by rule so re-runs never double-send; skip invoices with `payment_pending`, balance 0, or captured today. **MUST**
4. **Auto-cancel** (`billing.auto_cancel.days_overdue: 0` = off): cancels unpaid invoices with **no payments and no applied credit**; adds note; credit adjustment `cancellation`. **SHOULD**
5. **Card expiry** (`billing.card_expiry_notice_day: 1`): email clients whose default card expires this month. **SHOULD**

- Payment-pending reconciliation job (hourly) polls gateways supporting status queries. **COULD**

## 8. Quotes (SHOULD)

- Stage transitions: draft → delivered (email with PDF/link) → accepted | declined | on_hold; delivered → expired automatically after `valid_until`.
- Client can view/accept/decline from client area or a signed link (prospect without account: accepting prompts registration). `billing.quotes.require_tos: false`.
- Line total = `qty × unit × (1 − discount%)`, rounded per line; tax like invoices.
- Convert to invoice (staff, or automatically on client accept when `billing.quotes.auto_invoice_on_accept: true`) → unpaid invoice of `manual` lines; quote stores `invoice_id`; second conversion blocked. No provisioning link in v1.

## 9. PDFs

- Server-side rendering (HTML template → PDF), cached by `(invoice_id, version, locale)`; regenerated after any change. Contains company details, client snapshot, number (or proforma number), dates, lines with periods, tax breakdown, adjustments, payments table, balance, "PAID"/"REVERSE CHARGE" stamps. **MUST**
- Attach PDF to invoice emails `billing.pdf.attach_to_emails: true`; paper size `billing.pdf.paper: A4`. **SHOULD**

### 9.1 Bikram Sambat dates beside AD dates (#207)

- Invoices use Gregorian (AD) dates. `billing.show_bs_dates` (Admin → Settings → Billing, "Show the Bikram Sambat date
  beside the AD date on invoices", default off) adds the BS date in brackets after the issue and due dates: "12 Jan 2026
  (28 Poush 2082 BS)". The AD part follows the date format setting; BS month names are English transliterations
  (Baisakh … Chaitra). **SHOULD**
- Shown on the admin and client invoice pages (`issue_date_bs` / `due_date_bs` in the invoice resource, null when
  off), the invoice PDF, and the key-facts rows (Issued, Due, Was due, Charge date) of the invoice emails and the
  service suspension email (merge fields `invoice.issue_date_bs`, `invoice.due_date_bs`). Subjects, preheaders and
  sentences keep the AD date only.
- Conversion: `App\Modules\Billing\Support\BikramSambat`, a month-length table for 2000–2095 BS (14 Apr 1943 to
  14 Apr 2039 AD) with its sources in the class comment and `THIRD_PARTY_NOTICES.md`. The table stops at 2095 because
  both source tables give 2096 only 364 days. The BS label has a fixed format and does not follow the date format setting. Dates outside the table are refused (`OutOfRangeException`);
  the invoice then shows the AD date alone. Rows after the current panchang are projections and are corrected when
  Nepal's official calendar for that year is published.
- A PDF rendered with BS dates is cached under its own path (`…-<locale>-bs.pdf`), so switching the setting never
  serves the other version.
- Not in scope: BS fiscal-year invoice numbering (waits for an accountant's answer on IRD/CBMS rules) and Devanagari
  numerals or month names (the app has no Nepali locale yet).

## 10. Admin capabilities

Invoice list (filters: status, overdue, client, gateway, date ranges, number), view-only by default; actions: publish draft, add payment,
attempt capture (merchant/token only), apply/remove credit, refund (per transaction), cancel, cancel selected lines (credit adjustment),
reissue (cancel + clone to new draft), duplicate (draft), send email/reminder, download PDF, notes; bulk mark paid/cancel/send reminder.
Transactions list + manual transaction (income/expense, apply to invoice ids in order, remainder → credit). Credit management per client.
Gateways config. Tax rules/VAT config. Quotes CRUD. Gateway log viewer. **MUST** (except quotes SHOULD)

## 11. Client-area capabilities

Invoices list/view/PDF, pay (choose gateway if allowed, choose stored pay method or add one), apply credit, pay multiple (SHOULD), add funds,
manage pay methods (add via remote input, set default, delete if `billing.allow_pay_method_removal: true`), transactions & credit history,
quotes accept/decline (SHOULD). **MUST**

## 12. Events emitted

`InvoiceCreated` (after commit; includes reason renewal|order|manual|upgrade|add_funds), `InvoicePublished`, `InvoicePaid` (once per invoice
lifetime transition to paid), `InvoicePartiallyPaid`, `InvoiceCancelled`, `InvoiceRefunded` (full|partial), `InvoiceOverdue` (first day overdue),
`InvoiceReminderSent` (rule), `LateFeeApplied`, `PaymentReceived`, `PaymentFailed` (capture decline), `PaymentPending`, `PaymentReversed`, `PaymentReinstated` (won dispute, #221),
`RefundIssued`, `CreditAdded`, `CreditApplied`, `PayMethodAdded`/`Removed`/`Disabled`, `QuoteDelivered`, `QuoteAccepted`, `QuoteDeclined`.

## 13. Edge cases & pitfalls

- **Double callbacks / webhook retries** → unique `(gateway, txn_id, type)` + `InvoicePaid` only on status transition.
- **Return-URL spoofing** → only signed callbacks mark paid.
- **Callback for cancelled invoice** → still record the payment; if balance ≤ 0 reopen to paid only via staff, else credit to account; alert staff.
- **Late provider success on a closed attempt** (expired, failed or cancelled) → booked after the same server-side lookup and guards as any success: on the invoice up to its balance, the rest (all of it on a closed or paid invoice) as account credit in the invoice currency. Each case writes an activity entry and raises the staff to-do "Late payments to check" until staff mark it checked (Payment attempts); so does any hosted payment that goes to account credit because the invoice was already paid or cancelled, or the payment was held for review. A closed attempt the provider reports as partly refunded goes to staff review. An attempt whose currency no longer matches its invoice is not booked and goes to staff review. A provider refund of such a payment takes the credit back, up to what is left, in one credit line per provider refund id (`credit_ledger.gateway` and `external_ref`, unique); the rest goes to "Gateway payments to review". Idempotent per provider transaction id. Closed attempts are swept hourly (an attempt last looked up at least 50 minutes before the run started, so every run, #222) for `billing.gateways.late_lookup_hours` (48, at most 168), at most `late_lookups_per_run` (100) per run, with a 5-minute deadline and a per-gateway stop after an error answer; a session the provider reports as expired, failed or cancelled is not swept again. (#130)
- **Refund made in a provider portal after payment** (gateways without refund webhooks: Khalti, eSewa) → found by the daily automation task `billing.gateway_refund_check`, which looks up paid attempts of the last `billing.gateways.refund_check_days` (7, 0 = off, at most 30) again, at most `refund_checks_per_run` (300) a day. A refund the payment's own refund rows do not account for raises the to-do "Refunds made at the provider to record"; nothing is booked automatically. Details: `payments-nepal.md` §3.5. (#27)
- **Partial refund then reversal** → refundable_remaining prevents over-refund; reversals reference the parent transaction.
- **Currency mismatch** between gateway account and invoice → reject or convert explicitly; store the rate.
- **Rounding drift** inclusive tax across many lines → assign remainder to net, keep gross equal to catalog price.
- **Timezones**: due dates are calendar dates in company tz; jobs compute "today" in company tz, not UTC.
- **Reminder spam** on job re-runs → per-rule log.
- **Auto-capture hammering** declined cards daily → retry schedule + disable after N failures.
- **Editing issued invoices** destroys audit trail → immutability + adjustments + reissue.
- **Deleting invoices** re-triggers renewal generation in some products → only drafts deletable; renewal guard is period-based.
- **Mass-pay wrapper invoices** double-count tax/income in reports → batch allocation instead.
- **Credit applied to add-funds invoice** creates loops → disallowed.
- **Late fee on partially paid** invoice: base defaults to balance; one fee only.

## 14. Acceptance criteria

1. Given a published invoice, when staff edits a line amount, then it is rejected (immutable).
2. Given two concurrent publishes, then they get consecutive distinct numbers with no gaps.
3. Given format `INV-{YEAR}-{NUMBER}` with padding 5 and next number 42 in 2026, then the number is `INV-2026-00042`.
4. Given yearly reset and the first invoice of 2027, then {NUMBER} restarts at 1.
5. Given proforma mode, when an invoice is issued, then it has a proforma number and no legal number; when paid, then it receives the next legal number.
6. Given an unpaid invoice total 100.00, when 40.00 is paid, then status stays unpaid with balance 60.00 and `InvoicePartiallyPaid` fires.
7. Given balance 60.00, when 75.00 is paid, then invoice is paid, balance 0, client credit +15.00, and an overpayment debit adjustment exists.
8. Given the same gateway txn id delivered twice, then exactly one transaction exists and `InvoicePaid` fires once.
9. Given a callback with an invalid signature, then nothing is recorded and a gateway_log entry with result "invalid signature" exists.
10. Given client credit 20.00 and auto-apply on, when a 15.00 renewal invoice is generated, then it is paid by credit and credit is 5.00.
11. Given an add_funds invoice, then credit is never auto-applied to it.
12. Given add funds of 5.00 with min deposit 10.00, then the request is rejected.
13. Given `require_accepted_order` and a client with no accepted order, then add funds is unavailable.
14. Given a paid invoice with one 50.00 payment, when 20.00 is refunded record-only, then status stays paid, refundable_remaining 30.00, a credit adjustment of 20.00 exists.
15. Given the remaining 30.00 refunded, then status is refunded.
16. Given a refund larger than refundable_remaining, then it is rejected.
17. Given exclusive tax 20% and net 10.00, then tax 2.00 and total 12.00.
18. Given inclusive tax 20% and price 12.00, then net 10.00 and tax 2.00.
19. Given compound L1 10% and L2 5% on net 100.00 exclusive, then tax1 10.00, tax2 5.50, total 115.50.
20. Given 11 lines at 2.21 @20%, per_line gives tax 4.84; per_invoice gives 4.86.
21. Given a tax-exempt client, then tax totals are 0 and reason exempt.
22. Given VAT on, home DE, client FR with validated VAT ID, then 0% tax and reverse_charge note; same client in DE pays DE VAT.
23. Given a tax rule changed after issue, then the issued invoice's tax is unchanged.
24. Given an unpaid invoice due today and before_due_days=7, the reminder was sent 7 days ago; re-running today's job sends nothing twice.
25. Given overdue_days [1,4,7], when the job runs on overdue day 4 twice, then exactly one "second overdue" email is logged.
26. Given late fee 10% min 5.00 days 7 on balance 30.00, when 7 days overdue, then a 5.00 debit adjustment is added once.
27. Given capture days_before_due=1, a token pay method and invoice due tomorrow, then a capture attempt is made today.
28. Given a decline, then the next attempt happens 3 days later, then 7 days after the first failure, and after 3 failures the pay method is disabled.
29. Given auto-cancel 30 days and an overdue invoice with a partial payment, then it is not cancelled.
30. Given a chargeback webhook for a paid invoice, then a chargeback transaction exists, invoice is collections and `PaymentReversed` fires.
31. Given an invoice in EUR and a gateway configured to convert to USD at rate 1.10, then the charge amount is round(balance×1.10) USD and the rate is stored.
32. Given a quote accepted with auto-invoice, then one unpaid invoice with the quote lines exists and a second conversion is refused.
33. Given an invoice PDF requested twice without changes, then the cached file is reused; after a payment the PDF regenerates.
34. Given a payment batch of invoices A (10.00) and B (20.00) paid 30.00, then both are paid; if A was paid separately first, then 10.00 goes to credit.

## 15. Out of scope for v1

Subscriptions managed by the gateway (PayPal-style recurring profiles); disputes UI beyond webhook reversal; e-invoicing formats (UBL/Peppol,
ZUGFeRD); accounting exports/integrations; multi-company/brands; billable items & time billing; usage/overage billing; tax-on-tax for >2 levels;
automatic exchange-rate feeds (COULD); dunning sequences beyond 3 overdue reminders; storing raw card data (never); split payments across gateways.
