# Reordering

Admin 0.16 lets editors put records in their own order by dragging them: menu items, slides, FAQ entries, team members. The order is an integer column of the resource's own table, so the website reads it with `ORDER BY sort`.

## Turning it on

1. Add an integer column (an index keeps the list fast):

    ```sql
    ALTER TABLE faqs ADD sort INT NOT NULL DEFAULT 0, ADD INDEX (sort);
    ```

    `php flo make:admin-resource Faq --migration --reorderable` writes this column into a new resource's migration.

2. Call `->reorderable()` on the resource's table:

    ```php
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('question')->searchable(),
            ])
            ->filters([
                SelectFilter::make('category')->options(['billing' => 'Billing', 'shipping' => 'Shipping']),
            ])
            ->reorderable('sort');
    }
    ```

3. Run `php flo admin:doctor`: it reports a missing or non-integer order column.

The column does not have to be shown in the list or the form. Existing rows may all be 0: the first save numbers them 1, 2, 3… in their current order.

| Option | Default | |
| --- | --- | --- |
| `->reorderable('sort')` | `'sort'` | The integer column that holds the order |
| `->newRecordsFirst()` | off (new records go last) | New records go to the top, as for slides or news |
| `->reorderLimit(1000)` | 500, at most 2000 | Rows Reorder mode accepts at once; above that it asks to filter first |

## The list

When the table has no `defaultSort()`, the list follows the editors' order (`ORDER BY sort, id`). Sortable column headers still sort by their column. A table that keeps a `defaultSort()` shows that order, and Reorder mode still uses the editors' order.

## Reorder mode

The list's **Reorder** button opens `/admin/<slug>/reorder`. It shows every record of the current filters on one page, in their saved order, with a grip handle per row:

- **Mouse, touch or pen**: drag a row by its handle. The page scrolls when the row nears its top or bottom edge.
- **Keyboard**: Tab to a handle, Space or Enter picks the row up, ↑/↓ move it, Space or Enter drops it, Escape puts it back. A screen reader hears each step ("Garden, moved to position 3 of 8").
- **Without JavaScript**: each row has ↑ and ↓ buttons that move it one place.

Each drop is saved at once; a status line says "Saving…", then "Saved". If the save fails, the rows go back and the line says why. **Done** returns to the list with its filters.

### Ordering one group

Filter the list first (the items of one menu, the slides of one slider, one FAQ category), then click Reorder. Only those rows take part, and they hand out the positions they already have: if the Billing questions hold positions 2, 5 and 9, dragging the third to the top gives it 2 and the others 5 and 9. Rows of other categories keep their positions.

Any filter works this way, including relation filters (`RelationFilter::make('menu_id')`).

## Saving and safety

- Reorder needs the resource's **update** permission and `canReorder()`:

    ```php
    public function canReorder(): bool
    {
        return \FloCMS\Core\Auth::can('menus.arrange');
    }
    ```

- A save writes only the order column, in one transaction, and only for rows whose position changed. `updated_at`, `beforeSave()` and `afterSave()` are not touched: ordering is not an edit of the content.
- `afterReorder(array $ids)` runs inside that transaction when positions changed, with the keys in their new order. Use it to clear a cached menu; an exception rolls the order back.
- The resource's `query()` scope always applies: rows outside it are never shown, moved or renumbered.
- When another editor added or deleted a record of the set since the page opened, the save is refused, the page reloads and says "The list changed, so it was reloaded. Try again."
- The endpoint is `POST /admin/<slug>/reorder` with the CSRF token, the active filters and the keys in their new order. Keys must be exactly the rows the filters select; anything else is refused.

## New records

On create, when the form sends no value for the order column, the panel puts the record after the last one in the scope (largest position + 1), or before the first with `newRecordsFirst()` (smallest − 1).

## On the website

```php
$faqs = $db->table('faqs')->where('category', '=', 'billing')->orderBy('sort')->orderBy('id')->get();
```

Ordering by `id` second keeps rows with the same position in a stable order.

## Not in 0.16

Nested trees (dragging a menu item under another one), ordering inside a record's page (has-many managers) and undo are not part of this release. The chosen order of `BelongsToMany` links is kept by `->orderColumn()` (see the [Relationships guide](relationships.md)).
