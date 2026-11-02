# Task list

- [x] 1. Foundation: schema/seeds, config, admin login, sessions/CSRF, project selection, decimal helpers, protected attachments. Verify SQL import, PHP lint and login.
- [x] 2. Expense purchases: single/multiple lines, market units, defaults/recent items, edit/repeat/cancel, uploads. Verify four brief calculations and allocation-safe editing.
- [x] 3. Payments: advances, purchase/contract allocation, balances and receipts. Verify limits, same-project/contact ownership and cancellation.
- [x] 4. Thekas: fixed/quantity contracts, adjustments, recognized work, advances/installments and history. Verify commitments excluded from expenses.
- [x] 5. Settings/contacts: projects, work types, categories, units/conversions, item defaults and contacts/ledger. Verify historical snapshots survive master edits.
- [x] 6. Dashboard/reports: nine reports, applicable filters, unit-aware weighted material totals and drilldowns. Verify real aggregates without double counting.
- [x] 7. Final review: PHP lint, MySQL runtime, desktop/mobile browser checks, concise setup/login guide. Resolve findings; record any remaining limits here.

## Checkpoints
- [x] Foundation and expense flow usable.
- [x] Payment/contract accounting verified.
- [x] All required pages functional; setup guide ready.

## Continuation notes
Implementation complete. All PHP files pass lint. Verified with isolated MySQL 8 and Chromium: login; four market pricing examples; partial payments; advances allocated across purchases/contracts without duplicate payments; full contract work recognition; weighted kg/Mann quantities; fixed charges; notes-only editing after item-default changes; purchase/payment cancellation; upload/view/remove; CSRF, project-context and allocation limits; nine report tabs; 320px and 390px mobile viewports with no document overflow or console errors. Independent final review found no blockers.

Verification database, local config, credentials and uploads removed; temporary services stopped. No automated test files created. Use README.md for deployment/database/admin setup. Source requirements remain in tasks/brief.md. All required tasks completed; no outstanding implementation work. Final Git checkpoint records delivery.
