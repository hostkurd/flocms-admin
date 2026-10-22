<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Support\AdminLang;

/** A grid of images that opens them large (0.29). */
final class Gallery extends Block
{
    protected static string $type = 'gallery';
    protected static string $category = 'media';
    protected static string $icon = 'gallery';
    protected static ?string $label = 'blocks.gallery';
    protected static ?string $description = 'blocks.gallery_help';

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            TextInput::make('heading')->label($t('heading'))->rules('max:160')->columnSpan('full'),
            MediaPicker::make('images')->label($t('gallery_images'))->multiple()->max(60)->required()->columnSpan('full'),
            Select::make('columns')->label($t('gallery_columns'))->options(['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6'])->default('3')->segmented(),
            Toggle::make('lightbox')->label($t('gallery_lightbox'))->default('1'),
            // 0.34: each image's title from the media library, under it and in the larger view.
            Toggle::make('captions')->label($t('gallery_captions'))->help($t('gallery_captions_help')),
        ];
    }

    public function assets(): array { return ['gallery']; }
}
