<?php

declare(strict_types=1);

namespace FluxFiles;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Local adapter decorator: after any write/move/copy/mkdir whose destination is
 * under a `_fluxfiles/` dir, makes sure that dir carries the guard files.
 * Wraps LOCAL disks only (see DiskManager::build()).
 */
final class GuardedLocalAdapter implements FilesystemAdapter
{
    public function __construct(private LocalFilesystemAdapter $inner, private string $root) {}

    private function guard(string $path): void
    {
        FluxDirGuard::ensureForPath($this->root, $path);
    }

    public function fileExists(string $path): bool { return $this->inner->fileExists($path); }
    public function directoryExists(string $path): bool { return $this->inner->directoryExists($path); }
    public function write(string $path, string $contents, Config $config): void { $this->inner->write($path, $contents, $config); $this->guard($path); }
    public function writeStream(string $path, $contents, Config $config): void { $this->inner->writeStream($path, $contents, $config); $this->guard($path); }
    public function read(string $path): string { return $this->inner->read($path); }
    public function readStream(string $path) { return $this->inner->readStream($path); }
    public function delete(string $path): void { $this->inner->delete($path); }
    public function deleteDirectory(string $path): void { $this->inner->deleteDirectory($path); }
    public function createDirectory(string $path, Config $config): void { $this->inner->createDirectory($path, $config); $this->guard($path); }
    public function setVisibility(string $path, string $visibility): void { $this->inner->setVisibility($path, $visibility); }
    public function visibility(string $path): FileAttributes { return $this->inner->visibility($path); }
    public function mimeType(string $path): FileAttributes { return $this->inner->mimeType($path); }
    public function lastModified(string $path): FileAttributes { return $this->inner->lastModified($path); }
    public function fileSize(string $path): FileAttributes { return $this->inner->fileSize($path); }
    public function listContents(string $path, bool $deep): iterable { return $this->inner->listContents($path, $deep); }
    public function move(string $source, string $destination, Config $config): void { $this->inner->move($source, $destination, $config); $this->guard($destination); }
    public function copy(string $source, string $destination, Config $config): void { $this->inner->copy($source, $destination, $config); $this->guard($destination); }
}
