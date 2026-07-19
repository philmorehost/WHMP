<?php

declare(strict_types=1);

namespace CodeVault\Update;

interface Downloader
{
    public function download(string $url, string $destinationPath): bool;
}
