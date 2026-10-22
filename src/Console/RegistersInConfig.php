<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

/** Adds a class to a list in config/admin.php ('widgets', 'blocks'), keeping the file valid PHP. */
trait RegistersInConfig
{
    /** Add the class to a list ('widgets', 'blocks') in config/admin.php, or say how to. */
    private function register(string $configFile, string $class, string $key, bool $addKey = false): void
    {
        $line = '\\' . $class . '::class,';
        $manual = "Add it to config/admin.php:\n    '" . $key . "' => [\n        " . $line . "\n    ],";
        if (!is_file($configFile)) {
            $this->note('config/admin.php is missing: run php flo admin:install, then ' . lcfirst($manual));
            return;
        }
        $code = (string) file_get_contents($configFile);
        $short = substr($class, (int) strrpos($class, '\\') + 1);
        if (preg_match('/[\'"]' . preg_quote($key, '/') . '[\'"]\s*=>\s*\[(.*?)\]/s', $code, $match, PREG_OFFSET_CAPTURE) !== 1) {
            // A list added after the file was written (0.36: 'admin_pages'): add it before the closing "];".
            $end = strrpos($code, '];');
            if (!$addKey || $end === false || preg_match('/[\'"]' . preg_quote($key, '/') . '[\'"]\s*=>/', $code) === 1) {
                $this->note($manual);
                return;
            }
            $before = rtrim(substr($code, 0, $end));
            $comma = str_ends_with($before, ',') || str_ends_with($before, '[') ? '' : ',';
            $updated = $before . $comma . "\n\n    '" . $key . "' => [\n        " . $line . "\n    ],\n" . substr($code, $end);
            $this->writeConfig($configFile, $updated, $manual, $short);
            return;
        }
        $list = $match[1][0];
        // Already listed (with or without a leading backslash, ignoring comments).
        $active = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $list) ?? $list;
        if (preg_match('/\\\\?' . preg_quote($class, '/') . '::class/', $active) === 1) {
            $this->note($short . ' is already registered in config/admin.php.');
            return;
        }
        $insertAt = $match[1][1] + strlen(rtrim($list));
        // The last entry may lack a trailing comma: add one (a syntax problem is caught below).
        $comma = trim($active) !== '' && !str_ends_with(rtrim($active), ',') && str_ends_with(rtrim($list), '::class') ? ',' : '';
        $indent = preg_match('/\n([ \t]*)[\'"]' . preg_quote($key, '/') . '[\'"]/', $code, $m) === 1 ? $m[1] : '    ';
        $updated = substr($code, 0, $insertAt) . $comma . "\n" . $indent . '    ' . $line . ($list === '' ? "\n" . $indent : '') . substr($code, $insertAt);
        $this->writeConfig($configFile, $updated, $manual, $short);
    }

    private function writeConfig(string $configFile, string $updated, string $manual, string $short): void
    {
        $tmp = $configFile . '.tmp';
        if (file_put_contents($tmp, $updated) === false || !$this->validPhp($tmp) || !rename($tmp, $configFile)) {
            @unlink($tmp);
            $this->note($manual);
            return;
        }
        $this->success('Registered ' . $short . ' in config/admin.php');
    }

    private function validPhp(string $file): bool
    {
        if (!function_exists('exec')) {
            return true;
        }
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);

        return $status === 0;
    }
}
