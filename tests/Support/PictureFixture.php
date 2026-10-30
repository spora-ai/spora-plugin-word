<?php

declare(strict_types=1);

namespace Spora\Plugins\Office\Tests\Support;

/**
 * A small picture for the fixtures that need one.
 *
 * Base64-encoded rather than produced with GD, so a fixture does not depend
 * on an extension the render itself already needs.
 */
final class PictureFixture
{
    /**
     * A 40×40 red PNG.
     */
    public const BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAACgAAAAoCAIAAAADnC86AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAA'
        . 'MElEQVRYhe3NAQ0AAAQAMATRP4pYYtjsL/Cc7rhQJ6tYLBaLxWKxWCwWi8VisfhpvDq0AVRMfwL6'
        . 'AAAAAElFTkSuQmCC';

    /**
     * The alt text the fixtures give the picture.
     */
    public const ALT = 'the house logo';

    /**
     * The bytes of the picture.
     */
    public static function bytes(): string
    {
        $bytes = base64_decode(self::BASE64, true);

        if ($bytes === false) {
            throw new FixtureException('The picture fixture is not valid base64.');
        }

        return $bytes;
    }

    /**
     * The picture, written into `$directory` under `$name`.
     *
     * The directory is made when it is not there, and it is the caller's to
     * remove: the picture is written where a render can reach it.
     */
    public static function writeInto(string $directory, string $name = 'logo.png'): string
    {
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new FixtureException('Could not create the fixture directory ' . $directory);
        }

        $path = $directory . '/' . $name;

        if (file_put_contents($path, self::bytes()) === false) {
            throw new FixtureException('Could not write the picture to ' . $path);
        }

        return $path;
    }
}
