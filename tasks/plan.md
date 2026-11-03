# Construction Expense Tracker

Source requirements: `tasks/brief.md`. Core PHP 8.2+, PDO MySQL, Bootstrap 5, small server-rendered pages. No frameworks, build tools, automated tests or extra features.

## Decisions
- One admin created by a CLI setup command; no public installer or registration.
- PKR amounts, Asia/Karachi dates. BCMath decimal calculations; MySQL DECIMAL storage.
- Expense purchases and recognized contract work contribute to recorded cost. Contract commitments do not.
- Payments recorded once; allocations to purchases/contracts cannot exceed payment, purchase, or contract balances. Unallocated amounts remain advances.
- Cancelling purchases releases allocations; cancelling contracts preserves recognized work and prior payments, prevents new recognition/allocation.
- Snapshot units, pricing divisor and conversion factor on purchase lines; master changes never rewrite history.
- Private attachments served through authenticated PHP, outside public access using server rules.
- Shared helper functions, explicit page includes and prepared queries; no application framework.

## Work order
Foundation → expenses → payments → contracts → master data/contacts → reporting → runtime verification/setup. Independent page modules may be delegated after shared contracts are stable.

## Resume
Read `tasks/todo.md`, then `tasks/brief.md`. Preserve completed modules; resume first unchecked task. Update checklist and continuation notes after each slice.

## UI/UX redesign — 3 November 2026
User now authorizes custom design without the original Bootstrap-only visual restriction. Begin with Dashboard and Add/Edit Expense plus the shared navigation needed by those screens. Keep core PHP/MySQL and existing financial rules. No new product features or automated tests requested.

- Custom mobile-first finance interface: ink/stone surfaces, green primary actions, clear typography and compact navigation.
- Dashboard: lead with spent/paid/owed, one Add Expense action, understandable advances, recent records, secondary breakdowns.
- Expense: choose item, enter quantity and supplier's quoted price. Use market defaults and compatible units; explain calculations in plain language. Advanced pricing/category controls stay available behind disclosure. Optional notes/bill and payment details reveal as needed.
- Parallel ownership: root shared shell/CSS and integration; expense agent expense page/JS/scoped CSS; dashboard agent dashboard page/scoped CSS. Review after integration.
- Verify locally with disposable database and browser. Preserve server-side calculations, project/CSRF guards, snapshots and allocations. Deployment requires separate hosted verification.

## Risks
- Local MySQL initially unavailable; start isolated temporary instance for live checks if needed.
- No automated tests per explicit brief. Use PHP lint, real MySQL and manual browser/HTTP checks without adding test files.
- Shared-host deployment requires PHP BCMath, PDO MySQL and Fileinfo; Apache rules protect private directories. Other servers must use equivalent deny rules.
