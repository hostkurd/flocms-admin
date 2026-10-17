<?php
declare(strict_types=1);
namespace FloCMS\Admin\Notifications;

use FloCMS\Admin\{Support\AdminLang, Translation\Languages};

/** A session-delivered, text-only notification. Labels may use site language keys. */
final class Notification
{
    private string $title = '';
    private string $body = '';
    private string $level = 'info';
    private ?int $duration = null;

    /** Seconds before success and info notifications close by themselves. */
    public const DURATION = 5;
    public static function make(): self { return new self(); }
    public function title(string $title): self { $this->title = $title; return $this; }
    public function body(string $body): self { $this->body = $body; return $this; }
    public function success(): self { $this->level = 'success'; return $this; }
    public function warning(): self { $this->level = 'warning'; return $this; }
    public function danger(): self { $this->level = 'danger'; return $this; }
    public function info(): self { $this->level = 'info'; return $this; }
    /** Seconds on screen before it closes itself (hover or focus pauses it); 0 keeps it until dismissed. */
    public function duration(int $seconds): self { $this->duration = max(0, min(600, $seconds)); return $this; }
    /** Keep it until the user closes it (the default for warning and danger). */
    public function persistent(): self { return $this->duration(0); }

    /** Success and info close after DURATION seconds; warning and danger stay until dismissed. */
    public static function defaultDuration(string $level): int
    {
        return in_array($level, ['warning', 'danger'], true) ? 0 : self::DURATION;
    }
    public function send(): void
    {
        if (trim($this->title) === '') { throw new \InvalidArgumentException('Notifications need a title.'); }
        $lang = AdminLang::language();
        Notifications::push([
            'id' => bin2hex(random_bytes(16)), 'level' => $this->level,
            'duration' => $this->duration ?? self::defaultDuration($this->level),
            'title' => mb_strcut(AdminLang::label($this->title), 0, 512, 'UTF-8'),
            'body' => mb_strcut(AdminLang::label($this->body), 0, 4096, 'UTF-8'),
            'lang' => $lang, 'dir' => Languages::direction($lang),
            'dismiss' => AdminLang::get('notifications.dismiss'), 'issued' => time(),
        ]);
    }
}
