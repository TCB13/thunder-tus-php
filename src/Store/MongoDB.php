<?php

namespace ThunderTUS\Store;

use MongoDB\Client;
use ThunderTUS\ThunderTUSException;

class MongoDB extends StorageBackend
{
    /** @var Client */
    private $bucket;

    private static $bucketName = "tus";
    private static $containerPrefix = "container.";
    private static $partBufferSize = 10000000;

    public function __construct(\MongoDB\Database $database)
    {
        $this->bucket = $database->selectGridFSBucket(["bucketName" => "tus"]);
    }

    public function get(string $name, bool $all = false)
    {
        if ($all) {
            $result = $this->bucket->find(["filename" => $name]);
            return $result->toArray();
        } else {
            $result = $this->bucket->findOne(["filename" => $name]);
            return $result;
        }
    }

    /** Implement StoreInterface */
    public function exists(string $name): bool
    {
        return $this->get($name) !== null;
    }

    public function create(string $name): bool
    {
        return $this->get($name) === null;
    }

    public function getSize(string $name): int
    {
        $parts = $this->get($name, true);
        return (int)array_sum(array_column($parts, "length"));
    }

    public function append(string $name, $data): bool
    {
        return $this->store($name, $data);
    }

    public function store(string $name, $data): bool
    {
        $this->bucket->uploadFromStream($name, $data);
        return true;
    }

    public function delete(string $name): bool
    {
        $parts = $this->get($name, true);
        foreach ($parts as $part) {
            $this->bucket->delete($part->_id);
        }
        return true;
    }

    public function completeAndFetch(string $name, string $destinationDirectory, bool $removeAfter = true): bool
    {
        $parts   = $this->get($name, true);
        if (empty($parts) || $parts === null) {
            return false;
        }
        $parts = array_column($parts, "_id");

        // Read the gridfs parts file into local storage 10MB at the time
        $destinationDirectory = self::normalizePath($destinationDirectory);
        $file = fopen($destinationDirectory . $name, 'w');
        foreach ($parts as $part) {
            $stream = $this->bucket->openDownloadStream($part);
            while (!feof($stream)) {
                fwrite($file, fread($stream, self::$partBufferSize));
            }
            fclose($stream);
            // Delete part from mongodb
            if ($removeAfter) {
                $this->bucket->delete($part);
            }
        }

        if ($removeAfter) {
            $this->containerDelete($name);
        }

        fclose($file);
        return true;
    }

    public function completeAndStream(string $name, bool $removeAfter = true)
    {
        $parts   = $this->get($name, true);
        if (empty($parts) || $parts === null) {
            return false;
        }
        $parts = array_column($parts, "_id");

        // Read the gridfs parts file a final local tmp stream
        $final = fopen("php://temp", "r+");
        foreach ($parts as $part) {
            $partStream = $this->bucket->openDownloadStream($part);
            stream_copy_to_stream($partStream, $final);
            fclose($partStream);
            // Delete part from mongodb
            if ($removeAfter) {
                $this->bucket->delete($part);
            }
        }

        if ($removeAfter) {
            $this->containerDelete($name);
        }

        return $final;
    }

    public function complete(string $name): bool
    {
        $parts   = $this->get($name, true);
        if (empty($parts) || $parts === null) {
            return false;
        }
        $parts = array_column($parts, "_id");

        // Read the gridfs parts file into a local tmp 10MB at the time
        $final = fopen("php://temp", "r+");
        foreach ($parts as $part) {
            $stream = $this->bucket->openDownloadStream($part);
            while (!feof($stream)) {
                stream_copy_to_stream(fread($stream, self::$partBufferSize), $final);
            }
            fclose($stream);
            // Delete part from mongodb
            $this->bucket->delete($part);
        }

        $this->containerDelete($name);

        // We now have a final tmp with the entrie file upload it to mongodb
        rewind($final);
        $this->bucket->uploadFromStream($name, $final);

        return true;

    }

    public function containerExists(string $name): bool
    {
        return $this->exists(self::$containerPrefix . $name);
    }

    public function containerCreate(string $name, ?\stdClass $data = null): bool
    {
        $data = (array)$data;
        if ($data === null) {
            $data = [];
        }
        $stream = fopen("php://memory", "r+");
        $this->bucket->uploadFromStream(self::$containerPrefix . $name, $stream, ["metadata" => ["container" => $data]]);
        fclose($stream);
        return true;
    }

    public function containerUpdate(string $name, \stdClass $data): bool
    {
        $container = $this->get(self::$containerPrefix . $name);
        if ($container === null) {
            return false;
        }
        $this->bucket->delete($container->_id);
        return $this->containerCreate($name, $data);
    }

    public function containerFetch(string $name): \stdClass
    {
        $container = $this->get(self::$containerPrefix . $name);
        return (object)(array)$container->metadata->container;
    }

    public function containerDelete(string $name): bool
    {
        $container = $this->get(self::$containerPrefix . $name);
        if ($container === null) {
            return true;
        }
        $this->bucket->delete($container->_id);
        return true;
    }
}
