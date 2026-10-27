<?php
// Discover, bound execution, and reject partial receipts for either focused suite.
$suite = $argv[1] ?? '';
$junit = $argv[2] ?? '';
if (!in_array($suite, ['BackportTest.php', 'MySqlMigrationTest.php'], true) || !$junit) {
    throw new RuntimeException('Usage: run-bounded.php {BackportTest.php|MySqlMigrationTest.php} junit-path');
}
$autoload = getenv('COMPONENT_AUTOLOAD');
if (!$autoload || !is_file($autoload)) { throw new RuntimeException('COMPONENT_AUTOLOAD is required'); }
$base = [PHP_BINARY, dirname($autoload) . '/bin/phpunit', '--no-configuration', '--bootstrap', __DIR__ . '/bootstrap.php', __DIR__ . '/' . $suite];
function runBounded(array $command): array {
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start PHPUnit'); }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $deadline = microtime(true) + 900;
    do {
        $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) { break; }
        if (microtime(true) >= $deadline) {
            proc_terminate($process, 9);
            throw new RuntimeException('PHPUnit exceeded 900 seconds');
        }
        usleep(10000);
    } while (true);
    $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    return [$status['exitcode'], $output];
}
[$discoveryExit, $discovery] = runBounded([...$base, '--list-tests']);
echo $discovery;
preg_match_all('/^ - /m', $discovery, $matches);
$count = count($matches[0]);
if ($discoveryExit !== 0 || $count < 1 || $count >= 60) { throw new RuntimeException('Unsafe/failed test discovery'); }
if (is_file($junit)) { unlink($junit); }
[$exit, $output] = runBounded([...$base, '--fail-on-risky', '--fail-on-skipped', '--fail-on-incomplete', '--log-junit', $junit]);
echo $output;
echo "Raw PHPUnit exit: $exit; discovered: $count\n";
if ($exit !== 0) { exit($exit); }
$xml = simplexml_load_file($junit);
$cases = $xml ? $xml->xpath('//testcase') : [];
if (count($cases) !== $count || $xml->xpath('//failure|//error|//skipped')) { throw new RuntimeException('Incomplete or failed JUnit receipt'); }
echo "Verified JUnit test count: $count\n";
