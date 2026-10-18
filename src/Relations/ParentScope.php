<?php
declare(strict_types=1);

namespace FloCMS\Admin\Relations;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Data\RecordRepository;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\App;
use FloCMS\Core\Auth;
use FloCMS\Core\HttpException;

/**
 * A child resource seen from inside one parent record (0.22): the units of
 * property 5. Built from the parent's slug, id and manager name on every
 * request, after checking that the user may still edit that parent. Every
 * read of the child is limited to "key = this parent" and every save sets
 * the key; a record of another parent is not found.
 *
 * @internal
 */
final class ParentScope
{
    /** @param class-string<Resource> $parentClass */
    private function __construct(
        public readonly string $parentClass,
        public readonly string $parentId,
        public readonly RelationManager $manager,
        public readonly object $parent,
    ) {
    }

    /**
     * The scope of $childSlug inside parent $parentSlug/$parentId, or an
     * HttpException: 404 for an unknown parent, record or manager, 403 when
     * the user may not edit the parent.
     */
    public static function resolve(string $parentSlug, string $parentId, string $relation, string $childSlug): self
    {
        $class = Admin::registry()->find($parentSlug) ?? throw new HttpException(404);
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $parentId) !== 1) { throw new HttpException(404); }
        $parent = new $class();
        $manager = RelationManager::of($parent)[$relation] ?? throw new HttpException(404);
        if (Admin::registry()->find($childSlug) !== $manager->getResource()) { throw new HttpException(404); }
        Auth::authorize($class::permission('update'));
        $db = App::db() ?? throw new HttpException(503);
        $record = (new RecordRepository($parent, $db, Translations::store()))->find($parentId) ?? throw new HttpException(404);
        if (!$parent->canEdit($record)) { throw new HttpException(403); }
        return new self($class, $parentId, $manager, $record);
    }

    /** "properties:5:units", the ?parent= of the child's own pages without JavaScript. */
    public function token(): string
    {
        return $this->parentClass::getSlug() . ':' . $this->parentId . ':' . $this->manager->getName();
    }

    /** The scope a ?parent= token names, or an HttpException (as resolve()). */
    public static function fromToken(string $token, string $childSlug): self
    {
        $parts = explode(':', $token);
        if (count($parts) !== 3) { throw new HttpException(404); }
        return self::resolve($parts[0], $parts[1], $parts[2], $childSlug);
    }

    public function foreignKey(): string { return $this->manager->getForeignKey(); }

    /** The value the child's key holds: the parent's primary key, or its owner key. */
    public function key(): string
    {
        $column = $this->manager->getOwnerKey($this->parentClass);
        $value = $this->parent->{$column} ?? null;
        if (!is_scalar($value) || (string) $value === '') { throw new HttpException(404); }
        return (string) $value;
    }

    /** Where the manager keeps its Columns choices (apart from the child's own list). */
    public function columnsKey(): string { return 'relation.' . $this->parentSlug() . '.' . $this->manager->getName(); }

    public function parentSlug(): string { return $this->parentClass::getSlug(); }

    public function parentUrl(): string { return Admin::url($this->parentSlug() . '/edit/' . $this->parentId); }

    /** The parent's title, as notices show it. */
    public function parentTitle(): string
    {
        return \FloCMS\Admin\Actions\ActionExecutor::label(new ($this->parentClass)(), $this->parent);
    }

    /** The child's form here: the parent's key and hideFields() are left out. */
    public function adjustForm(Form $form): Form
    {
        // The key keeps the parent's value, so dependent fields and the resource's own rules see it.
        $form->field($this->foreignKey())?->omit($this->key());
        foreach ($this->manager->getHiddenFields() as $name) {
            if ($name !== $this->foreignKey()) { $form->field($name)?->omit(); }
        }
        return $form;
    }

    /** The child's list here: chosen columns, no key column or filter, no reordering, the manager's page size and sort. */
    public function adjustTable(Table $table): Table
    {
        return $table->forRelation($this->manager->getColumns(), $this->foreignKey(), $this->manager->getPerPage(), $this->manager->getDefaultSort());
    }
}
