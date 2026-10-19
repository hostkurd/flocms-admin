<?php
declare(strict_types=1);

namespace FloCMS\Admin\ImportExport;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\Database;

/**
 * What a resource's CSV file holds (0.25): the export's columns and the
 * import's targets, built from the form's fields, and how a value of each field
 * type is written to a cell and read back from one.
 *
 * Headings use field names ("title.ar", "price", "city_id"), so an exported file
 * imports back as it is; readable headings use labels ("Title (العربية)").
 */
final class Schema
{
    public function __construct(
        private readonly Resource $resource,
        private readonly Form $form,
        private readonly Database $db
    ) {
    }

    public static function for(Resource $resource, Database $db): self
    {
        return new self($resource, $resource->form(new Form()), $db);
    }

    public function form(): Form { return $this->form; }
    public function resource(): Resource { return $this->resource; }

    /* ---------------------------------------------------------------
     * Export
     * ------------------------------------------------------------- */

    /** The stored fields an export writes, in form order. @return array<string, Field> */
    public function exportFields(Export $export): array
    {
        $fields = [];
        foreach ($this->form->fields() as $name => $field) {
            if (in_array($name, $export->getExcept(), true) || $field->isHiddenFromExport() || $field->isOmitted()) { continue; }
            if ($field instanceof Fields\Placeholder || ($field instanceof Fields\TextInput && $field->isPassword())) { continue; }
            if ($name === $this->resource::getPrimaryKey()) { continue; }
            $fields[$name] = $field;
        }
        return $fields;
    }

    /**
     * The export's columns: heading, readable heading and the cell of a row.
     *
     * @return list<array{heading: string, label: string, cell: \Closure(object, ExportBatch): (string|array{number: string})}>
     */
    public function exportColumns(Export $export): array
    {
        $pk = $this->resource::getPrimaryKey();
        $columns = [[
            'heading' => $pk, 'label' => AdminLang::get('export.id'),
            'cell' => static fn (object $row, ExportBatch $batch): string|array => self::number((string) ($row->{$pk} ?? '')),
        ]];
        $languages = Languages::all();
        foreach ($this->exportFields($export) as $name => $field) {
            $label = strip_tags($field->getLabel());
            if ($field->isTranslatable()) {
                foreach ($languages as $lang) {
                    $columns[] = [
                        'heading' => $name . '.' . $lang, 'label' => $label . ' (' . Languages::name($lang) . ')',
                        'cell' => static fn (object $row, ExportBatch $batch): string => (string) (Translations::store()->decode($row->{$name} ?? null, Languages::default())[$lang] ?? ''),
                    ];
                }
                continue;
            }
            if ($field instanceof Fields\BelongsTo) {
                $columns[] = ['heading' => $name, 'label' => $label, 'cell' => static fn (object $row, ExportBatch $batch): string => (string) ($row->{$name} ?? '')];
                if ($field->hasRelation()) {
                    $columns[] = ['heading' => $name . '.name', 'label' => $label . ' (' . AdminLang::get('export.name') . ')',
                        'cell' => static fn (object $row, ExportBatch $batch): string => $batch->title($name, (string) ($row->{$name} ?? ''))];
                }
                continue;
            }
            if ($field instanceof Fields\BelongsToMany) {
                $columns[] = ['heading' => $name, 'label' => $label, 'cell' => static fn (object $row, ExportBatch $batch): string => implode('|', $batch->related($name, $row))];
                $columns[] = ['heading' => $name . '.names', 'label' => $label . ' (' . AdminLang::get('export.names') . ')',
                    'cell' => static fn (object $row, ExportBatch $batch): string => implode(' | ', array_map(static fn (string $id): string => $batch->title($name, $id), $batch->related($name, $row)))];
                continue;
            }
            if ($field instanceof Fields\Select) {
                $columns[] = ['heading' => $name, 'label' => $label, 'cell' => static fn (object $row, ExportBatch $batch): string
                    => $batch->readable() ? (string) ($field->getOptions()[(string) ($row->{$name} ?? '')] ?? ($row->{$name} ?? '')) : (string) ($row->{$name} ?? '')];
                continue;
            }
            if ($field instanceof Fields\Toggle) {
                $columns[] = ['heading' => $name, 'label' => $label, 'cell' => static function (object $row, ExportBatch $batch) use ($name): string|array {
                    $on = (string) ($row->{$name} ?? '0') === '1';
                    return $batch->readable() ? AdminLang::get($on ? 'export.yes' : 'export.no') : self::number($on ? '1' : '0');
                }];
                continue;
            }
            if ($field instanceof Fields\MediaPicker) {
                $columns[] = ['heading' => $name, 'label' => $label, 'cell' => static fn (object $row, ExportBatch $batch): string => implode(' | ', $batch->mediaUrls($name, (string) ($row->{$name} ?? '')))];
                continue;
            }
            $numeric = in_array('numeric', $field->getRules(), true) || in_array('integer', $field->getRules(), true);
            $columns[] = ['heading' => $name, 'label' => $label, 'cell' => static function (object $row, ExportBatch $batch) use ($name, $numeric): string|array {
                $value = $row->{$name} ?? null;
                $text = is_scalar($value) ? (string) $value : '';
                return $numeric ? self::number($text) : $text;
            }];
        }
        foreach ($export->getColumns() as $heading => [$label, $value]) {
            $columns[] = ['heading' => $heading, 'label' => AdminLang::label($label), 'cell' => static function (object $row, ExportBatch $batch) use ($value): string {
                $cell = $value($row);
                return is_scalar($cell) ? (string) $cell : '';
            }];
        }
        if ($this->resource::usesTimestamps()) {
            foreach (['created_at', 'updated_at'] as $stamp) {
                $columns[] = ['heading' => $stamp, 'label' => AdminLang::get('export.' . $stamp), 'cell' => static fn (object $row, ExportBatch $batch): string => (string) ($row->{$stamp} ?? '')];
            }
        }
        return $columns;
    }

    /** A cell written as a number when it is one. @return string|array{number: string} */
    private static function number(string $value): string|array
    {
        return preg_match('/^-?\d+(\.\d+)?$/D', $value) === 1 ? ['number' => $value] : $value;
    }

    /* ---------------------------------------------------------------
     * Import targets
     * ------------------------------------------------------------- */

    /**
     * Where a column of a file can go: the record's id, or a field (one per
     * language for a translatable field).
     *
     * @return array<string, array{key: string, label: string, field: ?Field, lang: ?string, names: list<string>, required: bool}>
     */
    public function importTargets(): array
    {
        $pk = $this->resource::getPrimaryKey();
        $targets = [$pk => ['key' => $pk, 'label' => AdminLang::get('export.id'), 'field' => null, 'lang' => null,
            'names' => array_values(array_unique([self::normalize($pk), 'id', self::normalize(AdminLang::get('export.id'))])), 'required' => false]];
        $default = Languages::default();
        foreach ($this->importableFields() as $name => $field) {
            $label = strip_tags($field->getLabel());
            $base = array_merge([$name, $label, $field->rawLabel() ?? ''], $field->getImportAliases());
            if ($field->isTranslatable()) {
                foreach (Languages::all() as $lang) {
                    $names = [];
                    foreach ([$name, $label] as $stem) {
                        foreach ([$lang, Languages::name($lang)] as $suffix) { $names[] = $stem . ' ' . $suffix; }
                    }
                    if ($lang === $default) { $names = array_merge($names, $base); }
                    $targets[$name . '.' . $lang] = ['key' => $name . '.' . $lang, 'label' => $label . ' (' . Languages::name($lang) . ')', 'field' => $field, 'lang' => $lang,
                        'names' => self::normalizeAll($names), 'required' => $lang === $default && $field->isRequired()];
                }
                continue;
            }
            $names = $base;
            if ($field instanceof Fields\BelongsTo) {
                $names = array_merge($names, [$name . ' name', $label . ' ' . AdminLang::get('export.name')]);
                if (str_ends_with($name, '_id')) { $names[] = substr($name, 0, -3); }
            }
            if ($field instanceof Fields\BelongsToMany) {
                $names = array_merge($names, [$name . ' names', $label . ' ' . AdminLang::get('export.names')]);
            }
            $targets[$name] = ['key' => $name, 'label' => $label, 'field' => $field, 'lang' => null, 'names' => self::normalizeAll($names),
                'required' => $field->isRequired() || $field->isRequiredOnCreate()];
        }
        return $targets;
    }

    /** Fields an import may fill. @return array<string, Field> */
    public function importableFields(): array
    {
        $fields = [];
        foreach ($this->form->fields() as $name => $field) {
            if (!self::importable($field) || $name === $this->resource::getPrimaryKey()) { continue; }
            $fields[$name] = $field;
        }
        return $fields;
    }

    public static function importable(Field $field): bool
    {
        if (!$field->isWritable() || $field->isNotImportable() || $field->isOmitted()) { return false; }
        if ($field instanceof Fields\TextInput && $field->isPassword()) { return false; }
        foreach ([Fields\Placeholder::class, Fields\Hidden::class, Fields\FileUpload::class, Fields\MediaPicker::class] as $class) {
            if ($field instanceof $class) { return false; }
        }
        return true;
    }

    /**
     * Column index => target key, matched by heading; a target is used once.
     *
     * @param list<string> $headings
     * @return array<int, string>
     */
    public function match(array $headings): array
    {
        $targets = $this->importTargets();
        $byName = [];
        foreach ($targets as $key => $target) {
            foreach ($target['names'] as $name) { $byName[$name] ??= $key; }
        }
        $mapping = [];
        $used = [];
        // Exact field names first ("title.ar"), then labels and aliases.
        foreach ([true, false] as $exact) {
            foreach ($headings as $index => $heading) {
                if (isset($mapping[$index])) { continue; }
                $normal = self::normalize($heading);
                $key = $exact ? (isset($targets[str_replace(' ', '.', $normal)]) ? str_replace(' ', '.', $normal) : (isset($targets[$normal]) ? $normal : null)) : ($byName[$normal] ?? null);
                if ($key !== null && !isset($used[$key])) {
                    $mapping[$index] = $key;
                    $used[$key] = true;
                }
            }
        }
        ksort($mapping);
        return $mapping;
    }

    /** "Title (العربية)", "title_ar", "title[ar]" → "title ar". */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim(strip_tags($text)));
        $text = preg_replace('/[\s_\-\.\[\]\(\):\/]+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /** @param list<string> $names @return list<string> */
    private static function normalizeAll(array $names): array
    {
        return array_values(array_unique(array_filter(array_map([self::class, 'normalize'], $names), static fn (string $n): bool => $n !== '')));
    }
}
