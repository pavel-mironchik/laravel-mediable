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
        foreach (['../bar' => 'bar', '/foo/../../../.env' => 'foo/env', '..\\bar' => 'bar', 'héllo/café' => 'hello/cafe', './bar.jpg' => 'bar-jpg', '/foo/// //bar' => 'foo/bar'] as $input => $expected) {
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
    private function migrate(): void { $this->migrator->run($this->migrator->paths()); }
}
