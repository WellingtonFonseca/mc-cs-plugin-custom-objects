<?php

/**
 * Bootstrap of the unit tests and of Infection in the Mautic stack: the autoloader of the project WITHOUT nikic/php-parser,
 * because Infection brings its own, a different version, and loading both is a fatal error. Without it Infection cannot
 * reflect the classes that extend Mautic's (the models, the query builders).
 */
define('MAUTIC_TABLE_PREFIX', '');
$_ENV['MAUTIC_UPLOAD_DIR'] = sys_get_temp_dir();

$composer = '/var/www/html/vendor/composer';
if (!class_exists(\Composer\Autoload\ClassLoader::class, false)) {
    require_once $composer.'/ClassLoader.php';
}

$loader = new \Composer\Autoload\ClassLoader('/var/www/html/vendor');
$skip   = static fn (string $name): bool => str_starts_with($name, 'PhpParser\\');

foreach (require $composer.'/autoload_psr4.php' as $prefix => $paths) {
    if (!$skip($prefix)) {
        $loader->setPsr4($prefix, $paths);
    }
}
foreach (require $composer.'/autoload_namespaces.php' as $prefix => $paths) {
    if (!$skip($prefix)) {
        $loader->set($prefix, $paths);
    }
}
$loader->addClassMap(array_filter(require $composer.'/autoload_classmap.php', static fn ($file, $class) => !$skip($class), ARRAY_FILTER_USE_BOTH));
$loader->register(false);
