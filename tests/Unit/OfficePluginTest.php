<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Core\SecurityManagerInterface;
use Spora\Events\ContainerBuildingEvent;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Exceptions\PluginLoadFailedException;
use Spora\Plugins\Office\Finder\EloquentMediaLibrarySource;
use Spora\Plugins\Office\Finder\MediaLibrarySource;
use Spora\Plugins\Office\OfficePlugin;
use Spora\Plugins\Office\Producers\MarkdownToOfficeProducer;
use Spora\Plugins\Office\Producers\MarkdownToPresentationProducer;
use Spora\Plugins\Office\Producers\OfficeToMarkdownProducer;
use Spora\Plugins\Office\Producers\PresentationToMarkdownProducer;
use Spora\Plugins\Office\Refiners\OfficeMimeRefiner;
use Spora\Plugins\Office\Services\OfficeConversion;
use Spora\Plugins\Office\Services\OfficeSourceBytes;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;
use Spora\Services\MediaArchive\MediaMimeRefinerDiscovery;
use Spora\Services\MediaArchive\MediaMimeRefinerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

it('subscribes to the container-building event with a public listener', function () {
    $events   = OfficePlugin::getSubscribedEvents();
    $listener = $events[ContainerBuildingEvent::class];

    expect(class_implements(OfficePlugin::class))->toContain(EventSubscriberInterface::class)
        ->and(get_parent_class(OfficePlugin::class))->toBe(AbstractPlugin::class)
        ->and($events)->toBe([ContainerBuildingEvent::class => 'onContainerBuilding']);

    // `PluginLoader` dispatches as `$plugin->$listener($event)`, so the
    // mapped name has to resolve to a public, single-argument method or the
    // listener silently never runs.
    $method = new ReflectionMethod(OfficePlugin::class, $listener);
    expect($method->isPublic())->toBeTrue()
        ->and($method->getNumberOfParameters())->toBe(1);
});

it('registers every producer and the refiner on boot', function () {
    (new OfficePlugin())->onContainerBuilding(new ContainerBuildingEvent(new ContainerBuilder()));

    expect(MediaDerivativeProducerDiscovery::all())->toBe([
        MarkdownToOfficeProducer::class,
        OfficeToMarkdownProducer::class,
        MarkdownToPresentationProducer::class,
        PresentationToMarkdownProducer::class,
    ])
        ->and(MediaMimeRefinerDiscovery::all())->toBe([OfficeMimeRefiner::class]);
});

/**
 * Pinned at the source level: `class_exists()` warns on a stale classmap entry, and
 * `MediaConverterDiscovery::all()` is the class core's cut deletes.
 */
it('never reaches for the converter discovery this plugin no longer uses', function () {
    $method = new ReflectionMethod(OfficePlugin::class, 'onContainerBuilding');
    $source = implode("\n", array_slice(
        explode("\n", (string) file_get_contents((string) $method->getFileName())),
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1,
    ));

    expect(interface_exists(MediaDerivativeProducerInterface::class))->toBeTrue()
        ->and($source)->toContain('MediaDerivativeProducerDiscovery::add(')
        ->and(substr_count($source, 'MediaDerivativeProducerDiscovery::add('))->toBe(4)
        ->and($source)->not->toContain('MediaConverter');
});

it('binds its classes so the container can autowire them', function () {
    $builder = new ContainerBuilder();
    // The two definitions core contributes around the producers'
    // `LocalAssetStore` collaborator. They are supplied here rather than
    // built in, so a failure below is the plugin's binding and not the
    // harness's.
    $builder->addDefinitions([
        Paths::class => \DI\factory(static fn(): Paths => new Paths(sys_get_temp_dir())),
        SecurityManagerInterface::class => \DI\factory(
            static fn(): SecurityManagerInterface => new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        ),
    ]);
    (new OfficePlugin())->onContainerBuilding(new ContainerBuildingEvent($builder));

    $container = $builder->build();

    expect($container->get(OfficeConversion::class))->toBeInstanceOf(OfficeConversion::class)
        ->and($container->get(OfficeSourceBytes::class))->toBeInstanceOf(OfficeSourceBytes::class)
        ->and($container->get(MediaLibrarySource::class))->toBeInstanceOf(EloquentMediaLibrarySource::class)
        ->and($container->get(MarkdownToOfficeProducer::class))->toBeInstanceOf(MarkdownToOfficeProducer::class)
        ->and($container->get(OfficeToMarkdownProducer::class))->toBeInstanceOf(OfficeToMarkdownProducer::class)
        ->and($container->get(MarkdownToPresentationProducer::class))->toBeInstanceOf(MarkdownToPresentationProducer::class)
        ->and($container->get(PresentationToMarkdownProducer::class))->toBeInstanceOf(PresentationToMarkdownProducer::class)
        ->and($container->get(OfficeMimeRefiner::class))->toBeInstanceOf(OfficeMimeRefiner::class);
});

/**
 * Registering the same FQCN twice is a no-op, and the registries are
 * process-global, so the listener has to be safe to run on every boot.
 */
it('is idempotent across repeated boots', function () {
    $plugin = new OfficePlugin();
    $event  = new ContainerBuildingEvent(new ContainerBuilder());

    $plugin->onContainerBuilding($event);
    $plugin->onContainerBuilding($event);

    expect(MediaDerivativeProducerDiscovery::all())->toBe([
        MarkdownToOfficeProducer::class,
        OfficeToMarkdownProducer::class,
        MarkdownToPresentationProducer::class,
        PresentationToMarkdownProducer::class,
    ])
        ->and(MediaMimeRefinerDiscovery::all())->toBe([OfficeMimeRefiner::class]);
});

it('contributes no tools and no admin app', function () {
    $plugin = new OfficePlugin();

    // Decision: the plugin is pure capability. The LLM drives both
    // directions through core's existing `media` tool
    // (`create_media` → `create_derivative` to write, an upload to read), so
    // an `office_document` tool would be a second spelling of what the
    // registries already expose.
    expect($plugin->tools())->toBe([])
        ->and($plugin->apps())->toBe([])
        ->and($plugin->agentTemplatePaths())->toBe([])
        ->and($plugin->getName())->toBe('Office Documents');
});

it('declares the migration that rewrites the rows the rename touched', function () {
    $plugin = new OfficePlugin();
    $path   = $plugin->migrationsPath();

    expect($plugin->schemaVersion())->toBe(1)
        ->and(is_dir($path))->toBeTrue()
        ->and(is_file($path . '/office_000001_rename_producer_identity.php'))->toBeTrue();

    // `DatabaseSchemaInstaller::validateMigrationFilenames()` refuses a
    // file that does not start with the plugin's slug and an underscore.
    foreach (glob($path . '/*.php') ?: [] as $file) {
        expect(basename($file, '.php'))->toStartWith('office_');
    }
});

it('ships its skills from a directory that exists', function () {
    $paths = (new OfficePlugin())->skillPaths();

    expect($paths)->toHaveCount(1)
        ->and(is_dir($paths[0]))->toBeTrue()
        ->and(is_file($paths[0] . '/office-documents/SKILL.md'))->toBeTrue()
        ->and(is_file($paths[0] . '/office-presentations/SKILL.md'))->toBeTrue();

    // `SkillValidator` rejects a frontmatter `name` that differs from the
    // parent directory, so the two must be renamed together.
    $documents = (string) file_get_contents($paths[0] . '/office-documents/SKILL.md');
    $decks     = (string) file_get_contents($paths[0] . '/office-presentations/SKILL.md');

    expect($documents)->toContain('name: office-documents')
        ->and($decks)->toContain('name: office-presentations');
});

/**
 * The load-time version floor.
 *
 * Observing the real throw needs a host core without
 * `MediaMimeRefinerInterface`, which this suite cannot uninstall, so the
 * test pins the guard's parts instead — the same approach
 * `spora-plugin-custom-skills` takes for its `SkillProviderInterface` guard.
 * That it matters is not hypothetical: `PluginLoader` catches listener
 * exceptions, so on an old core the refiner registration would be a log
 * line and a DOCX upload a 415 instead of a crash an operator would notice.
 */
it('guards the boot against a core without the refiner seam', function () {
    $method = new ReflectionMethod(OfficePlugin::class, 'onContainerBuilding');
    $source = implode("\n", array_slice(
        explode("\n", (string) file_get_contents((string) $method->getFileName())),
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1,
    ));

    expect(interface_exists(MediaMimeRefinerInterface::class))->toBeTrue()
        ->and(interface_exists('Spora\Services\MediaArchive\NotARealRefinerInterface'))->toBeFalse()
        ->and(class_exists(PluginLoadFailedException::class))->toBeTrue()
        // A `RuntimeException`, so a caller already catching that base keeps
        // working when the plugin refuses to load.
        ->and(get_parent_class(PluginLoadFailedException::class))->toBe(RuntimeException::class);

    // The guard, the throw, the interface it names, and the reason a silent
    // failure here would be the worst outcome.
    expect($source)->toContain('interface_exists(MediaMimeRefinerInterface::class)')
        ->and($source)->toContain('throw new PluginLoadFailedException(')
        ->and($source)->toContain('PluginLoader')
        ->and(str_replace('\\\\', '\\', $source))->toContain('MediaMimeRefinerInterface');
});
