# Bounded compatibility proof

This consumer harness installs actual published Illuminate components, never
Laravel Foundation or a framework version shim. The small application container
only maps helpers, paths and locale; provider, migrator, schema, Eloquent,
filesystem, uploader, copy/move and Image 2 operations execute real library code.
It is not full Laravel, application HTTP, S3, optimizer or full upstream-suite proof.

```
php tests/Component/prepare.php 10.49.0 /tmp/mediable-component-test
composer update --working-dir=/tmp/mediable-component-test --no-interaction
COMPONENT_AUTOLOAD=/tmp/mediable-component-test/vendor/autoload.php \
  /tmp/mediable-component-test/vendor/bin/phpunit --no-configuration \
  --bootstrap tests/Component/bootstrap.php tests/Component/BackportTest.php
```

Repeat with 11.51.0, PHP 8.3 and PHP 8.4. Image is pinned to real 2.7.2.
10.49.0 is the newest published Illuminate 10 component release; there are no
component 10.50.3 tags. Full framework 10.50.3 integration is a separate consumer
responsibility. Every install retains default Composer security blocking.
The upstream full Orchestra workflow is retained and may fail fresh resolution
because of framework advisories; this component workflow does not replace or
claim to pass that gate. The original require-dev graph is preserved.

The local path consumer requires the actual `flashasia-backports` development
branch. CI checks out the tested commit on that local branch, rather than using a
Composer version override or pretending the candidate is already tagged.

Security additions exercise real SVG sanitizer upload/replacement, malformed
replacement preservation, custom-DTD rejection, extension-or-MIME detection,
executable extension rejection, mixed-case double extensions, dotfiles and
zero-directory persistence/rename. No remote network requests are made. This
suite does not claim SSRF protection: consumers must deny remote sources.
PHP 8.4 retains eight unsuppressed Image 2.7.2 implicit-nullable deprecations.

## Historical MySQL identifier compatibility

`MySqlMigrationTest.php` uses a real MySQL service and a new randomly named
`mediable_regression_*` database for each test, with the production-like `kmj_`
table prefix. It never accepts a pre-existing database name. The test account
needs CREATE/DROP DATABASE permission; teardown drops only its generated database.
Fixtures are byte-identical migrations with `.fixture` suffixes (excluded from
PHP class discovery); each test copies them into a temporary directory and runs
them through the genuine Illuminate Migrator in a separate PHP process.
`fixtures/provenance.json` records source commits, archive and file SHA-256 values.
The genuine 5.9.1 MIT license accompanies its migrations. The INT foreign-key consumer is an independently written minimal test schema.
No application migration source is included. Actual application migration proof
belongs to the application repository and is not claimed by this package suite.

```
COMPONENT_AUTOLOAD=/tmp/mediable-component-test/vendor/autoload.php \
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3306 MYSQL_USER=root MYSQL_PASSWORD= \
  php tests/Component/run-bounded.php MySqlMigrationTest.php /tmp/mysql-junit.xml
```

`run-bounded.php` discovers the exact file, requires 1–59 tests, enforces a
900-second hard limit per PHPUnit invocation, fails on risky/skipped/incomplete
results, and requires JUnit testcase count to match discovery. The same runner
wraps the existing 16-test component suite. MySQL CI uses the same truthful
Composer consumer and security policy in all four PHP/Illuminate combinations.
There are three MySQL tests, separate from the 16 component tests.

The 5.9.1 upgrade test runs the genuine historical files and populates rows before
candidate migration. It verifies exactly one DDL statement (adding `alt`),
unchanged old ledger/rows, a repeated no-op, and rollback of that new batch only.
Fresh rollback exercises the candidate's own constraints. Rolling an upgraded
5.x database back through older historical batches is outside this patch's scope.
An existing fully migrated 6.2.2 BIGINT schema is deliberately left unchanged;
this patch does not provide an in-place BIGINT-to-INT conversion.
