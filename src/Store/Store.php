<?php

namespace Pacific\Licentra\Store;

interface Store
{
    /** @return array<string, mixed> */
    public function read(): array;

    /** @param array<string, mixed> $data */
    public function write(array $data): void;
}
