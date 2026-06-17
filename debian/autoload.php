<?php

/**
 * Autoloader for MultiFlexi HouseKeeper
 */

require_once '/usr/share/php/MultiFlexi/autoload.php';

spl_autoload_register(function ($class) {
    $prefix = 'MultiFlexi\\';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $relativePath = str_replace('\\', '/', $relativeClass).'.php';
    $file = __DIR__.'/MultiFlexi/'.$relativePath;

    if (file_exists($file)) {
        require_once $file;
    }
});

// Optional: activate RetentionService if multiflexi-web is installed
$webDataRetentionPath = '/usr/lib/multiflexi-web/MultiFlexi/DataRetention';

if (is_dir($webDataRetentionPath)) {
    spl_autoload_register(function ($class) use ($webDataRetentionPath) {
        if (str_starts_with($class, 'MultiFlexi\\DataRetention\\')) {
            $relativePath = str_replace('\\', '/', substr($class, strlen('MultiFlexi\\DataRetention\\'))).'.php';
            $file = $webDataRetentionPath.'/'.$relativePath;

            if (file_exists($file)) {
                require_once $file;
            }
        }
    });
}

require_once '/usr/share/php/Composer/InstalledVersions.php';

(function (): void {
    $versions = [];

    foreach (\Composer\InstalledVersions::getAllRawData() as $d) {
        $versions = array_merge($versions, $d['versions'] ?? []);
    }
    $name    = 'unknown';
    $version = '0.0.0';
    $versions[$name] = ['pretty_version' => $version, 'version' => $version,
        'reference' => null, 'type' => 'library', 'install_path' => __DIR__,
        'aliases' => [], 'dev_requirement' => false];
    \Composer\InstalledVersions::reload([
        'root' => ['name' => $name, 'pretty_version' => $version, 'version' => $version,
            'reference' => null, 'type' => 'project', 'install_path' => __DIR__,
            'aliases' => [], 'dev' => false],
        'versions' => $versions,
    ]);
})();
