<?php

declare(strict_types=1);

namespace CodeVault\Update;

final class CurlDownloader implements Downloader
{
    public function download(string $url, string $destinationPath): bool
    {
        @mkdir(dirname($destinationPath), 0755, true);
        $fp = fopen($destinationPath, 'w+b');

        if ($fp === false) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $ok = curl_exec($ch) !== false && curl_errno($ch) === 0;
        curl_close($ch);
        fclose($fp);

        if (!$ok) {
            @unlink($destinationPath);
        }

        return $ok;
    }
}
