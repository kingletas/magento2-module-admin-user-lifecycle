# Commerce_AdminUserLifecycle

Retires dormant Magento 2 admin accounts on a schedule: **warn**, then
**deactivate**, then **delete** — with an audit journal, last-administrator
protection, and a dry run that is on by default.

Every stage is separately configurable, separately switchable, and recorded —
so what the module did last night is a query rather than an inference.

It runs on Magento's cron, from the command line, or under an application
somewhere else entirely: there is a REST surface and an event for each thing it
does, and the protections are enforced on the store's side of that boundary.

---

## What it does

A dormant administrator account is a standing credential nobody is watching. The
account of somebody who left eighteen months ago has the same permissions it had
on their last day, and it is the one whose password is reused, whose 2FA is not
set up, and whose compromise nobody notices. Closing that window is worth
automating.

Automating it also means a cron job that can delete administrator accounts, and
that is worth being careful about — hence the shape below.

---

## The three stages

| Stage | Reversible? | Default | Clock |
| --- | --- | --- | --- |
| **Warn** | n/a | on, 7 days' notice | last sign-in |
| **Deactivate** | yes | on, 90 days | last sign-in |
| **Delete** | **no** | **off**, 180 days | **recorded deactivation** |

Stages are registered in `di.xml` and run in that order, so a deployment can drop
the warning stage, or add one of its own, without touching this module.

```
                 90 days since last sign-in
                            │
   ┌── warn ────────────────┤
   │   (7 days before)      │
   │                        ▼
   │                   deactivate ──── journal entry ───┐
   │                        │                           │
   │                   reversible                 180 days from
   │                                              the journal entry
   │                                                    │
   └── signing in resets every clock                    ▼
                                                     delete
                                                  (irreversible)
```

### Two different clocks

Deactivation is measured from the last sign-in. Deletion is measured from the
deactivation **recorded in this module's journal**.

Measuring both from `logdate` looks equivalent and is not. A dormant account's
`logdate` stops moving, so the deletion clock starts running at the same moment
the deactivation clock does — and if the deletion window is ever configured
shorter than the deactivation one, an account switched off on Monday is eligible
for permanent deletion on Tuesday, its deletion threshold having elapsed before
the deactivation that was supposed to precede it.

Running the two stages on different clocks means that ordering cannot be
inverted by any combination of settings, rather than merely being unlikely.

It also gives the module a memory. `admin_user` has no "when was this switched
off" column — `modified` moves whenever anything about the row changes — so
without a journal there is nothing for a deletion threshold to measure against.

---

## Protections

Checked before every write, at both stages. A protected account is journalled as
`skipped` **with the rule that stopped it**, so exclusions appear in the report
rather than as a silently shorter list.

| Rule | Setting | Applies to |
| --- | --- | --- |
| Never leave fewer than N active administrators | `protect/min_active_admins`, default 2 | deactivation |
| Never touch these usernames | `protect/usernames` | both |
| Never touch these roles | `protect/role_ids` (a picker, not a list of ids) | both |
| Never act on an account whose state changed mid-pass | — | both |

**The administrator floor is counted down during the pass, not checked once
before it.** Three dormant administrators and a floor of two: checking up front
lets all three through, because each check individually passes against the count
as it stood at the start. It is never allowed below 1 whatever is configured.

**Every write is a compare-and-swap.** Deactivation is
`UPDATE ... WHERE user_id = ? AND is_active = 1`; deletion re-reads the row and
refuses if it has been re-enabled. Somebody signing in or re-enabling an account
between selection and write has made a newer decision than the cron job's, and
theirs wins.

---

## Never-used accounts

`logdate` is NULL until an account's first sign-in, so an account created this
morning has no last-activity date at all — and `logdate <= cutoff` never returns
it, because NULL loses every comparison. Left there, abandoned never-used
accounts are invisible to this module forever, which is exactly the population it
exists to clear up.

Treating NULL as ancient is the obvious repair and the wrong one: it retires
accounts on the day they are created.

The selection query has both branches instead. A never-used account is aged from
`created`, and has to clear the dormancy threshold **and** a separate creation
grace (`deactivate/new_account_grace_days`, default 30) before anything happens
to it.

---

## Implementation notes

- **Selection returns read-only `Candidate` value objects**, four columns and a
  role id, and each write gets its own model instance. Reusing one `User` model
  across a loop does not work: `AbstractDb::load()` overlays onto whatever the
  object already holds rather than clearing it, so every field the next user
  happens not to define is inherited from the previous one — and then written
  back by `save()`. Value objects also put the decision logic where it can be
  tested without a database, which is where most of this module's suite lives.
- **Selection is keyset-paged**, not offset-paged. Every stage mutates the column
  its own query filters on, so an OFFSET page 2 is computed against a result set
  page 1 has already shortened, and rows that shuffle across the boundary are
  never visited. There is also a stalled-cursor guard: a fetcher that stops
  advancing stops the stage rather than spinning inside an unattended cron job.
- **Deleting goes through the resource model**, because
  `ResourceModel\User::delete()` also removes the `authorization_role` row that
  grants the account its permissions. A raw `DELETE` orphans that row, pointing
  at a user id the next account created may well be given.
- **Deactivating does not.** It is a single conditional `UPDATE`, because
  `ResourceModel\User::_beforeSave()` re-encrypts `rp_token` whenever it is set —
  and a freshly loaded row already holds the encrypted value, so a
  load-modify-save double-encrypts it and silently invalidates a pending
  password-reset link.
- **Live admin sessions are ended** when an account is deactivated. Otherwise the
  control only takes effect at the next sign-in, which on an account somebody has
  left logged in is never. Behind `SessionTerminatorInterface`, so a deployment
  without `Magento_Security` loses the capability rather than the module.
- **Every report value is escaped in PHP.** `ReportFormatter` produces finished
  HTML and the template's single raw variable is the one it produced. Usernames,
  email addresses and exception messages are all attacker-influenced in the
  ordinary sense.
- **The cron expression is validated on save.** Magento's schedule matcher does
  not error on a malformed expression, it simply never matches — so a typo
  produces a job that silently never runs.
- **The config section is a `di.xml` argument**, not a constant, which is what
  lets `bin/rebrand` retarget the module at another vendor namespace.

---

## Safe by default

A fresh install changes nothing:

| Setting | Ships as | Why |
| --- | --- | --- |
| `general/enabled` | **off** | Installing a module must not start retiring accounts on the next cron tick |
| `general/dry_run` | **on** | The first thing a deployment gets is a report of what *would* happen |
| `delete/enabled` | **off** | Irreversible, and takes role assignments with it |
| `protect/min_active_admins` | 2 | One is a single point of failure |
| `general/cron_enabled` | **on** | The schedule is the module's until a deployment says otherwise |

An absent or empty `dry_run` value reads as a dry run, so a missing config row
cannot turn the module into a live account-deletion job.

---

## Configuration

**Stores → Configuration → Commerce → Admin User Lifecycle**, or
`commerce_adminusers/*` in `core_config_data`. Every threshold has a floor: a `0`
that reached `inactive_days` would make every account dormant, so zero and
negatives fall back to the shipped default rather than being honoured.

Journal retention (`report/journal_retention_days`, default 730) is floored at
the deletion window plus a month, whatever is configured. Pruning inside that
window would destroy the record authorising a pending deletion — and the module
would then adopt the account afresh and restart a clock that had almost finished.

---

## Running it by hand

```bash
bin/magento commerce:admin-users:lifecycle --dry-run
```

| Flag | Effect |
| --- | --- |
| `--dry-run`, `-d` | Report only, overriding a live configured default |
| `--live` | Apply changes, overriding a dry-run default |
| `--force`, `-f` | Run even while the module is disabled, to evaluate settings |
| `--no-email` | Print the report instead of mailing it |

Exits non-zero when any account failed, so a deployment pipeline can gate on it
without parsing the output. `--dry-run` and `--live` together are refused rather
than resolved.

---

## Driving it from outside the store

Magento exposes no REST for admin accounts at all — `Magento_User` ships no
`webapi.xml` — so an application that wants to own this schedule has nothing to
call and no way to see what is due. These six routes are that surface.

| Method | Route | ACL resource |
| --- | --- | --- |
| GET | `/V1/commerce/adminUserLifecycle/candidates` | `::view` |
| GET | `/V1/commerce/adminUserLifecycle/journal` | `::view` |
| POST | `/V1/commerce/adminUserLifecycle/run` | `::run` |
| POST | `/V1/commerce/adminUserLifecycle/users/:userId/warn` | `::warn` |
| POST | `/V1/commerce/adminUserLifecycle/users/:userId/deactivate` | `::deactivate` |
| POST | `/V1/commerce/adminUserLifecycle/users/:userId/delete` | `::delete` |

They are deliberately narrow. Nothing here creates an account, edits one, or
reads a password hash: what is exposed is this module's decisions, not
`admin_user`.

```bash
# what deactivation would do tonight
curl -s "$STORE/rest/V1/commerce/adminUserLifecycle/candidates?stage=deactivate" \
  -H "Authorization: Bearer $TOKEN"
```

```json
[
  {
    "user_id": 47, "username": "dormant.dave", "email": "dave@example.com",
    "stage": "deactivate", "due": true, "due_at": "2026-06-01T09:14:00Z",
    "blocked_reason": null, "dormant_days": 200, "active": true
  },
  {
    "user_id": 3, "username": "break-glass", "stage": "deactivate",
    "due": true, "blocked_reason": "username is on the protected list", ...
  }
]
```

**A candidate can be due and blocked at once, and both facts travel.** An
application that only saw what it may act on could not report "this account is
dormant and protected", which is the line an operator most needs to read.

### The store keeps the judgement

Every protection the scheduled pass applies is applied here too, on this side of
the boundary: the two clocks, the protected usernames and roles, the
administrator floor counted down rather than checked once, and the
compare-and-swap that lets somebody signing in beat the decision to retire them.
**There is no flag that skips any of them**, because a remote scheduler is a
caller of this store and not an exception to it.

That is not a promise maintained by hand. All three stages and all six routes
act through one class — `Model\Service\AccountTransition` — so there is no
second implementation of the rules to keep in step, and
`OutOfProcessJourneyTest` asserts that a pass and an API call reach the same
verdict about the same store.

**The API can also do only what the configuration already permits.** A live
`delete` while the deletion stage is off is refused rather than performed: the
setting is the store's decision, and this is one of its callers.

### Dry run by default

`dryRun` defaults to **true** on `run`, `warn`, `deactivate` and `delete`. A
request that forgets the flag returns a report. Changing the store takes an
explicit `{"dryRun": false}`.

| Situation | Answer |
| --- | --- |
| A dry run, whatever else is off | `200`, with what would have happened |
| Live request, module switched off | `400` — refused, never downgraded to a dry run |
| Live request, that stage switched off | `400` |
| No such admin account | `404` |
| Protected, below the floor, or not yet due | `200`, `applied: false`, and the rule that stopped it |

A refusal is a successful call. "The administrator floor stopped this" is an
answer, and an application that receives an error instead will retry it.

The one refusal that is deliberately *not* a quiet success is a live request
while the module is off. A caller that believes it retired an account has to be
told that it did not.

### Events, so nothing has to poll

| Event | Payload | Fired |
| --- | --- | --- |
| `commerce_adminusers_user_warned` | user id, username, email, reason, actor, `occurred_at` | Per account warned |
| `commerce_adminusers_user_deactivated` | as above | Per account switched off |
| `commerce_adminusers_user_deleted` | as above | Per account removed |
| `commerce_adminusers_run_completed` | actor, store id, counts per action | Once per pass, whether or not anything changed |

Flat scalars, so they survive being forwarded to a subscriber outside the store
without a mapping step. Two rules about what is announced, and both are the
difference between a signal and noise:

- **A dry run announces nothing.** It recorded what it *would* have done, and a
  subscriber that cannot tell that from what happened will raise a ticket about
  an account nobody touched.
- **Only things that happened are announced.** A protection rule firing is in
  the report and in the journal, where somebody reads it once. As an event it
  would fire on every pass, for every protected account, forever.

The run event is not gated on there being changes: an application that schedules
the passes needs to know one finished, and "nothing was due" is the answer it is
usually waiting for.

### Give the schedule away

`general/cron_enabled` turns off the nightly pass and leaves everything else on,
so cron and an external scheduler cannot both retire the same account on the
same night. **Switching the module off is not the way to do this** — that also
refuses every write the API makes, which is the opposite of what an
API-scheduled deployment wants.

### Paging, dates and long passes

- **Paging is by cursor** — `afterUserId` for candidates, `afterEntryId` for the
  journal — not by page number. Every stage mutates the column its own query
  filters on, so an offset into a result set that has already shortened skips
  whatever moved across the boundary. `limit` is capped at the configured batch
  size.
- **Every date is ISO-8601 with an explicit `Z`.** Magento's usual
  `Y-m-d H:i:s` carries no zone, and a caller elsewhere in the world reads it in
  its own. What the API emits as `occurred_at` is what it accepts as `since`.
- **`run` pages through every admin account**, and an HTTP request has a
  timeout. Where `Magento_WebapiAsync` is installed, every POST route above is
  also available at `/async/V1/...`, which is the right way to call it on a
  store with a large admin team.

### The field names are not the constants

Magento derives a REST field name from the *method*: `get`, `is` or `has` is
stripped and the rest snake-cased. So `isActive()` is `active` on the wire, not
`is_active`, and `hasChanges()` is `changes`. Core's own data interfaces do not
follow that rule — `PageInterface::IS_ACTIVE` is `'is_active'` because it names
a database column — so the constants on the `Api\Data` interfaces here are the
wire format instead, and `FieldNamingTest` runs Magento's own `FieldNamer` over
every one of them.

The other half of the same trap: **every getter carries `@return <type>
<description>`, and all three parts are load-bearing.** `TypeProcessor` reads
the return type out of the docblock and throws without it — it does not look at
the native return type — so a missing annotation is a 500 on the first call that
touches it, past valid XML, green unit tests and a clean coding standard. The
coding standard in turn rejects a bare `@return int` beside a native `int`,
which is what the description is for; it is also what the generated REST schema
shows. The wiring suite checks it, for every module.

---

## The journal

`commerce_adminuser_lifecycle`, append-only. Rows carry the username and email
**copied in**, because the row they describe may be the one that no longer
exists — a deletion record that can only say "user 47" is not a record of
anything.

| Action | Meaning |
| --- | --- |
| `warned` | The owner was told, and delivery succeeded |
| `deactivated` | The account was switched off |
| `adopted` | Found inactive with no recorded deactivation; the deletion clock starts here |
| `deleted` | The account and its role assignment were removed |
| `skipped` | A protection rule or a mid-pass change stopped the action, with the reason |
| `failed` | The action was attempted and did not complete |

Dry-run rows are stored with `dry_run = 1` and **excluded from every lookup**, so
a simulated pass can never authorise a real deletion.

`actor` is `cron`, `cli` or `api`, which is how a store tells the nightly pass
from a request that came in over REST.

```sql
-- what the last week actually did, per stage
SELECT action, COUNT(*) AS entries, SUM(dry_run) AS simulated
FROM commerce_adminuser_lifecycle
WHERE occurred_at >= NOW() - INTERVAL 7 DAY
GROUP BY action ORDER BY entries DESC;
```

---

## Gotchas

- **`adopted` is not a bug.** The first pass after installing on a store with
  existing inactive accounts adopts all of them and deletes none. That is
  deliberate: the module cannot tell "deactivated two years ago" from
  "deactivated an hour ago" without a record, and only one of those is safe to
  act on. They become deletable one deletion window after adoption.
- **A dry run does not consume the administrator floor**, so its report shows
  everything a live pass would touch rather than stopping short.
- **`warn` needs `deactivate` enabled.** Warning about something that is not
  going to happen trains people to ignore the mail, so the stage disables itself.
- **The warning email carries no link and asks for nothing.** An unsolicited mail
  about an admin account that invites the reader to click something is
  indistinguishable from the phishing this module exists to reduce the surface
  for. It says: sign in the way you normally do.
- **`bin/magento setup:upgrade` is required** — the module adds a table. An
  upgrade from a version before the REST surface also rewrites one column
  comment, which is an `ALTER` on a table with a few thousand rows.
- **Turning the module off turns the API off too.** That is the intended
  reading of the setting, and it is why giving the schedule away is
  `general/cron_enabled` rather than `general/enabled`.
- **A REST caller cannot retire an account that is not due.** There is no
  "force" parameter and there will not be one: an account somebody wants gone
  today is a job for the admin panel, which is where a human is accountable for
  it.

---

## Tests

```bash
make check
```

The coding standard and all four suites — 293 tests, no database and no Magento bootstrap. Narrow it to one suite with `SUITE`:

```bash
make test SUITE=behaviour
```

The unit suite covers every class one-to-one, and each of the bugs described
above is pinned by a named regression test. `OutOfProcessJourneyTest` is the
one that matters for the REST surface: it drives a store through the API and
asserts that the answers match what the scheduled pass would have done, which
is the only claim in this file that a second implementation could quietly break. The integration suite covers the
three things a unit test structurally cannot reach:

- the **NULL-`logdate` branch** against a real MySQL, where three-valued logic
  actually applies;
- the **merged ACL tree** building without a duplicate resource id — nothing else
  catches this, because `acl.xsd` only validates element shape and an
  authorisation failure in Magento is a redirect rather than an exception, so a
  broken ACL puts the whole admin panel into a redirect loop with
  `exception.log` completely silent;
- the **pipeline end to end**: that the compare-and-swap is atomic in SQL, that a
  dry run leaves the database untouched, and that deleting a user takes its
  `authorization_role` row with it.

## CI

`.github/workflows/ci.yml`, self-contained — no private reusable workflow.

| Job | Needs | Gates |
| --- | --- | --- |
| `lint` | nothing | syntax, `composer validate`, XML well-formedness, `db_schema_whitelist` completeness |
| `coding-standard` | Packagist only | `Magento2` standard + `PHPCompatibility` at the declared 8.1 floor |
| `unit` | `COMPOSER_AUTH` | the unit suite on PHP 8.1–8.4 |
| `static-analysis` | `COMPOSER_AUTH` | PHPStan level 6, PHPMD |
| `integration` | a provisioned Magento install | manual dispatch; **documented, not enforced** until wired to an installation |

`unit` and `static-analysis` **fail** when `COMPOSER_AUTH` is absent rather than
skipping green. A silent skip is how a repository ends up with a green tick and
no test coverage.

---

## Rebranding

```bash
php ../bin/rebrand Acme
```

Rewrites the namespace, the module name, the composer package, the config
section and the table prefix in one pass. **The REST base path moves with
them** — `/V1/commerce/adminUserLifecycle/...` becomes `/V1/acme/...`, and the
ACL resource ids the routes are authorised against become `Acme_...`, so an
application calling the old paths gets a 404 rather than a 403. Existing `core_config_data` rows and
the journal table keep the old names — re-point them, or start fresh.
