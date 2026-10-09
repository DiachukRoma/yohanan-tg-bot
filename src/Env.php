<?php
declare(strict_types=1);

namespace App;

/**
 * Мінімальний завантажувач .env.
 *
 *   KEY=value              — без лапок, # після пробілу вважається коментарем
 *   KEY="рядок\nз переносом" — у подвійних лапках працюють \n, \" і \\
 *   KEY='як є'             — в одинарних лапках нічого не обробляється
 *
 * Справжні змінні оточення (з вебсервера чи системи) мають пріоритет над .env.
 */
final class Env
{
    private static array $vars = [];

    public static function load(string $path): void
    {
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $i => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                throw new \RuntimeException('.env, рядок ' . ($i + 1) . ': очікується KEY=value');
            }
            self::$vars[$m[1]] = self::parseValue($m[2], $i + 1);
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        return self::$vars[$key] ?? $default;
    }

    public static function required(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("У .env не задано $key");
        }
        return $value;
    }

    private static function parseValue(string $raw, int $lineNo): string
    {
        if ($raw === '') {
            return '';
        }

        if ($raw[0] === '"') {
            if (!preg_match('/^"((?:[^"\\\\]|\\\\.)*)"\s*(?:#.*)?$/', $raw, $m)) {
                throw new \RuntimeException(".env, рядок $lineNo: не закрито подвійні лапки");
            }
            return strtr($m[1], ['\\n' => "\n", '\\"' => '"', '\\\\' => '\\']);
        }

        if ($raw[0] === "'") {
            if (!preg_match("/^'([^']*)'\\s*(?:#.*)?$/", $raw, $m)) {
                throw new \RuntimeException(".env, рядок $lineNo: не закрито одинарні лапки");
            }
            return $m[1];
        }

        return trim(preg_replace('/\s+#.*$/', '', $raw));
    }
}
