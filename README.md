# Spora Plugin: Word

Markdown ⇄ Microsoft Word (`.docx`) for Spora agents. Backed by
[`fabeat/markdown-word`](https://github.com/fabeat/markdown-word) and the
media-archive producer / refiner registries in `spora-core`.

The plugin ships **no LLM tool, no routes and no admin app**. It contributes
three registrations to core's Media Archive and one skill, so the existing
`media` tool gains `.docx` in both directions:

- **Write** — `media(action: "create_media")` stores the Markdown, then
  `media(action: "create_derivative", format: "docx")` renders it to a Word
  document the user downloads.
- **Read** — a `.docx` uploaded to a chat is extracted into a `md` derivative,
  and that text is inlined into the agent's context. No tool call involved.

## Requires four PHP extensions

This is the first Spora plugin with more than one extension requirement. **All
four are hard requirements of the upstream package** — the plugin does not
degrade without them, and unlike `ext-typst` there is no optional-extension
path and no subset of the suite that runs without them.

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
composer require spora-ai/spora-plugin-word --ignore-platform-req=ext-gd
# or, to bypass all four at once:
composer require spora-ai/spora-plugin-word --ignore-platform-req=ext-zip --ignore-platform-req=ext-dom --ignore-platform-req=ext-mbstring --ignore-platform-req=ext-gd
```

Prefer enabling the extension. The flag exists for the host where you cannot.

### spora-core version floor

The real requirement is the `Spora\Services\MediaArchive\MediaMimeRefinerInterface`
seam — the third registry below — which `composer.json` expresses as
`spora-ai/spora-core >=0.30.0`. Until core tags a release carrying that seam,
`composer.json` resolves spora-core through a `path` repository pointing at
`../spora-core`, with the version pinned in `options.versions` so the constraint
stays stable whichever branch is checked out. CI checks spora-core out and
symlinks it to the sibling path before installing; see the comment at the top
of `.github/workflows/ci.yml`. Both go away once core tags the release.

`WordPlugin::onContainerBuilding()` checks `interface_exists()` at boot and
throws `PluginLoadFailedException` naming the missing seam when the host's core
predates it, rather than registering two of the three contributions and leaving
a silently half-working plugin.

## What's in the box

- 1 skill (`word-documents`) — the two-call chain, the five documented
  round-trip losses, the image rules, the approval and idempotency rules.
- 3 media-archive registrations: two derivative producers and a MIME refiner
  (table below).
- 3 exception classes, mirroring the Typst plugin's shape.
- No tools, no routes, no admin app, **no frontend package** — the download card
  that renders a `.docx` in chat is core's `MediaEmbed::fileCard()`, so there is
  no `spora-plugin-word-frontend` to build or ship.

## The three registrations

`WordPlugin::onContainerBuilding()` calls `add()` on two separate registries.
Both producers share one seam — the round trip is two directions of the same
contract, so an `md` extract is a legal parent for a `docx` render — and the
refiner is the other. Registering only some of them leaves a plugin that
half-works rather than one that errors.

| Interface | Class | What it does |
| --- | --- | --- |
| `MediaDerivativeProducerInterface` | `Producers\MarkdownToDocxProducer` | Markdown parent + `format: "docx"` → DOCX bytes as a new `media_assets` row. `pluginSlug()` = `spora-plugin-word`, `operationName()` = `word.render` — both are part of the idempotency natural key. |
| `MediaDerivativeProducerInterface` | `Producers\DocxToMarkdownProducer` | DOCX parent + `format: "md"` → GFM Markdown as a new `media_assets` row. `operationName()` = `word.extract`, distinct from the render half so the two rows never collapse onto one key. This is the half the chat reads. **Its declared source formats are also what puts `.docx` on the upload allowlist** — `MediaAllowedTypesService` unions every registered producer's source MIME, and nothing else in this plugin contributes an upload type. That union reaches `allowedMimeTypes()` only from core's md-derivative cut onwards; on an older core the entry came from the converter registry, which that cut deletes along with this PR. **The two must land together** — separated, the plugin boots fine and every `.docx` upload is a 415 at the gate. |
| `MediaMimeRefinerInterface` | `Refiners\WordDocxMimeRefiner` | Upgrades a coarse `application/zip` sniff to the DOCX MIME, but only when the archive actually contains `word/document.xml` — so xlsx / pptx / epub are not mislabelled. Runs before the upload allowlist check. |

The refiner exists for a concrete reason: `MimeSniffer` feeds 4096 bytes to
`finfo_buffer`, which on most current libmagic builds reports a DOCX as its
proper OOXML type — but on older builds the same 4096 bytes come back
`application/zip`, and the upload is then rejected outright. `PK\x03\x04`
matches every zip, so the signature table cannot fix it; only opening the
archive can.

## The chain

Two calls on core's `media` tool. There is no `word_document` tool, by design.

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
`derivative_id`.

The one producer option is `options: {"plain": true}`, which renders without
decoration. It is not purely cosmetic — see the round-trip note below.

Document assets render in chat as a download card rather than a bare markdown
link, emitted by core's `MediaEmbed::fileCard()` and styled by core's CSS. The
filename and the `href` are the entire contract.

## Reading a `.docx` back

No tool call. The archive extracts a `.docx` into a `md` derivative — a real
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

The agent-facing version of all of this, with the practical rules, is
`skills/word-documents/SKILL.md`. Read it before changing how documents are
produced.

## Licensing

The plugin and the upstream [`fabeat/markdown-word`](https://github.com/fabeat/markdown-word)
are both **MIT**. `phpoffice/phpword`, which does the actual Word writing, is
**LGPL-3.0** — a copyleft licence, dynamically linked through Composer, and
not relicensed by this plugin's MIT. Nothing here vendors its source.

Note that `fabeat/markdown-word` is a personal-scope Composer package
(`fabeat/`, not `spora-ai/`). That is fine as a library dependency, but it means
a fork or a move to the org is a `composer.json` change here.

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
        { "type": "path", "url": "../spora-plugin-word", "options": { "symlink": true } }
    ],
    "require": {
        "spora-ai/spora-plugin-word": "@dev"
    }
}
```

Then `composer update spora-ai/spora-plugin-word`. Exercise the whole thing:
`composer dev` → ask the agent for a Word document → attach a `.docx` and ask a
question about it. The three registrations are added on every boot, so no cache
clear is needed between runs.

## Layout

```text
.
├── composer.json          # spora-ai/spora-plugin-word + fabeat/markdown-word + the four ext-*
├── plugin.json            # manifest (class=Spora\Plugins\Word\WordPlugin, slug=word, icon=file-text)
├── src/
│   ├── WordPlugin.php                       # entry point — binds DI, registers both discoveries
│   ├── Producers/
│   │   ├── MarkdownToDocxProducer.php       # MediaDerivativeProducerInterface impl (md → docx)
│   │   └── DocxToMarkdownProducer.php       # MediaDerivativeProducerInterface impl (docx → md)
│   ├── Refiners/
│   │   └── WordDocxMimeRefiner.php          # MediaMimeRefinerInterface impl (application/zip → DOCX)
│   ├── Services/
│   │   ├── WordConversion.php               # shared size caps, deprecation suppressor, exception map
│   │   └── WordSourceBytes.php              # shared parent-asset read (data_url / local), AssetStorage map
│   └── Exceptions/
│       ├── WordDocumentException.php
│       ├── WordInvalidArgumentException.php
│       └── WordRuntimeException.php
├── skills/word-documents/
│   ├── SKILL.md                             # the chain, the five round-trip losses, the limits
│   └── examples.md                          # worked report-with-a-table example, both directions
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
  prove the manifest parses, the DI bindings resolve and the three registrations
  hook in.
- `sonarcloud` — SonarCloud scan, gated on `test`.

Every job installs **all four** extensions (`zip`, `dom`, `mbstring`, `gd`).
There is no `--ignore-platform-req` and no `continue-on-error` extension step —
unlike the Typst plugin, which skips `ext-typst`, the whole suite here depends on
them, and a silently missing `gd` would fail deep inside a test with a message
that says nothing about the environment.

The SonarCloud project this repo analyses into is
`spora-ai_spora-plugin-word`; SonarCloud provisions it from the first CI
analysis, so there is nothing to create by hand. What *does* have to stay off
is SonarCloud's **Automatic analysis** (autoscan) for this repository. Autoscan
provisions a project of its own — its key is this one with a `2` appended, and
its name is the same `spora-plugin-word` — analyses the clone with
`sonar.sources=.` instead of `sonar-project.properties`, and answers the same
`?pullRequest=2` links. The result is two dashboards for one PR that disagree:
the CI one carries the coverage, the test execution and the quality gate,
while the autoscan one reports findings nobody acts on because it treats the
test suite as production code. If a `…2` twin appears in the org's project
list, switch autoscan off for the repo and delete the twin; the header of
`sonar-project.properties` says the same thing next to the key itself.

## Publishing

1. `git tag v0.1.0 && git push --tags`.
2. Configure Packagist to auto-pull from the GitHub repo.

The runtime reads the version from the git tag via
`Composer\InstalledVersions::getPrettyVersion()`.

## Authoring guidelines

Framework-level conventions — which classes are plugin-stable, what's
framework-internal, schema versioning, deprecation policy — live in the
[Spora docs → Plugin system](https://docs.spora-ai.com/reference/concepts/plugins-system).
Do not import framework-internal driver classes from a plugin.
