# Releasing

### Execute tests

    composer test

This runs linting (`composer lint`), unit tests (`composer unit`), coding
standards checks (`composer cs`), and static analysis (`composer stan`).

To quickly fix PHPCS issues:

    composer cbf

## 4.0 upgrade notes

- PHP 8.2+ is required.
- All source files now declare `strict_types=1`.
- `StringifierInterface::stringifyArray()` is now an instance method rather
  than a static method. Custom `StringifierInterface` implementations and any
  callers of `Stringifier::stringifyArray()` as a static method must update.
- `Expander::expandArrayProperties()` now requires `$reference_array` to be an
  array.
- `Expander::expandPropertyWithReferenceData()` returns `mixed` instead of
  `?string`, so non-string values (booleans, integers, floats) retain their
  types when expanded via reference data.
- `${env.*}` placeholders no longer read `HTTP_*` keys from `$_SERVER`, since
  those originate from client-supplied request headers in a web context.
- Environment variables with falsy values (e.g. `0`) now expand correctly.
- Expansion of a single string is capped at 25 passes and 1 MiB to prevent
  runaway growth from circular references with surrounding text.
