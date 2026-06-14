# local_partnerapi — Moodle Partner API plugin

A Moodle **local** plugin that exposes a small, read-only, cohort-scoped REST
API consumed by the Saylor Partner Dashboard's sync service. It is the
Moodle-side counterpart to the contract in the dashboard repo
(`docs/moodle-partnerapi-plugin-spec.md`).

The dashboard pulls learner data on a schedule; this plugin is what it calls.
Moodle is the source of truth, the dashboard stores a synced snapshot, and
partners read from that snapshot — they never query Moodle live.

## What it provides

Five GET endpoints under `/local/partnerapi/v1/`, each returning a **bare JSON
array** with real HTTP status codes (not a Moodle web-service envelope):

| Endpoint | Params | Returns |
|---|---|---|
| `/v1/learners` | `cohortids[]` | learner profiles + cohort membership |
| `/v1/enrollments` | `userids[]` | per-course progress + completion |
| `/v1/completions` | `userids[]` | flat completion records (optional; unused by current sync) |
| `/v1/grades` | `userids[]` | grade items |
| `/v1/accesslogs` | `userids[]`, `since` | daily access counts |

Authentication is a per-client bearer token sent as `wstoken`. Every client is
**scoped to an explicit set of cohorts**; the plugin enforces that scope on both
cohort-keyed and user-keyed endpoints, so one partner's token can never read
another partner's learners.

## Requirements

- Moodle 4.1+ (tested target).
- The standard log store (`logstore_standard`) enabled for `/v1/accesslogs`.

## Install

1. Copy this directory to `MOODLE_ROOT/local/partnerapi`.
2. Visit **Site administration → Notifications** (or run
   `php admin/cli/upgrade.php`) to create the plugin tables.

## Create a scoped token

```bash
php local/partnerapi/cli/create_client.php --name="Chandigarh" --cohorts=23360,36144
```

This prints a `TOKEN:`; store it in the dashboard as the partner's
`moodle_api_token`.

## Test a call

```bash
curl "https://<moodle-host>/local/partnerapi/v1/learners?cohortids[]=23360&wstoken=<TOKEN>&moodlewsrestformat=json"
```

## Error semantics

| Condition | HTTP |
|---|---|
| success | 200 + JSON array |
| invalid/missing token | 401 |
| method other than GET | 405 |
| internal error | 500 |

The dashboard client retries only on `5xx` and `429`.

## Security notes

- Tokens are opaque 64-char hex strings; store and transmit over HTTPS only.
- Cohort scope is enforced server-side; requested ids outside a token's scope
  are ignored, never returned.
- The plugin loads Moodle with `NO_MOODLE_COOKIES` and performs no session
  login; it authenticates solely via the token table.

## License

GPL v3 or later (consistent with Moodle core).
