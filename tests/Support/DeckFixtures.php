<?php

declare(strict_types=1);

namespace Spora\Plugins\Office\Tests\Support;

use ZipArchive;

/**
 * Builds the binary fixtures the deck tests need at run time.
 *
 * The same approach {@see DocxFixtures} takes for `.docx`: a deck is rendered
 * with the library the plugin calls, so the fixture is a real deck by
 * construction rather than a checked-in binary nobody can review.
 */
final class DeckFixtures
{
    /**
     * The name the picture is filed under in the media library, and the name
     * the deck names it by.
     */
    public const PICTURE_NAME = 'logo.png';

    /**
     * The alt text the deck gives the picture. It is the text that must
     * **not** survive the read-back: the extraction writes no media
     * directory, so the library leaves the picture out of the extracted deck
     * altogether — no reference to it and no alt text standing in for it.
     */
    public const PICTURE_ALT = 'the house logo';

    /**
     * A 40×40 red PNG, base64-encoded.
     *
     * Written out rather than produced with GD, so the fixture does not
     * depend on an extension the render itself already needs.
     */
    public const PICTURE_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAACgAAAAoCAIAAAADnC86AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAA'
        . 'MElEQVRYhe3NAQ0AAAQAMATRP4pYYtjsL/Cc7rhQJ6tYLBaLxWKxWCwWi8VisfhpvDq0AVRMfwL6'
        . 'AAAAAElFTkSuQmCC';

    /**
     * A deck whose first slide shows a picture.
     */
    public const DECK_WITH_PICTURE = <<<'MARKDOWN'
        # Title slide

        ![the house logo](logo.png)
        MARKDOWN;

    /**
     * The bytes of the fixture picture.
     */
    public static function pictureBytes(): string
    {
        $bytes = base64_decode(self::PICTURE_PNG_BASE64, true);

        if ($bytes === false) {
            throw new FixtureException('The picture fixture is not valid base64.');
        }

        return $bytes;
    }

    /**
     * The names of the media parts a rendered deck carries.
     *
     * @return list<string>
     */
    public static function mediaParts(string $pptx): array
    {
        $path = tempnam(sys_get_temp_dir(), 'spora-deck-fixture-');

        if ($path === false) {
            throw new FixtureException('Could not stage a fixture file.');
        }

        try {
            if (file_put_contents($path, $pptx) === false) {
                throw new FixtureException('Could not write a fixture file to ' . $path);
            }

            $archive = new ZipArchive();
            if ($archive->open($path, ZipArchive::RDONLY) !== true) {
                throw new FixtureException('Could not open the fixture deck at ' . $path);
            }

            try {
                $parts = [];

                for ($index = 0; $index < $archive->numFiles; $index++) {
                    $name = (string) $archive->getNameIndex($index);

                    if (str_starts_with($name, 'ppt/media/')) {
                        $parts[] = $name;
                    }
                }

                return $parts;
            } finally {
                $archive->close();
            }
        } finally {
            @unlink($path);
        }
    }
}
