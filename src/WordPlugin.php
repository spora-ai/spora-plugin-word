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
 *   - **The two producers share a seam; the refiner is its own.** Registering
 *     one half of either pair leaves a silently half-working plugin.
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
