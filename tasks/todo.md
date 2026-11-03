# Task list

- [x] 1. Foundation: schema/seeds, config, admin login, sessions/CSRF, project selection, decimal helpers, protected attachments. Verify SQL import, PHP lint and login.
- [x] 2. Expense purchases: single/multiple lines, market units, defaults/recent items, edit/repeat/cancel, uploads. Verify four brief calculations and allocation-safe editing.
- [x] 3. Payments: advances, purchase/contract allocation, balances and receipts. Verify limits, same-project/contact ownership and cancellation.
- [x] 4. Thekas: fixed/quantity contracts, adjustments, recognized work, advances/installments and history. Verify commitments excluded from expenses.
- [x] 5. Settings/contacts: projects, work types, categories, units/conversions, item defaults and contacts/ledger. Verify historical snapshots survive master edits.
- [x] 6. Dashboard/reports: nine reports, applicable filters, unit-aware weighted material totals and drilldowns. Verify real aggregates without double counting.
- [x] 7. Final review: PHP lint, MySQL runtime, desktop/mobile browser checks, concise setup/login guide. Resolve findings; record any remaining limits here.
- [x] 8. Hosted login session issue: isolate cookie from other PHP applications, scope it to install path, handle stale login forms and verify CSRF remains enforced. Fresh hosted sessions reach credential validation; original browser-specific cause remains unconfirmed. Local checks passed: dedicated cookie, login unaffected by unrelated PHPSESSID, authenticated stale login redirects, anonymous stale form rejected with refreshed CSRF. Temporary config/data removed; no automated tests added.

## UI/UX redesign tasks
- [x] 9. Design foundation and app navigation: custom responsive shell, clear active project and page titles; preserve existing routes and account actions.
- [x] 10. Dashboard redesign: clear expense/paid/outstanding hierarchy, useful recent activity and progressive disclosure of breakdowns; real figures only.
- [x] 11. Expense entry redesign: item-led quantity and quoted-price entry, compatible unit choices, plain-language calculations, optional details, clear payment summary; preserve historical snapshots and server calculations.
- [x] 12. Verify redesign: real database/browser flows, mobile and desktop layouts, keyboard/errors, four market examples and mixed kg/Mann; independent review. No automated test files.

## Original delivery checkpoints
- [x] Foundation and expense flow usable.
- [x] Payment/contract accounting verified.
- [x] All required pages functional; setup guide ready.

## Continuation notes
3 November 2026 UI/UX redesign complete for Dashboard, Add/Edit Expense and shared navigation. Custom scoped CSS, responsive sidebar/mobile navigation, one clear dashboard action, compact balances/advances, item-first entry, compatible quantity units, familiar quoted-price labels and optional advanced controls. Financial POST calculations/allocations preserved. Unit change via item defaults resets quantity only when units differ.

Verified with disposable MySQL and browser at 1440px, 390px and 320px: four market pricing examples, 40kg quoted per Mann, fixed charge, partial payment, multi-item entry, historical pricing, client validation and server-error redisplay. Independent review scope issue fixed: current-project expense lookup gates paid totals. Sidebar supports short viewport scrolling. No console errors, duplicate form IDs or horizontal document overflow found. All PHP lint clean. No automated test files created.

User explicitly approved one fictional sample save after automatic approval review initially blocked it. Saved local expense #5: 40kg at Rs10400/Mann = Rs10400, paid4000, remaining6400. Invalid large rate rejected without changing stored total; snapshot factor and user inputs retained.

Local preview: http://127.0.0.1:8087 (PHP session 28372); disposable database construction_ui on /private/tmp/construction-ui-mysql.sock, port33077 (MySQL session4655). Ignored config.php points only at that temporary socket; sample data is not hosted/user financial data. Keep these services while preview is needed; remove config.php and stop/delete temporary instance when finished. No production schema changes required. Redesign not deployed. Source requirements in tasks/brief.md; current design plan in tasks/plan.md.

## Searchable dropdown follow-up
- [x] 13. Add a lightweight searchable select control for long lists and item/contact selectors; match custom styling, support added expense rows and unit filtering, preserve native form submission/validation. Verify browser search, keyboard, programmatic defaults and mobile layout. No automated test files.

Search follow-up complete: bundled Tom Select 2.4.3 + Apache license, no jQuery. Item/contact/target selects and lists of eight or more options gain search. New rows and recent-item defaults stay synchronized; compatible units can return to native controls with labels restored. Verified typed filtering, Enter selection, row add/remove, unit list expansion/contraction and kg/Mann totals; 390px layout/input16px and console clean. No financial records saved for this follow-up.

## Contact types follow-up
- [x] 14. Expand contact types with common trades and Other; keep trade/Other contacts available for thekas. Upgrade existing database without changing saved types. Verified browser filtering/selection and database insert/update for all 11 types in a rolled-back transaction. Saved contact types preserved. Existing installs must import database/upgrade-contact-types.sql; applied locally only.

## Miscellaneous purchases follow-up
- [x] 15. Separate Loading (Trip) from Miscellaneous / Other (Piece); allow an optional purchase description. Added optional per-line description, saved through the existing description snapshot column and retained on edit/error redisplay. Local catalog upgrade applied twice safely; existing expense rows unchanged. Browser verified Measurement tape, 1 Piece × Rs650 = Rs650 and 390px layout without overflow. No expense saved. Existing installs need database/upgrade-miscellaneous-item.sql.

## Dashboard charts and Reports design
- [x] 16. Dashboard: six-month expenses vs payments chart and category bars, real project-scoped data, readable values and empty states.
- [x] 17. Reports: clean navigation, progressive filters, summary cards, quiet responsive tables; preserve accounting semantics.
- [x] 18. Verify dashboard aggregates, report filters/drilldowns, empty states and desktop/mobile layout. No automated test files.

Charts/Reports verification: six-month project totals reconcile against database (expenses Rs314200, payments Rs180000); category bars reconcile with all-time expenses. Empty project states verified read-only. All nine Reports views checked on desktop and 390px; custom October dates, contact filter, reset, future no-results and material-to-rate drilldown verified. Charts checked at 320/390/768/1024 and desktop widths without document overflow; monthly exact figures remain keyboard accessible. Independent chart review led to stronger payment-bar contrast and keyboard scroll region. PHP lint, JS syntax and diff checks passed. No records written, no automated test files, no deployment/push.
