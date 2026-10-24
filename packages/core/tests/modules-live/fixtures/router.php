<?php
// Core's router with every paid module loadable. The modules aren't Composer
// deps in this monorepo, so map FluxFiles\<Module>\ to /app/modules/<id>/src —
// exactly what an installed (composer/UpdateClient) module provides.
require_once '/app/core/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (glob('/app/modules/*/src/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match('/^namespace\s+([^;]+);/m', $src, $ns) && preg_match('/^(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)/m', $src, $cl)) {
                $map[trim($ns[1]) . '\\' . $cl[1]] = $file;
            }
        }
    }
    if (isset($map[$class])) {
        require_once $map[$class];
    }
});
return require '/app/core/router.php';
