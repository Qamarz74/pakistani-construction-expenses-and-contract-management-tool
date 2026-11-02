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

## Risks
- Local MySQL initially unavailable; start isolated temporary instance for live checks if needed.
- No automated tests per explicit brief. Use PHP lint, real MySQL and manual browser/HTTP checks without adding test files.
- Shared-host deployment requires PHP BCMath, PDO MySQL and Fileinfo; Apache rules protect private directories. Other servers must use equivalent deny rules.
