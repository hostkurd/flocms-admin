<?php
declare(strict_types=1);

namespace FloCMS\Admin\Activity;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Translation\Translations;

/**
 * One line of the activity log.
 */
final class ActivityEntry
{
    /** The kinds people filter by (the Action switch on the Activity page). */
    public const GROUPS = ['created', 'edited', 'deleted', 'restored', 'other'];

    /**
     * @param list<array{field: string, label: string, lang: ?string, type: string, before: string, after: string}> $changes
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly int $id,
        public readonly string $action,
        public readonly ?string $resource,
        public readonly ?string $recordId,
        public readonly string $title,
        public readonly ?int $userId,
        public readonly ?string $userName,
        public readonly array $changes,
        public readonly array $meta,
        public readonly ?string $batch,
        public readonly string $createdAt,
    ) {
    }

    public static function fromRow(object $row): self
    {
        $changes = json_decode((string) ($row->changes ?? ''), true);
        $meta = json_decode((string) ($row->meta ?? ''), true);
        $clean = [];
        foreach (is_array($changes) ? $changes : [] as $change) {
            if (!is_array($change)) { continue; }
            $clean[] = [
                'field' => (string) ($change['field'] ?? ''), 'label' => (string) ($change['label'] ?? ($change['field'] ?? '')),
                'lang' => isset($change['lang']) && is_string($change['lang']) ? $change['lang'] : null,
                'type' => in_array($change['type'] ?? '', ['value', 'text', 'hidden', 'file', 'item', 'order'], true) ? (string) $change['type'] : 'value',
                'before' => is_scalar($change['before'] ?? '') ? (string) ($change['before'] ?? '') : '',
                'after' => is_scalar($change['after'] ?? '') ? (string) ($change['after'] ?? '') : '',
            ];
        }
        return new self(
            (int) $row->id, (string) $row->action,
            $row->resource !== null ? (string) $row->resource : null,
            $row->record_id !== null ? (string) $row->record_id : null,
            (string) ($row->title ?? ''),
            $row->user_id !== null ? (int) $row->user_id : null,
            $row->user_name !== null ? (string) $row->user_name : null,
            $clean, is_array($meta) ? $meta : [],
            $row->batch !== null ? (string) $row->batch : null,
            (string) $row->created_at,
        );
    }

    /** created, edited, deleted, restored or other. */
    public function group(): string
    {
        return match ($this->action) {
            'created', 'media_uploaded', 'role_created' => 'created',
            'updated', 'settings_saved', 'media_updated', 'media_replaced', 'profile_updated', 'role_updated', 'role_reset' => 'edited',
            'deleted', 'purged', 'media_deleted', 'role_deleted' => 'deleted',
            'restored' => 'restored',
            default => 'other',
        };
    }

    /** The action as a short label for its badge. */
    public function badge(): string
    {
        $key = 'activity.badge.' . $this->action;
        $text = AdminLang::get($key);
        if ($text !== $key) { return $text; }
        // A developer's own action ("published"): a site label admin.activity.badge.published, else its name
        return Str::headline(str_replace(['_', '.', '-'], ' ', $this->action));
    }

    public function badgeColor(): string
    {
        return match ($this->action) {
            'created', 'media_uploaded', 'role_created' => 'green',
            'updated', 'settings_saved', 'media_updated', 'profile_updated', 'role_updated', 'role_reset' => 'blue',
            'deleted', 'purged', 'media_deleted', 'role_deleted' => 'red',
            'restored' => 'purple',
            'imported' => 'teal',
            'import_undone' => 'red',
            'media_replaced', 'media_moved' => 'teal',
            'login' => 'yellow',
            default => '',
        };
    }

    /** The person, or "System" for commands and scripts. */
    public function who(): string
    {
        if ($this->userName !== null && $this->userName !== '') { return $this->userName; }
        return $this->userId !== null ? '#' . $this->userId : AdminLang::get('activity.system');
    }

    public function initial(): string
    {
        $who = trim($this->who());
        return $who === '' ? '?' : mb_strtoupper(mb_substr(ltrim($who, '#'), 0, 1));
    }

    /** The record's title in the admin's language, or "#id". */
    public function label(): string
    {
        $text = $this->title !== '' ? trim(Translations::get($this->title, \FloCMS\Admin\Support\AdminLang::language())) : '';
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        if ($text !== '') { return mb_strimwidth($text, 0, 120, '…'); }
        return $this->recordId !== null && $this->recordId !== '' ? '#' . $this->recordId : '';
    }

    /** @return class-string<Resource>|null */
    public function resourceClass(): ?string
    {
        return $this->resource !== null ? Admin::registry()->find($this->resource) : null;
    }

    /** What kind of thing it is about: a resource's label, "Settings", "Media", or ''. */
    public function typeLabel(): string
    {
        $class = $this->resourceClass();
        if ($class !== null) { return $class::getLabel(); }
        return match ($this->resource) {
            'settings' => AdminLang::get('settings.title'),
            'media' => AdminLang::get('media.title'),
            'roles' => AdminLang::get('activity.what.role'),
            null, '' => '',
            default => Str::headline($this->resource),
        };
    }

    /**
     * The parent records of a related record's entry (0.22): ["properties:5"].
     *
     * @return list<string>
     */
    public function parents(): array
    {
        $parents = $this->meta['parents'] ?? [];
        return is_array($parents) ? array_values(array_filter($parents, 'is_string')) : [];
    }

    /** Whether the entry has a before → after table to show. */
    public function hasChanges(): bool
    {
        return $this->changes !== [] || $this->details() !== [];
    }

    /**
     * Extra facts written with the entry (a developer's Activity::log() details,
     * a bulk action's records), as label => text.
     *
     * @return array<string, string>
     */
    public function details(): array
    {
        $details = [];
        foreach ($this->meta as $key => $value) {
            if ($key === 'trash' && $value) { $details[AdminLang::get('trash.title')] = AdminLang::get('activity.moved_to_trash'); continue; }
            if ($key === 'expired' && $value) { $details[AdminLang::get('trash.title')] = AdminLang::get('activity.purged_expired'); continue; }
            if (in_array($key, ['count', 'action', 'titles', 'expired', 'folder', 'file', 'trash', 'parents', 'import', 'status', 'selected'], true)) { continue; }
            if (is_scalar($value) && (string) $value !== '') {
                $details[Str::headline((string) $key)] = mb_strimwidth((string) $value, 0, 300, '…');
            }
        }
        $titles = $this->meta['titles'] ?? null;
        if (is_array($titles) && $titles !== []) {
            $names = array_map(static fn ($t): string => trim(Translations::get(is_scalar($t) ? (string) $t : '', \FloCMS\Admin\Support\AdminLang::language())), $titles);
            $details[AdminLang::get('activity.records')] = implode(', ', array_filter($names, static fn (string $t): bool => $t !== ''));
        }
        return $details;
    }

    /** Names of the fields that changed, for one line under an entry. */
    public function fieldNames(): string
    {
        $names = [];
        foreach ($this->changes as $change) {
            $name = $change['label'] !== '' ? $change['label'] : $change['field'];
            if (!in_array($name, $names, true)) { $names[] = $name; }
        }
        return implode(AdminLang::get('form.list_separator'), $names);
    }

    /**
     * The sentence of the Activity page's "What" column: a lead-in, then the
     * record (linked when it can be opened).
     *
     * @return array{verb: string, target: string, url: ?string}
     */
    public function describe(): array
    {
        $count = (int) ($this->meta['count'] ?? 0);
        $class = $this->resourceClass();
        $plural = $class !== null ? $class::getPluralLabel() : $this->typeLabel();
        $action = is_string($this->meta['action'] ?? null) ? (string) $this->meta['action'] : '';
        return match ($this->action) {
            'reordered' => ['verb' => AdminLang::get('activity.what.reordered'), 'target' => $count . ' ' . $plural, 'url' => $this->listUrl()],
            'bulk_action' => ['verb' => AdminLang::get('activity.what.bulk_action', ['action' => $action]), 'target' => $count . ' ' . $plural, 'url' => $this->listUrl()],
            'action' => ['verb' => AdminLang::get('activity.what.action', ['action' => $action]), 'target' => $this->label(), 'url' => $this->recordUrl()],
            'media_uploaded', 'media_updated', 'media_replaced', 'media_deleted' => ['verb' => AdminLang::get('activity.what.' . $this->action), 'target' => $this->label(), 'url' => $this->recordUrl()],
            'media_moved' => ['verb' => AdminLang::get('activity.what.media_moved', ['count' => $count]), 'target' => (string) ($this->meta['folder'] ?? AdminLang::get('media.unfiled')), 'url' => Admin::url('media')],
            'login' => ['verb' => AdminLang::get('activity.what.login'), 'target' => '', 'url' => null],
            'profile_updated' => ['verb' => AdminLang::get('activity.what.profile_updated'), 'target' => '', 'url' => null],
            'settings_saved' => ['verb' => AdminLang::get('settings.title'), 'target' => $this->label(), 'url' => $this->recordUrl()],
            // CSV import and export (0.25)
            'imported' => ['verb' => AdminLang::get('activity.what.imported', ['created' => (int) ($this->meta['created'] ?? 0), 'updated' => (int) ($this->meta['updated'] ?? 0), 'label' => $plural]), 'target' => $this->label(), 'url' => $this->importUrl()],
            'import_undone' => ['verb' => AdminLang::get('activity.what.import_undone', ['label' => $plural]), 'target' => $this->label(), 'url' => $this->importUrl()],
            'exported' => ['verb' => AdminLang::get('activity.what.exported', ['count' => (int) ($this->meta['rows'] ?? 0)]), 'target' => $plural, 'url' => $this->listUrl()],
            default => ['verb' => $this->typeLabel(), 'target' => $this->label(), 'url' => $this->recordUrl()],
        };
    }

    /** One phrase for the History list of a record: "edited 3 fields". */
    public function phrase(): string
    {
        $count = count($this->changes);
        $action = is_string($this->meta['action'] ?? null) ? (string) $this->meta['action'] : '';
        return match ($this->action) {
            'created', 'deleted', 'restored', 'purged' => AdminLang::get('activity.history.' . $this->action),
            'updated' => AdminLang::get($count === 1 ? 'activity.history.updated_one' : 'activity.history.updated_many', ['count' => $count]),
            'action' => AdminLang::get('activity.history.action', ['action' => $action]),
            'bulk_action' => AdminLang::get('activity.history.bulk_action', ['action' => $action]),
            default => AdminLang::get('activity.history.other', ['action' => mb_strtolower($this->badge())]),
        };
    }

    /** The result page of the import an entry is about (0.25), when the user may import into it. */
    private function importUrl(): ?string
    {
        $class = $this->resourceClass();
        $id = (int) ($this->meta['import'] ?? 0);
        if ($class === null || $id <= 0 || !$class::canImport()) { return null; }
        return Admin::url($class::getSlug() . '/import/' . $id . '/result');
    }

    /** Where the record can be opened, if it still exists and the user may edit it. */
    public function recordUrl(): ?string
    {
        if ($this->recordId === null || $this->recordId === '') { return null; }
        if ($this->resource === 'settings') {
            $page = Admin::settings()->find($this->recordId);
            return $page !== null && \FloCMS\Core\Auth::can($page->getPermission()) ? Admin::url('settings/edit/' . $this->recordId) : null;
        }
        if ($this->resource === 'roles') {
            if ($this->action === 'role_deleted' || !\FloCMS\Admin\Roles\Roles::canManage() || !is_numeric($this->recordId)) { return null; }
            $role = \FloCMS\Admin\Roles\Roles::find((int) $this->recordId);
            return $role !== null ? Admin::url('roles/edit/' . $role->number) : null;
        }
        if ($this->resource === 'media') {
            return \FloCMS\Admin\Media\MediaLibrary::enabled() && \FloCMS\Admin\Media\MediaLibrary::can('update') ? Admin::url('media/edit/' . $this->recordId) : null;
        }
        $class = $this->resourceClass();
        if ($class === null || !\FloCMS\Core\Auth::can($class::permission('update')) || in_array($this->action, ['deleted', 'purged'], true)) { return null; }
        return Admin::url($class::getSlug() . '/edit/' . $this->recordId);
    }

    private function listUrl(): ?string
    {
        $class = $this->resourceClass();
        return $class !== null && \FloCMS\Core\Auth::can($class::permission('view')) ? Admin::url($class::getSlug()) : null;
    }

    public function time(): int
    {
        return strtotime($this->createdAt) ?: 0;
    }
}
