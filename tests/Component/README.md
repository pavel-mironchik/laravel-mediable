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
