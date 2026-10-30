---
name: office-presentations
description: "Produce and read PowerPoint decks (.pptx) through the Media Archive. Trigger on any of: 'make this a slide deck', 'turn this into a presentation', 'build me a pitch deck', 'attach a PowerPoint', 'read this deck', 'what were the slides about', or any request naming a .pptx file. Covers the deck dialect, the themes the media library holds, and what a deck does and does not survive."
license: MIT
compatibility: "Designed for Spora agents with the `media` tool enabled."
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: media
---

# Office presentations

This plugin ships **no tool of its own**. It registers two PPTX *producers* with
the Media Archive — one that renders a Markdown deck into a `.pptx` derivative,
one that extracts a `.pptx` back into an `md` derivative — so a deck becomes a
format the existing `media` tool can write and read. Producing one costs **two
`media` calls**. Reading one the user attached costs **none at all** — the
extracted text is already in your context.

A deck is **not** a long document. It is a sequence of slides, and the Markdown
that describes one is a different dialect from the Markdown that describes a
report. Read [The dialect](#the-dialect) before you write the first deck.

## The chain

Markdown in, `.pptx` out, in two calls on the `media` tool.

```jsonc
// 1. store the markdown as a media asset
{
  "action": "create_media",
  "content": "# Q3 review\n\nRevenue grew **12%**.\n\n---\n\n# What changed\n\nThree things.",
  "filename": "q3-review.md",
  "mime_type": "text/markdown"
}
// → data.asset_id          e.g. "0b8a…f31c"

// 2. render that same asset to PowerPoint
{
  "action": "create_derivative",
  "asset_id": "0b8a…f31c",   // ← the id step 1 returned, verbatim
  "format": "pptx"
}
// → data.derivative_id, data.asset_url
```

**`data.asset_id` from step 1 is the only input step 2 takes.** Copy it
character-for-character. Do not invent a UUID, do not `search` for the file you
just wrote, and do not call `create_media` again to recover the id — it is not
idempotent, and the second row is a duplicate the user will see in their
library. (Step 2 *is* idempotent; see [Non-idempotency](#non-idempotency).)

- `filename` is what the user sees on the download card. Name the deck the way
  the user would (`q3-review`), not `deck.md`.
- `mime_type` is a **hint**, default `text/markdown`. The archive re-sniffs the
  bytes, so `data.mime_type` on the response is the authoritative type.
- `content` is capped at 1 MiB.
- The one producer option is `{"theme": "<name>"}` — see [Themes](#themes).
- `prompt` is optional provenance: the brief the deck was written from. Not the
  deck body.

The result is a **download card**, exactly as for a document: echo the `div`
the response carries, do not hand-roll a markdown link in its place. The `href`
is session-authenticated, so use `media(action: "get_public_url")` when the
user wants a share link.

## The dialect

A deck is Markdown. **Slides are separated by a line of three dashes**, and
each slide may carry its own frontmatter. The first slide's frontmatter is the
deck's.

```markdown
---
title: Q3 review
theme: corporate
---

# Q3 review

Revenue grew **12%** against a flat market.

---

# What changed

- Pricing
- Volume

::note
Say the number out loud.
::
```

- `---` on a line of its own **closes the slide being read**. A `---` followed
  by a block and another `---` is a frontmatter block for the next slide.
- A thematic break inside a slide is `***`, not `---`. There is no other way to
  write one.
- A `---` inside a fenced code block or an HTML comment does **not** split a
  slide.
- The **first heading of a slide is its title**. A `##` after it is ordinary
  content.
- `::note` … `::` is the speaker note, and it has to be the last thing on the
  slide.
- `::column` on a line of its own moves everything below it into the next
  column — the only way to have two columns.
- `layout:` names the slide's shape: `title`, `content`, `two-column`,
  `section`, `blank`, `image-text`, `image-title`, `image-slide`. It is the
  deck's choice, and the theme decides what each of those looks like.
- The keys a deck's frontmatter may carry are `title`, `name`, `level`,
  `layout`, `theme`, `author`, `description`, `template` and `templates-file`.
  **A key outside that list fails the render** — so do not invent one, and do
  not carry a document's frontmatter over into a deck.

The rules are the ones Slidev uses, so a dialect a model already writes is the
dialect worth writing.

## Themes

A theme is the look a deck is drawn in: its colours, its type scale, its
layouts. A deck names one in its **frontmatter**:

```markdown
---
theme: corporate
---
```

**The theme names are two lists, and the media library's wins.**

The ones that ship with the renderer, always available:

| Theme | The look |
| --- | --- |
| `default` | the plain look — the safe answer |
| `corporate-dark` | the house style on a dark page |
| `minimal` | less decoration than the default |
| `dense` | more on a slide: the look for a deck people read closely |
| `pitch` | a pitch deck: big type, room to breathe |

And the ones the media library holds. A theme is a **folder** in the media
library whose name is the theme's name; list them with

```jsonc
{ "action": "list_folders" }   // the media_folders tool: the principal's folders
```

A folder that holds a `theme.yml` is a theme. **A media theme shadows a
built-in of the same name**, so the list you fetched is the list to choose
from. The folder's id resolves as well, so `theme:` may name either the
folder's name or its id.

A theme name is a **folder name, not a path**. `theme: ../something` names no
folder of yours and the render fails saying so.

**A theme named in the call outranks the frontmatter.** `options: {"theme":
"corporate"}` answers for the deck: the frontmatter's `theme:` is not read. A
theme that does not exist fails the render with a message naming it.

## What a deck does and does not survive

A `.pptx` is a package of positioned boxes. A deck is text. **The text and the
structure travel; the design does not.**

Reading a deck back gives you the words, the order they were in, and the
pictures — as a Markdown deck you can render again in another design. What does
not travel is the type sizes, the colours and the geometry: those live in the
theme and the master, and neither is in the extracted Markdown.

**The rule that follows: never use a round trip to edit a deck.** Read the deck
back, edit the Markdown, and render a new one. The Markdown you stored in step
1 is the source of truth.

A picture in a deck comes back as a reference to a file the extraction wrote
beside the Markdown, not as the bytes inlined into it.

## Slides that are too full

A slide whose content does not fit is **clamped** to the room left and the
render carries on — nothing is lost silently in the file, but something is. The
report of what was clamped is on the renderer, not in the deck, so the practical
rule is the one a designer would give anyway: **one idea per slide**.

A slide that is too full is a slide the reader cannot read. Split it. A deck of
forty slides of dense prose is a document; render it as one with `format:
"docx"` instead.

## Limits

- **Slides are separated by `---`, so a `---` in your prose splits the deck.**
  A horizontal rule in a slide has to be `***`.
- **Remote images are never fetched.** The library has no HTTP client, so
  `![Chart](https://example.com/chart.png)` is not downloaded — the alt text
  stands in.
- **A deck may have at most 1000 slides.** No deck a person writes reaches it.
- **Only `pptx` is rendered.** There is no PDF, no `.odp` and no `.potx` output;
  asking for one fails with "No derivative producer supports format …".
- **`md` is a format identifier this plugin registers too**, in the other
  direction: asking for `format: "md"` on a PPTX parent produces the extracted
  deck as Markdown.

## Approval

Both `create_media` and `create_derivative` require operator approval per call.

**Do not tell the user the deck exists before the approval resolves.** No
`asset_id`, no download card, no "here's your file". Wait for the response,
then report what it returned.

## Reading a deck back

**Reading a `.pptx` the user attached needs no tool call.** The archive extracts
the upload into an `md` derivative and inlines that text into your context. So
"what were the slides about" and "summarise the attached deck" are answered
from text you can already see.

```jsonc
{ "action": "get_media", "asset_id": "<uuid>" }   // metadata, filename, mime, byte size, derivatives[]
{ "action": "get_source", "asset_id": "<uuid>" }  // extracted markdown preview for a binary mime
{ "action": "search", "mime_type": "application/" } // every document in scope
```

A corrupt or hostile `.pptx` degrades quietly: the upload still succeeds, the
extraction is skipped, and all you get is a metadata block. Say the text could
not be extracted rather than guessing at the contents.

## Non-idempotency

- `create_media` **duplicates on every call.** Keep the `asset_id` it returned
  and reuse it; never call it again to "check whether it worked".
- `create_derivative` **is idempotent** on
  `(parent_id, format, producer_plugin, producer_operation)`. Re-calling it with
  the same parent and format returns the existing `derivative_id`. **It does
  rewrite the stored bytes**, though: the existing derivative is re-rendered and
  replaced in place, and its `updated_at` moves — so a second call does pick up
  an edit to the parent, and does change the file the user already downloaded.

A *different* deck means a new `create_media` under a new filename, and
therefore a new parent id and a new derivative row.

## Notes

`allowed-tools` in this skill's frontmatter is **spec-experimental and not
enforced** by Spora. It records intent — this skill expects the `media` tool —
and nothing more.

The dialect, the directives and the themes are documented by the underlying
library at [markdown-office/markdown-presentation](https://github.com/markdown-office/markdown-presentation)
(MIT; `phpoffice/phppresentation` underneath is MIT).
