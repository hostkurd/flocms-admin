<?php
declare(strict_types=1);

use FloCMS\Admin\Form\Fields\{ImageUpload, KeyValue, Select, TextInput, Textarea, Toggle};
use FloCMS\Admin\Form\Layout\Section;
use FloCMS\Admin\Settings\SettingsPage;

return [
    SettingsPage::make('site')->label('General')->icon('settings')->description('Name, tagline and logo')->schema([
        TextInput::make('title')->translatable()->required()->rules('max:60'),
        Textarea::make('tagline')->translatable()->default('Homes by the sea'),
        TextInput::make('phone')->tel(),
        Toggle::make('open'),
        Select::make('currency')->options(['eur' => 'Euro', 'usd' => 'Dollar']),
        ImageUpload::make('logo')->translatable(),
    ]),
    SettingsPage::make('contact')->label('Contact & locations')->icon('map-pin')->permission('settings.contact')->schema([
        Section::make('office')->schema([TextInput::make('email')->email()]),
        KeyValue::make('links')->label('Social links')->keyLabel('Network')->valueLabel('Address')->addLabel('Add link'),
    ]),
    SettingsPage::make('seo')->label('SEO')->icon('search')->translationBadges()->schema([
        TextInput::make('meta_title')->translatable(),
    ]),
];
