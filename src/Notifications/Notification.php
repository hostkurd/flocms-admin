<?php
declare(strict_types=1);
namespace FloCMS\Admin\Notifications;

use FloCMS\Admin\{Support\AdminLang, Translation\Languages};

/**
 * A text-only notification. Labels may use site language keys with :placeholders.
 *
 * send() shows it now to the person (a toast). sendTo() (0.41) stores it for
 * other people: their bell counts it until they read it.
 */
final class Notification
{
    private string $title = '';
    private string $body = '';
    /** @var array<string, string> */
    private array $params = [];
    private ?string $url = null;
    private string $level = 'info';
    private ?int $duration = null;
    /** @var array{label: string, url: string}|null */
    private ?array $action = null;
    private ?string $icon = null;

    /** Seconds before success and info notifications close by themselves. */
    public const DURATION = 5;
    public static function make(): self { return new self(); }
    /**
     * The title: plain text or a lang key; $replace fills its :placeholders
     * (also those of the body), in each reader's language.
     *
     * @param array<string, scalar|null> $replace
     */
    public function title(string $title, array $replace = []): self { $this->title = $title; return $this->with($replace); }
    /** @param array<string, scalar|null> $replace */
    public function body(string $body, array $replace = []): self { $this->body = $body; return $this->with($replace); }
    /** @param array<string, scalar|null> $replace */
    private function with(array $replace): self
    {
        foreach ($replace as $key => $value) {
            if (is_string($key) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) === 1) {
                $this->params[$key] = mb_strcut((string) $value, 0, 1000, 'UTF-8');
            }
        }
        return $this;
    }
    /**
     * The page a stored notification opens (0.41): a panel path such as
     * "inquiries/edit/5", a panel URL, or a path of this website ("/en/contact").
     * Other sites are refused.
     */
    public function url(string $url): self
    {
        $target = Target::normalize($url);
        if ($target === null) { throw new \InvalidArgumentException('Notification::url() takes a panel path (inquiries/edit/5) or an address of this website.'); }
        $this->url = $target;
        return $this;
    }
    public function success(): self { $this->level = 'success'; return $this; }
    public function warning(): self { $this->level = 'warning'; return $this; }
    public function danger(): self { $this->level = 'danger'; return $this; }
    public function info(): self { $this->level = 'info'; return $this; }
    /** A plain notice without a colour (the trash's "Listing deleted." with Undo). */
    public function neutral(): self { $this->level = 'neutral'; return $this; }
    /** An icon name from the panel's set (Icons), shown before the title. */
    public function icon(string $name): self { $this->icon = in_array($name, \FloCMS\Admin\Support\Icons::names(), true) ? $name : null; return $this; }
    /**
     * One button that POSTs to a panel URL (with the CSRF token), e.g. Undo. A
     * notification with an action shows a bar counting down its duration.
     */
    public function action(string $label, string $url): self
    {
        if (!str_starts_with($url, \FloCMS\Admin\Admin::url('') )) { throw new \InvalidArgumentException('Notification actions point to the admin panel.'); }
        $this->action = ['label' => $label, 'url' => $url];
        return $this;
    }
    /** Seconds on screen before it closes itself (hover or focus pauses it); 0 keeps it until dismissed. */
    public function duration(int $seconds): self { $this->duration = max(0, min(600, $seconds)); return $this; }
    /** Keep it until the user closes it (the default for warning and danger). */
    public function persistent(): self { return $this->duration(0); }

    /** How long warnings and errors stay (0.34): longer, so they are read; ->persistent() keeps one until closed. */
    public const LONG_DURATION = 12;

    /**
     * Success and info close after DURATION seconds; warnings and errors (0.34)
     * after LONG_DURATION, paused while the pointer is over them or they have
     * focus. Errors beside a form's fields are not toasts and always stay.
     */
    public static function defaultDuration(string $level): int
    {
        return in_array($level, ['warning', 'danger'], true) ? self::LONG_DURATION : self::DURATION;
    }
    public function send(): void
    {
        if (trim($this->title) === '') { throw new \InvalidArgumentException('Notifications need a title.'); }
        $lang = AdminLang::language();
        $text = fn (string $value): string => $this->params === [] ? AdminLang::label($value) : \FloCMS\Core\Lang::replace(AdminLang::label($value), $this->params);
        Notifications::push([
            'id' => bin2hex(random_bytes(16)), 'level' => $this->level,
            'duration' => $this->duration ?? self::defaultDuration($this->level),
            'title' => mb_strcut($text($this->title), 0, 512, 'UTF-8'),
            'body' => mb_strcut($this->body === '' ? '' : $text($this->body), 0, 4096, 'UTF-8'),
            'lang' => $lang, 'dir' => Languages::direction($lang),
            'dismiss' => AdminLang::get('notifications.dismiss'), 'issued' => time(),
            'action' => $this->action === null ? null : ['label' => mb_strcut(AdminLang::label($this->action['label']), 0, 80, 'UTF-8'), 'url' => $this->action['url']],
            'icon' => $this->icon,
        ]);
    }

    /**
     * Store it for these people (0.41): each one's bell counts it until they
     * open or mark it read. The text is kept as written (a lang key stays a
     * key), so each person reads it in their own panel language. Returns how
     * many people got it; 0 when nobody matched or the notifications table is
     * missing (that is logged, so a form that notifies never fails because of it).
     *
     * @param Recipients|list<int>|int $recipients
     */
    public function sendTo(Recipients|array|int $recipients): int
    {
        if (trim($this->title) === '') { throw new \InvalidArgumentException('Notifications need a title.'); }
        $recipients = $recipients instanceof Recipients ? $recipients : Recipients::users($recipients);
        try {
            if (!Inbox::enabled()) {
                if (Inbox::status() === 'missing') { \FloCMS\Admin\Support\Log::error('A notification was not stored: the notifications table is missing (php flo admin:install, php flo migrate).'); }
                return 0;
            }
            return Inbox::store($recipients->ids(), [
                'level' => $this->level, 'icon' => $this->icon, 'title' => $this->title, 'body' => $this->body,
                'params' => $this->params, 'url' => $this->url,
            ]);
        } catch (\Throwable $error) {
            \FloCMS\Admin\Support\Log::error('A notification could not be stored: ' . $error->getMessage());
            return 0;
        }
    }
}
