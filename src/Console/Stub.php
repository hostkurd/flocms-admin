<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

/** @internal */
final class Stub
{
    /** @param array<string, string> $replace */
    public static function render(string $name, array $replace): string
    {
        $content = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/stubs/' . $name . '.stub');
        foreach ($replace as $key => $value) {
            $content = str_replace('{{ ' . $key . ' }}', $value, $content);
        }

        return $content;
    }

    public static function write(string $path, string $content): bool
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        return file_put_contents($path, $content) !== false;
    }
}
