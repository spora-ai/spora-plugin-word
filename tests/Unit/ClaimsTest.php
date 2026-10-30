<?php

declare(strict_types=1);

use Spora\Plugins\Office\OfficePlugin;

/**
 * The text of a skill and of the README.
 *
 * Both are read by a model or by an operator, so a claim in them is a
 * promise the plugin makes. The assertions below pin the claims a change in
 * behaviour would make false — the phrasing that has already been wrong once
 * must not come back.
 */
function officeSkillText(string $name): string
{
    $paths = (new OfficePlugin())->skillPaths();

    return (string) file_get_contents($paths[0] . '/' . $name . '/SKILL.md');
}

function officeReadmeText(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
}

it('does not claim that a deck read back carries its pictures', function () {
    $skill  = officeSkillText('office-presentations');
    $readme = officeReadmeText();

    // The reverse direction names no media directory, so the library leaves
    // the picture out of the extracted deck: no reference to a file, and no
    // alt text standing in for it.
    expect($skill)->not->toContain('the pictures — as a Markdown deck')
        ->and($skill)->not->toContain('a reference to a file the extraction wrote')
        ->and($skill)->toContain('no pictures')
        ->and($readme)->not->toContain('their order and the pictures')
        ->and($readme)->toContain('The pictures do not travel');
});

it('tells the operator that the old package has to be removed', function () {
    $readme = officeReadmeText();

    // Two plugins claiming the same source MIME and format split the
    // derivative's natural key, so the loser of the race mints a duplicate.
    expect($readme)->toContain('composer remove spora-ai/spora-plugin-word')
        ->and($readme)->toContain('The two cannot both be installed');
});

it('says the folder tool needs a core that carries the media-folders feature', function (string $name) {
    $skill = officeSkillText($name);

    // The tool and the table it reads arrive with core migration `0097`,
    // which no tagged core carries yet.
    expect($skill)->toContain('list_folders')
        ->and($skill)->toContain('media-folders feature')
        ->and($skill)->toContain('0097');
})->with(['office-documents', 'office-presentations']);

it('says media themes need the core that carries the folders', function () {
    $readme = officeReadmeText();

    expect($readme)->toContain('0097')
        ->and($readme)->toContain('v0.33.0');
});
