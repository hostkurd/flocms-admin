<?php
declare(strict_types=1);

namespace FloCMS\Admin;

use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\Auth;

/**
 * A page of the site's own inside the panel (0.36): a report, a tool, a help
 * page. It gets the panel's layout, sidebar, permissions, search, RTL, dark
 * mode and phone layout. Registered in config/admin.php under 'admin_pages'.
 *
 *     final class SalesReport extends AdminPage
 *     {
 *         protected static string $label = 'Sales report';
 *         protected static ?string $icon = 'chart';
 *         protected static ?string $group = 'Reports';
 *
 *         public function widgets(): array { return [MonthlySales::class]; }
 *         public function view(): ?string { return 'admin/sales-report'; }
 *         public function data(array $filters): array { return ['months' => Sales::byMonth($filters)]; }
 *     }
 *
 * Every method is optional. The page shows, in this order: its header with
 * actions(), filters(), widgets(), view(), table() and form(). A tool page
 * adds form(), fill() and submit(array $data). See docs/custom-pages.md.
 */
abstract class AdminPage
{
    /** URL segment: /admin/<slug>. '' makes it from the class name (SalesReport → sales-report). */
    protected static string $slug = '';

    /** The page's name: a key of the site's lang files ("admin.sales_report") or plain text. */
    protected static string $label = '';

    /** A line under the title, and in Ctrl+K search: plain text or a lang key. */
    protected static ?string $description = null;

    /** Sidebar icon (the names of resources' icons). */
    protected static ?string $icon = null;

    /** Sidebar group ("Reports", a lang key works); null puts the page under Content. */
    protected static ?string $group = null;

    /** Order in its group; groups follow the lowest sort of their pages. */
    protected static int $sort = 0;

    /** 'default', 'full' (the whole width) or 'narrow' (a form or a short text). */
    protected static string $width = 'default';

    /** The permission to open the page; null: panel.<slug>, ticked on the Roles page. */
    protected static ?string $permission = null;

    /** false keeps the page out of the sidebar; it still opens and is found by search. */
    protected static bool $navigation = true;

    /** false leaves the page out of Ctrl+K search. */
    protected static bool $globallySearchable = true;

    /**
     * More addresses of the page: ['export' => 'export'] makes
     * /admin/<slug>/route/export call $this->export(), with the page's
     * permission (and CSRF on POST). The method returns a Response or HTML.
     *
     * @var array<string, string>
     */
    protected static array $routes = [];

    /** The label of form()'s Save button: a lang key or plain text; '' is "Save". */
    protected static string $submitLabel = '';

    /** false: a saved form() is not written to the activity log. */
    protected static bool $activity = true;

    /** The width values $width accepts. */
    public const WIDTHS = ['default', 'full', 'narrow'];

    /** @var array<string, mixed> the filters' values of this request */
    private array $filterValues = [];

    final public function __construct()
    {
    }

    /* ----------------------------------------------------------------
     * What the page holds (all optional)
     * -------------------------------------------------------------- */

    /**
     * Dashboard widgets shown on the page, as classes (StatsWidget, RecordsWidget,
     * ChartWidget). A widget reads the page's filters with $this->pageFilters().
     *
     * @return list<class-string<\FloCMS\Admin\Widgets\Widget>>
     */
    public function widgets(): array
    {
        return [];
    }

    /** Filters at the top: fields whose values are kept in the address (GET). */
    public function filters(Form $form): Form
    {
        return $form;
    }

    /** A view of the site's own: 'admin/sales-report' is views/admin/sales-report.html. */
    public function view(): ?string
    {
        return null;
    }

    /**
     * Variables of the view. $filters holds the filters' values.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function data(array $filters): array
    {
        return [];
    }

    /**
     * A read-only table from any query:
     * $table->query(fn (Database $db, array $filters) => $db->table('bookings'))->columns([...]).
     */
    public function table(Table $table): Table
    {
        return $table;
    }

    /**
     * Buttons in the page header (Action::make(...)): each opens its own page
     * with its form or a confirmation, then runs fn (array $data, AdminPage $page).
     *
     * @return list<\FloCMS\Admin\Actions\Action>
     */
    public function actions(): array
    {
        return [];
    }

    /** A form with a Save button (a tool page); its values go to submit(array $data). */
    public function form(Form $form): Form
    {
        return $form;
    }

    /**
     * The form's starting values, by field name (translatable fields: ['en' => …, 'ar' => …]).
     *
     * @return array<string, mixed>
     */
    public function fill(): array
    {
        return [];
    }

    // submit(array $data) is declared by the page when it has a form: it saves
    // the validated values anywhere, may throw a ValidationException (shown on
    // the fields) or send a Notification, and may return a message to show.

    /** Narrow who may open the page, after its permission. */
    public function canAccess(): bool
    {
        return true;
    }

    /** A number or a short text on the page's sidebar item (null: none). Keep it cheap. */
    public function badge(): string|int|null
    {
        return null;
    }

    /* ----------------------------------------------------------------
     * Definition
     * -------------------------------------------------------------- */

    final public static function getSlug(): string
    {
        if (static::$slug !== '') {
            return static::$slug;
        }
        $short = substr(static::class, (int) strrpos(static::class, '\\') + 1);
        $short = preg_replace('/(?<!^)Page$/', '', $short) ?? $short;
        return Str::kebab($short);
    }

    final public static function getLabel(): string
    {
        $short = substr(static::class, (int) strrpos(static::class, '\\') + 1);
        return AdminLang::label(static::$label !== '' ? static::$label : ucfirst(strtolower(Str::headline(Str::kebab(preg_replace('/(?<!^)Page$/', '', $short) ?? $short)))));
    }

    final public static function getDescription(): ?string
    {
        return static::$description === null || static::$description === '' ? null : AdminLang::label(static::$description);
    }

    final public static function getIcon(): string
    {
        return static::$icon ?? 'file';
    }

    /** The group's label, or null (under Content). */
    final public static function getGroup(): ?string
    {
        return static::$group === null || trim(static::$group) === '' ? null : AdminLang::label(static::$group);
    }

    final public static function getSubmitLabel(): string
    {
        return static::$submitLabel !== '' ? AdminLang::label(static::$submitLabel) : AdminLang::get('actions.save');
    }

    final public static function getSort(): int
    {
        return static::$sort;
    }

    final public static function getWidth(): string
    {
        return static::$width;
    }

    /** The permission that opens the page. */
    final public static function permission(): string
    {
        return static::$permission ?? 'panel.' . static::getSlug();
    }

    final public static function showsInNavigation(): bool
    {
        return static::$navigation;
    }

    final public static function isGloballySearchable(): bool
    {
        return static::$globallySearchable;
    }

    final public static function logsActivity(): bool
    {
        return static::$activity;
    }

    /** @return array<string, string> route => method */
    final public static function routes(): array
    {
        return static::$routes;
    }

    /** /admin/<slug>, with a query. @param array<string, mixed> $query */
    final public static function url(array $query = []): string
    {
        return Admin::url(static::getSlug(), $query);
    }

    /** Whether the signed-in person may open the page: its permission and canAccess(). */
    final public function allowed(): bool
    {
        try {
            return Auth::can(static::permission()) && $this->canAccess();
        } catch (\Throwable $error) {
            \FloCMS\Admin\Support\Log::error('Admin page ' . static::class . ': canAccess() failed: ' . $error->getMessage());
            return false;
        }
    }

    /** Whether the page has a form with Save (form() has fields and submit() exists). */
    final public function hasForm(): bool
    {
        return method_exists($this, 'submit') && $this->form(new Form())->fields() !== [];
    }

    /**
     * The filters' values of this request, by field name (empty values left out).
     *
     * @return array<string, mixed>
     */
    final public function filterValues(): array
    {
        return $this->filterValues;
    }

    /** @internal set by the page's controller before anything is rendered */
    final public function useFilterValues(array $values): void
    {
        $this->filterValues = $values;
    }

    /**
     * What is wrong with the definition, for the registry and admin:doctor.
     *
     * @return list<string>
     */
    final public static function configurationErrors(): array
    {
        $errors = [];
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', static::getSlug()) !== 1 || strlen(static::getSlug()) > 64) {
            $errors[] = 'slug "' . static::getSlug() . '" is not valid: use lower case letters, digits and single hyphens';
        }
        if (!in_array(static::$width, self::WIDTHS, true)) {
            $errors[] = 'width "' . static::$width . '" is not valid: use default, full or narrow';
        }
        if (static::$permission !== null && preg_match('/^[A-Za-z0-9_.-]+$/D', static::$permission) !== 1) {
            $errors[] = 'permission "' . static::$permission . '" is not valid: use letters, digits, ".", "_" and "-"';
        }
        if (static::$icon !== null && !\FloCMS\Admin\Support\Icons::exists(static::$icon)) {
            $errors[] = 'icon "' . static::$icon . '" is not one of the panel\'s icons';
        }
        foreach (static::$routes as $route => $method) {
            if (!is_string($route) || preg_match('/^[a-z][a-z0-9_]{0,40}$/D', $route) !== 1) {
                $errors[] = 'route "' . $route . '" is not valid: use lower case letters, digits and "_"';
                continue;
            }
            if (!is_string($method) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $method) !== 1 || !method_exists(static::class, $method)) {
                $errors[] = 'route "' . $route . '" names the method ' . (is_string($method) ? $method . '()' : '?') . ', which the page does not have';
                continue;
            }
            $reflection = new \ReflectionMethod(static::class, $method);
            if (!$reflection->isPublic() || $reflection->isStatic() || $reflection->getDeclaringClass()->getName() === self::class) {
                $errors[] = 'route "' . $route . '": ' . $method . '() must be a public method of the page';
            }
        }
        return $errors;
    }
}
