<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Extension;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\TextInput;

/** One class standing for many kinds of block, as a frontend package's components do (0.43). */
final class ComponentBlock extends Block
{
    public function __construct(private string $kind, private string $title, private string $group = 'site') {}

    public function blockType(): string { return 'site_' . $this->kind; }

    public function blockLabel(): string { return $this->title; }

    public function blockIcon(): string { return 'grid'; }

    public function blockCategory(): string { return $this->group; }

    public function blockDescription(): string { return 'The ' . $this->title . ' component'; }

    public function fields(): array
    {
        // Each kind has a field of its own, as components do
        return [TextInput::make('heading'), TextInput::make($this->kind . '_note')->default($this->kind . ' default')];
    }

    public function render(array $vars): ?string
    {
        return '<p class="component-' . $this->kind . '">' . htmlspecialchars((string) ($vars['heading'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>';
    }
}
