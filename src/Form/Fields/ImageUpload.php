<?php
declare(strict_types=1);
namespace FloCMS\Admin\Form\Fields;

final class ImageUpload extends FileUpload
{
    protected array $mimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    protected array $dimensions = ['max_width' => 8192, 'max_height' => 8192];
    public function dimensions(int $maxWidth, int $maxHeight, int $minWidth = 1, int $minHeight = 1): static
    {
        if ($minWidth < 1 || $minHeight < 1 || $maxWidth < $minWidth || $maxHeight < $minHeight || $maxWidth > 8192 || $maxHeight > 8192) { throw new \InvalidArgumentException('Image dimensions must be ordered and within 1–8192 pixels.'); }
        $this->dimensions = ['max_width' => $maxWidth, 'max_height' => $maxHeight, 'min_width' => $minWidth, 'min_height' => $minHeight]; return $this;
    }
}
