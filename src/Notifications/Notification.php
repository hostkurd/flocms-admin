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
    public static function make(): self { return new self(); }
    public function title(string $title): self { $this->title = $title; return $this; }
    public function body(string $body): self { $this->body = $body; return $this; }
    public function success(): self { $this->level = 'success'; return $this; }
    public function warning(): self { $this->level = 'warning'; return $this; }
    public function danger(): self { $this->level = 'danger'; return $this; }
    public function info(): self { $this->level = 'info'; return $this; }
    public function send(): void
    {
        if (trim($this->title) === '') { throw new \InvalidArgumentException('Notifications need a title.'); }
        $lang = AdminLang::language();
        Notifications::push([
            'id' => bin2hex(random_bytes(16)), 'level' => $this->level,
            'title' => mb_strcut(AdminLang::label($this->title), 0, 512, 'UTF-8'),
            'body' => mb_strcut(AdminLang::label($this->body), 0, 4096, 'UTF-8'),
            'lang' => $lang, 'dir' => Languages::direction($lang),
            'dismiss' => AdminLang::get('notifications.dismiss'), 'issued' => time(),
        ]);
    }
}
