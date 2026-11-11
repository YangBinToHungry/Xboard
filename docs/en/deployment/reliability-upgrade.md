# Reliable traffic reporting and explicit upgrades

## Upgrade order

Deploy Xboard before enabling the matching V2bX changes. Back up the database,
application image and persistent configuration. This change adds the
`v2_traffic_report_receipt` table. Keep that table during an application rollback;
removing it also removes the deduplication history.

Container startup no longer applies migrations, installs plugins or refreshes
themes by default. For an installed instance, it runs the read-only
`xboard:check-migrations` command and stops if migrations are missing or the
database is unavailable. Run `php artisan xboard:update` as a separate release
step with the new image before switching application traffic. When the image
uses embedded Redis, run maintenance with `CACHE_DRIVER=array`,
`QUEUE_CONNECTION=sync`, and `SESSION_DRIVER=array` or connect to the existing
Redis instance. Verify the command succeeds, then start the application.
Explicit `xboard:install` and `xboard:update` invocations bypass the startup
preflight so they can initialize or migrate the database.

`XBOARD_AUTO_UPDATE=true` is an explicit compatibility option. In this mode an
update failure stops startup; it is no longer ignored. The default is false.
The existing production Horizon mount is independent of this change.

## Traffic protocol

Updated nodes send the existing UniProxy push JSON map plus a 32-character
lowercase hexadecimal `X-Traffic-Report-ID` header. A batch has 1..1000 users,
positive user ids, and nonnegative integer upload/download byte counts. The
panel returns the same id in `X-Traffic-Report-Ack` only after the transaction
commits. Reusing an id with different data returns HTTP 409.

The database unique key is `(server_id, report_id)`. The receipt, user usage,
user statistics, server statistics and server usage all commit together.
An uncertain response can be retried with the same id and immutable payload
without charging twice. Existing traffic filter hooks are retained. External
side effects performed by custom hooks are outside the database transaction
and must themselves be idempotent.

The new path performs accounting synchronously, rather than acknowledging
three independent queue publishes. Monitor push latency and database pressure
before rolling it out to large installations. Legacy requests without the
header retain the existing asynchronous queue behavior and do not gain
request-level deduplication. A new node talking to an old panel remains
compatible, but cannot gain deduplication until the panel is upgraded.

Receipts retain only ids, payload hashes and creation times, not full traffic
payloads. No automatic pruning is added: deleting receipts while nodes may
still retry old ids permits duplicate accounting. Plan ledger storage growth
and establish a bounded retry/retention policy before introducing pruning.
V2bX currently retains pending/queued traffic in memory; a process crash can
still lose unreported traffic. This protocol does not provide a disk spool.

## Reproducible images

Initialize the admin assets submodule before building. The Dockerfile copies
the supplied checkout and composer.lock; it never fetches an application
branch during the build. Build with `BUILD_COMMIT` and `BUILD_DATE` to set the
image revision and the application's version metadata. CI supplies the exact
checkout SHA and UTC date. Keep the working tree clean when labeling a build
with a commit. Production secrets, local databases, Git metadata and runtime
logs are excluded from the build context. Docker images should be upgraded by
replacing images rather than running the Git-based source updater inside them.

## Tests

Use PHP 8.3 or newer for the locked PHPUnit 11 development dependency. Runtime
application requirements remain PHP 8.2.35 or newer. Install composer.lock with
development dependencies, create `database/testing.sqlite`, then run
`vendor/bin/phpunit`. Tests replace the named Redis cache with an in-memory
store, use a dedicated test database, and mock the Redis pending-user set.
CI runs the suite on SQLite and MySQL. These tests do not access production.
