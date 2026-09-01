<?php

/**
 * Interface-conformance guard for MetadataRepositoryInterface.
 *
 * Reflects over the interface and asserts every implementer (StorageMetadataHandler,
 * the JSON/file backend; DbMetadataHandler, the SQL backend) implements every
 * declared method with a matching signature (param types + count + return type).
 * Cheap regression guard against the interface and its implementers drifting
 * apart — see docs/DB-STORAGE-MIGRATION-DESIGN.md §2/§12.
 *
 * Usage: php packages/core/tests/unit/test-metadata-repository-interface.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

$green  = "\033[32m";
$red    = "\033[31m";
$cyan   = "\033[36m";
$reset  = "\033[0m";

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try {
        $fn();
        echo "  {$green}PASS{$reset} {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function paramSignature(\ReflectionMethod $m): string
{
    $parts = [];
    foreach ($m->getParameters() as $p) {
        $type = $p->getType();
        $typeStr = $type instanceof \ReflectionNamedType ? ($type->allowsNull() ? '?' : '') . $type->getName() : 'mixed';
        $parts[] = $typeStr . ' $' . $p->getName() . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '');
    }
    return implode(', ', $parts);
}

function returnSignature(\ReflectionMethod $m): string
{
    $type = $m->getReturnType();
    if (!$type instanceof \ReflectionNamedType) {
        return 'mixed';
    }
    return ($type->allowsNull() ? '?' : '') . $type->getName();
}

echo "\n{$cyan}══ MetadataRepositoryInterface ↔ implementers conformance ══{$reset}\n\n";

$iface = new \ReflectionClass(\FluxFiles\MetadataRepositoryInterface::class);

$ifaceMethods = $iface->getMethods();
if (count($ifaceMethods) === 0) {
    throw new \RuntimeException('MetadataRepositoryInterface declares no methods — something is very wrong');
}

$implClasses = [
    \FluxFiles\StorageMetadataHandler::class,
    \FluxFiles\Db\DbMetadataHandler::class,
];

foreach ($implClasses as $implClass) {
    echo "  {$cyan}-- {$implClass} --{$reset}\n";
    $impl = new \ReflectionClass($implClass);

    test("{$implClass} implements MetadataRepositoryInterface", function () use ($impl) {
        if (!$impl->implementsInterface(\FluxFiles\MetadataRepositoryInterface::class)) {
            throw new \RuntimeException("{$impl->getName()} does not implement MetadataRepositoryInterface");
        }
    });

    foreach ($ifaceMethods as $ifaceMethod) {
        $name = $ifaceMethod->getName();
        test("signature matches for {$name}()", function () use ($impl, $ifaceMethod, $name) {
            if (!$impl->hasMethod($name)) {
                throw new \RuntimeException("{$impl->getName()} is missing method {$name}()");
            }
            $implMethod = $impl->getMethod($name);

            $ifaceParams = paramSignature($ifaceMethod);
            $implParams  = paramSignature($implMethod);
            if ($ifaceParams !== $implParams) {
                throw new \RuntimeException("param mismatch: interface({$ifaceParams}) vs impl({$implParams})");
            }

            $ifaceReturn = returnSignature($ifaceMethod);
            $implReturn  = returnSignature($implMethod);
            if ($ifaceReturn !== $implReturn) {
                throw new \RuntimeException("return type mismatch: interface({$ifaceReturn}) vs impl({$implReturn})");
            }
        });
    }
}

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "{$cyan}  Results: {$green}{$passed} passed{$reset}";
if ($failed > 0) {
    echo ", {$red}{$failed} failed{$reset}";
}
echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
