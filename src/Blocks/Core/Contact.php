<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Blocks\BlockRenderer;
use FloCMS\Admin\Form\Fields\Repeater;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Form\Layout\Tab;
use FloCMS\Admin\Support\AdminLang;

/**
 * How to reach the business (0.34): address, phone, email, WhatsApp, opening
 * hours and a map (Google Maps by address, or OpenStreetMap by coordinates)
 * that loads only when the visitor asks for it, unless "Load the map at once".
 */
final class Contact extends Block
{
    protected static string $type = 'contact';
    protected static string $category = 'site';
    protected static string $icon = 'map-pin';
    protected static ?string $label = 'blocks.contact';
    protected static ?string $description = 'blocks.contact_help';

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            Tab::make('content')->label($t('tab_content'))->columns(2)->schema([
                TextInput::make('heading')->label($t('heading'))->rules('max:160')->columnSpan('full'),
                Textarea::make('intro')->label($t('intro'))->rows(2)->rules('max:400')->columnSpan('full'),
                Textarea::make('address')->label($t('contact_address'))->rows(3)->rules('max:300')->columnSpan('full'),
                TextInput::make('phone')->label($t('contact_phone'))->rules('max:40'),
                TextInput::make('email')->label($t('contact_email'))->rules('max:160'),
                TextInput::make('whatsapp')->label($t('contact_whatsapp'))->help($t('contact_whatsapp_help'))->rules('max:40'),
            ]),
            Tab::make('hours')->label($t('contact_hours'))->columns(1)->schema([
                Repeater::make('hours')->label($t('contact_hours'))->schema([
                    TextInput::make('days')->label($t('contact_days'))->placeholder('Sun–Thu')->required()->rules('max:60'),
                    TextInput::make('time')->label($t('contact_time'))->placeholder('9:00–17:00')->rules('max:60'),
                ])->itemLabel('days')->addLabel($t('contact_hours_add'))->maxItems(14),
            ]),
            Tab::make('map')->label($t('contact_map'))->columns(2)->schema([
                Select::make('map')->label($t('contact_map'))->options(['none' => $t('contact_map_none'), 'google' => 'Google Maps', 'osm' => 'OpenStreetMap'])->default('google')->segmented()->columnSpan('full'),
                TextInput::make('location')->label($t('contact_location'))->help($t('contact_location_help'))->rules('max:300')->columnSpan('full')->shownWhen('map', ['google', 'osm']),
                Select::make('zoom')->label($t('contact_zoom'))->options(['12' => '12', '13' => '13', '14' => '14', '15' => '15', '16' => '16', '17' => '17', '18' => '18'])->default('15')->shownWhen('map', ['google', 'osm']),
                Select::make('layout')->label($t('contact_layout'))->options(['map-end' => $t('contact_map_end'), 'map-start' => $t('contact_map_start'), 'map-below' => $t('contact_map_below')])->default('map-end')->shownWhen('map', ['google', 'osm']),
                Toggle::make('map_now')->label($t('contact_map_now'))->help($t('contact_map_now_help'))->columnSpan('full')->shownWhen('map', ['google', 'osm']),
            ]),
        ];
    }

    public function title(array $fields): string
    {
        $heading = trim((string) ($fields['heading'] ?? ''));
        return $heading !== '' ? mb_strimwidth($heading, 0, 80, '…') : self::label();
    }

    /** "36.19, 44.01" as [lat, lng], or null. */
    public static function coordinates(string $value): ?array
    {
        if (preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $value, $m) !== 1) { return null; }
        [$lat, $lng] = [(float) $m[1], (float) $m[2]];
        return abs($lat) <= 90 && abs($lng) <= 180 ? [$lat, $lng] : null;
    }

    public function check(array $fields): array
    {
        $errors = [];
        $email = trim((string) ($fields['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) { $errors['email'] = AdminLang::get('blocks.contact_email_invalid'); }
        $map = (string) ($fields['map'] ?? 'google');
        $location = trim((string) ($fields['location'] ?? ''));
        if ($map === 'osm' && self::coordinates($location) === null) { $errors['location'] = AdminLang::get('blocks.contact_location_osm'); }
        if ($map === 'google' && $location === '' && trim((string) ($fields['address'] ?? '')) === '') { $errors['location'] = AdminLang::get('blocks.contact_location_missing'); }
        return $errors;
    }

    /** The links and the map's addresses for the template. */
    public function data(array $fields): array
    {
        $address = trim((string) ($fields['address'] ?? ''));
        $phone = trim((string) ($fields['phone'] ?? ''));
        $whatsapp = preg_replace('/\D+/', '', (string) ($fields['whatsapp'] ?? '')) ?? '';
        $email = trim((string) ($fields['email'] ?? ''));
        $place = trim((string) ($fields['location'] ?? '')) !== '' ? trim((string) $fields['location']) : preg_replace('/\s+/', ' ', $address) ?? '';
        $zoom = max(3, min(19, (int) ($fields['zoom'] ?? 15)));
        $map = in_array($fields['map'] ?? 'google', ['none', 'google', 'osm'], true) ? (string) ($fields['map'] ?? 'google') : 'google';
        $src = $link = '';
        if ($map === 'google' && $place !== '') {
            $src = 'https://www.google.com/maps?q=' . rawurlencode($place) . '&z=' . $zoom . '&output=embed';
            $link = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($place);
        } elseif ($map === 'osm' && ($point = self::coordinates($place)) !== null) {
            [$lat, $lng] = $point;
            $span = 0.6 / (2 ** ($zoom - 10));
            $bbox = implode(',', array_map(static fn (float $n): string => rtrim(rtrim(sprintf('%.6F', $n), '0'), '.'), [$lng - $span, $lat - $span / 2, $lng + $span, $lat + $span / 2]));
            $src = 'https://www.openstreetmap.org/export/embed.html?bbox=' . $bbox . '&layer=mapnik&marker=' . $lat . ',' . $lng;
            $link = 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '#map=' . $zoom . '/' . $lat . '/' . $lng;
        }
        $hours = array_values(array_filter(repeater_items($fields['hours'] ?? []), static fn (array $row): bool => trim((string) ($row['days'] ?? '')) !== ''));
        return [
            'tel' => $phone !== '' ? 'tel:' . (preg_replace('/[^\d+]/', '', $phone) ?? '') : '',
            'mailto' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? 'mailto:' . $email : '',
            'wa' => $whatsapp !== '' ? 'https://wa.me/' . $whatsapp : '',
            'directions' => $place !== '' ? 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($place) : '',
            'map_src' => $src, 'map_link' => BlockRenderer::safeUrl($link), 'map_host' => $map === 'osm' ? 'OpenStreetMap' : 'Google Maps',
            'hours_list' => $hours,
            'layout' => in_array($fields['layout'] ?? '', ['map-end', 'map-start', 'map-below'], true) ? (string) $fields['layout'] : 'map-end',
        ];
    }

    public function assets(): array { return ['contact']; }
}
