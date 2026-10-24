<?php

/*
| Billing defaults. Every value can be overridden at runtime through Settings
| (app(Settings::class)->get('billing.<key>')). Money values are integer minor units.
*/

return [
    // Seller identity printed on invoices (snapshotted at issue). Seller state/country for GST: tax.home_*.
    'seller' => [
        'legal_name' => '',
        'address' => '',
        'gstin' => '',
        'pan' => '',
        'vat_number' => '',
        'email' => '',
        'phone' => '',
    ],

    // Numbering (§3)
    'number_format' => '{NUMBER}',
    'number_padding' => 0,
    'number_increment' => 1,
    'number_reset' => 'never', // never|yearly|monthly
    'sequential_paid_numbering' => false,
    'proforma_format' => 'PF-{NUMBER}',
    'set_issue_date_on_payment' => true,
    'quote_number_format' => 'Q-{NUMBER}',
    // Credit and debit notes (ADR 0024): own gap-free sequences with their own format, padding and reset.
    'credit_note_format' => 'CN-{NUMBER}',
    'credit_note_padding' => 0,
    'credit_note_reset' => 'never', // never|yearly|monthly
    'debit_note_format' => 'DN-{NUMBER}',
    'debit_note_padding' => 0,
    'debit_note_reset' => 'never',
    'invoice_days_before_due' => 14,

    // Credit & add funds (§4.4, §4.5)
    'credit' => [
        'auto_apply_on_generate' => true,
        'tax_add_funds' => false,
        'min_deposit' => 1000,
        'max_deposit' => 100000,
        'max_balance' => 200000,
        'require_accepted_order' => true,
    ],
    'mass_pay_enabled' => true,
    // Renewal lines raised together go on one invoice per client and due date; true = one invoice per item.
    // Clients can override this (billing_client_flags.separate_invoices).
    'separate_invoices' => false,
    'clients_choose_gateway' => true,
    'allow_pay_method_removal' => true,

    // Hosted gateways (payments-nepal.md §3): lookup-driven reconciliation
    'gateways' => [
        'lookup_interval_minutes' => 5,
        // Pending and unclear attempts looked up per five-minute run at most (1 to 1,000), least recently looked up first.
        'lookups_per_run' => 200,
        'lookup_grace_minutes' => 120,
        // Closed attempts are still looked up hourly this long after they started, for late payments (0 = off, max 168),
        // at most late_lookups_per_run of them per hourly run.
        'late_lookup_hours' => 48,
        'late_lookups_per_run' => 100,
        // Settled payments at providers without webhooks (Khalti, eSewa) are looked up once a day this many days after
        // they started, for refunds made in the provider's portal (0 = off, max 30), at most refund_checks_per_run a day.
        'refund_check_days' => 7,
        'refund_checks_per_run' => 300,
        'http_timeout_seconds' => 15,
        'ambiguous_alert_minutes' => 30,
        // Gateway log entries (request and response payloads) are deleted after this many days (90 to 3,650).
        'log_retention_days' => 180,
    ],

    // Refunds & reversals (§4.7, §4.8)
    'reversal' => [
        'set_collections' => true,
        'rollback_due_dates' => true,
    ],

    // Tax (§5)
    'tax' => [
        'enabled' => false,
        'mode' => 'exclusive', // exclusive|inclusive
        'compound' => false,
        'calculation' => 'per_line', // per_line|per_invoice
        'rounding' => 'half_up', // half_up|half_even|down|up
        'apply_to' => ['service', 'addon', 'domain', 'setup', 'late_fee', 'manual'],
        // Seller location for intra/inter-state rules (India GST: CGST+SGST in the home state, IGST elsewhere).
        'home_country' => 'IN',
        'home_state' => 'MH',
        // SAC printed per line on Indian tax invoices (998315: hosting and IT infrastructure provisioning).
        'default_sac' => '998315',
    ],
    'vat' => [
        'enabled' => false,
        'home_country' => null,
        'auto_exempt_on_valid_id' => true,
    ],

    // Automation (§7)
    'late_fee' => [
        'type' => 'percent', // percent|fixed
        'amount' => 0, // percent, when type is percent; 0 = off
        'fixed_amount' => 0, // minor units, when type is fixed; 0 = off
        'minimum' => 0,
        'days_overdue' => 7,
        'base' => 'balance', // balance|total
    ],
    'capture' => [
        'days_before_due' => 1,
        'retry_days' => [3, 7],
        'max_failures_per_pay_method' => 3,
    ],
    'reminders' => [
        'before_due_days' => 7,
        'overdue_days' => [1, 4, 7],
    ],
    'auto_cancel' => [
        'days_overdue' => 0,
    ],

    // Quotes (§8)
    'quotes' => [
        'require_tos' => false,
        'auto_invoice_on_accept' => true,
        'valid_days' => 30,
    ],

    // Print the Bikram Sambat date beside the AD issue and due dates on invoice pages, PDFs and emails (#207).
    'show_bs_dates' => false,

    // PDFs (§9)
    'pdf' => [
        'attach_to_emails' => true,
        'paper' => 'A4',
    ],
];
