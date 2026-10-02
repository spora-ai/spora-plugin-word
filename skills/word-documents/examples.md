# Examples

Two worked end-to-end examples. Read with
`skill(action: "read", name: "word-documents", filename: "examples.md")`.

## Example 1 — a report with a table, Markdown → `.docx`

**User:** "Turn this into a Word document I can send to the board."

> # Q3 Report
>
> Revenue grew 12% quarter over quarter, driven by three EU renewals that
> closed six weeks earlier than planned. Two renewals slipped into Q4 on
> supplier-side delays rather than demand.
>
> - EU: 412,000 (+18%)
> - US: 288,000 (+4%)
> - Two renewals deferred to Q4
>
> | Region | Revenue | Change |
> | --- | --- | ---: |
> | EU | 412,000 | +18% |
> | US | 288,000 | +4% |
>
> Full detail in the appendix.

### Call 1 — store the Markdown

```jsonc
// the user's Markdown, verbatim, as the content
{
  "action": "create_media",
  "content": "# Q3 Report\n\nRevenue grew 12% quarter over quarter, driven by three EU renewals that closed six weeks earlier than planned. Two renewals slipped into Q4 on supplier-side delays rather than demand.\n\n- EU: 412,000 (+18%)\n- US: 288,000 (+4%)\n- Two renewals deferred to Q4\n\n| Region | Revenue | Change |\n| --- | --- | ---: |\n| EU | 412,000 | +18% |\n| US | 288,000 | +4% |\n\nFull detail in the appendix.",
  "filename": "q3-report.md",
  "mime_type": "text/markdown",
  "prompt": "Q3 revenue summary for the board, from the user's chat message"
}
```

Response `data`:

```jsonc
{
  "asset_id": "0b8a7f31c1e24d5e9a7c2f0b1d3e4a56",
  "asset_url": "/api/v1/assets/0b8a7f31c1e24d5e9a7c2f0b1d3e4a56.md",
  "filename": "q3-report.md",
  "mime_type": "text/markdown",
  "byte_size": 512
}
```

### Call 2 — render it to Word

`asset_id` is copied verbatim out of call 1. Nothing is re-derived, nothing is
searched for.

```jsonc
{
  "action": "create_derivative",
  "asset_id": "0b8a7f31c1e24d5e9a7c2f0b1d3e4a56",
  "format": "docx"
}
```

Response `data` (the keys that matter):

```jsonc
{
  "derivative_id": "1c4e9b02d7f84a6bb3e5c9d1a70f28be",
  "parent_id": "0b8a7f31c1e24d5e9a7c2f0b1d3e4a56",
  "format": "docx",
  "mime_type": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  "byte_size": 18562,
  "asset_url": "/api/v1/assets/1c4e9b02d7f84a6bb3e5c9d1a70f28be.docx"
}
```

### What the user sees

The user asked for a Word file, so the reply carries the download card and one
line of prose. Nothing is invented, and the card is echoed exactly as returned.

> Here's the Q3 report as a Word document:
>
> <div class="spora-file-card"><a class="spora-file-card__link" href="/api/v1/assets/1c4e9b02d7f84a6bb3e5c9d1a70f28be.docx"><span class="spora-file-card__name">q3-report.docx</span><span class="spora-file-card__meta">18.2 KB · application/vnd.openxmlformats-officedocument.wordprocessingml.document</span></a></div>
>
> The Markdown source is kept alongside it, so I can re-render an updated version
> without you re-pasting anything.

Two mistakes to notice, because they are the common ones:

- The reply does **not** contain `[q3-report.docx](…)`. A hand-rolled markdown
  link loses the card, and the card is what the user is used to.
- `create_media` was called **once**. The `asset_id` is reused for call 2, for
  any later `get_media`, and for a re-render after an edit. A second
  `create_media` is a duplicate asset in the user's library, and it would
  invalidate the URL already handed out.

## Example 2 — a `.docx` attached to the chat, answered with no tool call

**User** attaches `northwind-contract.docx` and asks: "what's the renewal date
and who's the counterparty?"

The archive's converter already turned the upload into Markdown during
ingestion, and that text is inlined into the model's context. **There is no
tool call in this exchange at all** — not `get_media`, not `get_source`.

```text
user: [attachment: northwind-contract.docx]
      What's the renewal date and who's the counterparty?
```

The answer is read straight out of the attached document's extracted text:

> The contract is with **Northwind Ltd** and renews on **14 March** —
> twelve months from the 14 March start date, auto-renewing for successive
> 12-month terms unless either party gives 60 days' notice.

Three things to get right here:

- **No `media` call, and no `create_media` / `create_derivative` round trip.**
  The text is already in context. A round trip would re-parse the document,
  lose the table header bolding and any code-fence language, and charge the
  operator an approval for nothing.
- **Quote the facts as they were written.** "14 March", not "March 14th" or
  "the 14th of March" — the user is checking a contract, and paraphrasing a
  date in a legal document is how a wrong answer gets believed.
- **Only reach for a tool when the text is genuinely not there.** A truncated
  extraction (the inline preview is capped), a table that came through garbled,
  or a document the user did not attach at all — those are the cases where
  `media(action: "get_source", asset_id: "<uuid>")` is the right move, and it
  costs an approval, so say why you need it.

## Example 3 — editing an existing document

**User:** "Take the contract I attached, change the renewal term to 24 months,
and give me the Word file back."

Do **not** convert the `.docx` forward and re-render. Read it, edit the
Markdown, and render a new document from that — the round trip is lossy
(the table header returns bold, code-fence languages are dropped), so editing
the DOCX means editing a lossy copy of the user's text.

```text
1. the attached document's extracted Markdown is already in context
2. edit the Markdown — the renewal clause, nothing else
3. media(action: "create_media", content: "<edited markdown>",
          filename: "northwind-contract-24-months.md",
          mime_type: "text/markdown", prompt: "Renewal term 12 → 24 months")
4. media(action: "create_derivative", asset_id: "<asset_id from step 3>", format: "docx")
```

The edited Markdown is a *new* asset under a *new* filename, so both versions
coexist and the original is untouched. The derivative is idempotent on
`(parent_id, format, producer_plugin, producer_operation)`, so if step 4's result was ever lost to an ambiguous
failure, re-running step 4 is the correct recovery.
