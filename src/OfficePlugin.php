<?php

declare(strict_types=1);

namespace Spora\Plugins\Office;

use Psr\Container\ContainerInterface;
use Spora\Events\ContainerBuildingEvent;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Exceptions\PluginLoadFailedException;
use Spora\Plugins\Office\Finder\EloquentMediaLibrarySource;
use Spora\Plugins\Office\Finder\MediaLibrarySource;
use Spora\Plugins\Office\Producers\MarkdownToOfficeProducer;
use Spora\Plugins\Office\Producers\MarkdownToPresentationProducer;
use Spora\Plugins\Office\Producers\OfficeToMarkdownProducer;
use Spora\Plugins\Office\Producers\PresentationToMarkdownProducer;
use Spora\Plugins\Office\Refiners\OfficeMimeRefiner;
use Spora\Plugins\Office\Services\OfficeConversion;
use Spora\Plugins\Office\Services\OfficeSourceBytes;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaMimeRefinerDiscovery;
use Spora\Services\MediaArchive\MediaMimeRefinerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin entry point for `spora-plugin-office`.
 *
 * Contributes **no tools, no routes and no admin app**: the LLM drives both
 * directions through core's existing `media` tool (`create_media` →
 * `create_derivative` to write, an upload to read), so an `office_document`
 * tool would be a second spelling of what the registry already exposes.
 *
 * Architectural invariants:
 *
 *   - **The producers share a seam; the refiner is its own.** Registering
 *     one half of either pair leaves a silently half-working plugin.
 *
 *   - **Discovery calls run on every boot by design.** The registries are
 *     in-process statics that reset between tests, and `add()` no-ops on an
 *     already-registered FQCN, so repeated registration is harmless.
 */
final class OfficePlugin extends AbstractPlugin implements EventSubscriberInterface
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
     * @throws PluginLoadFailedException when the host's spora-core predates the
     *         refiner seam. Without the guard the refiner registration is a
     *         fatal on the missing class, which
     *         `PluginLoader::dispatchWithTolerance()` catches and logs —
     *         leaving the refiner silently never running, which on an old
     *         libmagic is the difference between a DOCX upload working and a
     *         415.
     */
    public function onContainerBuilding(ContainerBuildingEvent $event): void
    {
        if (!interface_exists(MediaMimeRefinerInterface::class)) {
            throw new PluginLoadFailedException(
                'spora-plugin-office requires a spora-core that ships '
                . 'Spora\\Services\\MediaArchive\\MediaMimeRefinerInterface. Either upgrade the '
                . 'host or disable the plugin: on a core without the refiner seam, DOCX uploads '
                . 'are rejected on hosts whose libmagic reports a .docx as application/zip. Note '
                . 'that PluginLoader swallows listener exceptions, so the failure would otherwise '
                . 'be a log line and a half-working plugin.',
            );
        }

        $event->builder()->addDefinitions([
            // The one seam the finder reads the media library through. A plugin
            // cannot widen it: the finder asks for the principal's own rows and
            // for nothing else.
            MediaLibrarySource::class => \DI\factory(
                static fn(ContainerInterface $c): MediaLibrarySource => new EloquentMediaLibrarySource(
                    $c->get(OfficeSourceBytes::class),
                ),
            ),
            OfficeConversion::class         => \DI\autowire(),
            OfficeSourceBytes::class        => \DI\autowire(),
            MarkdownToOfficeProducer::class => \DI\autowire(),
            OfficeToMarkdownProducer::class => \DI\autowire(),
            MarkdownToPresentationProducer::class => \DI\autowire(),
            PresentationToMarkdownProducer::class => \DI\autowire(),
            OfficeMimeRefiner::class        => \DI\autowire(),
        ]);

        MediaDerivativeProducerDiscovery::add(MarkdownToOfficeProducer::class);
        MediaDerivativeProducerDiscovery::add(OfficeToMarkdownProducer::class);
        MediaDerivativeProducerDiscovery::add(MarkdownToPresentationProducer::class);
        MediaDerivativeProducerDiscovery::add(PresentationToMarkdownProducer::class);
        MediaMimeRefinerDiscovery::add(OfficeMimeRefiner::class);
    }

    public function getName(): string
    {
        return 'Office Documents';
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

    /**
     * The plugin's `locales/` directory — one `<lang>.json` per language.
     *
     * @return string[]
     */
    public function translationsPaths(): array
    {
        return [
            __DIR__ . '/../locales',
        ];
    }
}
