<?php
declare(strict_types=1);

namespace FloCMS\Admin\Settings;

use FloCMS\Admin\Admin;
use FloCMS\Admin\AdminConfig;
use FloCMS\Admin\Form\Fields\ColorPicker;
use FloCMS\Admin\Form\Fields\ImageUpload;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Layout\Section;
use FloCMS\Admin\Support\AdminLang;

/**
 * The built-in "Admin panel" settings page (keys panel.*). A saved value wins;
 * an empty one falls back to config/admin.php, then to the built-in default.
 * Turned off with 'panel_settings' => false in config/admin.php.
 */
final class PanelSettings
{
    public const DENSITIES = ['comfortable', 'compact'];

    public static function page(): SettingsPage
    {
        $config = Admin::config();
        $default = static fn (string $value): string => AdminLang::get('panel_settings.use_default', ['value' => $value]);
        $choices = static fn (array $values, string $prefix): array => array_combine($values, array_map(static fn (string $v): string => AdminLang::get($prefix . $v), $values));

        return SettingsPage::make('panel')
            ->label(AdminLang::get('panel_settings.label'))
            ->description(AdminLang::get('panel_settings.description'))
            ->icon('layers')
            ->permission('settings.panel')
            ->schema([
                Section::make('brand')->label(AdminLang::get('panel_settings.brand_section'))->schema([
                    TextInput::make('brand')->label(AdminLang::get('panel_settings.brand'))->translatable()->rules('max:80')
                        ->placeholder($config->brand())->help(AdminLang::get('panel_settings.brand_help')),
                    ColorPicker::make('primary_color')->label(AdminLang::get('panel_settings.primary_color'))
                        ->placeholder($config->primaryColor() ?? '#0b6e91')->help(AdminLang::get('panel_settings.primary_color_help')),
                    ImageUpload::make('logo')->label(AdminLang::get('panel_settings.logo'))->translatable()->maxSize(1024)
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/gif'])->columnSpan('full')
                        ->help(AdminLang::get('panel_settings.logo_help')),
                ]),
                Section::make('layout')->label(AdminLang::get('panel_settings.layout_section'))->schema([
                    Select::make('theme')->label(AdminLang::get('panel_settings.theme'))
                        ->options($choices(AdminConfig::THEMES, 'theme.'))->placeholder($default(AdminLang::get('theme.' . $config->theme()))),
                    Select::make('sidebar')->label(AdminLang::get('panel_settings.sidebar'))
                        ->options($choices(AdminConfig::SIDEBARS, 'panel_settings.sidebar_'))->placeholder($default(AdminLang::get('panel_settings.sidebar_' . $config->sidebar()))),
                    Select::make('content_width')->label(AdminLang::get('panel_settings.content_width'))
                        ->options($choices(AdminConfig::WIDTHS, 'panel_settings.width_'))->placeholder($default(AdminLang::get('panel_settings.width_' . $config->contentWidth()))),
                    Select::make('density')->label(AdminLang::get('panel_settings.density'))
                        ->options($choices(self::DENSITIES, 'density.'))->placeholder($default(AdminLang::get('density.' . $config->density())))
                        ->help(AdminLang::get('panel_settings.density_help')),
                ]),
            ]);
    }
}
