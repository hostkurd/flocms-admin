# Relation managers (0.22)

A relation manager shows a record's related records on its edit page: the units of a property, the cities of a country, the orders of a customer. They are listed below the form, with search, filters, sorting and pages, and can be added, edited and deleted in dialogs without leaving the page.

A manager reuses the child resource you already have (its table, form, permissions, `query()`, trash and activity log). It adds one rule on top: **every record in the manager belongs to this parent**, and new records get the parent's key from the server.

0.22 covers **has-many** relations: the child's table holds the parent's key (`units.property_id`). To choose existing records through a pivot table, keep using the [`BelongsToMany` field](relationships.md). For a short list that lives only inside the record (a flat's rooms, opening hours), a [Repeater](repeaters.md) (0.24) is simpler: no table of its own, saved with the form.

## Adding a manager

On the parent resource, list the managers in `relations()`. Each one names the child resource and the child's column that holds the parent's key:

```php
// admin/Resources/PropertyResource.php
use FloCMS\Admin\Relations\RelationManager;

public function relations(): array
{
    return [
        RelationManager::make(UnitResource::class, 'property_id'),
        RelationManager::make(EnquiryResource::class, 'property_id')
            ->label('Enquiries')                        // default: the child's plural label
            ->columns(['name', 'email', 'created_at'])  // default: the child's list columns
            ->readOnly(),                               // no New, Edit, Delete or actions
    ];
}
```

The child resource must be registered in `config/admin.php` like any other resource. To manage it only inside its parent, keep it out of the sidebar:

```php
// admin/Resources/UnitResource.php
protected static bool $navigation = false;
```

The resource stays registered, so its own pages, permissions, trash and Ctrl+K search still work. Ctrl+K doesn't offer "New unit" for it, and its own edit page links back to its parent.

## On the page

- The managers are drawn **below the record's form**, full width, in one card. With two or more there are tabs, each with its count ("Units 12"). Without JavaScript the tabs become one section after another.
- The form and the managers save separately: **Save** on the record never touches the units, and a unit saved in its dialog is stored at once.
- On the **create page** there are no managers yet. A short note says so ("Add units and enquiries after saving"). **Save** keeps you on the new record's page, which then shows them.
- **New unit** opens the unit's form in a dialog, without its `property_id` field. **Edit** and **Delete** are the unit's own dialogs; Delete goes to the trash with Undo. Search, filters (except one on `property_id`), sorting, pages, bulk actions and custom actions work as on the units list. Create and Edit always open as dialogs here (or as a slide-over when the child or the action uses one), even if the child's `$formMode` is `'page'`.
- **Without JavaScript**, each manager has a link to the child's own list, limited to this parent (`/admin/units?static=1&parent=properties:5:units`). Its New and Edit pages keep the parent, leave out the key field, and **Back** returns to the property.
- A resource with `$formMode = 'modal'` shows its managers on its full edit page only (`/admin/<slug>/edit/<id>`), not in the edit dialog.

## Options

| Method | |
| --- | --- |
| `->label('Units')` | The tab's title: text or a lang key (default: the child's plural label) |
| `->icon('building')` | The tab's icon (default: the child's `$icon`) |
| `->columns(['name', 'price'])` | Only these list columns, in the child's order. The key column is always left out |
| `->readOnly()` | Only the list: no New, Edit, Delete, bulk or custom actions |
| `->canCreate(false)` | No New button; Edit and Delete stay |
| `->perPage(10)` | Rows per page (default: the child's, else the panel's) |
| `->defaultSort('created_at', 'desc')` | The sort when none is chosen |
| `->emptyText('No units yet')` | The empty list's text (default "No units yet.") |
| `->hideFields(['notes'])` | Child form fields to leave out here: not shown, not validated, not saved. A new record gets the column's default |
| `->name('flats')` | Needed only when one parent lists the same child resource twice (default: the child's slug) |
| `RelationManager::make(UnitResource::class, 'property_code', 'code')` | The parent's column the key points to, when it isn't the primary key |

Column choices made with the **Columns** button are kept per manager (separately from the child's own list).

## Who sees what

| What | Rule |
| --- | --- |
| See a manager | Edit rights on the parent (its `update` permission, `query()` and `canEdit()`) **and** view rights on the child (`content.units.view`). Otherwise the manager is not drawn |
| New / Edit / Delete / actions | The child's own `create` / `update` / `delete` permissions and `canCreate()` / `canEdit()` / `canDelete()`, as on the child's list. `->readOnly()` hides them all |
| Which records | The child's `query()` **and** `key = this parent`. A record of another parent is not found, even with a forged id |
| The key | Set by the server on every create and edit, whatever the browser sends |

The parent is part of the manager's signed state, and every request checks again that the parent still exists and that you may still edit it. The pages used without JavaScript check the same on every request.

Nothing changes on the Roles page: the child already has its own row there (View / Create / Edit / Delete).

## Trash and activity log

- A deleted child goes to the trash with Undo, as everywhere. Restored, it goes back under the same parent.
- Child changes are logged on the child as before. Each entry also records its parents, so the parent's **History** lists them too: "Units · Flat 3 — created this record". Only people who may view the child see these lines.
- Deleting the parent is the database's decision, as before (a foreign key with `RESTRICT` refuses it, `CASCADE` removes the children). The delete confirmation says how many related records there are: "This property has 12 units and 4 enquiries." `admin:doctor` already warns about `CASCADE` together with the trash.

## Counts in the parent's list

`CountColumn` shows how many related records each row has, with one grouped query per page and through the child's `query()`:

```php
use FloCMS\Admin\Table\Columns\CountColumn;

public function table(Table $table): Table
{
    return $table->columns([
        TextColumn::make('title')->searchable(),
        CountColumn::make('units')->label('Units')->relation(UnitResource::class, 'property_id'),
    ]);
}
```

A third argument names this table's column when the key doesn't point to `id`. Count columns can't be sorted or searched.

## Checks

`php flo admin:doctor` lists each manager and reports a child resource that isn't registered, a child table without the key column, or a parent table without the column it points to. A resource with `$navigation = false` is reported as "Not in the sidebar".

## Not in 0.22

Many-to-many **Attach** / **Detach**, managers inside managers (a unit's rooms), managers in edit dialogs, editing cells in the manager's table, and Reorder limited to one parent. These may come in later releases.
