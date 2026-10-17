# Relationships

Admin 0.15 lets a record point to other records:

- **`BelongsTo`**: one related record, whose key is stored in a column of this table (a property's `city_id`, `agent_id`).
- **`BelongsToMany`**: several related records, kept in a pivot table (a property's features).
- **`RelationColumn`** and **`RelationFilter`**: related titles in the list, and filtering by them.

They need no new table and no change to the website: `city_id` is an ordinary column and the pivot table an ordinary table. On MySQL/MariaDB, create tables with `CHARACTER SET utf8mb4` (`DEFAULT CHARSET=utf8mb4`) so titles in every language are kept; `admin:doctor` reports tables that are not.

## Declaring where related records come from

Every relation field, column and filter says where its records are:

```php
->resource(CityResource::class)                  // table, key, title, translations and scope of a resource
->resource(AgentResource::class)->titleColumn('full_name')
->table('brands', 'name')                        // a table without a resource: title column, key 'id'
->table('brands', 'name', 'brand_id')            // … and another key column
```

With `->resource()` the title is the resource's title column (its first `searchable()` column, the one that titles a row on phones). When that field is translatable, titles appear in the admin's language and are searched in every language. The related resource's `query()` scope always applies: records outside it are never offered or accepted.

## BelongsTo: one related record

```php
use FloCMS\Admin\Form\Fields\BelongsTo;

BelongsTo::make('city_id')->label('City')->resource(CityResource::class)->required(),
BelongsTo::make('agent_id')->resource(AgentResource::class)->titleColumn('full_name'),
BelongsTo::make('brand_id')->table('brands', 'name'),
```

- **The control** is a combobox: type to search, pick with the mouse or the keyboard (arrows, Enter, Escape), × clears it. Related tables of up to 50 rows are sent with the page and filtered as you type; larger ones are searched on the server (20 results at a time). Right-to-left languages work as everywhere in the panel.
- **Without JavaScript** it is a native `<select>` with the first 200 records by title, plus the chosen one.
- **Saving**: the key must exist in the related table, within its scope. An empty value is stored as `NULL` (the column should allow it) unless the field is `required()`.
- **Trees**: a relation to the resource's own table (`parent_id` of a category) never offers the record itself, and refuses it on save.
- **A related record that left the scope later** (archived) keeps working on records that already point to it: editing them does not force a new choice. Choosing it anew is refused.
- **A related record that was deleted** shows as `#12 (missing)`.

### Dependent relations

```php
BelongsTo::make('country_id')->resource(CountryResource::class)->required(),
BelongsTo::make('city_id')->resource(CityResource::class)->dependsOn('country_id')->required(),
```

`city_id` offers only cities whose `country_id` column equals the chosen country; `->dependsOn('country_id', 'nation_id')` names another column of the related table. Until a country is chosen the city says "Choose Country first". Changing the country clears a city of another country. The server checks the pair on save.

## BelongsToMany: several related records

```php
use FloCMS\Admin\Form\Fields\BelongsToMany;

BelongsToMany::make('features')
    ->resource(FeatureResource::class)
    ->pivot('property_feature', 'property_id', 'feature_id')   // table, this record's key, the related key
    ->orderColumn('sort')                                      // optional: keep the chosen order
    ->max(20),                                                 // optional
```

```sql
CREATE TABLE property_feature (
  property_id INT UNSIGNED NOT NULL,
  feature_id  INT UNSIGNED NOT NULL,
  sort        INT NOT NULL DEFAULT 0,          -- only with ->orderColumn('sort')
  PRIMARY KEY (property_id, feature_id)
);
```

- **The control** shows the chosen records as chips with ×, and a search box with the same search as `BelongsTo`. Backspace in the empty box removes the last chip. Without JavaScript: a multiple `<select>`.
- **Saving** happens in the same database transaction as the record. Only the differences are written: removed pairs are deleted, new pairs inserted, unchanged rows kept with their other columns. With `->orderColumn()` the chosen order is stored as 1, 2, 3…
- **Deleting the record** removes its pivot rows in the same transaction, from the delete button, the row menu and bulk deletes.
- `->required()` means at least one; `->max(n)` caps the count; `->dependsOn()` works as for `BelongsTo`.
- The field is not a column of this table. In custom code, its form value is a JSON list of keys (`'["3","7"]'`).
- The fourth argument of `->pivot()` is this record's key column (default `id`); it must be the resource's primary key.

## List columns

```php
use FloCMS\Admin\Table\Columns\RelationColumn;

RelationColumn::make('city_id')->label('City')->resource(CityResource::class)->sortable(),
RelationColumn::make('features')->resource(FeatureResource::class)
    ->pivot('property_feature', 'property_id', 'feature_id')->orderColumn('sort')->limit(3)->toggleable(),
```

Titles are loaded once per page and column, not once per row. A many-to-many column shows up to `limit()` titles and "+n". Sorting a one-to-one column sorts by its key; many-to-many columns cannot be sorted, and relation columns cannot be searched yet (the search box searches the record's own columns). Records widgets accept one-to-one relation columns.

## Filters

```php
use FloCMS\Admin\Table\Filters\RelationFilter;

RelationFilter::make('city_id')->resource(CityResource::class),                 // records of the chosen city
RelationFilter::make('features')->resource(FeatureResource::class)
    ->pivot('property_feature', 'property_id', 'feature_id'),                    // records having any of the chosen features
```

The Filters menu shows the same combobox (chips for many-to-many, up to 20). Active filters appear as chips with the related titles ("City: Erbil"), in the Reactive list and the full-page list, and through bookmarks.

## Searching and permissions

The combobox asks `GET /admin/<slug>/lookup/<field>?q=…` on the resource that owns the field. It answers only for declared relations; the related table, title and scope come from PHP code, never from the request.

| Asked by | Needs |
| --- | --- |
| A form field | create or update permission of the owning resource |
| A filter (`?for=filter`) | view permission of the owning resource |

The user does not also need permission to view the related resource: an Editor who adds properties picks a city without managing cities. Results are at most 20 keys and titles.

Relation fields in custom action forms (not the resource's own form) show their first 200 records and filter them in the page.

## Deleting a related record that is still used

The panel cannot know every table that points to a record, so the database decides. Add foreign keys:

```sql
ALTER TABLE properties ADD FOREIGN KEY (city_id) REFERENCES cities (id) ON DELETE RESTRICT;
ALTER TABLE property_feature ADD FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE;
ALTER TABLE property_feature ADD FOREIGN KEY (feature_id) REFERENCES features (id) ON DELETE RESTRICT;
```

When the database refuses a delete, the panel says "This City is still used by other records, so it was not deleted." and keeps the record. `php flo admin:doctor` checks every relation's tables and columns and suggests the foreign key for each `BelongsTo` that has none. Without foreign keys the delete goes through and the records that pointed to it show `#12 (missing)`.

## Reading relations on the website

Use the site's own queries, for example:

```php
$city = $db->table('cities')->where('id', '=', $property->city_id)->first();
echo localized($city->name);

$features = $db->query('SELECT f.* FROM features f JOIN property_feature pf ON pf.feature_id = f.id WHERE pf.property_id = ? ORDER BY pf.sort', [$property->id])->fetchAll(PDO::FETCH_OBJ);
```
