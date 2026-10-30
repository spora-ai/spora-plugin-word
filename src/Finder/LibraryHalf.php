<?php

declare(strict_types=1);

namespace Spora\Plugins\Office\Finder;

/**
 * Which half of `markdown-office` a finder serves.
 *
 * The two halves read the same manifest file and disagree about what it may
 * carry, so a finder has to know which one it is answering for.
 */
enum LibraryHalf
{
    /** The Word half: Markdown in, `.docx` / `.odt` / `.rtf` out. */
    case Word;

    /** The slides half: Markdown in, `.pptx` out. */
    case Slides;
}
