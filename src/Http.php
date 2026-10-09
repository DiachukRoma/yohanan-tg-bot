<?php
declare(strict_types=1);

namespace App;

final class Http
{
    public static function download(string $url, string $path): void
    {
        $fh = fopen($path, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
        ]);
        $ok = curl_exec($ch);
        $error = curl_error($ch);
        fclose($fh);

        if (!$ok) {
            throw new \RuntimeException("не вдалося завантажити файл: $error");
        }
    }
}
