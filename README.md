# Spora Plugin: Office

Markdown ⇄ Office documents for Spora agents — Word (`.docx`, `.odt`, `.rtf`)
and PowerPoint (`.pptx`). Backed by
[`markdown-office/markdown-word`](https://github.com/markdown-office/markdown-word)
and [`markdown-office/markdown-presentation`](https://github.com/markdown-office/markdown-presentation),
and the media-archive producer / refiner registries in `spora-core`.

The plugin ships **no LLM tool, no routes and no admin app**. It contributes
five registrations to core's Media Archive and two skills, so the existing
`media` tool gains Office documents in both directions:

- **Write** — `media(action: "create_media")` stores the Markdown, then
  `media(action: "create_derivative", format: "docx")` renders it to a document
  the user downloads.
- **Read** — a `.docx` or a `.pptx` uploaded to a chat is extracted into an
  `md` derivative, and that text is inlined into the agent's context. No tool
  call involved.

> **This package was renamed.** It was `spora-ai/spora-plugin-word`, and the
> GitHub repository is still `spora-ai/spora-plugin-word` until the maintainer
> renames it. The old package stays published and is **deprecated, not
> deleted**: `spora-fgrassl` pins `spora-ai/spora-plugin-word: ^0.2.0`, and a
> host that has not moved yet keeps working. Install the new name for
> everything new.

## Requires four PHP extensions

**All four are hard requirements of the upstream packages** — the plugin does
not degrade without them, and unlike `ext-typst` there is no
optional-extension path and no subset of the suite that runs without them.

| | |
| --- | --- |
| PHP | `^8.4.1` |
| `ext-zip` | `*` — a `.docx` is a zip archive; `ZipArchive` is the only way in |
| `ext-dom` | `*` — the renderer writes and rewrites `word/document.xml` |
| `ext-mbstring` | `*` — multibyte-safe string handling across the document model |
| `ext-gd` | `*` — rescaling an embedded image to `imageMaxWidth`; only reached when a readable *local* image is embedded |
| spora-core | `>=0.30.0` — see the note below |

Check them before installing:

```bash
php -m | grep -E '^(zip|dom|mbstring|gd)$'
```

All four must be listed. On a shared host (cPanel / FTP) that does not already
have them, the plugin is a hard install failure and there is no partial mode.

### Escape hatch: `--ignore-platform-req`

Composer will refuse the install over any missing extension, so the useful
question is what each one actually costs you if you skip it:

- `ext-zip` and `ext-dom` are required in **both** directions. Without either,
  nothing works — no rendering and no reading back.
- `ext-mbstring` is required in both directions too: the reader uses
  `mb_strtolower` when matching fonts and styles.
- `ext-gd` is the cheapest to skip. The reverse direction never calls into it,
  so DOCX→Markdown keeps working and the chat keeps the extracted text of every
  document it is given. Forward, `gd` is reached only when an embedded image is
  actually **rescaled** to `imageMaxWidth`, and the library catches the failure
  and falls back to the image's alt text. So ignoring it costs you rescaled
  embedded local images, not documents.

If that trade is acceptable — and on a host already running Spora, `ext-dom` is
present because spora-core requires it — install with:

```bash
composer require spora-ai/spora-plugin-office --ignore-platform-req=ext-gd
# or, to bypass all four at once:
composer require spora-ai/spora-plugin-office --ignore-platform-req=ext-zip --ignore-platform-req=ext-dom --ignore-platform-req=ext-mbstring --ignore-platform-req=ext-gd
```

Prefer enabling the extension. The flag exists for the host where you cannot.

### The host has to allow `cweagans/composer-patches`

`markdown-presentation` writes a few things a released PHPPresentation cannot,
and gets them from a patch applied to the installed package. Composer runs
that patch only when the **host** allows the plugin — `allow-plugins` is a
root-project setting, and `spora-ai/installer` routes `spora-plugin` packages
into the host's `plugins/{name}/`, so the host's tree is where the dependency
lands.

```bash
composer config allow-plugins.cweagans/composer-patches true
```

Without the entry the install stops with Composer's own message:

```
cweagans/composer-patches contains a Composer plugin which is blocked by your allow-plugins config.
```

With the entry set to `false`, or with `--no-plugins`, the install succeeds and
every deck render fails with `MarkdownPresentation\Exception\UnpatchedDependency`
— which names the plugin and says what to do, so the failure is diagnosable
rather than mysterious.

The patch adds five methods to PHPPresentation. When PHPPresentation 1.3.0
ships them upstream — the changes are queued as the open PRs #1041, #1042 and
#1043 — `markdown-presentation` drops the composer plugin, the patch and the
`allow-plugins` entry all at once. Until then the entry is required.

### spora-core version floor

The real requirement is the `Spora\Services\MediaArchive\MediaMimeRefinerInterface`
seam — the refiner registry below — which `composer.json` expresses as
`spora-ai/spora-core >=0.30.0`. Core `v0.30.0` is tagged and indexed on
Packagist, so spora-core resolves from Packagist like every other plugin.
Install it the usual way:

```bash
composer require spora-ai/spora-plugin-office
```

A host running core older than `v0.30.0` has no `MediaMimeRefinerInterface` and
must upgrade core.

`OfficePlugin::onContainerBuilding()` checks `interface_exists()` at boot and
throws `PluginLoadFailedException` naming the missing seam when the host's core
predates it, rather than registering two of the five contributions and leaving
a silently half-working plugin.

## What's in the box

- 2 skills (`office-documents`, `office-presentations`) — the two-call chain,
  the theme catalogue, the five documented round-trip losses, the image rules,
  the approval and idempotency rules.
- 5 media-archive registrations: four derivative producers and a MIME refiner
  (table below).
- 3 exception classes, mirroring the Typst plugin's shape.
- 1 plugin migration — the rows the rename touched (table below).
- No tools, no routes, no admin app, **no frontend package** — the download card
  that renders a `.docx` in chat is core's `MediaEmbed::fileCard()`, so there is
  no `spora-plugin-office-frontend` to build or ship.

## The data migration

The plugin's identity changed, and the rows that carry it carry **two**
vocabularies: `pluginSlug()` returns the Composer **package** name
(`spora-plugin-word`), not the manifest slug (`word`), and `operationName()`
returns the short slug (`word.render`). `office_000001_rename_producer_identity.php`
rewrites all of it:

| Column | Before | After |
| --- | --- | --- |
| `media_derivatives.producer_plugin` | `spora-plugin-word` | `spora-plugin-office` |
| `media_derivatives.producer_operation` | `word.render` / `word.extract` | `office.render` / `office.extract` |
| `media_assets.plugin_slug` | `spora-plugin-word` | `spora-plugin-office` |
| `media_assets.tool_name` | `word.render` / `word.extract` | `office.render` / `office.extract` |

Both halves of each pair have to move together. The natural key of a derivative
is `(parent_id, format, producer_plugin, producer_operation)`, and
`MediaDerivativeService` looks the row up by exactly that — a derivative whose
`producer_plugin` was rewritten while its `producer_operation` was not no
longer matches, so the service mints a **second** derivative instead of
refreshing the one that is there.

It is a **plugin** migration, in `database/migrations/`, because the plugin owns
its own identity and the rows are core's tables. Every file in that directory
has to start with the plugin's slug and an underscore — `office_` — or
`DatabaseSchemaInstaller::validateMigrationFilenames()` refuses it.

The `presentation.*` operations are new and have no rows to rewrite.

## The five registrations

`OfficePlugin::onContainerBuilding()` calls `add()` on two separate registries.
The producers share one seam — the round trip is two directions of the same
contract, so an `md` extract is a legal parent for a `docx` render — and the
refiner is the other. Registering only some of them leaves a plugin that
half-works rather than one that errors.

| Interface | Class | What it does |
| --- | --- | --- |
| `MediaDerivativeProducerInterface` | `Producers\MarkdownToOfficeProducer` | Markdown parent + `format: "docx"` / `"odt"` / `"rtf"` → document bytes as a new `media_assets` row. `pluginSlug()` = `spora-plugin-office`, `operationName()` = `office.render` — both are part of the idempotency natural key. |
| `MediaDerivativeProducerInterface` | `Producers\OfficeToMarkdownProducer` | DOCX parent + `format: "md"` → GFM Markdown as a new `media_assets` row. `operationName()` = `office.extract`, distinct from the render half so the two rows never collapse onto one key. This is the half the chat reads. **Its declared source formats are also what puts `.docx` on the upload allowlist** — `MediaAllowedTypesService` unions every registered producer's source MIME, and nothing else in this plugin contributes an upload type. That union reaches `allowedMimeTypes()` only from core's md-derivative cut onwards; on an older core the entry came from the converter registry, which that cut deletes along with this PR. **The two must land together** — separated, the plugin boots fine and every `.docx` upload is a 415 at the gate. |
| `MediaDerivativeProducerInterface` | `Producers\MarkdownToPresentationProducer` | Markdown deck parent + `format: "pptx"` → deck bytes. `operationName()` = `presentation.render`. The format is part of the natural key, so a deck and a document from the same parent are two rows and never one. |
| `MediaDerivativeProducerInterface` | `Producers\PresentationToMarkdownProducer` | PPTX parent + `format: "md"` → the deck as Markdown. `operationName()` = `presentation.extract`. Its PPTX MIME is what puts `.pptx` on the upload allowlist. |
| `MediaMimeRefinerInterface` | `Refiners\OfficeMimeRefiner` | Upgrades a coarse `application/zip` sniff to the DOCX or the PPTX MIME, but only when the archive actually contains `word/document.xml` or `ppt/presentation.xml` — so xlsx and epub are not mislabelled. Runs before the upload allowlist check. |

The refiner exists for a concrete reason: `MimeSniffer` feeds 4096 bytes to
`finfo_buffer`, which on most current libmagic builds reports a DOCX as its
proper OOXML type — but on older builds the same 4096 bytes come back
`application/zip`, and the upload is then rejected outright. `PK\x03\x04`
matches every zip, so the signature table cannot fix it; only opening the
archive can.

## Themes in the media library

A **theme** is a top-level folder in the media library. Its name is the
theme's name, and its files are the library's own:

```
Corporate/                     ← the folder; "Corporate" is the theme's name
  theme.yml                    ← the slides manifest (the superset)
  styles.yml                   ← the word half's slots
  layouts.yml                  ← the slides half's layout roles
  text-styles.yml              ← the slides half's named text styles
  template.pptx                ← the slides half's master (optional)
```

A document names one in its frontmatter — `theme: corporate` — and the folder's
UUID resolves as well, so a task can pin a theme by a value that survives a
rename. **A media theme shadows a built-in of the same name**, so the media
library's `corporate` is not the renderer shipped `corporate`.

The plugin reads the library through one class,
`Finder\MediaLibraryFinder`, which implements both upstream finder interfaces
(`MarkdownWord\Files\FileFinder` and `MarkdownPresentation\Files\FileFinder`)
and answers for **one principal**. A theme name is a folder name and never a
path, so `theme: ../other-tenant` names no folder of yours and resolves to
nothing.

**The Word half is served a projection of the manifest** — `name`,
`description` and `author` only. The slides manifest accepts colours, fonts
and a type scale, and the Word loader refuses a key it does not know, so one
folder cannot hold one manifest unchanged for both halves. The projection is
the one lossy step in the whole arrangement, and it is stated here, in one
place, on purpose.

## The chain

Two calls on core's `media` tool. There is no `office_document` tool, by design.

```jsonc
// 1. store the Markdown
{ "action": "create_media", "content": "# Q3 Report\n\n…", "filename": "q3-report.md", "mime_type": "text/markdown" }
// → data.asset_id

// 2. render that asset to Word
{ "action": "create_derivative", "asset_id": "<asset_id from step 1>", "format": "docx" }
// → data.derivative_id, data.asset_url
```

`create_media` is **not** idempotent — every call inserts a new row, so the
returned `asset_id` is the handle for everything downstream and a retry leaves a
duplicate in the user's library. `create_derivative` **is** idempotent on
`(parent_id, format, producer_plugin, producer_operation)`, so re-rendering the
same parent in the same format is a safe retry and returns the same
`derivative_id`. It **does** rewrite the stored bytes: the existing derivative
is re-rendered in place.

The producer options are `{"plain": true}` (no decoration) and
`{"theme": "<name>"}` (a theme that outranks the document's own frontmatter).
`plain` is not purely cosmetic — see the round-trip note below.

Document assets render in chat as a download card rather than a bare markdown
link, emitted by core's `MediaEmbed::fileCard()` and styled by core's CSS. The
filename and the `href` are the entire contract.

## Reading a `.docx` back

No tool call. The archive extracts a `.docx` into an `md` derivative — a real
`media_assets` row, reachable and re-derivable like any other — and the chat
inlines its text into the agent's context. "What does this contract say" is
answered from text the model can already see.

Behind the scenes the reverse direction is tightened for a chat attachment
rather than a file server: `maxPartBytes` is lowered from the library's 256 MiB
to 64 MiB, `mediaDirectory` stays `null` so no images are ever written to disk,
and the upstream zip-bomb / style-loop caps (`maxEntries` 4096,
`maxStyleDepth` 32) are kept. A corrupt or hostile `.docx` does not fail the
upload — the extraction failure is caught, logged at warning, and the chat
degrades to the asset's metadata block.

## Round-trip limits

A `.docx` is a lower-fidelity form of the Markdown it came from. Five documented
losses, all silent: a table's header row returns **bold**; a fenced code block
returns **without its language**; a **one-line** fence returns as **inline
code**; a quote written as plain indentation returns as a **plain paragraph**;
and the **last line has no trailing newline**.

`options: {"plain": true}` triggers the quote loss as a side effect, because
dropping the renderer's quote *style* is exactly the case the reader cannot
recognise — and it also drops the code font, leaving fenced blocks as two bare
prose paragraphs with no fence at all. Nested bullet and ordered lists work at
any depth, and remote
images are never fetched (the library has no HTTP client), so they fall back to
their alt text.

A `.pptx` is a package of positioned boxes. Reading a deck back gives the
words, their order and the pictures; the type sizes, the colours and the
geometry live in the theme and the master, and neither travels.

The agent-facing version of all of this, with the practical rules, is
`skills/office-documents/SKILL.md` and `skills/office-presentations/SKILL.md`.
Read them before changing how documents are produced.

## Licensing

The plugin and the two upstream [`markdown-office`](https://github.com/markdown-office)
packages are all **MIT**. `phpoffice/phpword`, which does the actual Word
writing, is **LGPL-3.0** — a copyleft licence, dynamically linked through
Composer, and not relicensed by this plugin's MIT. Nothing here vendors its
source.

## Translations

This plugin ships a German catalogue at [`locales/de.json`](locales/de.json),
declared through `translationsPaths()` in `src/OfficePlugin.php`. The host merges
it under core's own catalogue, so a German install reads the plugin's operator
copy in German.

The **key is the English source string**, byte for byte — editing the English
in the source changes the key, so the old entry stops matching and the new
English has no entry. The gate is this repository's own
`tests/Unit/LocaleCatalogTest.php`: it fails on an empty translation, on a
translation identical to its key, and on a key the source no longer produces.

The catalogue holds **one** string: the `plugin.json` description. The plugin
ships no tool, no admin panel and no setting — the LLM drives both conversion
directions through core's own `media` tool — so that description is the only
operator copy it owns.

**Never translated** — these strings are read by the model, so the operator
sees exactly what the model sees:

- `#[Tool].description`
- `#[ToolOperation].description`
- `#[ToolParameter].description`
- every `#[ToolSetting].label` and its option labels, including the labels of
  settings that carry `exposeToLlm: true`

See the [plugin author guide → Translations](https://docs.spora-ai.com/develop/plugins/author-guide/translations).

## Bootstrap

```bash
composer install
composer test:parallel   # Pest
composer analyse         # PHPStan level 5
composer format          # PHP-CS-Fixer
```

The suite has no optional-extension skip: it needs all four extensions, so it
runs everywhere CI does. Binary fixtures are **built** with `MarkdownToWord`
rather than checked into the repository, the same approach as the Typst
plugin's `tests/Support/TemplateFactory.php`.

## Local development

In `spora-local`, add the plugin as a path repo:

```jsonc
// spora-local/composer.json
{
    "repositories": [
        { "type": "path", "url": "../spora-plugin-office", "options": { "symlink": true } }
    ],
    "require": {
        "spora-ai/spora-plugin-office": "@dev"
    }
}
```

Then `composer update spora-ai/spora-plugin-office`. Exercise the whole thing:
`composer dev` → ask the agent for a document → attach a `.docx` and ask a
question about it. The five registrations are added on every boot, so no cache
clear is needed between runs.

## Layout

```text
.
├── composer.json          # spora-ai/spora-plugin-office + the two markdown-office packages + the four ext-*
├── plugin.json            # manifest (class=Spora\Plugins\Office\OfficePlugin, slug=office, icon=file-text)
├── database/migrations/
│   └── office_000001_rename_producer_identity.php   # the rows the rename touched
├── locales/
│   └── de.json            # German catalogue, keyed by the English source string
├── src/
│   ├── OfficePlugin.php                       # entry point — binds DI, registers both discoveries
│   ├── Finder/
│   │   ├── LibraryHalf.php                    # which half of markdown-office a finder serves
│   │   ├── MediaLibraryFinder.php             # both upstream FileFinder interfaces, one principal
│   │   ├── MediaLibrarySource.php             # the seam the finder reads the library through
│   │   └── EloquentMediaLibrarySource.php     # that seam over core's tables
│   ├── Producers/
│   │   ├── MarkdownToOfficeProducer.php       # MediaDerivativeProducerInterface impl (md → docx/odt/rtf)
│   │   ├── OfficeToMarkdownProducer.php       # MediaDerivativeProducerInterface impl (docx → md)
│   │   ├── MarkdownToPresentationProducer.php # MediaDerivativeProducerInterface impl (md → pptx)
│   │   └── PresentationToMarkdownProducer.php # MediaDerivativeProducerInterface impl (pptx → md)
│   ├── Refiners/
│   │   └── OfficeMimeRefiner.php              # MediaMimeRefinerInterface impl (application/zip → DOCX/PPTX)
│   ├── Services/
│   │   ├── OfficeConversion.php               # shared size caps, deprecation suppressor, exception map
│   │   └── OfficeSourceBytes.php              # shared parent-asset read (data_url / local), AssetStorage map
│   └── Exceptions/
│       ├── OfficeDocumentException.php
│       ├── OfficeInvalidArgumentException.php
│       └── OfficeRuntimeException.php
├── skills/
│   ├── office-documents/                     # the chain, the themes, the five round-trip losses
│   └── office-presentations/                 # the deck dialect, the themes, the deck's limits
├── tests/
│   ├── Pest.php                             # resets all three discovery registries in afterEach
│   ├── bootstrap.php
│   ├── Unit/
│   └── Feature/
└── .github/workflows/ci.yml                 # pest + phpstan + cs-fixer + skeleton boot + sonarcloud
```

## CI

Five jobs (the standard Spora-plugin CI, plus the skeleton boot):

- `test` — Pest on `ubuntu-latest`, PHP 8.4 + 8.5. PHP 8.4 also writes the
  coverage report the SonarCloud job ingests.
- `static-analysis` — PHPStan level 5 (memory limit 512M).
- `code-style` — `php-cs-fixer` dry-run.
- `integration` — boots the plugin against a fresh `spora-core` checkout to
  prove the manifest parses, the DI bindings resolve and the five registrations
  hook in.
- `sonarcloud` — SonarCloud scan, gated on `test`.

Every job installs **all four** extensions (`zip`, `dom`, `mbstring`, `gd`).
There is no `--ignore-platform-req` and no `continue-on-error` extension step —
unlike the Typst plugin, which skips `ext-typst`, the whole suite here depends on
them, and a silently missing `gd` would fail deep inside a test with a message
that says nothing about the environment.

The SonarCloud project this repo analyses into is
`spora-ai_spora-plugin-word`; it is not renamed with the package, because the
project key is bound to the GitHub repository and the maintainer performs that
rename. The header of `sonar-project.properties` says the same thing next to
the key itself.

## Publishing

1. `git tag v0.3.0 && git push --tags`.
2. Configure Packagist to auto-pull from the GitHub repository.

The runtime reads the version from the git tag via
`Composer\InstalledVersions::getPrettyVersion()`.

## Authoring guidelines

Framework-level conventions — which classes are plugin-stable, what's
framework-internal, schema versioning, deprecation policy — live in the
[Spora docs → Plugin system](https://docs.spora-ai.com/reference/concepts/plugins-system).
Do not import framework-internal driver classes from a plugin.
