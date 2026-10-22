<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Blocks\BlockRenderer;
use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Form\Fields\Repeater;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Form\Layout\Tab;
use FloCMS\Admin\Support\AdminLang;

/** People with photo, role, a short bio and their links (0.34). */
final class Team extends Block
{
    protected static string $type = 'team';
    protected static string $category = 'marketing';
    protected static string $icon = 'users';
    protected static ?string $label = 'blocks.team';
    protected static ?string $description = 'blocks.team_help';

    /** Host → [kind, name] for a link's icon and label. */
    private const NETWORKS = ['linkedin.com' => ['linkedin', 'LinkedIn'], 'x.com' => ['x', 'X'], 'twitter.com' => ['x', 'X'], 'instagram.com' => ['instagram', 'Instagram'],
        'facebook.com' => ['facebook', 'Facebook'], 'github.com' => ['github', 'GitHub'], 'youtube.com' => ['youtube', 'YouTube'], 'tiktok.com' => ['tiktok', 'TikTok']];

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            Tab::make('content')->label($t('tab_content'))->columns(2)->schema([
                TextInput::make('heading')->label($t('heading'))->rules('max:160')->columnSpan('full'),
                Textarea::make('intro')->label($t('intro'))->rows(2)->rules('max:400')->columnSpan('full'),
                Select::make('columns')->label($t('per_row'))->options(['2' => '2', '3' => '3', '4' => '4'])->default('4')->segmented(),
                Select::make('shape')->label($t('team_shape'))->options(['square' => $t('shape_square'), 'round' => $t('shape_round')])->default('square')->segmented(),
            ]),
            Tab::make('people')->label($t('team_people'))->columns(1)->schema([
                Repeater::make('people')->label($t('team_people'))->schema([
                    MediaPicker::make('photo')->label($t('photo'))->columnSpan('full'),
                    TextInput::make('name')->label($t('person_name'))->required()->rules('max:120'),
                    TextInput::make('role')->label($t('person_role'))->rules('max:160'),
                    Textarea::make('bio')->label($t('team_bio'))->rows(2)->rules('max:300')->columnSpan('full'),
                    TextInput::make('email')->label($t('contact_email'))->rules('max:160'),
                    TextInput::make('phone')->label($t('contact_phone'))->rules('max:40'),
                    TextInput::make('link')->label($t('team_link'))->help($t('team_link_help'))->rules('max:300')->columnSpan('full'),
                    TextInput::make('link2')->label($t('team_link2'))->rules('max:300'),
                    TextInput::make('link3')->label($t('team_link3'))->rules('max:300'),
                ])->itemLabel('name')->addLabel($t('team_add'))->collapsed()->minItems(1)->maxItems(40),
            ]),
        ];
    }

    public function title(array $fields): string
    {
        $heading = trim((string) ($fields['heading'] ?? ''));
        return $heading !== '' ? mb_strimwidth($heading, 0, 80, '…') : self::label();
    }

    /** A link's icon and the name read out ("Sara on LinkedIn"). @return array{kind: string, name: string} */
    public static function network(string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^(www\.|m\.)/', '', $host) ?? $host;
        foreach (self::NETWORKS as $domain => [$kind, $name]) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) { return ['kind' => $kind, 'name' => $name]; }
        }
        return ['kind' => 'website', 'name' => AdminLang::get('blocks.site_website')];
    }

    public function data(array $fields): array
    {
        $people = [];
        foreach (repeater_items($fields['people'] ?? []) as $person) {
            $name = trim((string) ($person['name'] ?? ''));
            if ($name === '') { continue; }
            $links = [];
            $email = trim((string) ($person['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && preg_match('/[?&#%]/', $email) !== 1) { $links[] = ['url' => 'mailto:' . $email, 'kind' => 'mail', 'label' => AdminLang::get('blocks.site_email', ['name' => $name])]; }
            $phone = trim((string) ($person['phone'] ?? ''));
            if (($tel = preg_replace('/[^\d+]/', '', $phone) ?? '') !== '') { $links[] = ['url' => 'tel:' . $tel, 'kind' => 'phone', 'label' => AdminLang::get('blocks.site_call', ['name' => $name])]; }
            foreach (['link', 'link2', 'link3'] as $key) {
                $url = BlockRenderer::safeUrl((string) ($person[$key] ?? ''));
                if ($url === '' || !preg_match('~^https?://~i', $url)) { continue; }
                $network = self::network($url);
                $links[] = ['url' => $url, 'kind' => $network['kind'], 'label' => AdminLang::get('blocks.site_on', ['name' => $name, 'site' => $network['name']])];
            }
            // The computed values win over anything stored under the same names.
            $people[] = array_merge($person, ['initials' => Testimonials::initials($name), 'links' => $links]);
        }
        return ['members' => $people];
    }
}
