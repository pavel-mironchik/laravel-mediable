<?php
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class MySqlMigrationTest extends TestCase
{
    private PDO $admin;
    private string $database;
    private Capsule $db;
    private Migrator $migrator;
    private string $fixtures;

    protected function setUp(): void
    {
        $host = getenv('MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('MYSQL_PORT') ?: '3306';
        $user = getenv('MYSQL_USER') ?: 'root';
        $password = getenv('MYSQL_PASSWORD') ?: '';
        $this->admin = new PDO("mysql:host=$host;port=$port", $user, $password);
        $this->database = 'mediable_regression_' . bin2hex(random_bytes(8));
        $this->admin->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->fixtures = sys_get_temp_dir() . '/' . $this->database;
        $manifest = json_decode(file_get_contents(__DIR__ . '/fixtures/provenance.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (glob(__DIR__ . '/fixtures/*/*.php.fixture') as $source) {
            $relative = substr($source, strlen(__DIR__ . '/fixtures/'));
            self::assertSame($manifest['sha256'][$relative], hash_file('sha256', $source));
            $target = $this->fixtures . '/' . basename(dirname($source));
            (new Filesystem)->ensureDirectoryExists($target);
            copy($source, $target . '/' . basename($source, '.fixture'));
        }
        $app = new ComponentApplication;
        Illuminate\Container\Container::setInstance($app);
        $app->instance('config', new Repository(['mediable' => ['connection_name' => 'default']]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $events = new Dispatcher($app);
        $this->db = new Capsule($app);
        $this->db->addConnection(['driver'=>'mysql', 'host'=>$host, 'port'=>$port, 'database'=>$this->database,
            'username'=>$user, 'password'=>$password, 'charset'=>'utf8mb4', 'collation'=>'utf8mb4_unicode_ci', 'prefix'=>'kmj_']);
        $app->instance('db', $this->db->getDatabaseManager());
        $app->bind('db.schema', fn() => $this->db->getConnection()->getSchemaBuilder());
        $repository = new DatabaseMigrationRepository($this->db->getDatabaseManager(), 'migrations');
        $repository->createRepository();
        $this->migrator = new Migrator($repository, $this->db->getDatabaseManager(), new Filesystem, $events);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) { $this->db->getDatabaseManager()->purge(); }
        if (isset($this->database)) { $this->admin->exec("DROP DATABASE `{$this->database}`"); }
        if (isset($this->fixtures)) { (new Filesystem)->deleteDirectory($this->fixtures); }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Illuminate\Container\Container::setInstance(null);
    }

    private function candidate(): string { return dirname(__DIR__, 2) . '/migrations'; }
    // Independent minimal consumer schema: only the INT foreign-key contract.
    private function createThumbnailConsumer(): void
    {
        app('db.schema')->create('media_thumbnail_states', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('media_id');
            $table->string('status');
            $table->string('thumbnail_path');
            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
        });
    }
    private function rows(string $table): array { return $this->db->getConnection()->table($table)->orderBy('id')->get()->map(fn($row) => (array) $row)->all(); }
    private function seedRows(): void
    {
        $base = ['disk'=>'local','directory'=>'old/0','extension'=>'png','mime_type'=>'image/png','aggregate_type'=>'image','size'=>17];
        $this->db->getConnection()->table('media')->insert($base + ['id'=>11,'filename'=>'original']);
        $this->db->getConnection()->table('media')->insert($base + ['id'=>12,'filename'=>'variant','variant_name'=>'thumb','original_media_id'=>11]);
        $this->db->getConnection()->table('mediables')->insert(['media_id'=>11,'mediable_type'=>'Example','mediable_id'=>19,'tag'=>'image','order'=>2]);
        $this->db->getConnection()->table('media_thumbnail_states')->insert(['media_id'=>11,'status'=>'ready','thumbnail_path'=>'old/0/thumb.png']);
    }
    private function assertIntegerTypes(): void
    {
        foreach (['media'=>['id','original_media_id'], 'mediables'=>['media_id','mediable_id'], 'media_thumbnail_states'=>['media_id']] as $table=>$columns) {
            foreach ($columns as $column) {
                $result = $this->db->getConnection()->selectOne('SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$this->database,'kmj_'.$table,$column]);
                self::assertSame('int unsigned', $result->type, "$table.$column");
            }
        }
    }

    public function testFreshSchemaAcceptsIntegerConsumerForeignKeys(): void
    {
        $this->migrator->run([$this->candidate()]);
        $this->createThumbnailConsumer();
        $this->assertIntegerTypes();
        $this->seedRows();
        $this->db->getConnection()->table('media')->where('id',11)->delete();
        self::assertSame(0, $this->db->getConnection()->table('mediables')->count());
        self::assertSame(0, $this->db->getConnection()->table('media_thumbnail_states')->count());
        self::assertNull($this->db->getConnection()->table('media')->find(12)->original_media_id);
        self::assertSame('variant', $this->db->getConnection()->table('media')->find(12)->filename);
        app('db.schema')->drop('media_thumbnail_states');
        $this->migrator->rollback([$this->candidate()]);
        self::assertFalse(app('db.schema')->hasTable('media'));
        self::assertFalse(app('db.schema')->hasTable('mediables'));
        self::assertSame(0,$this->db->getConnection()->table('migrations')->count());
    }

    public function testAlreadyMigratedSixTwoTwoBigIntSchemaIsNotConverted(): void
    {
        $this->migrator->run([$this->fixtures . '/mediable-6.2.2']);
        $before = $this->rows('migrations');
        $this->db->getConnection()->enableQueryLog();
        $this->migrator->run([$this->candidate()]);
        self::assertSame([], array_values(array_filter(array_column($this->db->getConnection()->getQueryLog(), 'query'), fn($sql) => preg_match('/^(alter|create|drop|insert|update|delete) /i', $sql))));
        self::assertSame($before, $this->rows('migrations'));
        foreach (['media'=>['id','original_media_id'], 'mediables'=>['media_id','mediable_id']] as $table=>$columns) {
            foreach ($columns as $column) {
                $result = $this->db->getConnection()->selectOne('SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$this->database,'kmj_'.$table,$column]);
                self::assertSame('bigint unsigned', $result->type);
            }
        }
    }

    public function testGenuineFiveNineOneLedgerUpgradeOnlyAddsAltAndPreservesRows(): void
    {
        $legacy = $this->fixtures . '/mediable-5.9.1';
        $this->migrator->run([$legacy]);
        $this->createThumbnailConsumer();
        $this->assertIntegerTypes();
        $this->seedRows();
        $before = $this->rows('media');
        $thumb = $this->rows('media_thumbnail_states');
        $pivot = $this->db->getConnection()->table('mediables')->get()->all();
        $ledger = $this->rows('migrations');
        self::assertFalse(app('db.schema')->hasColumn('media','alt'));
        $this->db->getConnection()->enableQueryLog();
        $this->migrator->run([$this->candidate()]);
        $queries = array_column($this->db->getConnection()->getQueryLog(),'query');
        $ddl = array_values(array_filter($queries, fn($sql) => preg_match('/^(alter|create|drop) /i',$sql)));
        self::assertCount(1,$ddl);
        self::assertStringContainsString('`alt`',$ddl[0]);
        self::assertSame(array_map(fn($row) => $row + ['alt'=>null],$before),$this->rows('media'));
        self::assertSame($thumb,$this->rows('media_thumbnail_states'));
        self::assertEquals($pivot,$this->db->getConnection()->table('mediables')->get()->all());
        self::assertSame($ledger,array_slice($this->rows('migrations'),0,2));
        self::assertCount(3,$this->rows('migrations'));
        $this->db->getConnection()->flushQueryLog();
        $this->migrator->run([$this->candidate()]);
        self::assertSame([],array_values(array_filter(array_column($this->db->getConnection()->getQueryLog(),'query'),fn($sql)=>preg_match('/^(alter|create|drop|insert|update|delete) /i',$sql))));
        $this->migrator->rollback([$this->candidate()]);
        self::assertSame($before,$this->rows('media'));
        self::assertSame($ledger,$this->rows('migrations'));
        self::assertSame($thumb,$this->rows('media_thumbnail_states'));
        $this->assertIntegerTypes();
    }
}
