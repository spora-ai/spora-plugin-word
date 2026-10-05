<?php

declare(strict_types=1);

namespace Spora\Plugins\Word;

use Spora\Events\ContainerBuildingEvent;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Exceptions\PluginLoadFailedException;
use Spora\Plugins\Word\Producers\DocxToMarkdownProducer;
use Spora\Plugins\Word\Producers\MarkdownToDocxProducer;
use Spora\Plugins\Word\Refiners\WordDocxMimeRefiner;
use Spora\Plugins\Word\Services\WordConversion;
use Spora\Plugins\Word\Services\WordSourceBytes;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaMimeRefinerDiscovery;
use Spora\Services\MediaArchive\MediaMimeRefinerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin entry point for `spora-plugin-word`.
 *
 * Contributes three media-archive registrations and the
 * `skills/word-documents/` skill. It contributes **no tools, no routes and no
 * admin app**: the LLM drives both directions through core's existing `media`
 * tool (`create_media` → `create_derivative` to write, an upload to read), so
 * a `word_document` tool would be a second spelling of what the registry
 * already exposes.
 *
 * Architectural invariants:
 *
 *   - **Two of the three registrations share a seam, and the third does
 *     not.** Both producers mint derivatives — the extract half the chat
 *     reads, the render half the user downloads — so they are registered with
 *     the same discovery call and an `md` extract is a legal `docx` parent
 *     because of what they declare, not because of any extra wiring. The
 *     refiner is the separate seam: it runs *before* the upload allowlist so
 *     a DOCX on an old libmagic is typed correctly before anything rejects
 *     it. Registering the producers without the refiner, or the refiner
 *     without the producers, leaves a silently half-working plugin rather
 *     than an error.
 *
 *   - **Discovery calls run on every boot by design.** The registries are
 *     in-process statics that reset between tests, and `add()` no-ops on an
 *     already-registered FQCN, so repeated registration is harmless.
 */
final class WordPlugin extends AbstractPlugin implements EventSubscriberInterface
{
    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ContainerBuildingEvent::class => 'onContainerBuilding',
        ];
    }

    /**
     * Bind the plugin's five classes and register all three media-archive
     * contributions.
     *
     * @throws PluginLoadFailedException when the host's spora-core predates the
     *         refiner seam. Without it the third registration below is a fatal
     *         on the missing class, which
     *         `PluginLoader::dispatchWithTolerance()` catches and logs — leaving
     *         the refiner silently never running, which on an old libmagic is
     *         the difference between a DOCX upload working and a 415.
     */
    public function onContainerBuilding(ContainerBuildingEvent $event): void
    {
        if (!interface_exists(MediaMimeRefinerInterface::class)) {
            throw new PluginLoadFailedException(
                'spora-plugin-word requires a spora-core that ships '
                . 'Spora\\Services\\MediaArchive\\MediaMimeRefinerInterface. Either upgrade the '
                . 'host or disable the plugin: on a core without the refiner seam, DOCX uploads '
                . 'are rejected on hosts whose libmagic reports a .docx as application/zip. Note '
                . 'that PluginLoader swallows listener exceptions, so the failure would otherwise '
                . 'be a log line and a half-working plugin.',
            );
        }

        $event->builder()->addDefinitions([
            WordConversion::class         => \DI\autowire(),
            WordSourceBytes::class        => \DI\autowire(),
            MarkdownToDocxProducer::class => \DI\autowire(),
            DocxToMarkdownProducer::class => \DI\autowire(),
            WordDocxMimeRefiner::class    => \DI\autowire(),
        ]);

        MediaDerivativeProducerDiscovery::add(MarkdownToDocxProducer::class);
        MediaDerivativeProducerDiscovery::add(DocxToMarkdownProducer::class);
        MediaMimeRefinerDiscovery::add(WordDocxMimeRefiner::class);
    }

    public function getName(): string
    {
        return 'Word Documents';
    }

    /**
     * @return string[]
     */
    public function skillPaths(): array
    {
        return [
            __DIR__ . '/../skills',
        ];
    }
}
