<?php

namespace ThunderTUS\Store;

use ThunderTUS\ThunderTUSException;

abstract class StorageBackend implements StoreInterface
{

    public function supportsCrossCheck(): bool
    {
        return false;
    }

    public function crossCheck(string $name, string $algo, string $expectedHash): bool
    {
        throw new ThunderTUSException("The " . static::class . " storage backend doesn't support the CrossCheck extension due to performance reasons.");
    }

    public function getCrossCheckAlgoritms(): array
    {
        return [];
    }

    public function streamFromStorage(string $name, bool $removeAfter = true)
    {
        throw new ThunderTUSException("The " . static::class . " storage backend hasn't implemented 'streamFromStorage'. Please use 'fetchFromStorage' to fetch the complete file into the local filesystem.");
    }

    public static function normalizePath(string $path): string
    {
        return rtrim(realpath($path), \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
    }

}
