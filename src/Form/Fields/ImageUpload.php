<?php
declare(strict_types=1);
namespace FloCMS\Admin\Form\Fields;

final class ImageUpload extends FileUpload
{
    protected array $mimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    protected array $dimensions = ['max_width' => 8192, 'max_height' => 8192];
    protected ?int $square = null;
    protected bool $avatar = false;
    public function isAvatar(): bool { return $this->avatar; }
    /**
     * Crop the image to a centred square of $size pixels when it is saved
     * (0.28; needs PHP's GD extension, otherwise the image is kept as uploaded).
     */
    public function square(int $size = 256): static
    {
        if ($size < 16 || $size > 2048) { throw new \InvalidArgumentException('square() takes 16–2048 pixels.'); }
        $this->square = $size; return $this;
    }
    public function getSquare(): ?int { return $this->square; }
    /** A profile photo (0.28): public, a 256 px square, up to 2 MB, shown round. */
    public function avatar(): static
    {
        $this->avatar = true;
        return $this->public()->square(256)->maxSize(2048)->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp']);
    }
    public function dimensions(int $maxWidth, int $maxHeight, int $minWidth = 1, int $minHeight = 1): static
    {
        if ($minWidth < 1 || $minHeight < 1 || $maxWidth < $minWidth || $maxHeight < $minHeight || $maxWidth > 8192 || $maxHeight > 8192) { throw new \InvalidArgumentException('Image dimensions must be ordered and within 1–8192 pixels.'); }
        $this->dimensions = ['max_width' => $maxWidth, 'max_height' => $maxHeight, 'min_width' => $minWidth, 'min_height' => $minHeight]; return $this;
    }
}
