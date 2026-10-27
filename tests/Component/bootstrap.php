<?php
// Minimal application helper mappings only. Production Mediable and Illuminate behavior is unmodified.
require getenv('COMPONENT_AUTOLOAD');
function app($abstract = null) { $app = Illuminate\Container\Container::getInstance(); return $abstract === null ? $app : $app->make($abstract); }
function config($key = null, $default = null) { return app('config')->get($key, $default); }
function config_path($path = '') { return app()->root . '/config/' . $path; }
class ComponentApplication extends Illuminate\Container\Container {
    public string $root;
    public function runningInConsole() { return false; }
    public function currentLocale() { return 'en'; }
    public function databasePath($path = '') { return $this->root . '/database/' . $path; }
}
