### Added

- **Refunds made in the Khalti or eSewa portal are found (#27).** A new daily automation task, "Check paid gateway
  payments for refunds", looks up again the payments of the last 7 days at gateways that send no refund notice (Khalti,
  eSewa). A refund there that the payment's own refunds do not account for (a refund to account credit never counts,
  since that money stayed with the client) flags the payment for staff: the new
  dashboard to-do "Refunds made at the provider to record" and the "Refunded at gateway" flag on Payment attempts (new
  Flags filter). Nothing is booked automatically: staff record the refund on the invoice, then mark the payment checked.
  The reason says where to record it: on the invoice, or with Refund account credit for a payment kept as credit or
  refunded to credit. A payout of that credit already recorded with Refund account credit counts, while the client no
  longer holds the credit. An
  open late or credit flag on the same payment is kept in the reason. A partial refund that later becomes full is
  flagged again; a second partial refund after one already recorded is not detected. Each flag writes an activity entry
  (`billing.payment.portal_refund`). Upgrade notes: two new settings, `billing.gateways.refund_check_days` (7; 0 turns
  the check off, at most 30) and `billing.gateways.refund_checks_per_run` (300 a day, at most 1,000, least recently
  checked first); the first run looks up every Khalti and eSewa payment of the last 7 days once.

### Changed

- **A refund Khalti declines tells staff what to do for a bank-funded payment (#27).** The refund error now says to
  refund a payment made from a bank account in the Khalti merchant portal and record it, since Khalti's API needs the
  payer's full mobile number, which is not stored.

### Fixed

- **"Gateway payments to review" opens a list that shows what it counts.** It linked to attempts in review only, so
  booked payments refunded at the provider beyond their account credit were counted but not listed; Payment attempts
  now takes `?review=1` for both.
