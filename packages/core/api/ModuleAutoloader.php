<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Autoloader for the PAID MODULES (private packages Composer cannot resolve).
 *
 * A module arrives as a signed zip unpacked into `<base>/<module>/src/`, which no
 * Composer-generated map knows about, so `FluxFiles\Share\ShareModule` is found here:
 * the namespace segment picks the module dir, the rest is the PSR-4 relative path.
 * `modules-autoload.php` registers the two standalone/dependency layouts; a host that
 * keeps modules elsewhere (the WordPress add-on bundles them in its own plugin folder)
 * calls `register()` with its own base dirs. Bases accumulate across calls and the
 * underlying spl autoloader is installed exactly once.
 *
 * Resolution is lazy and cheap: the filesystem is touched only when a
 * `FluxFiles\<Module>\…` class is requested, which happens only when a paid gate runs.
 * A free-core install stays a no-op that ends in an is_file() miss.
 *
 * Only real install layouts are searched. A monorepo sibling (packages/share next to
 * packages/core) is deliberately NOT a base: treating it as installed would make the
 * "module absent → 501" path untestable on the one machine that has the private packages.
 */
final class ModuleAutoloader
{
    /** @var string[] base dirs, each ending in a slash, searched in registration order */
    private static array $bases = [];

    private static bool $installed = false;

    /**
     * Add base directories (each holds `<module>/src/`). Duplicates are ignored.
     *
     * @param string[] $bases e.g. [__DIR__.'/vendor/fluxfiles/', '/plugins/fluxfiles-pro/modules/']
     */
    public static function register(array $bases): void
    {
        foreach ($bases as $base) {
            if (!is_string($base) || $base === '') {
                continue;
            }
            $base = rtrim($base, '/\\') . '/';
            if (!in_array($base, self::$bases, true)) {
                self::$bases[] = $base;
            }
        }
        if (!self::$installed) {
            self::$installed = true;
            spl_autoload_register([self::class, 'load']);
        }
    }

    /** @return string[] */
    public static function bases(): array
    {
        return self::$bases;
    }

    /** Forget every base (tests). The spl hook stays installed and simply finds nothing. */
    public static function reset(): void
    {
        self::$bases = [];
    }

    /**
     * Candidate module directory names for a namespace segment. Module dirs are
     * kebab-case ("audit-export", "legal-hold") but the PHP segment is PascalCase with
     * no separator ("AuditExport", "LegalHold"); plain strtolower() collapses to
     * "auditexport", which never matches a real install dir. Try the plain lowercase
     * ("Share" → "share", "C2pa" → "c2pa") and a kebab-cased form.
     *
     * @return string[]
     */
    public static function dirNames(string $segment): array
    {
        $plain = strtolower($segment);
        $kebab = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $segment));
        return $plain === $kebab ? [$plain] : [$plain, $kebab];
    }

    public static function load(string $class): void
    {
        if (strncmp($class, 'FluxFiles\\', 10) !== 0) {
            return;
        }
        $rest = substr($class, 10);
        $slash = strpos($rest, '\\');
        if ($slash === false) {
            return;   // FluxFiles\Foo is core's own namespace, already mapped
        }
        $segment = substr($rest, 0, $slash);
        if (!preg_match('/^[A-Za-z0-9]+$/', $segment)) {
            return;   // never let a crafted class name walk the filesystem
        }
        $relative = str_replace('\\', '/', substr($rest, $slash + 1)) . '.php';

        foreach (self::dirNames($segment) as $module) {
            foreach (self::$bases as $base) {
                $file = $base . $module . '/src/' . $relative;
                if (is_file($file)) {
                    require_once $file;
                    return;
                }
            }
        }
    }
}
