<?php
/**
 * Makes the plugin classes and the test support classes loadable.
 *
 * A test runs without the plugin runtime, which is what normally registers the PSR-4 root of
 * plugin.json. Registering the same mapping here keeps the tests to the classes they name.
 */

spl_autoload_register(static function (string $class): void {
    $roots = array(
        'AdmidioPlugin\\Provisioning\\Tests\\' => dirname(__DIR__) . '/',
        'AdmidioPlugin\\Provisioning\\' => dirname(__DIR__, 2) . '/src/'
    );
    foreach ($roots as $prefix => $root) {
        if (str_starts_with($class, $prefix)) {
            $path = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
            return;
        }
    }
});
