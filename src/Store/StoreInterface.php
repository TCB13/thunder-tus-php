<?php

namespace ThunderTUS\Store;

interface StoreInterface
{
    public function exists(string $name): bool;
    public function create(string $name): bool;
    public function getSize(string $name): int;
    public function append(string $name, $data): bool;
    public function store(string $name, $data): bool;
    public function delete(string $name): bool;

    public function completeAndFetch(string $name, string $destinationDirectory, bool $removeAfter = true): bool;
    public function completeAndStream(string $name, bool $removeAfter = true);
    public function complete(string $name): bool;

    public function supportsCrossCheck(): bool;
    public function crossCheck(string $name, string $algo, string $expectedHash): bool;
    public function getCrossCheckAlgoritms(): array;

    public function containerExists(string $name): bool;
    public function containerCreate(string $name, ?\stdClass $data = null): bool;
    public function containerUpdate(string $name, \stdClass $data): bool;
    public function containerFetch(string $name): \stdClass;
    public function containerDelete(string $name): bool;

}
