<?php
declare(strict_types=1);

namespace App;

final class Log
{
    private static ?string $path = null;

    public static function init(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        self::$path = $path;
    }

    public static function error(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        if (self::$path !== null) {
            @file_put_contents(self::$path, $line, FILE_APPEND | LOCK_EX);
        }
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $line);
        }
    }
}
