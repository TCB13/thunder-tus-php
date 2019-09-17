<?php

namespace ThunderTUS\Store;

use ThunderTUS\ThunderTUSException;

class FileSystem implements StoreInterface
{

    private static $containerSuffix = ".cachecontainer";
    private $uploadDir;

    public function __construct(string $uploadDir = null)
    {
        if ($uploadDir !== null) {
            $this->setUploadDir($uploadDir);
        }
    }

    public function setUploadDir(string $uploadDir): bool
    {
        if (!is_dir($uploadDir)) {
            throw new ThunderTUSException("Invalid upload directory. Path wasn't set, it doesn't exist or it isn't a directory.");
        }
        $this->uploadDir = rtrim(realpath($uploadDir), \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
        return true;
    }

    public function getUploadDir(): string
    {
        return $this->uploadDir;
    }

    /** Implement StoreInterface */
    public function exists(string $name): bool
    {
        return file_exists($this->uploadDir . $name);
    }

    public function create(string $name): bool
    {
        touch($this->uploadDir . $name);
        return true;
    }

    public function getSize(string $name): int
    {
        if (!file_exists($this->uploadDir . $name)) {
            throw new ThunderTUSException("File doesn't exist.");
        }
        return filesize($this->uploadDir . $name);
    }

    public function hashMatch(string $name, string $algo, string $expectedHash): bool
    {
        return base64_encode(hash_file($algo, $this->uploadDir . $name, true)) === $expectedHash;
    }

    public function append(string $name, $data): bool
    {
        // Write the uploaded chunk to the file
        $file = fopen($this->uploadDir . $name, "ab");
        stream_copy_to_stream($data, $file);
        fclose($file);
        clearstatcache(true, $this->uploadDir . $name);
        return true;
    }

    public function delete(string $name): bool
    {
        unlink($this->uploadDir . $name);
        return true;
    }

    public function containerCreate(string $name, ?\stdClass $data = null): bool
    {
        if ($data === null) {
            touch($this->uploadDir . $name . static::$containerSuffix);
            return true;
        }

        $result = file_put_contents($this->uploadDir . $name . static::$containerSuffix, \json_encode($data));
        return $result === false ? false : true;
    }

    public function containerUpdate(string $name, \stdClass $data): bool
    {
        $result = file_put_contents($this->uploadDir . $name . static::$containerSuffix, \json_encode($data));
        return $result === false ? false : true;
    }

    public function containerExists(string $name): bool
    {
        return file_exists($this->uploadDir . $name . static::$containerSuffix);
    }

    public function containerFetch(string $name): \stdClass
    {
        return json_decode(file_get_contents($this->uploadDir . $name . static::$containerSuffix));
    }

    public function containerDelete(string $name): bool
    {
        unlink($this->uploadDir . $name . static::$containerSuffix);
        return true;
    }

}
