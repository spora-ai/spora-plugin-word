---
name: office-documents
description: "Produce and read Office documents through the Media Archive. Trigger on any of: 'make this a Word document', 'write this up as a .docx', 'turn this Markdown into a document', 'attach a Word file', 'read this Word document', 'what does this contract say', or any request naming a .docx, .odt or .rtf file. Covers the two-call create_media → create_derivative chain, the themes the media library holds, what survives the round trip, and the limits on images."
license: MIT
compatibility: "Designed for Spora agents with the `media` tool enabled."
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: media
---

# Office documents

This plugin ships **no tool of its own**. It registers DOCX *producers* with
the Media Archive — one that renders Markdown into a document derivative, one
that extracts a document into an `md` derivative — so a Word document becomes a
format the existing `media` tool can write and read, and an `md` extract is
itself a legal parent for a render. Producing one costs **two `media` calls**.
Reading one the user attached costs **none at all** — the extracted text is
already in your context.

Worked end-to-end examples: `skill(action: "read", name: "office-documents", filename: "examples.md")`.

## The chain

Markdown in, `.docx` out, in two calls on the `media` tool. There is no third
step and no convert operation.

```jsonc
// 1. store the markdown as a media asset
{
  "action": "create_media",
  "content": "# Q3 Report\n\nRevenue grew 12% quarter over quarter…",
  "filename": "q3-report.md",
  "mime_type": "text/markdown"
}
// → data.asset_id          e.g. "0b8a…f31c"

// 2. render that same asset to Word
{
  "action": "create_derivative",
  "asset_id": "0b8a…f31c",   // ← the id step 1 returned, verbatim
  "format": "docx"
}
// → data.derivative_id, data.asset_url
```

**`data.asset_id` from step 1 is the only input step 2 takes.** Copy it
character-for-character out of the response. Do not invent a UUID, do not
`search` for the file you just wrote, and do not call `create_media` again to
recover the id — it is not idempotent, and the second row is a duplicate the
user will see in their library. (Step 2 *is* idempotent; see
[Non-idempotency](#non-idempotency).)

What changes how you write the call:

- `filename` is what the user sees on the download card. Name the document the
  way the user would (`q3-report`), not `document.md` or `output.md`. The
  `.md` extension is appended for you when the hint implies one, so
  `q3-report` is the right thing to send and the card reads `q3-report.md`.
- `mime_type` is a **hint**, default `text/markdown`. The archive re-sniffs the
  bytes and stores what it sniffed, so `data.mime_type` on the response is the
  authoritative type — never report the type you asked for as though it had
  been confirmed. The bytes alone cannot distinguish Markdown from any other
  prose, so the `.md` extension is what lifts the stored type to
  `text/markdown`. Send the bare name and this all happens; there is no reason
  to spell the extension out yourself.
- `content` is capped at 1 MiB. Past that the call fails with both byte counts;
  split the document rather than truncating it.
- The producer options are `{"plain": true}` and `{"theme": "<name>"}`. Read
  [Themes](#themes) before you pass the second, and
  [What the round trip does not preserve](#what-the-round-trip-does-not-preserve)
  before you pass the first.
- `prompt` is optional provenance: the brief the document was written from. Not
  the document body.

## The download card

A document asset renders in chat as a **download card**, not a bare markdown
link:

```html
<div class="spora-file-card"><a class="spora-file-card__link" href="/api/v1/assets/<uuid>.docx"><span class="spora-file-card__name">q3-report.docx</span><span class="spora-file-card__meta">18.2 KB · application/vnd.openxmlformats-officedocument.wordprocessingml.document</span></a></div>
```

`create_media`, `create_derivative` and `get_media` all return one, because the
rule keys on the asset's type and not on which operation produced it — a `.docx`
looks the same however it came to exist. **Echo the block verbatim** into your
reply so the chat UI renders it. Do not rewrite the label, rename the file, or
hand-roll `[q3-report.docx](url)` in its place, and do not try to reproduce or
explain the internals: the filename and the `href` are the whole contract. The
`div`/`span` wrappers are CSS chrome, there is no icon element, and there is no
`aria-hidden` to add.

So the two-call chain above needs no third call to surface the file: step 2's
response already carries the card for the `.docx` it just rendered.

The `href` is the session-authenticated route, so a click forces a download; use
`media(action: "get_public_url")` when the user needs an external share link.

## Why there is no office tool

There is no `office_document` tool, no `word_document` tool, and no
`media(action: "convert")`. Do not go looking for one and do not synthesise a
call shape for it. The capability is a *producer* behind `create_derivative`,
which is exactly why a document is two calls and nothing else.

## Format identifiers

`docx`, `odt` and `rtf` are the formats this plugin renders, and the only
values to pass as `format`. Do not pass `word`, `doc`, `pdf`, or the full
`application/vnd.openxmlformats-officedocument.wordprocessingml.document` MIME
type.

| Format | What the user gets |
| --- | --- |
| `docx` | a Microsoft Word document — the default, and what to send unless there is a reason not to |
| `odt` | an OpenDocument text file, for LibreOffice and Google Docs |
| `rtf` | rich text, for a reader that takes nothing else |

The render producer accepts Markdown parents only — the `text/markdown` MIME or
the `md` / `markdown` extension, either of which is enough. A document
derivative's parent is therefore always a Markdown asset, never a PDF or an
image, and a document is never itself rendered into another document. (Reading
a `.docx` back is the *extract* producer's job, at `format: "md"`.)

`md` is a format identifier this plugin registers too, in the other direction.
Asking for `format: "md"` on a DOCX parent produces the extracted Markdown as a
derivative; asking on a Markdown parent produces a file, and fails. The two are
distinguished by the parent's type, not by the format name.

**There is no PDF output, and asking for one will fail.** `format: "pdf"`
returns "No derivative producer supports format pdf" unless some *other*
plugin registers one — Typst does, if it is installed, but it accepts Typst
sources rather than Markdown, so it will not pick up a Markdown parent
either. Do not offer a PDF of a document you authored here: the only routes
are a producer you do not control, or the user exporting from Word. Say so
plainly instead of retrying.

Ask the archive rather than guess when you need to know what exists:

```jsonc
{ "action": "list_derivatives", "asset_id": "0b8a…f31c" }   // what already exists for this parent
{ "action": "get_media",        "asset_id": "0b8a…f31c" }   // metadata plus a derivatives[] array
{ "action": "search", "mime_type": "application/" }          // the `document` bucket — includes every .docx
```

A format identifier is capped at 16 characters by the `media_derivatives.format`
column; `docx`, `odt` and `rtf` use four of them.

## Themes

A theme is the look a document is drawn in: its colours, its type scale, its
heading styles. A document names one in its **frontmatter**, at the very top of
the Markdown:

```markdown
---
theme: corporate
---

# Q3 Report
```

**The theme names are two lists, and the media library's wins.**

The ones that ship with the renderer, always available:

| Theme | The look |
| --- | --- |
| `default` | the plain look — the safe answer |
| `minimal` | less decoration than the default |
| `corporate` | the house style, for a formal document |
| `report` | a report: bigger headings, room for tables |

And the ones the media library holds. A theme is a **folder** in the media
library whose name is the theme's name; list them with

```jsonc
{ "action": "list_folders" }   // the media_folders tool: the principal's folders
```

**`list_folders` needs a core that carries the media-folders feature**
(migration `0097`). On a core without it there is no `media_folders` tool to
call and the media library holds no folders, so the built-in table above is
the whole list of themes.

A folder that holds a `theme.yml` is a theme. **A media theme shadows a
built-in of the same name** — a folder called `corporate` is *that* corporate
theme, not the one that ships with the renderer — so the list you fetched is
the list to choose from. The folder's id resolves as well, so `theme:` may
name either the folder's name or its id; the id does not change when the
operator renames the folder, so prefer it when the user has pinned a theme.

A theme name is a **folder name, not a path**. `theme: ../something` names no
folder of yours and the render fails saying so. Do not try to reach a theme
outside the folder the operator made.

**A theme named in the call outranks the frontmatter.** `options: {"theme":
"corporate"}` answers for the document: the frontmatter's `theme:` is not read
at all. Use it when the caller has asked for a specific look; use the
frontmatter when the document itself decides. A theme that does not exist fails
the render with a message naming it — that is not a reason to retry with
another name, it is a reason to list the folders again.

A theme changes the *look* only. Nothing about the text changes, and a document
in a theme carries no more information than the same document without one.

## What the round trip does not preserve

A `.docx` is a lower-fidelity form of the Markdown it came from. Word does not
record that a bold run was a table header rather than bold text, nor which word
followed a code fence, so a reader cannot recover either. Five losses, all of
them real, all of them silent:

| # | What you wrote | What comes back | Recoverable? |
| --- | --- | --- | --- |
| 1 | `\| A \| B \|` header row | `\| **A** \| **B** \|` — the header row returns bold | Library option, not exposed here |
| 2 | ` ```php ` … ` ``` ` | ` ``` ` … ` ``` ` — the fence survives, the language does not | No |
| 3 | a fence of **one** line | `` `$x = 1;` `` — inline code, not a block | No |
| 4 | a quote written as plain indentation | a plain paragraph | No |
| 5 | `"# Title\n"` | `"# Title"` — no trailing newline | Only via a `crlf` line ending, not used here |

Two corollaries that bite in practice, both verified against a real round trip:

- **Adjacent code fences merge.** A two-line fence immediately followed by a
  one-line fence comes back as a *single* block holding all three lines. Keep
  code blocks apart with a paragraph of prose if you need them to stay separate.
- **`options: {"plain": true}` triggers loss 4, and more.** `plain` drops the
  renderer's decoration — code colouring, quote style, table borders — and
  dropping the quote *style* is precisely the case the reader cannot recognise.
  With `plain`, a `>` quote comes back as a plain paragraph **and** a fenced
  block comes back as two bare prose paragraphs — no fence, and no code
  formatting either, since `plain` drops the code font along with everything
  else:

  ```text
  plain: true    "> A quoted line."   →  A quoted line.
                 "```php\n$x = 1;\n$y = 2;\n```"  →  $x = 1;   (blank)   $y = 2;
  default        "> A quoted line."   →  > A quoted line.
                 "```php\n$x = 1;\n$y = 2;\n```"  →  ```  $x = 1;  $y = 2;  ```
  ```

  Omit `plain` unless the user explicitly asks for an undecorated document.

What *does* survive: nested bullet and ordered lists at any depth, task lists
(`- [x]` / `- [ ]`), strikethrough, headings, links whose label carries
formatting, and table **alignment** (`:--`, `--:`, `:-:` are preserved — the
delimiter row is rewritten, the alignment is not). Expect cosmetic changes on
the way back: a cell beginning with `+` or `-` returns escaped (`|+18%|` comes
back as `|\+18%|`), and a bare URL is promoted to an explicit Markdown link —
`https://example.com/a/b` returns as
`[https://example.com/a/b](https://example.com/a/b)`, slashes untouched.

**The rule that follows: never use a round trip to edit a document.** The
transform is stable — a second and third round trip are byte-identical to the
first, so it does not compound — but that first pass is a visible diff, and it
throws away structure the user wrote. When the user asks you to change an
existing `.docx`, read it (see below), edit the **Markdown**, and render a new
document. The Markdown you stored in step 1 is the source of truth; keep it and
re-run step 2 rather than converting the DOCX forward.

## Images

**Remote images are never fetched.** The library has no HTTP client by design, so
`![Chart](https://example.com/chart.png)` is not downloaded — the alt text is
written into the document instead, and it comes back as the bare word `Chart`,
not as an image reference. Any path that is not a readable local file degrades
the same way, and in a server deployment nearly every path is not one.

If the document needs a picture, say what the picture should show in the
surrounding prose rather than relying on the image surviving. If the user needs
real images in the file, this pipeline is not the way — render a PDF, or tell
them the images are placeholders.

## Approval

Both `create_media` and `create_derivative` require operator approval per call.
Expect a prompt before either returns.

**Do not tell the user the document exists before the approval resolves.** No
`asset_id`, no download card, no "here's your file" — a refused or expired
approval means nothing was written. Wait for the response, then report what it
returned. When a call is queued behind an approval, carry the pending
description forward ("writing q3-report.md, then rendering it to Word") so the
user can approve the right thing in the right order.

## Reading a document back

**Reading a `.docx` the user attached needs no tool call.** The archive extracts
the upload into an `md` derivative, and that extracted text is inlined into your
context automatically. So "what does this contract say", "summarise the attached
document", and "what's the renewal date" are answering questions about text you
can already see — answer them. Reaching for `get_source` on an attachment you have
just been given is a wasted, approval-gated call.

The archive itself is reachable when you need more:

```jsonc
{ "action": "get_media", "asset_id": "<uuid>" }   // metadata, filename, mime, byte size, derivatives[]
{ "action": "get_source", "asset_id": "<uuid>" }  // extracted markdown preview for a binary mime
{ "action": "search", "mime_type": "application/" } // every document in scope
```

`get_source` on a binary mime returns the extracted Markdown from that `md`
derivative (truncated to 8 KB), which is the shape you can actually iterate on —
not the DOCX bytes. It is off by default and always approval-gated; do not call
it when the text is already in front of you.

A corrupt or hostile `.docx` degrades quietly: the upload still succeeds, the
extraction is skipped, and all you get is a metadata block. Say the text could
not be extracted rather than guessing at the contents.

## Non-idempotency

- `create_media` **duplicates on every call.** There is no natural key for
  authored text, so a retry after an ambiguous failure leaves two near-identical
  rows in the user's library. Never call it again to "check whether it worked"
  or to recover an id — keep the `asset_id` it returned and reuse it. If you
  genuinely do not know whether it landed, `search` for the filename and inspect
  the newest row before retrying.
- `create_derivative` **is idempotent** on
  `(parent_id, format, producer_plugin, producer_operation)`. Re-calling it with
  the same parent and format returns the existing `derivative_id`, so it is a
  safe retry and the URL the user bookmarked keeps working. **It does rewrite
  the stored bytes**, though: the existing derivative is re-rendered and
  replaced in place, and its `updated_at` moves. So a second call *does* pick up
  an edit to the parent, and it *does* change the file the user already
  downloaded — re-rendering the same parent in the same format is a way of
  updating a document, not a way of leaving it alone.

Producing a *different* document means a new `create_media` under a new
filename — and therefore a new parent id and a new derivative row, because
`parent_id` is part of the natural key. Two versions side by side is the
intended outcome; two rows sharing one filename is a mistake.

## Notes

`allowed-tools` in this skill's frontmatter is **spec-experimental and not
enforced** by Spora. It records intent — this skill expects the `media` tool —
and nothing more. Whether the `media` tool is available to you, and which of its
operations the operator has enabled, is decided by the agent's tool
configuration, not by this file. If a call fails with an unknown-action or
disabled-operation error, the fix is the tool configuration, not the skill.

Versions of the Markdown language the renderer supports, and the `plain` option's
other effects, are documented by the underlying library at
[markdown-office/markdown-word](https://github.com/markdown-office/markdown-word)
(MIT; `phpoffice/phpword` underneath is LGPL-3.0).
