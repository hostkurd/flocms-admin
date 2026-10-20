<?php
declare(strict_types=1);

namespace FloCMS\Admin\Mailings;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Mail\Message;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;

/**
 * An email to a user written in the panel (0.28): the subject and the cleaned
 * rich text with {name}, {first_name}, {email} and {site} filled in, in the
 * panel's email style, with a plain-text part. A mailing's email has an
 * unsubscribe link and the List-Unsubscribe headers bulk mail needs.
 *
 * @internal
 */
final class MailMessage
{
    public const PLACEHOLDERS = ['{name}', '{first_name}', '{email}', '{site}'];

    /** Inline styles for the tags rich text can hold (email programs ignore style sheets). */
    private const STYLES = [
        'p' => 'margin:0 0 12px', 'h2' => 'margin:20px 0 10px;font-size:18px;line-height:1.35;color:#101828',
        'h3' => 'margin:18px 0 8px;font-size:16px;line-height:1.35;color:#101828', 'ul' => 'margin:0 0 12px;padding-inline-start:22px',
        'ol' => 'margin:0 0 12px;padding-inline-start:22px', 'li' => 'margin:0 0 4px', 'blockquote' => 'margin:0 0 12px;padding:4px 14px;border-inline-start:3px solid #d0d5dd;color:#475467',
    ];

    /** @param bool $mailing a mailing (unsubscribe link and headers) rather than a direct email */
    public static function make(string $subject, string $body, string $email, string $name, string $lang, bool $mailing, ?string $replyTo = null): Message
    {
        $values = self::values($email, $name);
        $subject = strtr($subject, $values);
        $html = self::html($body, $values);
        $brand = Admin::config()->brand();
        $headers = [];
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false) { $headers['Reply-To'] = $replyTo; }
        if ($mailing) {
            $url = \FloCMS\Admin\Auth\PasswordReset::absolute('mailings/unsubscribe/' . Mailings::unsubscribeToken($email));
            $headers['List-Unsubscribe'] = '<' . $url . '>';
            // No List-Unsubscribe-Post (one-click) yet: the panel's POST pages need a CSRF token,
            // so the link opens a page that asks once.
            $headers['Precedence'] = 'bulk';
            $footer = Str::e(AdminLang::get('mailings.footer', ['brand' => $brand])) . ' <a href="' . Str::e($url) . '" style="color:#475467">' . Str::e(AdminLang::get('mailings.unsubscribe')) . '</a>';
            $textFooter = AdminLang::get('mailings.footer', ['brand' => $brand]) . "\n" . AdminLang::get('mailings.unsubscribe') . ': ' . $url;
        } else {
            $footer = Str::e(AdminLang::get('mailings.direct_footer', ['email' => $email, 'brand' => $brand, 'name' => \FloCMS\Admin\Support\Actor::name() ?? $brand]));
            $textFooter = AdminLang::get('mailings.direct_footer', ['email' => $email, 'brand' => $brand, 'name' => \FloCMS\Admin\Support\Actor::name() ?? $brand]);
        }
        $document = \FloCMS\Core\TemplateEngine::renderFile(dirname(__DIR__, 2) . '/resources/mail/message.html', [
            'lang' => $lang, 'dir' => \FloCMS\Admin\Http\PanelView::direction($lang), 'subject' => $subject, 'heading' => '',
            'color' => Admin::config()->primaryColor() ?? '#0b6e91', 'brand' => $brand, 'initial' => mb_strtoupper(mb_substr(trim($brand), 0, 1)),
            'body' => $html, 'button' => null, 'footer' => $footer,
        ]);
        return new Message($email, $subject, self::text($html) . "\n\n-- \n" . $textFooter, $document, toName: $name !== '' ? $name : null, headers: $headers);
    }

    /** @return array<string, string> placeholder => value (plain text) */
    public static function values(string $email, string $name): array
    {
        $name = trim($name) !== '' ? trim($name) : $email;
        $first = preg_split('/\s+/u', $name)[0] ?? $name;
        return ['{name}' => $name, '{first_name}' => $first, '{email}' => $email, '{site}' => Admin::config()->brand()];
    }

    /**
     * The cleaned rich text with the placeholders filled in (escaped, in text
     * only, never inside a tag or a link) and inline styles for email.
     *
     * @param array<string, string> $values
     */
    public static function html(string $clean, array $values): string
    {
        $parts = preg_split('/(<[^>]*>)/', $clean, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $html = '';
        foreach ($parts as $part) {
            if (str_starts_with($part, '<')) {
                $html .= self::style($part);
                continue;
            }
            $html .= strtr($part, array_map(static fn (string $value): string => Str::e($value), $values));
        }
        return $html;
    }

    private static function style(string $tag): string
    {
        if (preg_match('/^<(p|h2|h3|ul|ol|li|blockquote|a)(\s[^>]*)?>$/i', $tag, $m) !== 1) { return $tag; }
        $name = strtolower($m[1]);
        $style = $name === 'a' ? 'color:' . (Admin::config()->primaryColor() ?? '#0b6e91') : self::STYLES[$name];
        $attributes = preg_replace('/\sstyle="[^"]*"/i', '', $m[2] ?? '') ?? '';
        return '<' . $name . $attributes . ' style="' . $style . '">';
    }

    /** The plain-text part: paragraphs and list items on their own lines, links with their address. */
    public static function text(string $html): string
    {
        $html = preg_replace('~<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>~is', '$2 ($1)', $html) ?? $html;
        $html = preg_replace('~<li[^>]*>~i', '- ', $html) ?? $html;
        $html = preg_replace('~<br\s*/?>~i', "\n", $html) ?? $html;
        $html = preg_replace('~</(p|h2|h3|li|blockquote)>~i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
