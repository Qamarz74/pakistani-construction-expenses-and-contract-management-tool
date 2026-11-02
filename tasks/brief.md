# Build a Construction Expense Tracker (PHP + MySQL)

## Objective

Build a simple, private Construction Expense Tracker web application for managing my home construction expenses in Pakistan.

This is a personal-use application, not a commercial SaaS product.

**Priorities:**
1. Functionality and accurate financial calculations.
2. Very quick and easy expense entry.
3. Detailed expense and material reports.
4. Pakistani construction units and pricing conventions.
5. Minimal implementation complexity and code.

**IMPORTANT: Keep development lightweight.**

- Do not overengineer.
- Do not spend time on fancy UI or design systems.
- Do not create automated tests, test cases or test documentation.
- Do not create unnecessary abstractions, services or additional layers.
- Do not add extra features beyond the requirements.
- Do not create detailed documentation or lengthy explanations.
- Implement the application directly.
- Keep responses concise to save tokens.
- Make sensible implementation decisions without asking unnecessary questions.
- Prioritize working functionality over visual appearance.

---

## 1. Technology Stack

Use:

- Core PHP 8.2+
- MySQL
- PDO for database access
- HTML5
- Bootstrap 5
- JavaScript / jQuery
- Bootstrap Icons or simple icons if required

Do not use:

- Laravel or other PHP frameworks
- React, Vue, Angular
- Node.js, npm or frontend build tools
- Tailwind
- Docker
- Complex routing or architectural patterns

Build a responsive web application that works well on Android, iPhone and desktop browsers.

No APK, PWA installation, offline functionality or mobile-native features are required.

Keep the interface simple, clean and functional. Use standard Bootstrap components.

---

## 2. Project Management

Support multiple projects in the database, but keep the UI simple.

Features:
- Create, edit and select projects.
- Project name and optional description.
- One active project selected by default.
- All expenses, contracts, payments and reports belong to a project.
- Basic project switcher.

Do not implement complicated project management.

---

## 3. Dashboard

For the selected project, show:

- Total recorded expenses
- Total amount paid
- Total outstanding
- Unallocated advances
- Material expenses
- Labour / Theka expenses
- Current month's expenses
- Recent expenses
- Recent payments
- Quick Add Expense button

Include a simple category/work-type breakdown.

Use plain summary cards and tables. Charts are optional, only if easy to implement.

All figures must be calculated from real database records.

---

## 4. Expense Management

### Quick Expense Entry

This is the most important feature.

Keep the form fast and mobile-friendly.

Fields:
- Project: automatically selected
- Expense date: today by default
- Work type
- Category/item selection
- Quantity
- Quantity unit
- Rate
- Rate basis
- Total amount: automatically calculated
- Supplier: optional
- Payment: full, partial or unpaid
- Notes: optional
- Bill attachment: optional

Show recently used items and remember the last used rate.

Support:
- Save expense
- Save and add another
- View expense details
- Edit expense
- Cancel expense
- Repeat previous expense

### Multi-item Purchase

Allow multiple items under one purchase.

Example:

- Cement: 10 bags
- Bricks: 2,000 pieces
- Bajri: 150 CFT
- Transport: Rs. 2,000

Each item can have its own work type, quantity, unit and rate.

Calculate the total automatically.

A purchase should have one supplier and support payments against its total.

Reuse the same expense functionality for single-item and multi-item entries where possible.

---

## 5. Pakistani Construction Units

**Very important: Follow Pakistani construction market conventions.**

Default units and pricing:

| Item | Default |
|---|---|
| Bricks / Eent | Rate per 1,000 bricks |
| Cement | Per bag |
| Sariya | Per Mann, 1 Mann = 40 kg |
| Sand / Rait | Per trolley |
| Bajri / Crush | Per CFT |
| Tiles / Cheeni | Per box or Sq. Ft. |
| Marble | Per Sq. Ft. |
| Electrical wire | Per coil / roll |
| Pipes | Per piece / foot |
| Paint | Per bucket / gallon |
| Transport | Per trip |
| Labour | Per Theka |
| Electrician / Plumber | Per Theka or point |

### Configurable Unit System

- Units must be globally configurable.
- Every item must have its own default quantity unit and pricing basis.
- Allow changing units during individual expense entry.
- Allow changing default units from item settings.
- Allow creating custom units.
- Store original units and rates on each expense line.
- Changing a default unit must not change historical purchases.
- Support basic conversion rules where explicitly configured.
- Do not automatically convert trolley, dumper or other variable-capacity units.

### Pricing Calculation

Support different quantity and rate bases.

Example 1:

Bricks:
- Quantity: 5,000 pieces
- Rate: Rs. 18,000 per 1,000 pieces
- Total: Rs. 90,000

Example 2:

Sariya:
- Quantity: 5 Mann
- Rate: Rs. 10,400 per Mann
- Total: Rs. 52,000

Example 3:

Rait:
- Quantity: 3 Trolleys
- Rate: Rs. 12,000 per Trolley
- Total: Rs. 36,000

Example 4:

Bajri:
- Quantity: 250 CFT
- Rate: Rs. 130 per CFT
- Total: Rs. 32,500

Also support fixed-amount charges without quantities, such as loading or miscellaneous services.

The user must never need to manually calculate per-piece or per-kg rates.

---

## 6. Work Types, Categories and Items

Create editable master data for:

### Work Types
- Site Preparation
- Foundation
- Grey Structure
- Roof / Slab
- Plastering
- Plumbing
- Electrical
- Flooring / Tiles
- Woodwork
- Painting
- Finishing
- Other

### Categories
- Construction Materials
- Labour / Theka
- Plumbing Materials
- Electrical Materials
- Flooring Materials
- Paint / Finishing
- Transport
- Machinery
- Miscellaneous

### Items

Seed common Pakistani construction materials:

- Bricks
- Cement
- Sand / Rait
- Bajri / Crush
- Sariya
- Binding Wire
- Pipes
- Fittings
- Electrical Wire
- Switches
- Lights
- Tiles
- Marble
- Paint
- Doors
- Windows
- Transport
- Other common materials

Allow:
- Add
- Edit
- Deactivate
- Set default unit
- Set default pricing basis
- Set default rate

Keep work type, category and item separate so reports can group purchases correctly.

---

## 7. Theka / Labour Management

Add simple contract management.

Examples:
- Grey Structure Theka
- Plaster Theka
- Electrician Theka
- Plumbing Theka
- Tile Work Theka
- Painting Theka

Fields:
- Project
- Contractor
- Work type
- Contract title
- Description
- Contract date
- Contract type: fixed amount or quantity × rate
- Total agreed amount
- Status: Active, Completed, Cancelled
- Notes

Features:
- Create and edit contract.
- Record advances and installments.
- Show payment history.
- Show total agreed, paid and remaining contract amount.
- Allow adjustments to agreed contract value.
- Show contract summary in reports.

Keep the accounting simple but accurate.

An agreed contract amount is a commitment, not automatically a completed expense. Keep contract commitments separate from actual recorded expenses.

For MVP, allow recording completed/approved contract work cost manually, including full completion, without implementing a milestone management system.

Payments must not be counted twice.

---

## 8. Suppliers and Contractors

One simple contacts module.

Fields:
- Name
- Phone
- Type: Supplier, Contractor or Both
- Notes

Features:
- Create/edit contacts.
- Select an existing contact during purchase or contract entry.
- View related purchases, contracts and payments.
- Show outstanding amount.
- Show advance balance.

Avoid full CRM functionality.

---

## 9. Payments

Track expenses and payments separately.

Support:
- Full payment
- Partial payment
- Unpaid purchase
- Advance payment
- Subsequent payment against previous purchases
- Contractor payments

Payment fields:
- Project
- Supplier/contractor
- Date
- Amount
- Payment method
- Notes
- Optional attachment

Payment methods:
- Cash
- Bank Transfer
- JazzCash
- Easypaisa
- Cheque
- Other

Allow allocating payments to expenses or contracts.

If an advance is paid before a purchase, keep it as unallocated until applied.

Calculation rules:

- Expense total = sum of expense line totals.
- Paid against expense = sum of allocated payments.
- Outstanding = expense total - allocated payments.
- Contract remaining commitment = agreed contract amount - recognized contract work cost.
- Contract payment balance = agreed contract amount - payments made, shown separately.
- Do not add contract commitments to recorded expense totals.
- Do not double-count advance payments.

Use MySQL transactions for multi-table financial operations.

---

## 10. Reports

Reports are an essential part of the application.

Build one Reports page with simple tabs or filters. Avoid building a separate complex UI for every report.

### A. Overall Expense Report

Show:
- Total project expense
- Total paid
- Outstanding
- Advances
- Material vs labour cost
- Date range filter

### B. Material-wise Report

This is one of the most important reports.

Show:
- Material name
- Total quantity purchased
- Unit
- Total purchase amount
- Average rate
- Number of purchases

Allow opening a material to see:
- Purchase dates
- Quantities
- Rates
- Supplier
- Work type
- Total amounts

Examples:

- Cement: total bags and total cost
- Eent: total bricks and total cost
- Sariya: total Mann and cost
- Rait: total trolleys and cost
- Bajri: total CFT and cost

Combine quantities only where the units are compatible and a valid conversion exists. Otherwise, show separate unit-wise totals.

Use a weighted average rate, not a simple average of purchase rates.

### C. Category-wise Report

Show total expense for every category.

### D. Work Type Report

Show expenses grouped by:
- Foundation
- Grey Structure
- Plumbing
- Electrical
- Finishing
- Other work types

Allow viewing the underlying expense details.

### E. Date-wise Report

Support:
- Today
- This week
- This month
- Last month
- Custom date range

Show date-wise expense totals and details.

### F. Labour / Theka Report

Show:
- Contractor name
- Work type
- Agreed contract value
- Recognized work cost
- Payments made
- Remaining commitment/payment balance
- Contract status

### G. Supplier Report

Show:
- Supplier name
- Total purchases
- Total paid
- Outstanding
- Unallocated advances

Allow opening the supplier ledger.

### H. Payment Report

Show:
- Payments by date
- Payment method
- Recipient
- Amount
- Related expense or contract

### I. Material Rate History

For any selected material, show:
- Purchase date
- Quantity
- Original rate and rate basis
- Supplier

Show rate comparisons only for compatible units.

### Common Report Filters

Where applicable:
- Project
- Date range
- Work type
- Category
- Item
- Supplier
- Payment status

Use simple Bootstrap tables, totals and filters.

No exports, advanced analytics, PDF generation or complicated charts.

---

## 11. Attachments

Support optional bill and receipt uploads.

- JPG, PNG, WebP, PDF
- Attach to expense or payment.
- Open/view attachment.
- Remove attachment.

Store files on the PHP server.

Validate file types and sizes.

Do not build a document management system.

---

## 12. Authentication

This is a private application.

Implement:
- One admin login.
- Password stored securely using PHP password hashing.
- PHP sessions.
- Logout.
- Basic session protection and CSRF validation.

No registration, public signup, user roles, invitations, password reset emails or rate limiting.

Use reasonable basic security, but avoid building unnecessary authentication infrastructure.

---

## 13. Database

Use MySQL with a straightforward relational structure.

Suggested tables:

- users
- projects
- work_types
- categories
- units
- unit_conversions
- items
- item_unit_options
- contacts
- expenses
- expense_items
- payments
- payment_allocations
- contracts
- contract_work_costs
- attachments

You may adjust the schema if a simpler design meets all requirements.

Important:
- Use DECIMAL for money and quantities.
- Use foreign keys where appropriate.
- Preserve historical unit/rate information.
- Store separate project relationships.
- Use prepared statements.
- Prevent incorrect payment allocations.
- Keep financial calculations server-side.
- Support transaction cancellation without corrupting reports.

Provide a simple SQL schema and initial seed data.

Do not introduce a full migration framework.

---

## 14. UI Requirements

**Do not spend extra effort on design.**

Use standard Bootstrap 5 components.

Pages:

1. Login
2. Dashboard
3. Expenses (list, add, edit, details)
4. Payments
5. Thekas
6. Suppliers / Contractors
7. Reports
8. Settings (Projects, Work Types, Categories, Items, Units)

Keep navigation simple.

The interface must work comfortably on mobile.

Prioritize:
- Large buttons
- Dropdown selections
- Numeric inputs
- Minimal typing
- Smart defaults
- Fast form submissions
- Clear calculations

No custom design system, animations, fancy dashboards or unnecessary JavaScript libraries.

---

## 15. Explicitly Excluded

Do not implement:

- Laravel or any large framework
- React/Vue
- APK or native mobile application
- PWA installation or offline support
- Multiple users or roles
- Public registration
- Inventory or stock consumption
- Worker attendance
- Payroll
- Full accounting system
- Taxes
- Notifications
- Email or SMS
- Reports export
- PDF report generation
- Automated backups
- Cloud storage integrations
- Rate limiting
- Advanced audit logging
- Automated test suites
- Unit tests
- Feature tests
- Test cases or testing documentation
- API documentation
- Swagger
- Docker
- CI/CD pipelines
- Design system
- Unnecessary third-party libraries
- Extra features not explicitly requested

Do not create functionality for a future commercial version.

Build only what is needed for personal home construction expense management.

---

## 16. Implementation Instructions

1. Inspect the existing project directory first.
2. If empty, create the minimal PHP application structure.
3. Create the MySQL schema and seed data.
4. Implement backend operations.
5. Implement functional Bootstrap pages.
6. Implement expense and payment calculations.
7. Implement Theka management.
8. Implement reports using SQL aggregate queries.
9. Ensure the application works on mobile browsers.
10. Provide a concise setup guide with database configuration and login instructions.

Do not generate automated tests or lengthy documentation.

Do not spend time on visual polish beyond a usable Bootstrap interface.

Do not make unrelated changes.

If requirements are unclear, choose the simplest reasonable implementation that preserves accounting accuracy.

**Final goal:** A small, reliable, functional home construction expense tracker that I can deploy to my existing PHP/MySQL server and use from my phone.

Start implementation now. Keep code and responses concise.
