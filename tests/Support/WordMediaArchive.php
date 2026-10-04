<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Tests\Support;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Core\SecurityManagerInterface;
use Spora\Models\MediaAsset;
use Spora\Plugins\Word\Converters\DocxToMarkdownConverter;
use Spora\Plugins\Word\Producers\MarkdownToDocxProducer;
use Spora\Plugins\Word\Services\WordConversion;
use Spora\Services\AssetStore;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveIngestPipeline;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaConverterRegistry;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\MediaArchive\MediaIngestDecoder;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Wires the media-archive object graph this plugin plugs into.
 *
 * Everything here is built by a **real** `DI\Container` from definitions
 * equivalent to core's `ContainerDefinitions`, not by hand-assembled mocks.
 * Two contracts only hold when the graph is genuinely resolved rather than
 * stubbed: `MediaDerivativeService::findProducer()` instantiates each
 * registered producer *through the container* (which is why a plugin
 * producer may take constructor arguments at all), and
 * `MediaConverterRegistry` snapshots `MediaConverterDiscovery` at
 * construction time, so a registry built before a test registers its
 * converter can never see it.
 *
 * A fresh container per call rather than a shared static: the registries
 * `Pest.php` resets in `afterEach` are process-global, and a container
 * cached across tests would keep serving a `MediaConverterRegistry` built
 * from a previous test's registrations.
 */
final class WordMediaArchive
{
    /**
     * Payload ceiling above which `AutoAssetStore` routes to disk. Set
     * above every fixture the suite builds so the whole chain stays in the
     * `data_url` mode the ingest pipeline's `writePayloadToAsset()` and the
     * derivative's payload column are the only readers of — a `local`
     * derivative would hide its own bytes behind the filesystem.
     */
    private const DB_MODE_CEILING = 8 * 1024 * 1024;

    /**
     * @param LoggerInterface|null $logger a recorder, so a test can read the
     *        warnings the best-effort conversion pipeline emits instead of
     *        swallowing. The whole graph shares it — the ingest pipeline is
     *        the only component in these tests that logs.
     */
    public static function container(?LoggerInterface $logger = null): ContainerInterface
    {
        // No `SPORA_STORAGE_DIR` override: `Paths` resolves `storage()`
        // under the base path it is given, so one temp root per container
        // keeps local-mode writes isolated without mutating process env
        // that other tests in this worker read.
        $storageRoot = sys_get_temp_dir() . '/spora-word-archive-' . bin2hex(random_bytes(6));

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            Paths::class => \DI\factory(static fn(): Paths => new Paths($storageRoot)),
            SecurityManagerInterface::class => \DI\factory(
                // A throwaway key: `LocalAssetStore` only HMACs legacy
                // token filenames with it, and nothing in these tests
                // resolves one.
                static fn(): SecurityManagerInterface => new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            ),
            LoggerInterface::class => \DI\factory(static fn(): LoggerInterface => $logger ?? new NullLogger()),

            // `ffprobe` stays off and the fetcher is a mock: the URL branch
            // is not what these tests exercise, and an accidental fetch
            // would make the suite depend on the network.
            MetadataExtractor::class => \DI\factory(
                static fn(ContainerInterface $c): MetadataExtractor => new MetadataExtractor($c->get(LoggerInterface::class)),
            ),
            MediaArchiveUrlResolver::class => \DI\factory(static fn(ContainerInterface $c): MediaArchiveUrlResolver => new MediaArchiveUrlResolver(
                new RemoteMediaFetcher(new MockHttpClient([]), $c->get(LoggerInterface::class)),
                new MimeSniffer(),
                $c->get(LoggerInterface::class),
            )),
            AssetStore::class => \DI\factory(static fn(ContainerInterface $c): AssetStore => new AutoAssetStore(
                $c->get(DatabaseAssetStore::class),
                $c->get(LocalAssetStore::class),
                self::DB_MODE_CEILING,
            )),

            // Reads the discovery list once, at construction, so it must be
            // built lazily and after the test has registered its converter.
            MediaConverterRegistry::class => \DI\factory(
                static fn(ContainerInterface $c): MediaConverterRegistry => new MediaConverterRegistry($c),
            ),
            MediaArchiveIngestPipeline::class => \DI\factory(
                static fn(ContainerInterface $c): MediaArchiveIngestPipeline => new MediaArchiveIngestPipeline(
                    new MediaIngestDecoder(),
                    $c->get(MediaArchiveUrlResolver::class),
                    new MimeSniffer(),
                    $c->get(MetadataExtractor::class),
                    $c->get(AssetStore::class),
                    $c->get(MediaConverterRegistry::class),
                    new PrincipalService(new PrincipalResolver()),
                    $c->get(LoggerInterface::class),
                ),
            ),
            MediaArchiveService::class => \DI\factory(
                static fn(ContainerInterface $c): MediaArchiveService => new MediaArchiveService(
                    $c->get(MediaArchiveIngestPipeline::class),
                ),
            ),
            MediaDerivativeService::class => \DI\factory(
                static fn(ContainerInterface $c): MediaDerivativeService => new MediaDerivativeService(
                    $c->get(AssetStore::class),
                    new PrincipalService(new PrincipalResolver()),
                    // The container itself: producer resolution goes through
                    // it, which is the whole point of the real-DI graph.
                    $c,
                ),
            ),
        ]);

        return $builder->build();
    }

    public static function archiveService(ContainerInterface $container): MediaArchiveService
    {
        return self::resolve($container, MediaArchiveService::class);
    }

    public static function derivativeService(ContainerInterface $container): MediaDerivativeService
    {
        return self::resolve($container, MediaDerivativeService::class);
    }

    public static function producer(ContainerInterface $container): MarkdownToDocxProducer
    {
        return self::resolve($container, MarkdownToDocxProducer::class);
    }

    /**
     * The registry over whatever is in `MediaConverterDiscovery` *now*. It
     * snapshots the list once at construction, so a test that registers a
     * converter afterwards has to ask for a fresh container.
     */
    public static function converterRegistry(ContainerInterface $container): MediaConverterRegistry
    {
        return self::resolve($container, MediaConverterRegistry::class);
    }

    public static function converter(ContainerInterface $container): DocxToMarkdownConverter
    {
        return self::resolve($container, DocxToMarkdownConverter::class);
    }

    /**
     * The `create_media` half of the chain: store Markdown as a media asset
     * through the real ingest pipeline, so the byte path re-sniffs and
     * persists exactly as it would for an agent's tool call.
     */
    public static function ingestMarkdown(
        ContainerInterface $container,
        string $markdown = DocxFixtures::SAMPLE_MARKDOWN,
        string $filename = 'report.md',
    ): MediaAsset {
        return self::archiveService($container)->ingest(new MediaIngestRequest(
            bytes: $markdown,
            mime: 'text/markdown',
            filename: $filename,
            pluginSlug: 'spora-core',
            toolName: 'create_media',
            uploadSource: 'tool',
        ));
    }

    /**
     * The chat-input half: a user drops a `.docx` into the composer and the
     * ingest pipeline is expected to extract its text.
     */
    public static function ingestDocxUpload(
        ContainerInterface $container,
        string $bytes,
        string $filename = 'report.docx',
    ): MediaAsset {
        return self::archiveService($container)->ingest(new MediaIngestRequest(
            bytes: $bytes,
            mime: WordConversion::DOCX_MIME,
            filename: $filename,
            uploadSource: 'upload',
        ));
    }

    /**
     * An unsaved `data_url` row holding `$markdown` in its payload.
     *
     * Never persisted: the unit tests want the producer's byte-reading path
     * without a database round trip, and an 8 MiB payload is not something
     * to push through SQLite.
     */
    public static function dataUrlAsset(string $markdown, string $filename = 'report.md'): MediaAsset
    {
        $asset = new MediaAsset();

        $asset->id          = 'word-parent-' . bin2hex(random_bytes(8));
        $asset->mime_type   = 'text/markdown';
        $asset->media_type  = 'document';
        $asset->filename    = $filename;
        $asset->storage_mode = 'data_url';
        $asset->byte_size   = strlen($markdown);
        $asset->payload     = $markdown;

        return $asset;
    }

    /**
     * An unsaved row in the third storage mode — one that holds a URL and
     * no bytes at all.
     */
    public static function externalAsset(string $filename = 'report.md'): MediaAsset
    {
        $asset = self::dataUrlAsset('', $filename);

        $asset->storage_mode = 'external';
        $asset->source_url   = 'https://example.test/report.md';
        $asset->payload      = null;

        return $asset;
    }

    /**
     * A `local`-mode row with its bytes genuinely on disk, resolved through
     * the container's own {@see LocalAssetStore} so the token, the
     * `<token>.<ext>` filename and the `Paths` root all agree.
     *
     * `LocalAssetStore::readFromAsset()` picks the extension from the MIME
     * alone, and `text/markdown` is not in its table, so the file is named
     * `<token>.bin` — the same name production would resolve.
     *
     * @return array{asset: MediaAsset, store: LocalAssetStore}
     */
    public static function localAsset(
        ContainerInterface $container,
        string $markdown,
        string $filename = 'report.md',
    ): array {
        $store  = self::resolve($container, LocalAssetStore::class);
        $paths  = self::resolve($container, Paths::class);
        $token  = bin2hex(random_bytes(16));
        $assets = $paths->storage('assets');
        $onDisk = $assets . '/' . $token . '.bin';

        if (!is_dir($assets) && !mkdir($assets, 0755, true) && !is_dir($assets)) {
            throw new FixtureException('Could not create the local asset directory at ' . $assets);
        }
        if (file_put_contents($onDisk, $markdown) === false) {
            throw new FixtureException('Could not write the local asset file at ' . $onDisk);
        }

        $asset = self::dataUrlAsset($markdown, $filename);

        $asset->storage_mode = 'local';
        $asset->asset_token  = $token;
        $asset->payload      = null;

        return ['asset' => $asset, 'store' => $store];
    }

    /**
     * @template T of object
     *
     * @param  class-string<T> $class
     * @return T
     */
    private static function resolve(ContainerInterface $container, string $class): object
    {
        $instance = $container->get($class);
        assert($instance instanceof $class);

        return $instance;
    }
}
