<?php
declare(strict_types=1);

namespace FloCMS\Admin\Media;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Form\Fields\RichEditor;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;

/**
 * Where library items are used: every MediaPicker value and every library
 * address inside a RichEditor value of a saved record. Written in the record's
 * save transaction, removed when the record is deleted.
 *
 * Addresses typed into templates or by hand are not seen; the Media page says so.
 */
final class MediaUsage
{
    /** Record the usage of one saved record (all its picker and rich text fields). */
    public static function record(Resource $resource, Form $form, object $saved): void
    {
        if (!MediaLibrary::enabled()) { return; }
        $id = (string) ($saved->{$resource::getPrimaryKey()} ?? '');
        if ($id === '') { return; }
        self::recordValues($resource::getSlug(), $id, $form, (array) $saved);
    }

    /**
     * Usage of one owner (a resource record, or a settings page with resource "settings").
     *
     * @param array<string, mixed> $values Stored column values by field name
     */
    public static function recordValues(string $resource, string $recordId, Form $form, array $values): void
    {
        $store = MediaLibrary::store();
        foreach ($form->fields() as $name => $field) {
            if ($field instanceof MediaPicker) {
                $store->syncUsage($resource, $recordId, $name, MediaPicker::ids($values[$name] ?? null));
            } elseif ($field instanceof RichEditor) {
                $store->syncUsage($resource, $recordId, $name, self::idsInText($values[$name] ?? null));
            }
        }
    }

    public static function forget(Resource $resource, string $recordId): void
    {
        if (MediaLibrary::enabled()) { MediaLibrary::store()->forgetRecord($resource::getSlug(), $recordId); }
    }

    /**
     * Library items whose address appears in rich text (one language or all of a JSON value).
     *
     * @return list<int>
     */
    public static function idsInText(mixed $value): array
    {
        $html = is_string($value) ? $value : '';
        if ($html !== '' && str_starts_with(ltrim($html), '{')) {
            $decoded = json_decode($html, true);
            if (is_array($decoded)) { $html = implode("\n", array_filter($decoded, 'is_string')); }
        } elseif (is_array($value)) {
            $html = implode("\n", array_filter($value, 'is_string'));
        }
        if ($html === '') { return []; }
        $store = MediaLibrary::store();
        $base = rtrim($store->baseUrl(), '/') . '/';
        if (preg_match_all('/\b(?:src|href|poster)\s*=\s*(["\'])(.*?)\1/i', $html, $matches) < 1) { return []; }
        $paths = [];
        foreach ($matches[2] as $url) {
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Relative to the site ("/uploads/editor/…") or absolute with the same path
            $path = parse_url($url, PHP_URL_PATH);
            $basePath = (string) (parse_url($base, PHP_URL_PATH) ?? $base);
            if (!is_string($path) || !str_starts_with($path, $basePath)) { continue; }
            $relative = implode('/', array_map('rawurldecode', explode('/', substr($path, strlen($basePath)))));
            if ($relative !== '' && !str_contains($relative, '..')) { $paths[] = $relative; }
        }
        return $paths === [] ? [] : $store->idsForPaths($paths);
    }

    /** Whether a usage row's field is rich text (an unknown one counts as text, to be safe). */
    public static function isTextField(string $resource, string $field): bool
    {
        $form = self::formFor($resource);
        if ($form === null) { return true; }
        $found = $form->field($field);
        return $found === null || $found instanceof RichEditor;
    }

    /**
     * Where a usage row points, for people: label and link (null when the owner is gone).
     *
     * @param array{resource: string, record_id: string, field: string} $row
     * @return array{label: string, url: ?string}
     */
    public static function describe(array $row): array
    {
        if ($row['resource'] === 'settings') {
            $page = Admin::settings()->find($row['record_id']);
            $label = \FloCMS\Admin\Support\AdminLang::get('settings.title') . ' · ' . ($page?->getLabel() ?? $row['record_id']);
            $field = $page?->form()->field($row['field']);
            return ['label' => $label . ' · ' . ($field?->getLabel() ?? $row['field']), 'url' => $page !== null ? Admin::url('settings/edit/' . $row['record_id']) : null];
        }
        $class = Admin::registry()->find($row['resource']);
        if ($class === null) {
            return ['label' => $row['resource'] . ' #' . $row['record_id'] . ' · ' . $row['field'], 'url' => null];
        }
        /** @var class-string<Resource> $class */
        $resource = new $class();
        $field = $resource->form(new Form())->field($row['field']);
        $title = '#' . $row['record_id'];
        try {
            $record = (new \FloCMS\Admin\Data\RecordRepository($resource, MediaLibrary::store()->connection(), \FloCMS\Admin\Translation\Translations::store()))->find($row['record_id']);
            $table = $resource->table(new \FloCMS\Admin\Table\Table());
            $primary = $table->primaryColumn();
            if ($record !== null && $primary !== null && isset($record->{$primary})) {
                $text = trim(\FloCMS\Admin\Translation\Translations::get($record->{$primary}));
                if ($text !== '') { $title = mb_strimwidth($text, 0, 80, '…'); }
            }
        } catch (\Throwable) {
            // The label stays "#id"
        }
        return ['label' => $class::getPluralLabel() . ' · ' . $title . ' · ' . ($field?->getLabel() ?? $row['field']), 'url' => Admin::url($row['resource'] . '/edit/' . $row['record_id'])];
    }

    /**
     * Rebuild every usage row from the stored records (php flo admin:media-usage).
     *
     * @return array<string, int> Records read per resource slug
     */
    public static function rebuild(): array
    {
        $store = MediaLibrary::store();
        $counts = [];
        foreach (Admin::registry()->all() as $slug => $class) {
            /** @var class-string<Resource> $class */
            $resource = new $class();
            $form = $resource->form(new Form());
            if (!self::hasMediaFields($form)) { continue; }
            $store->forgetResource($slug);
            $counts[$slug] = 0;
            $pk = $class::getPrimaryKey();
            $after = null;
            do {
                $query = $store->connection()->table($class::getTable());
                if ($after !== null) { $query = $query->where($pk, '>', $after); }
                $rows = $query->orderBy($pk, 'asc')->limit(200)->get();
                foreach ($rows as $row) {
                    self::recordValues($slug, (string) $row->{$pk}, $form, (array) $row);
                    $after = $row->{$pk};
                    $counts[$slug]++;
                }
            } while (count($rows) === 200);
        }
        foreach (Admin::settings()->all() as $name => $page) {
            $form = $page->form();
            if (!self::hasMediaFields($form)) { continue; }
            self::recordValues('settings', $name, $form, ($store2 = \FloCMS\Admin\Settings\Settings::store()) !== null ? (new \FloCMS\Admin\Settings\SettingsEditor($page, $store2))->stored() : []);
            $counts['settings'] = ($counts['settings'] ?? 0) + 1;
        }
        return $counts;
    }

    public static function hasMediaFields(Form $form): bool
    {
        foreach ($form->fields() as $field) {
            if ($field instanceof MediaPicker || $field instanceof RichEditor) { return true; }
        }
        return false;
    }

    private static function formFor(string $resource): ?Form
    {
        if ($resource === 'settings') { return null; }
        $class = Admin::registry()->find($resource);
        if ($class === null) { return null; }
        return (new $class())->form(new Form());
    }
}
