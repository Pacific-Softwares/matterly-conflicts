# Matterly Conflict Check (free add-on)

Check every new client or opposing party against the firm's clients, matters and recorded parties before taking a case on, and keep a conflict log for compliance.

| Where | What |
|---|---|
| Firm panel → Add-ons → **Conflict Check** (`/matterly-conflicts`) | Search a person or company name, email, phone or matter number. Results are grouped into recorded parties (opposing parties highlighted), clients and their matters. **Record the outcome** (Clear / Conflict / Waived, with a note) to save it to the log together with what was found. |
| Conflict log (same page) | Every recorded check: when, who, what was searched, what was found, the decision. Filter by outcome, **Export CSV**. |
| **Parties** (`/matterly-conflicts/parties`) | Register of everyone on a matter who isn't the client: opposing party, opposing counsel, related party, witness, other. Optional aliases (former or trading names) and a link to the matter. |
| Automatic flag | When a client is added whose name matches an opposing party or opposing counsel, a **Needs review** check is added to the log for someone to decide. |
| Super Admin → Add-ons → **Conflict Check** | Totals across firms for the last 30 days (firms checking, checks, conflicts, parties). Never what firms searched for. |

Needs Matterly 1.3+. Free for every firm (`plan_feature: false`). Staff need the **Clients** permission.

## How it works

- Matching is word-based and case-insensitive: every word of the search must appear in the name (or aliases, or the client's email). Company suffixes such as Ltd, LLC, Inc are ignored, so "Acme Ltd" finds "ACME Corporation". 7+ digits also match client phone numbers; anything with a digit also matches matter numbers.
- Every query is pinned to the signed-in firm (`Support\ConflictSearch` takes the firm id from the user, never from the browser). Party and log ids sent from the browser are only looked up inside the firm.
- Recording a check runs the search again on the server and stores a snapshot of the names found (`hits`), so the log shows what was known at the time even if records change later.
- Tables: `matterly_conflicts_parties` and `matterly_conflicts_checks`, both with `firm_id`. Deleting a matter keeps its parties (unlinked). Uninstalling keeps the data unless the admin chooses to delete it.
- The new-client hook runs through `observe()`: if it ever fails, the client is still saved.
- The CSV export neutralises spreadsheet formulas.

## Develop

```bash
# Matterly .env: LICENTRA_MODULES_DEV=true, then php artisan config:clear
ln -s ~/Sites/gitlab/licentra/modules/matterly-conflicts ~/Sites/gitlab/advocate/modules-dev/matterly-conflicts
cd ~/Sites/gitlab/advocate
php artisan module:migrate matterly-conflicts     # tables + publishes public/build
php artisan test ../licentra/modules/matterly-conflicts/tests/ConflictsModuleTest.php
```

The pages are plain JavaScript in `public/build/pages.js` (no build step) using Matterly's module API (`window.__matterly`). All text comes from `lang/en/messages.php` through the `t` prop; add `lang/{locale}/messages.php` to translate. After changing a file in `public/`, run `module:migrate` again to republish it.

## Release

```bash
mkdir -p ~/Releases && cd ~/Releases
php ~/Sites/gitlab/advocate/vendor/pacific/licentra-client/bin/licentra-release module ~/Sites/gitlab/licentra/modules/matterly-conflicts --upload
```

Then publish it in Licentra: **Admin → Releases**. The first release also needs the add-on product: **Admin → Products → New**, slug `matterly-conflicts`, *Add-on for* Matterly, pricing *Free*. The Licentra **Team Handbook** has the full steps.
