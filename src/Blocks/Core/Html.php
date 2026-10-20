<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\Hidden;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Core\Auth;

/**
 * Raw HTML, printed on the website as written (0.29). Only people with the
 * blocks.html permission add or change it; for everyone else an existing
 * HTML block is read-only and keeps its markup (a signed copy travels with
 * the form, so it cannot be swapped for other markup).
 */
final class Html extends Block
{
    protected static string $type = 'html';
    protected static string $category = 'other';
    protected static string $icon = 'code';
    protected static ?string $label = 'blocks.html';
    protected static ?string $description = 'blocks.html_help';

    public const PERMISSION = 'blocks.html';
    public const MAX_BYTES = 60000;

    public static function allowed(): bool { return Auth::can(self::PERMISSION); }

    public function fields(): array
    {
        $html = Textarea::make('html')->label(AdminLang::get('blocks.html'))->rows(14)->rules('max:' . self::MAX_BYTES)->columnSpan('full')
            ->help(AdminLang::get(self::allowed() ? 'blocks.html_field_help' : 'blocks.html_locked'));
        return self::allowed() ? [$html] : [$html->disabled(), Hidden::make('_signed')];
    }

    /** The state as it goes into the form: a locked copy carries its signature, for this block only. */
    public function prepareState(array $state, string $id = ''): array
    {
        if (!self::allowed()) { $state['_signed'] = \FloCMS\Admin\Form\Fields\Builder::sign((string) ($state['html'] ?? ''), 'html|' . $id); }
        return $state;
    }

    /** Submitted values: without the permission only this block's signed, unchanged copy is kept. */
    public function acceptInput(array $state, string $id = ''): array
    {
        if (!self::allowed()) {
            $signed = (string) ($state['_signed'] ?? '');
            $html = \FloCMS\Admin\Form\Fields\Builder::unsign($signed, 'html|' . $id);
            // No copy: a new HTML block, which only people with the permission add. A changed copy: reload.
            if ($html === null) { $state['_rejected'] = $signed === '' ? 'new' : 'stale'; }
            $state['html'] = $html ?? '';
        }
        unset($state['_signed']);
        return $state;
    }

    public function title(array $fields): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($fields['html'] ?? ''))) ?? '');
        return $text !== '' ? mb_strimwidth($text, 0, 80, '…') : AdminLang::get('blocks.html');
    }
}
