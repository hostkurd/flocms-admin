<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Media\MediaLibrary;
use FloCMS\Admin\Support\AdminLang;

/** One image from the media library, with a caption and a link (0.29). */
final class Image extends Block
{
    protected static string $type = 'image';
    protected static string $category = 'media';
    protected static string $icon = 'image';
    protected static ?string $label = 'blocks.image';
    protected static ?string $description = 'blocks.image_help';

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            MediaPicker::make('media')->label($t('image'))->required()->columnSpan('full'),
            TextInput::make('caption')->label($t('caption'))->rules('max:300')->columnSpan('full'),
            TextInput::make('link')->label($t('link'))->rules('max:500'),
            Select::make('size')->label($t('image_size'))->options(['content' => $t('size_content'), 'wide' => $t('size_wide'), 'full' => $t('width_full')])->default('content')->segmented(),
        ];
    }

    public function title(array $fields): string
    {
        $caption = trim((string) ($fields['caption'] ?? ''));
        if ($caption !== '') { return mb_strimwidth($caption, 0, 80, '…'); }
        $id = (int) ($fields['media'] ?? 0);
        $store = $id > 0 ? MediaLibrary::storeOrNull() : null;
        $item = $store?->find($id);
        if ($item !== null) { return $item->title !== '' ? $item->title : $item->fileName; }
        return AdminLang::get('blocks.image');
    }
}
