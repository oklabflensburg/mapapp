# AGENTS.md

This is a Symfony project. Check `composer.json` for the exact Symfony/PHP version
in use, and read `symfony.lock` to see which recipes ran. Don't assume Doctrine,
Twig, API Platform, Messenger, or Lock are installed unless one of those says so.

## Ask before generating

If the task doesn't specify, ask rather than guess:

- Persistence: Doctrine ORM, Doctrine ODM, or none?
- Interface: server-rendered (Twig), API (Serializer, maybe API Platform), or both?
- Auth: SecurityBundle, and which authenticator?

If you can't ask (no interactive channel), state the assumption you're making and
pick the smallest option (e.g. no persistence layer) rather than scaffolding a
full stack nobody asked for.

## Adding features: Flex, not hand-wiring

Install new capabilities with `composer require <package>` (e.g. `symfony/lock`,
`symfony/messenger`, `orm-pack`) and let the Flex recipe register the bundle and
generate its config. Don't hand-edit `config/bundles.php` or hand-write a bundle's
base config; that's what the recipe is for. Don't skip a good-fit component just
because it isn't installed yet; installing it is one command.

## Conventions

Follow https://symfony.com/doc/current/best_practices.html to write idiomatic
Symfony:

- Use PHP attributes for framework metadata, and not only on controllers:
  `#[Route]`, `#[MapRequestPayload]`, `#[IsGranted]` on actions, `#[Assert\...]`
  on properties, `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler]`, and
  `#[AsAlias]` / `#[AsTaggedItem]` / `#[Autoconfigure]` on services. No YAML or
  XML routing.
- Rely on autowiring and autoconfiguration. Type-hint constructor arguments and
  let the container resolve them. Where a type-hint can't express it, stay in the
  class with `#[Autowire]` (parameters, env vars, expressions) or `#[Target]` (one
  of several implementations of an interface). A YAML service definition is the
  last resort, not the first.
- Controllers extend `AbstractController`, stay thin, and delegate to services.
- Use the framework for what it already does: Form for server-rendered forms,
  Validator for validation, Serializer for JSON, Messenger for async work,
  Security (voters, authenticators) for access control, Twig `path()`/`url()`
  instead of hardcoded URLs.
- Before hand-writing infrastructure (locks, queues, caches, HTTP clients,
  mailers, schedulers) or reaching for a third-party library, check whether a
  Symfony component covers it. It usually does.

Three specifics worth spelling out, because they are easy to get wrong:

- Bind request data with `#[MapRequestPayload]` / `#[MapQueryString]` on action
  arguments, which wires up Serializer and Validator for you, instead of calling
  `json_decode()` or `SerializerInterface` by hand. If neither package is
  installed yet, `composer require` them rather than falling back to manual
  parsing.
- Use constructor property promotion, and `readonly` for DTOs and value objects.
  Don't mark a service `readonly` if it might become `lazy: true`: a lazy proxy
  can't extend a `readonly` class.
- Use `symfony/lock` (`LockFactory`) for mutual exclusion. A hand-built flag or
  lock file looks fine in review and is usually wrong under concurrency.

## Everyday workflow

- Run the app with `symfony serve -d`, and commands with `symfony console ...`
  (or `bin/console` when the Symfony CLI isn't available).
- When something fails, read `var/log/dev.log` and the web profiler
  (`/_profiler`) before changing code.
- If `maker-bundle` is installed, prefer `bin/console make:*` with every argument
  passed up front and `--no-interaction` where supported: makers prompt on a
  terminal by default, which hangs a non-interactive shell. If a maker still
  needs interactive input, hand-write the code instead.
- If Doctrine ORM is installed, schema changes go through migrations
  (`bin/console make:migration`, then `doctrine:migrations:migrate`), never
  `doctrine:schema:update` or hand-written SQL.
- `.env` is committed and holds defaults only. Real secrets belong in `.env.local`
  (git-ignored) or the secrets vault (`bin/console secrets:set`), read via
  `%env(...)%`.

## Tag translations

- `tag-translations/tags.{en,de}.xlf` are the source of published OSM and user
  tag key/value labels. The format is XLIFF 2.0. Proposed translations are stored
  on `Tag.translations`, indexed by language, and published only on admin approval.
  Pending review forms can save proposals without changing catalog targets.
  Never use pending proposals as runtime label overrides.
- The EasyAdmin Translations page edits German and English targets together,
  with one save for all fields on the displayed page. Keep CSRF protection,
  per-entry conflict checks, and shared Symfony file locking for both admin saves
  and sync. Validate and stage both catalogs before publishing a batch. Never
  permit admin forms to change unit identities.
- Resolve through `App\Service\TagKeyLabeler` (`label` and `valueLabel`).
  Unknown keys replace underscores with spaces; unknown values stay verbatim.
  Raw identifiers must stay unchanged in storage, APIs and edit inputs.
- Unit names are `key:<raw key>` or `value:<JSON array of raw key and value>`.
  Use `TagTranslationCatalog::valueId()` to build value identifiers.
- Generation writes `tag:keyRef` on contextual value units and `tag:valueRefs`
  (space-separated unit IDs) on key units, using `urn:accessapp:tag-references:1`.
  Sync rebuilds both directions from all retained pairs; target edits preserve
  the references. IDs, names and translation text must not be changed by linking.
- Generation adds `tag:category="osm"` for OSM TSV sources or `"custom"` for
  database sources, with OSM taking precedence for a unit found in both. Keep
  established OSM categories on database-only syncs. Remove generated `Tag value:`
  notes during generation, preserving other translator notes and targets.
- `tag:ignore` is a per-unit admin review flag (`true`/`false`), shared across
  the German and English editor rows. Generation defaults missing flags to false
  and preserves existing flags. It does not disable runtime label lookup. Keep
  metadata conflict checks in the bilingual batch save and preserve filter
  choices across pagination. Default to `not_ignored`; an ignored key makes its
  values effectively ignored without changing their own stored flags. Parent keys
  may appear as context for matching values.
- Sync with `npm run translations:sync` at repository root to extract tags from OSM
  nodes, ways and relations and collect backend tags together. Explicit `-- --database` collects
  only backend tags and configured choices.
  Existing targets, metadata and units must survive sync; new targets are empty.
- The old `tag_key_labels` entity/table exists only for the explicit one-time
  `--import-legacy-labels` import. Never use it for runtime lookup or new edits.
- `GET /api/i18n/tag-keys/{locale}` serves both maps. Its content ETag must
  change when either map changes. Only supported locales are accepted.
- The frontend's `tagKeyLabels` store is separate from vue-i18n. All tag labels,
  including profile priority keys and selectable values, use this store.
- Keep PHP `prettify()` and frontend `prettifyTagKey()` identical. A new locale
  needs a file, `SUPPORTED_LOCALES`, `AppLocale`, and profile language options.

## Testing

Install `symfony/test-pack` if it isn't already. Functional/HTTP tests extend
`WebTestCase`; service-level tests extend `KernelTestCase`. Run
`php bin/phpunit` (falls back to `vendor/bin/phpunit`). A feature isn't done
until it has a test that exercises it the way a caller would, an HTTP request for
a controller or a service call for a service, not just "it didn't throw."

## Code style

Symfony's coding standard, the `@Symfony` php-cs-fixer ruleset (a PSR-12-derived
superset). Run `vendor/bin/php-cs-fixer fix` if `friendsofphp/php-cs-fixer` is
installed; it isn't part of the skeleton by default.

## Discover, don't guess

Framework APIs change between versions and your training data may be stale. Look
things up in the project instead of relying on memory:

- `bin/console about`: versions, environment, paths.
- `bin/console debug:router`, `debug:container`, `debug:autowiring <name>`,
  `debug:config <bundle>`, `config:dump-reference <bundle>`: what exists and how
  it is configured.
- `bin/console lint:container`, plus `lint:twig templates/` and
  `lint:yaml config/` where those packages are installed: validate before running.
- Read the installed source and docblocks under `vendor/`.
- Docs: https://symfony.com/doc/current/ (switch to the version matching
  `composer.json` if it differs).
