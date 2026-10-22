<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\Repeater;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Form\Layout\Tab;
use FloCMS\Admin\Support\AdminLang;

/**
 * Questions that open and close (0.34), on the browser's own <details>: the
 * first open, one open at a time (the "name" attribute), and FAQPage data for
 * search engines. Answers are plain text: paragraphs, line breaks and links
 * (http, https, mailto) become HTML.
 */
final class Faq extends Block
{
    protected static string $type = 'faq';
    protected static string $category = 'text';
    protected static string $icon = 'help-circle';
    protected static ?string $label = 'blocks.faq';
    protected static ?string $description = 'blocks.faq_help';

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            Tab::make('content')->label($t('tab_content'))->columns(1)->schema([
                TextInput::make('heading')->label($t('heading'))->rules('max:160'),
                Textarea::make('intro')->label($t('intro'))->rows(2)->rules('max:400'),
                Toggle::make('open_first')->label($t('faq_open_first'))->default('1'),
                Toggle::make('one_open')->label($t('faq_one_open'))->help($t('faq_one_open_help'))->default('1'),
                Toggle::make('schema')->label($t('faq_schema'))->help($t('faq_schema_help'))->default('1'),
            ]),
            Tab::make('questions')->label($t('faq_questions'))->columns(1)->schema([
                Repeater::make('items')->label($t('faq_questions'))->schema([
                    TextInput::make('question')->label($t('faq_question'))->required()->rules('max:300')->columnSpan('full'),
                    Textarea::make('answer')->label($t('faq_answer'))->help($t('faq_answer_help'))->rows(4)->required()->rules('max:3000')->columnSpan('full'),
                ])->itemLabel('question')->addLabel($t('faq_add'))->collapsed()->minItems(1)->maxItems(30),
            ]),
        ];
    }

    public function title(array $fields): string
    {
        $heading = trim((string) ($fields['heading'] ?? ''));
        return $heading !== '' ? mb_strimwidth($heading, 0, 80, '…') : self::label();
    }

    /** Plain text as HTML: paragraphs at blank lines, <br> at line breaks, links for web and mail addresses. */
    public static function answerHtml(string $text): string
    {
        $paragraphs = preg_split('/\R\s*\R/u', trim($text)) ?: [];
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $escaped = htmlspecialchars(trim($paragraph), ENT_QUOTES, 'UTF-8');
            $linked = preg_replace_callback('~\b(https?://[^\s<>"\']+[^\s<>"\'.,;:!?)\]]|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})~u', static function (array $m): string {
                $href = str_contains($m[1], '@') && !str_starts_with($m[1], 'http') ? 'mailto:' . $m[1] : $m[1];
                return '<a href="' . $href . '">' . $m[1] . '</a>';
            }, $escaped) ?? $escaped;
            $html .= '<p>' . (preg_replace('/\R/u', '<br>', $linked) ?? $linked) . '</p>';
        }
        return $html;
    }

    public function data(array $fields): array
    {
        $items = [];
        foreach (repeater_items($fields['items'] ?? []) as $item) {
            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));
            if ($question === '' || $answer === '') { continue; }
            $items[] = ['question' => $question, 'answer' => self::answerHtml($answer), 'plain' => $answer];
        }
        $schema = '';
        if (($fields['schema'] ?? '1') === '1' && $items !== []) {
            // Search engines' FAQPage data; a data block, not a script, so a strict CSP allows it.
            $schema = (string) json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(static fn (array $item): array => [
                '@type' => 'Question', 'name' => $item['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['plain']],
            ], $items)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        }
        return ['questions' => $items, 'schema_json' => $schema];
    }

    /** faq.js: a link to a question opens it. */
    public function assets(): array { return ['faq']; }
}
