<?php

namespace ThunderTUS\Store;

use Predis\ClientInterface;

class Redis implements StoreInterface
{
    /** @var RedisClient */
    private $client;

    private static $prefix = "tus:";
    private static $containerPrefix = "tuscontainer:";
    private static $tusExpire = 3600;

    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    public function setTUSKeysPrefix(string $prefix): void
    {
        static::$prefix = $prefix . ":";
        static::$containerPrefix = $prefix . "container:";
    }

    public function setUploadTTL(int $ttlSeconds): void
    {
        static::$tusExpire = $ttlSeconds;
    }

    public function get(string $name): ?string
    {
        return $this->client->get(static::$prefix . $name);
    }

    public static function downloadIntoLocalFolder(ClientInterface $client, string $destinationDirectory, string $name): bool
    {
        $storage = new static($client);
        $data = $storage->get($name);
        if ($data === null) {
            return false;
        }

        $destinationDirectory = rtrim($destinationDirectory, "/") . "/";
        $result = file_put_contents($destinationDirectory . $name, $data);
        if ($result === false) {
            return false;
        }

        $storage->delete($name);
        return true;
    }

    /** Implement StoreInterface */
    public function exists(string $name): bool
    {
        return $this->client->exists(static::$prefix . $name) === 1;
    }

    public function create(string $name): bool
    {
        return $this->client->setex(static::$prefix . $name, self::$tusExpire, "") == "OK";
    }

    public function getSize(string $name): int
    {
        //return ((int)$this->client->bitcount(static::$prefix . $name))/8;
        return (int)$this->client->strlen(static::$prefix . $name);
    }

    public function hashMatch(string $name, string $algo, string $expectedHash): bool
    {
        // Redis backend storage doesn't support efficient hashing.
        // Hashing is only used in the custom CrossCheck extension implemented by
        // thunder-tus-php. This isn't a part of the TUS protocol, so it can be skipped safely.
        return true;
    }

    public function append(string $name, $data): bool
    {
        $result = $this->client->append(static::$prefix . $name, stream_get_contents($data));
        return $result;
    }

    public function delete(string $name): bool
    {
        return $this->client->del([self::$prefix . $name]);
    }

    public function containerExists(string $name): bool
    {
        return $this->client->exists(static::$containerPrefix . $name) == 1;
    }

    public function containerCreate(string $name, ?\stdClass $data = null): bool
    {
        return $this->client->setex(static::$containerPrefix . $name, self::$tusExpire, \json_encode($data)) == "OK";
    }

    public function containerUpdate(string $name, \stdClass $data): bool
    {
        return $this->containerCreate($name, $data);
    }

    public function containerFetch(string $name): \stdClass
    {
        return \json_decode($this->client->get(static::$containerPrefix . $name));
    }

    public function containerDelete(string $name): bool
    {
        return $this->client->del([self::$containerPrefix . $name]);
    }

}
