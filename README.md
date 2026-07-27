# local_partnerapi - Moodle Partner API plugin

A Moodle local plugin that provides a cohort-scoped REST API for the Saylor
Partner Dashboard. Moodle remains the source of truth; authorized partners read
only data for cohorts explicitly assigned to their API client.

## Supported Moodle versions

Moodle 4.1 through 4.5 are supported. GitHub Actions runs the plugin's coding
standard, PHPDoc, validation, PHPUnit, and Behat checks against both ends of that
range. Moodle Workplace tenant isolation is not claimed or supported until it
has been tested in a licensed Workplace environment.

## Endpoints

All endpoints are under `/local/partnerapi/v1/` and use a client token supplied
as `wstoken`.

| Endpoint | Parameters | Result |
|---|---|---|
| `/accesslogs` | `userids[]`, `since`, `until` | Daily access counts in a bounded date range |
| `/certificates` | `userids[]` | Issued certificate records |
| `/cohorts` | none | Cohorts assigned to the client |
| `/completions` | `userids[]` | Flat course completion records |
| `/enrollments` | `userids[]` | Course enrolment and progress |
| `/grades` | `userids[]` | Released, visible grade items |
| `/learners` | `cohortids[]` | Learner profiles and affiliation provenance |
| `/profilefields` | none | Registration form field definitions |
| `/quizzes` | `userids[]` | Attempts released by Moodle's quiz review policy |
| `/register` | JSON POST | Create an account within the client's cohort scope |
| `/timeincourse` | `userids[]`, `since`, `until`, `page`, `perpage` | Bounded time-on-task estimates |

User-keyed endpoints and cohort lists accept at most 200 unique IDs per request. The
time-in-course endpoint accepts up to 1,000 IDs and processes a page of at most
200 at a time. Its date range defaults to the last 90 days and cannot exceed
366 days. `page` is zero-based.

## Security and privacy behavior

- Tokens are opaque 64-character secrets and must be sent over HTTPS.
- Every requested cohort and user is checked against the authenticated client.
- Registration rejects email-domain affiliations outside the client's scope.
- Self-service affiliation accepts only visible `AFF-` cohorts.
- Editing another user's affiliation requires `moodle/cohort:assign`.
- Hidden grades and unreleased quiz results are excluded from API responses.
- The Moodle Privacy API describes, exports, and deletes affiliation provenance.
- External sharing is declared in Privacy API metadata and is limited to the
  administratively assigned client scope.

## Install

1. Copy this directory to `MOODLE_ROOT/local/partnerapi`.
2. Visit **Site administration > Notifications**, or run
   `php admin/cli/upgrade.php`.

## Create a scoped token

```bash
php local/partnerapi/cli/create_client.php --name="Chandigarh" --cohorts=23360,36144
```

Store the emitted token as a secret in the Partner Dashboard.

## Example

```bash
curl "https://moodle.example/local/partnerapi/v1/learners?cohortids[]=23360&wstoken=TOKEN"
```

## License

GPL v3 or later.
