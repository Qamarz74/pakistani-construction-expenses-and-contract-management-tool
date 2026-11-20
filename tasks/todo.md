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

## Contract/payment corrections — 20 November 2026
- [x] 19. Rename interface Thekas to Contracts; expose category creation and existing expense editing.
- [x] 20. Edit payment details safely and simplify purchase/contract/advance selection; preserve allocations and money totals.
- [x] 21. Edit approved contract work and existing contract details, including clear payment history/actions.
- [x] 22. Add explicit expense correction form: transfer approved cost + payments to a contract, or transfer payments as an advance and remove mistaken expense cost. User chooses destination; preserve original history and avoid double counting.
- [x] 23. Verify corrections, edits, project boundaries, totals and responsive UI. No automated test files.

Contract/payment correction verification (20 November): existing main was clean at start. Prior temporary database was absent; created fresh isolated MySQL datadir /private/tmp/construction-corrections-mysql using the existing local config socket /private/tmp/construction-ui-mysql.sock, port33077. Fictional fixtures only; no hosted records read or changed. PHP server session90418, MySQL session28813.

Verified both correction modes inside rolled-back transactions: approved work preserves total expense; advance-only removes mistaken cost; original record cancelled with destination/payment audit note; money/payment count unchanged. Foreign project, missing target and over-commitment reject. HTTP exercised actual payment amount/date/method/notes updates, allocation floor, approved-work edits/limit, category creation and expense correction. Released the same payment, applied to another purchase, then reapplied to a contract: still one payment, Rs200. Browser checked purpose choice, target filtering/recipient defaults, contract payment prefill, expense correction choices and visible edit actions. Contracts, payment create and expense detail fit390px; desktop preview and app console clean. Independent correction review found duplicate history and missing moved-payment trace; both fixed. PHP lint/JS syntax/diff checks pass. No automated test files. Functional changes need no schema upgrade; database/upgrade-contract-labels.sql optionally renames shipped master labels, preserving snapshots. Not deployed/pushed.

## Dashboard number display and push — 20 November 2026
- [x] 24. Verify Dashboard amount formatting; preserve decimal precision and prevent phone amounts breaking across lines. Push code only after checks; do not run SQL upgrades or modify any saved data.

Dashboard display verified before push: currency formatting now rounds/groups decimal strings without floating-point conversion (including 9999999999999999.99, negative values, zero and half-cent rounding). Kept existing comma grouping and two decimals. Dashboard money stays on one line; phone balance/cost layouts provide wider amount space. Browser checks at320/390/768px showed no document overflow, console errors or split currency lines. PHP lint/diff checks passed. No database writes, upgrades or data migrations performed for this follow-up.

## Contract milestones — 20 November 2026
- [x] 25. Keep full-construction agreement independent of stages; classify each approved-work entry by Foundation, Grey Structure, etc. Existing payments stay contract-level.
- [x] 26. Update cost correction, Dashboard work breakdown and Reports to use entry stages; stage-filtered reports must not invent per-stage payment balances.
- [x] 27. Add non-destructive optional schema upgrade with pre-upgrade compatibility, verify local classification/unchanged totals, preserve live contract pending deployment.

Milestone support verified locally: additive nullable contract_work_costs.work_type_id, repeat-safe upgrade leaves existing stages unassigned; pre-upgrade contracts/dashboard/reports render without referencing the missing column. Local HTTP verified same agreement with Foundation and Grey Structure entries, correct stage filters, invalid stage rejection and contract-level payments. Browser edited a fictional work entry stage to Foundation; monetary totals and legacy agreement work_type_id remained unchanged. 320/390/768px layouts fit without document overflow; no app console errors. PHP lint and diff checks pass. No new automated tests or test files.

Local preview: PHP server on127.0.0.1:8087; MySQL local datadir/private/tmp/construction-corrections-mysql, socket/private/tmp/construction-ui-mysql.sock, port33077. Existing config.php remains ignored. Live agreement editor was exited without saving; no live classification or schema was changed for milestones. Deployment requires database/upgrade-contract-work-stages.sql, then existing approved-work entries can be explicitly assigned to Foundation. Do not relabel the entire construction agreement as a single stage.
- [ ] 28. Deploy milestone code/upgrade and classify the live approved-work entries as Foundation; preserve the overall construction agreement and all monetary values. Pending deployment.
