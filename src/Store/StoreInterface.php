<?php

namespace ThunderTUS\Store;

interface StoreInterface
{
    public function exists(string $name): bool;
    public function create(string $name): bool;
    public function getSize(string $name): int;
    public function hashMatch(string $name, string $algo, string $expectedHash): bool;
    public function append(string $name, $data): bool;
    public function delete(string $name): bool;

    public function containerExists(string $name): bool;
    public function containerCreate(string $name, ?\stdClass $data = null): bool;
    public function containerUpdate(string $name, \stdClass $data): bool;
    public function containerFetch(string $name): \stdClass;
    public function containerDelete(string $name): bool;
}
