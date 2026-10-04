<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Core\SecurityManagerInterface;
use Spora\Events\ContainerBuildingEvent;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Exceptions\PluginLoadFailedException;
use Spora\Plugins\Word\Producers\DocxToMarkdownProducer;
use Spora\Plugins\Word\Producers\MarkdownToDocxProducer;
use Spora\Plugins\Word\Refiners\WordDocxMimeRefiner;
use Spora\Plugins\Word\Services\WordConversion;
use Spora\Plugins\Word\Services\WordSourceBytes;
use Spora\Plugins\Word\WordPlugin;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;
use Spora\Services\MediaArchive\MediaMimeRefinerDiscovery;
use Spora\Services\MediaArchive\MediaMimeRefinerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

it('subscribes to the container-building event with a public listener', function () {
    $events   = WordPlugin::getSubscribedEvents();
    $listener = $events[ContainerBuildingEvent::class];

    expect(class_implements(WordPlugin::class))->toContain(EventSubscriberInterface::class)
        ->and(get_parent_class(WordPlugin::class))->toBe(AbstractPlugin::class)
        ->and($events)->toBe([ContainerBuildingEvent::class => 'onContainerBuilding']);

    // `PluginLoader` dispatches as `$plugin->$listener($event)`, so the
    // mapped name has to resolve to a public, single-argument method or the
    // listener silently never runs.
    $method = new ReflectionMethod(WordPlugin::class, $listener);
    expect($method->isPublic())->toBeTrue()
        ->and($method->getNumberOfParameters())->toBe(1);
});

/**
 * The three registrations, and the shape that replaced the converter: both
 * halves of the round trip are producers now, so `md` extraction and `docx`
 * rendering share one discovery call and the refiner is the only other seam.
 * Registering the producers without the refiner, or the refiner without the
 * producers, leaves a silently half-working plugin rather than an error.
 */
it('registers both producers and the refiner on boot', function () {
    (new WordPlugin())->onContainerBuilding(new ContainerBuildingEvent(new ContainerBuilder()));

    expect(MediaDerivativeProducerDiscovery::all())
        ->toBe([MarkdownToDocxProducer::class, DocxToMarkdownProducer::class])
        ->and(MediaMimeRefinerDiscovery::all())->toBe([WordDocxMimeRefiner::class]);
});

/**
 * The registration half, pinned at the source level rather than through
 * `MediaConverterDiscovery::all()`.
 *
 * `class_exists()` would be the obvious check and it is the wrong one: the
 * plugin's classmap still carries a deleted path until `composer
 * dump-autoload` runs, so it raises a file-include warning instead of
 * answering. Asking the discovery registry for its list would work, but that
 * class is exactly what the core this PR merges after deletes — a test that
 * fatals on the host it is written for is worse than no test. Reading the
 * listener's own body is version-independent, and it is the same approach the
 * refiner-guard test below takes.
 */
it('never reaches for the converter discovery this plugin no longer uses', function () {
    $method = new ReflectionMethod(WordPlugin::class, 'onContainerBuilding');
    $source = implode("\n", array_slice(
        explode("\n", (string) file_get_contents((string) $method->getFileName())),
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1,
    ));

    expect(interface_exists(MediaDerivativeProducerInterface::class))->toBeTrue()
        ->and($source)->toContain('MediaDerivativeProducerDiscovery::add(')
        // Both directions on the one seam the plugin still declares, and no
        // mention of the `bytes -> string` contract at all.
        ->and(substr_count($source, 'MediaDerivativeProducerDiscovery::add('))->toBe(2)
        ->and($source)->not->toContain('MediaConverter');
});

it('binds its five classes so the container can autowire them', function () {
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
    (new WordPlugin())->onContainerBuilding(new ContainerBuildingEvent($builder));

    $container = $builder->build();

    expect($container->get(WordConversion::class))->toBeInstanceOf(WordConversion::class)
        ->and($container->get(WordSourceBytes::class))->toBeInstanceOf(WordSourceBytes::class)
        ->and($container->get(MarkdownToDocxProducer::class))->toBeInstanceOf(MarkdownToDocxProducer::class)
        ->and($container->get(DocxToMarkdownProducer::class))->toBeInstanceOf(DocxToMarkdownProducer::class)
        ->and($container->get(WordDocxMimeRefiner::class))->toBeInstanceOf(WordDocxMimeRefiner::class);
});

/**
 * Registering the same FQCN twice is a no-op, and the registries are
 * process-global, so the listener has to be safe to run on every boot.
 */
it('is idempotent across repeated boots', function () {
    $plugin = new WordPlugin();
    $event  = new ContainerBuildingEvent(new ContainerBuilder());

    $plugin->onContainerBuilding($event);
    $plugin->onContainerBuilding($event);

    expect(MediaDerivativeProducerDiscovery::all())
        ->toBe([MarkdownToDocxProducer::class, DocxToMarkdownProducer::class])
        ->and(MediaMimeRefinerDiscovery::all())->toBe([WordDocxMimeRefiner::class]);
});

it('contributes no tools, no admin app and no schema', function () {
    $plugin = new WordPlugin();

    // Decision: the plugin is pure capability. The LLM drives both
    // directions through core's existing `media` tool
    // (`create_media` → `create_derivative` to write, an upload to read), so
    // a `word_document` tool would be a second spelling of what the
    // registries already expose.
    expect($plugin->tools())->toBe([])
        ->and($plugin->apps())->toBe([])
        ->and($plugin->agentTemplatePaths())->toBe([])
        ->and($plugin->schemaVersion())->toBe(0)
        ->and($plugin->migrationsPath())->toBeNull()
        ->and($plugin->getName())->toBe('Word Documents');
});

it('ships the word-documents skill from a directory that exists', function () {
    $paths = (new WordPlugin())->skillPaths();

    expect($paths)->toHaveCount(1)
        ->and(is_dir($paths[0]))->toBeTrue()
        ->and(is_file($paths[0] . '/word-documents/SKILL.md'))->toBeTrue();

    // `SkillValidator` rejects a frontmatter `name` that differs from the
    // parent directory, so the two must be renamed together.
    $frontmatter = (string) file_get_contents($paths[0] . '/word-documents/SKILL.md');
    expect($frontmatter)->toContain('name: word-documents');
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
    $method = new ReflectionMethod(WordPlugin::class, 'onContainerBuilding');
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
