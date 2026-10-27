<?php
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Plank\Mediable\Helpers\File;
use Plank\Mediable\ImageManipulation;
use Plank\Mediable\ImageManipulator;
use Plank\Mediable\Media;
use Plank\Mediable\MediableServiceProvider;
use Plank\Mediable\MediaUploader;

final class InspectOnlySanitizer implements \Plank\Mediable\FileSanitizers\SanitizerInterface {
    public function isApplicable(string $mimeType, string $extension, string $aggregateType): bool { return true; }
    public function sanitize(\Psr\Http\Message\StreamInterface $source): ?\Psr\Http\Message\StreamInterface {
        $source->getContents();
        return null;
    }
}

final class BackportTest extends TestCase {
    private ComponentApplication $app;
    private string $root;
    private Migrator $migrator;
    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/mediable-proof-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/disk', 0777, true);
        mkdir($this->root . '/database/migrations', 0777, true);
        file_put_contents($this->root . '/sentinel.txt', 'outside-sentinel');
        $this->app = new ComponentApplication;
        $this->app->root = $this->root;
        Illuminate\Container\Container::setInstance($this->app);
        $this->app->instance('app', $this->app);
        $this->app->instance('config', new Repository([
            'filesystems' => ['default' => 'local', 'disks' => ['local' => ['driver' => 'local', 'root' => $this->root . '/disk', 'throw' => true]]],
            'mediable' => ['default_disk' => 'local', 'allowed_disks' => ['local']],
        ]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $events = new Dispatcher($this->app);
        $this->app->instance('events', $events);
        $db = new Capsule($this->app);
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setEventDispatcher($events);
        $db->bootEloquent();
        $this->app->instance('db', $db->getDatabaseManager());
        $this->app->bind('db.schema', fn() => $db->getConnection()->getSchemaBuilder());
        $fs = new FilesystemManager($this->app);
        $this->app->instance('filesystem', $fs);
        $this->app->instance(FilesystemManager::class, $fs);
        $repository = new DatabaseMigrationRepository($db->getDatabaseManager(), 'migrations');
        $repository->createRepository();
        $this->migrator = new Migrator($repository, $db->getDatabaseManager(), new Filesystem, $events);
        $this->app->instance('migrator', $this->migrator);
        $provider = new MediableServiceProvider($this->app);
        $provider->register();
        $provider->boot();
    }
    protected function tearDown(): void {
        Media::clearBootedModels();
        Media::unsetConnectionResolver();
        Media::unsetEventDispatcher();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Illuminate\Container\Container::setInstance(null);
        (new Filesystem)->deleteDirectory($this->root);
    }
    public function testUpstreamVectorsAndUnicode(): void {
        foreach (['../bar' => 'bar', '/foo/../../../.env' => 'foo/env', '..\\bar' => 'bar', 'héllo/café' => 'hello/cafe', '0' => '0', 'a/0/b' => 'a/0/b', './bar.jpg' => 'bar-jpg', '/foo/// //bar' => 'foo/bar'] as $input => $expected) {
            self::assertSame($expected, File::sanitizePath($input));
        }
    }
    public function testNonConsoleProviderRegistersAndRunsRealMigrations(): void {
        self::assertFalse($this->app->runningInConsole());
        self::assertContains(dirname(__DIR__, 2) . '/migrations', $this->migrator->paths());
        $this->migrate();
        self::assertTrue(app('db.schema')->hasColumn('media', 'alt'));
        self::assertCount(3, app('db')->table('migrations')->get());
    }
    public function testAltMigrationPreservesExistingRowsAndRunsOnce(): void {
        $paths = glob(dirname(__DIR__, 2) . '/migrations/*.php');
        $this->migrator->run(array_slice($paths, 0, 2));
        self::assertFalse(app('db.schema')->hasColumn('media', 'alt'));
        $id = app('db')->table('media')->insertGetId(['disk'=>'local','directory'=>'old','filename'=>'kept','extension'=>'txt','mime_type'=>'text/plain','aggregate_type'=>'document','size'=>7]);
        $this->migrate();
        self::assertSame('kept', app('db')->table('media')->find($id)->filename);
        self::assertNull(app('db')->table('media')->find($id)->alt);
        $this->migrate();
        self::assertSame(1, app('db')->table('media')->count());
        self::assertCount(3, app('db')->table('migrations')->get());
    }
    public function testUploaderCopyAndMoveContainTraversalAndPreserveSentinel(): void {
        $this->migrate();
        foreach ([['../bar', 'bar'], ['/foo/../../../.env', 'foo/env'], ['..\\bar', 'bar'], ['héllo/café', 'hello/cafe']] as $i => [$directory, $expectedDirectory]) {
            $media = app(MediaUploader::class)->fromString('payload-' . $i)->toDestination('local', $directory)->useFilename('sentinel')->upload();
            self::assertSame($expectedDirectory, $media->directory);
            self::assertSame('payload-' . $i, $media->contents());
            $copy = $media->copyTo('../copies/case-' . $i);
            self::assertSame('copies/case-' . $i, $copy->fresh()->directory);
            self::assertSame('payload-' . $i, $copy->contents());
            $oldPath = $media->getDiskPath();
            $media->move('..\\moved/case-' . $i);
            self::assertSame('moved/case-' . $i, $media->fresh()->directory);
            self::assertFalse(app('filesystem')->disk('local')->exists($oldPath));
            self::assertSame('payload-' . $i, $media->contents());
            self::assertSame('outside-sentinel', file_get_contents($this->root . '/sentinel.txt'));
        }
    }
    public function testRealImageTwoVariantWithoutOptimizer(): void {
        $this->migrate();
        $image = new Intervention\Image\ImageManager(['driver' => 'gd']);
        $png = (string) $image->canvas(16, 12, '#ff0000')->encode('png');
        $media = app(MediaUploader::class)->fromString($png)->toDestination('local', 'images')->useFilename('original')->upload();
        $manipulator = app(ImageManipulator::class);
        $manipulator->defineVariant('thumb', ImageManipulation::make(fn($image) => $image->resize(4, 3))->noOptimization());
        $variant = $manipulator->createImageVariant($media, 'thumb');
        self::assertSame($media->id, $variant->original_media_id);
        self::assertSame('thumb', $variant->variant_name);
        $decoded = $image->make($variant->contents());
        self::assertSame(4, $decoded->width());
        self::assertSame(3, $decoded->height());
        self::assertSame($png, $media->contents());
    }

    public function testZeroDirectoriesPersistForUploadCopyAndMove(): void {
        $this->migrate();
        $media = app(MediaUploader::class)->fromString('zero-bytes')->toDestination('local', '0')->useFilename('original')->upload();
        self::assertSame('0/original.txt', $media->fresh()->getDiskPath());
        self::assertSame('zero-bytes', app('filesystem')->disk('local')->get('0/original.txt'));
        $copy = $media->copyTo('archive/0');
        self::assertSame('archive/0/original.txt', $copy->fresh()->getDiskPath());
        self::assertSame('zero-bytes', $copy->contents());
        $media->move('nested/0/destination');
        self::assertSame('nested/0/destination/original.txt', $media->fresh()->getDiskPath());
        self::assertSame('zero-bytes', $media->contents());
        self::assertFalse(app('filesystem')->disk('local')->exists('0/original.txt'));
    }
    public function testExistingZeroDirectoryRenameDoesNotCollideWithRootNeighbor(): void {
        $this->migrate();
        $disk = app('filesystem')->disk('local');
        $disk->put('0/existing.txt', 'existing-zero');
        $disk->put('renamed.txt', 'root-neighbor');
        $media = new Media;
        $media->forceFill(['disk'=>'local','directory'=>'0','filename'=>'existing','extension'=>'txt','mime_type'=>'text/plain','aggregate_type'=>'document','size'=>13])->save();
        $media->rename('renamed');
        self::assertSame('0/renamed.txt', $media->fresh()->getDiskPath());
        self::assertSame('existing-zero', $disk->get('0/renamed.txt'));
        self::assertSame('root-neighbor', $disk->get('renamed.txt'));
        self::assertFalse($disk->exists('0/existing.txt'));
    }
    public function testSvgUploadAndReplacementRemoveActiveContent(): void {
        $this->migrate();
        $malicious = file_get_contents(dirname(__DIR__) . '/_data/insecure.svg');
        $media = app(MediaUploader::class)->fromString($malicious)->toDestination('local', 'vectors')->useFilename('image')->upload();
        $this->assertSafeSvg($media);
        $replaced = app(MediaUploader::class)->fromString($malicious)->replace($media);
        self::assertSame($media->id, $replaced->id);
        $this->assertSafeSvg($replaced);
    }
    public function testSvgExtensionTriggersSanitizerEvenWithTextMime(): void {
        $this->migrate();
        $source = new class(file_get_contents(dirname(__DIR__) . '/_data/insecure.svg')) extends \Plank\Mediable\SourceAdapters\RawContentAdapter {
            public function mimeType(): string { return 'text/plain'; }
            public function extension(): ?string { return 'svg'; }
        };
        $media = app(MediaUploader::class)->fromSource($source)->setAllowUnrecognizedTypes(true)->toDestination('local', 'vectors')->useFilename('disguised')->upload();
        self::assertSame('svg', $media->extension);
        $this->assertSafeSvg($media);
    }
    public function testCustomDtdSvgFailsBeforePersistence(): void {
        $this->migrate();
        $payload = '<!DOCTYPE svg [<!ENTITY custom "unsafe">]><svg xmlns="http://www.w3.org/2000/svg"><text>&custom;</text></svg>';
        try {
            app(MediaUploader::class)->fromString($payload)->toDestination('local', 'vectors')->useFilename('entity')->upload();
            self::fail('Custom DTD entity must be rejected');
        } catch (\Plank\Mediable\Exceptions\MediaUploadException $e) {
            self::assertStringContainsString('sanitize', $e->getMessage());
        }
        self::assertSame(0, Media::count());
        self::assertSame([], app('filesystem')->disk('local')->allFiles());
    }
    public function testExecutableExtensionsRejectedAndDoubleExtensionsNeutralized(): void {
        $this->migrate();
        foreach (['php', 'pht', 'ini', 'cer', 'asa'] as $extension) {
            $source = new class('ordinary text', $extension) extends \Plank\Mediable\SourceAdapters\RawContentAdapter {
                private string $suffix;
                public function __construct(string $source, string $suffix) { parent::__construct($source); $this->suffix = $suffix; }
                public function extension(): ?string { return $this->suffix; }
            };
            try {
                app(MediaUploader::class)->fromSource($source)->toDestination('local', 'files')->useFilename('blocked')->upload();
                self::fail('Executable source extension must be rejected');
            } catch (\Plank\Mediable\Exceptions\MediaUpload\FileNotSupportedException $e) {
                self::assertStringContainsString($extension, $e->getMessage());
            }
        }
        self::assertSame(0, Media::count());
        $media = app(MediaUploader::class)->fromString('safe-text')->toDestination('local', 'files')->useFilename('image.PhP')->upload();
        self::assertSame('image-PhP.txt', basename($media->fresh()->getDiskPath()));
        self::assertSame('safe-text', $media->contents());
        $media->move('files', 'renamed.pht');
        self::assertSame('renamed-pht.txt', basename($media->fresh()->getDiskPath()));
        self::assertSame('safe-text', $media->contents());
    }
    public function testDotfilesAreNeutralizedAndEmptyInferredFilenameRejected(): void {
        $this->migrate();
        self::assertSame('env', File::sanitizeFilename('.env'));
        self::assertSame('cafe-resume', File::sanitizeFilename('café résumé'));
        self::assertSame('script-PHT.txt', File::sanitizeFilename('script.PHT.txt'));
        self::assertSame('settings-ini', File::sanitizeFilename('settings.ini'));
        $media = app(MediaUploader::class)->fromString('ordinary')->toDestination('local', 'files')->useFilename('.env')->upload();
        self::assertSame('env.txt', basename($media->getDiskPath()));
        $source = new class('ordinary') extends \Plank\Mediable\SourceAdapters\RawContentAdapter {
            public function filename(): ?string { return '...'; }
        };
        $this->expectException(\Plank\Mediable\Exceptions\MediaUpload\ConfigurationException::class);
        app(MediaUploader::class)->fromSource($source)->toDestination('local', 'files')->upload();
    }

    public function testMalformedSvgReplacementPreservesOriginalBytesAndRow(): void {
        $this->migrate();
        $media = app(MediaUploader::class)->fromString('<svg xmlns="http://www.w3.org/2000/svg"><rect width="2" height="2"/></svg>')->toDestination('local', 'vectors')->useFilename('original')->upload();
        $originalBytes = $media->contents();
        $originalRow = $media->fresh()->getAttributes();
        $source = new class('<svg><unclosed>') extends \Plank\Mediable\SourceAdapters\RawContentAdapter {
            public function mimeType(): string { return 'image/svg+xml'; }
            public function extension(): ?string { return 'svg'; }
        };
        try {
            app(MediaUploader::class)->fromSource($source)->replace($media);
            self::fail('Malformed SVG replacement must fail');
        } catch (\Plank\Mediable\Exceptions\MediaUploadException $e) {
            self::assertStringContainsString('sanitize', $e->getMessage());
        }
        self::assertSame($originalRow, $media->fresh()->getAttributes());
        self::assertSame($originalBytes, $media->fresh()->contents());
        self::assertSame(1, Media::count());
    }


    public function testReadingNullSanitizerCannotBypassSvgForFileAndStreamUploadAndReplace(): void {
        $this->migrate();
        app('config')->set('mediable.file_sanitizers', [InspectOnlySanitizer::class, \Plank\Mediable\FileSanitizers\SvgSanitizer::class]);
        $path = dirname(__DIR__) . '/_data/insecure.svg';
        foreach (['file', 'stream'] as $kind) {
            $makeSource = fn() => $kind === 'file'
                ? new \Plank\Mediable\SourceAdapters\FileAdapter(new \Symfony\Component\HttpFoundation\File\File($path))
                : new \Plank\Mediable\SourceAdapters\StreamAdapter(\GuzzleHttp\Psr7\Utils::streamFor(file_get_contents($path)));
            $media = app(MediaUploader::class)->fromSource($makeSource())->toDestination('local', 'chain')->useFilename($kind)->upload();
            $this->assertSafeSvg($media);
            $replaced = app(MediaUploader::class)->fromSource($makeSource())->replace($media);
            self::assertSame($media->id, $replaced->id);
            $this->assertSafeSvg($replaced);
        }
    }
    public function testForbiddenExtensionOverridesAllowedExtension(): void {
        $this->migrate();
        $source = new class('ordinary text') extends \Plank\Mediable\SourceAdapters\RawContentAdapter {
            public function extension(): ?string { return 'php'; }
        };
        try {
            app(MediaUploader::class)->fromSource($source)->setAllowUnrecognizedTypes(true)
                ->setAllowedExtensions(['php'])->setForbiddenExtensions(['php'])
                ->setAllowedMimeTypes(['text/plain'])->setForbiddenMimeTypes([])
                ->toDestination('local', 'files')->useFilename('blocked')->upload();
            self::fail('Forbidden extension must override the allowlist');
        } catch (\Plank\Mediable\Exceptions\MediaUpload\FileNotSupportedException $e) {
            self::assertStringContainsString('php', $e->getMessage());
        }
        self::assertSame(0, Media::count());
        self::assertSame([], app('filesystem')->disk('local')->allFiles());
    }
    public function testForbiddenMimeOverridesAllowedMime(): void {
        $this->migrate();
        try {
            app(MediaUploader::class)->fromString('ordinary text')
                ->setAllowedExtensions(['txt'])->setForbiddenExtensions([])
                ->setAllowedMimeTypes(['text/plain'])->setForbiddenMimeTypes(['text/plain'])
                ->toDestination('local', 'files')->useFilename('blocked')->upload();
            self::fail('Forbidden MIME must override the allowlist');
        } catch (\Plank\Mediable\Exceptions\MediaUpload\FileNotSupportedException $e) {
            self::assertStringContainsString('text/plain', $e->getMessage());
        }
        self::assertSame(0, Media::count());
        self::assertSame([], app('filesystem')->disk('local')->allFiles());
    }

    private function assertSafeSvg(Media $media): void {
        $bytes = $media->contents();
        self::assertStringNotContainsString('<script', $bytes);
        self::assertStringNotContainsString('onload=', $bytes);
        self::assertStringContainsString('<rect', $bytes);
        self::assertSame(strlen($bytes), $media->fresh()->size);
    }

    private function migrate(): void { $this->migrator->run($this->migrator->paths()); }
}
