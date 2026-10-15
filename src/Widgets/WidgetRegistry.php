<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Admin, AdminConfig};

/** Explicit class allowlist, bounded definitions, deterministic configuration order. */
final class WidgetRegistry
{
    private array $widgets = [];
    private array $problems = [];
    private bool $configured = false;
    private static array $autoloaders = [];
    public static function fromConfig(AdminConfig $config): self
    {
        $result = new self(); $classes = $config->all()['widgets'] ?? [];
        $result->configured = $classes !== [];
        if (!is_array($classes) || !array_is_list($classes) || count($classes) > 16) { $result->problems[] = 'widgets must be a list of at most 16 widget classes'; return $result; }
        $namespace = trim((string) ($config->all()['widgets_namespace'] ?? 'App\\Admin\\Widgets'), '\\');
        $path = (string) ($config->all()['widgets_path'] ?? 'admin/Widgets');
        $absolute = str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[/\\\\]~', $path);
        $path = rtrim($absolute ? $path : $config->root() . '/' . $path, '/\\');
        $key = $namespace . ':' . $path;
        if ($namespace !== '' && !isset(self::$autoloaders[$key])) {
            self::$autoloaders[$key] = true;
            spl_autoload_register(static function (string $class) use ($namespace, $path): void {
                $prefix = $namespace . '\\'; if (!str_starts_with($class, $prefix)) { return; }
                $suffix = substr($class, strlen($prefix)); if (preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $suffix) !== 1) { return; }
                $file = $path . '/' . str_replace('\\', '/', $suffix) . '.php'; if (is_file($file)) { require_once $file; }
            });
        }
        try { $config->widgetColumns(); } catch (\InvalidArgumentException $error) { $result->problems[] = $error->getMessage(); }
        $seen = [];
        foreach ($classes as $class) {
            try {
                if (!is_string($class) || !is_subclass_of($class, Widget::class) || !(new \ReflectionClass($class))->isInstantiable()) { throw new \InvalidArgumentException('must be an instantiable Widget subclass'); }
                $class = (new \ReflectionClass($class))->getName();
                if (isset($seen[$class])) { throw new \InvalidArgumentException('duplicate widget class'); } $seen[$class] = true;
                $widget = new $class();
                if (!$widget instanceof StatsWidget && !$widget instanceof RecordsWidget && !$widget instanceof ChartWidget) { throw new \InvalidArgumentException('extend StatsWidget, RecordsWidget or ChartWidget'); }
                $errors = $widget->configurationErrors(); if ($errors !== []) { throw new \InvalidArgumentException(implode('; ', $errors)); }
                $result->widgets[] = $widget;
            } catch (\Throwable $error) { $result->problems[] = (is_string($class) ? $class : get_debug_type($class)) . ': ' . $error->getMessage(); }
        }
        usort($result->widgets, static fn (Widget $a, Widget $b): int => $a->sort() <=> $b->sort());
        return $result;
    }
    public function configured(): bool { return $this->configured; }
    public function all(): array { return $this->widgets; }
    public function problems(): array { return $this->problems; }
}
