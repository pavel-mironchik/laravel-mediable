<?php
// Genuine component-only consumer graph: no Foundation or framework version shims.
$version = $argv[1] ?? '';
$destination = $argv[2] ?? '';
if (!in_array($version, ['10.49.0', '11.51.0'], true) || !$destination) {
    throw new RuntimeException('Usage: prepare.php {10.49.0|11.51.0} destination');
}
@mkdir($destination, 0777, true);
$requirements = ['flashasia/laravel-mediable' => 'dev-flashasia-backports', 'intervention/image' => '2.7.2', 'phpunit/phpunit' => '^10.5.38'];
foreach (['database', 'filesystem', 'support', 'config', 'events', 'cache', 'http', 'console'] as $component) {
    $requirements['illuminate/' . $component] = $version;
}
file_put_contents($destination . '/composer.json', json_encode([
    'name' => 'flashasia/mediable-component-tests',
    'repositories' => [['type' => 'path', 'url' => dirname(__DIR__, 2), 'options' => ['symlink' => true]]],
    'require' => $requirements,
    'minimum-stability' => 'dev', 'prefer-stable' => true,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
