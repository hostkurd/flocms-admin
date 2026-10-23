<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Wizard\{MovingIn, RentalDialogResource, RentalPageModeResource, RentalResource};

/**
 * Wizard forms (0.39) through whole requests, as a browser without JavaScript
 * sends them: Next checks only the shown step, Back and the step buttons never
 * check or save, a step hidden by an answer is skipped and not saved, the final
 * submit checks every step and opens the first one with an error, and the edit
 * page opens any step.
 */
final class WizardRequestTest extends RequestTestCase
{
    private static function options(array $extra = []): array
    {
        return ['admin_config' => ['resources' => [RentalResource::class, RentalDialogResource::class, RentalPageModeResource::class], 'admin_pages' => [MovingIn::class], 'reactive' => false], 'permissions' => [0 => [], 1 => ['*'], 2 => ['*'], 3 => ['*']]] + $extra;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS rentals');
        self::createTable($pdo, 'CREATE TABLE rentals (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NULL, type VARCHAR(20) NULL, city VARCHAR(100) NULL, address VARCHAR(190) NULL, lease_months INTEGER NULL, price DECIMAL(12,2) NULL, brochure VARCHAR(190) NULL, notes TEXT NULL, featured TINYINT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $pdo->exec("INSERT INTO rentals (title, type, city, address, lease_months, price, featured) VALUES ('{\"en\":\"Villa in Kifisia\"}', 'rent', 'Athens', 'Kolokotroni 14', 12, 2400, 0)");
    }

    /** Every answer, as the form posts them on any step (the hidden steps' fields are sent too). */
    private static function answers(array $extra = []): array
    {
        return $extra + ['title' => ['en' => 'Stone house', 'ar' => '', 'ku' => ''], 'type' => 'sale', 'city' => 'Chania', 'address' => '', 'lease_months' => '', 'price' => '465000', 'notes' => '', 'featured' => '0'];
    }

    private static function current(string $body): string
    {
        self::assertSame(1, preg_match('#name="_wizard_step" value="([^"]+)"#', $body, $m), 'the wizard says which step is shown');
        return $m[1];
    }

    private static function rows(): int
    {
        return (int) self::query('SELECT COUNT(*) FROM rentals');
    }

    private static function query(string $sql): mixed
    {
        return self::pdo()->query($sql)->fetchColumn();
    }

    public function testTheCreatePageOpensOnTheFirstStepWithEveryStepInTheForm(): void
    {
        $page = self::request('GET', '/en/admin/rentals/create', self::options());
        self::assertSame(200, $page['status'], (string) $page['error']);
        $body = $page['body'];
        self::assertSame('details', self::current($body));
        self::assertStringContainsString('data-admin-wizard data-wizard-mode="create"', $body);
        self::assertStringContainsString('aria-current="step"', $body);
        self::assertStringContainsString('Step 1 of 4', $body, 'Details, Location, Pricing, Review: Rental terms waits for the type');
        self::assertStringContainsString('name="city"', $body, 'later steps are in the form, hidden');
        self::assertMatchesRegularExpression('#data-wizard-step="location"[^>]*hidden#', $body);
        self::assertStringContainsString('name="_wizard" value="next"', $body);
        self::assertStringNotContainsString('name="_wizard" value="back"', $body, 'no Back on the first step');
        self::assertStringNotContainsString('data-wizard-finish', $body, 'Create waits for the last step');
        self::assertStringNotContainsString('name="_another"', $body, 'the page head has no save buttons');
        self::assertStringContainsString('name="featured"', $body, 'the aside field sits beside the steps');
    }

    public function testNextChecksOnlyTheShownStep(): void
    {
        $empty = self::post('/en/admin/rentals/create', self::answers(['title' => ['en' => ''], 'city' => '', 'price' => '', '_wizard' => 'next', '_wizard_step' => 'details']), self::options());
        self::assertSame(200, $empty['status'], (string) $empty['error']);
        self::assertSame('details', self::current($empty['body']));
        self::assertStringContainsString('Fix these before going on: Title.', $empty['body']);
        self::assertStringNotContainsString('City is required', $empty['body'], 'a later step is not checked yet');
        self::assertStringContainsString('1 problem', $empty['body']);

        $next = self::post('/en/admin/rentals/create', self::answers(['city' => '', 'price' => '', '_wizard' => 'next', '_wizard_step' => 'details']), self::options());
        self::assertSame('location', self::current($next['body']));
        self::assertStringContainsString('data-wizard-moved', $next['body']);
        self::assertStringNotContainsString('is required', $next['body']);
        self::assertSame(1, self::rows(), 'nothing is saved before the last step');
        self::assertStringContainsString('name="_wizard" value="go:details"', $next['body'], 'a done step can be opened again');
        self::assertStringNotContainsString('name="_wizard" value="go:pricing"', $next['body'], 'a step not reached yet cannot');
    }

    public function testAStepShownForOneAnswerIsSkippedForTheOthers(): void
    {
        $sale = self::post('/en/admin/rentals/create', self::answers(['_wizard' => 'next', '_wizard_step' => 'location', '_wizard_reached' => 'location']), self::options());
        self::assertSame('pricing', self::current($sale['body']), 'no Rental terms for a sale');

        $rent = self::post('/en/admin/rentals/create', self::answers(['type' => 'rent', '_wizard' => 'next', '_wizard_step' => 'location', '_wizard_reached' => 'location']), self::options());
        self::assertSame('terms', self::current($rent['body']));
        self::assertStringContainsString('Step 3 of 5', $rent['body']);
    }

    public function testBackAndStepButtonsNeverCheckOrSave(): void
    {
        $back = self::post('/en/admin/rentals/create', self::answers(['city' => '', '_wizard' => 'back', '_wizard_step' => 'location', '_wizard_reached' => 'pricing']), self::options());
        self::assertSame('details', self::current($back['body']));
        self::assertStringNotContainsString('is required', $back['body']);
        self::assertStringContainsString('name="_wizard_reached" value="pricing"', $back['body'], 'the furthest step is remembered');
        self::assertStringContainsString('name="_wizard" value="go:pricing"', $back['body']);

        $jump = self::post('/en/admin/rentals/create', self::answers(['_wizard' => 'go:pricing', '_wizard_step' => 'details', '_wizard_reached' => 'pricing']), self::options());
        self::assertSame('pricing', self::current($jump['body']));
        $refused = self::post('/en/admin/rentals/create', self::answers(['_wizard' => 'go:_review', '_wizard_step' => 'details', '_wizard_reached' => 'location']), self::options());
        self::assertSame('details', self::current($refused['body']), 'a new record cannot jump past the furthest step reached');
        self::assertSame(1, self::rows());
    }

    public function testEnterBeforeTheLastStepMeansNext(): void
    {
        // The form's hidden default button sends no move: on a new record it means Next.
        $enter = self::post('/en/admin/rentals/create', self::answers(['_wizard_step' => 'details']), self::options());
        self::assertSame(200, $enter['status']);
        self::assertSame('location', self::current($enter['body']));
        self::assertSame(1, self::rows());
    }

    public function testTheReviewStepListsTheAnswersAndCreateSavesEverything(): void
    {
        $review = self::post('/en/admin/rentals/create', self::answers(['_wizard' => 'next', '_wizard_step' => 'pricing', '_wizard_reached' => 'pricing']), self::options());
        self::assertSame('_review', self::current($review['body']));
        self::assertStringContainsString('fa-wizard-answers', $review['body']);
        self::assertStringContainsString('Stone house', $review['body']);
        self::assertStringContainsString('name="_wizard" value="go:location"', $review['body'], 'each group has an Edit button');
        self::assertStringContainsString('data-wizard-finish', $review['body']);
        self::assertStringNotContainsString('Lease (months)', substr($review['body'], (int) strpos($review['body'], 'fa-wizard-review')), 'a hidden step is not reviewed');

        $created = self::post('/en/admin/rentals/create', self::answers(['lease_months' => '24', '_close' => '1', '_wizard_step' => '_review', '_wizard_reached' => '_review']), self::options());
        self::assertTrue($created['redirected'], (string) $created['error']);
        $row = self::row('rentals', 2);
        self::assertSame('Chania', $row['city']);
        self::assertSame('{"en":"Stone house"}', $row['title']);
        self::assertNull($row['lease_months'], 'the fields of a hidden step are not saved');
    }

    public function testTheFinalSubmitOpensTheFirstStepWithAnError(): void
    {
        $failed = self::post('/en/admin/rentals/create', self::answers(['city' => '', 'price' => '', '_close' => '1', '_wizard_step' => '_review', '_wizard_reached' => '_review']), self::options());
        self::assertSame(200, $failed['status'], (string) $failed['error']);
        self::assertSame('location', self::current($failed['body']));
        self::assertStringContainsString('City (Location), Price (Pricing)', $failed['body']);
        self::assertSame(1, self::rows());
    }

    public function testAStepCheckRunsAfterItsRules(): void
    {
        $high = self::post('/en/admin/rentals/create', self::answers(['type' => 'rent', 'lease_months' => '12', 'price' => '90000', '_wizard' => 'next', '_wizard_step' => 'pricing', '_wizard_reached' => 'pricing']), self::options());
        self::assertSame('pricing', self::current($high['body']));
        self::assertStringContainsString('looks like a sale price', $high['body']);

        // On the final submit too, which then opens that step.
        $final = self::post('/en/admin/rentals/create', self::answers(['type' => 'rent', 'lease_months' => '12', 'price' => '90000', '_close' => '1', '_wizard_step' => '_review', '_wizard_reached' => '_review']), self::options());
        self::assertSame('pricing', self::current($final['body']));
        self::assertSame(1, self::rows());
    }

    public function testTheEditPageOpensAnyStepAndSavesFromEveryStep(): void
    {
        $page = self::request('GET', '/en/admin/rentals/edit/1', self::options());
        self::assertSame(200, $page['status'], (string) $page['error']);
        self::assertStringContainsString('data-wizard-mode="edit"', $page['body']);
        self::assertSame('details', self::current($page['body']));
        foreach (['location', 'terms', 'pricing', '_review'] as $step) { self::assertStringContainsString('value="go:' . $step . '"', $page['body']); }
        self::assertStringContainsString('data-wizard-finish', $page['body'], 'Save on the first step');

        $jump = self::post('/en/admin/rentals/edit/1', self::answers(['type' => 'rent', 'lease_months' => '12', 'price' => '2400', '_wizard' => 'go:pricing', '_wizard_step' => 'details']), self::options());
        self::assertSame('pricing', self::current($jump['body']));

        $saved = self::post('/en/admin/rentals/edit/1', self::answers(['type' => 'rent', 'lease_months' => '18', 'price' => '2500', '_continue' => '1', '_wizard_step' => 'details']), self::options());
        self::assertTrue($saved['redirected'], (string) $saved['error']);
        self::assertSame('18', (string) self::row('rentals', 1)['lease_months']);

        $failed = self::post('/en/admin/rentals/edit/1', self::answers(['type' => 'rent', 'lease_months' => '', 'price' => '2500', '_continue' => '1', '_wizard_step' => 'pricing']), self::options());
        self::assertSame('terms', self::current($failed['body']), 'Save opens the step with the error');
        self::assertStringContainsString('Lease (months) (Rental terms)', $failed['body']);
    }

    public function testTheViewPageShowsEachStepAsACard(): void
    {
        $view = self::request('GET', '/en/admin/rentals/view/1', self::options());
        self::assertSame(200, $view['status'], (string) $view['error']);
        foreach (['Details', 'Location', 'Rental terms', 'Pricing'] as $title) { self::assertStringContainsString('>' . $title . '</h2>', $view['body']); }
        self::assertStringNotContainsString('data-admin-wizard', $view['body']);
    }

    private static function operation(string $body): string
    {
        self::assertSame(1, preg_match('/name="_operation" value="([^"]+)"/', $body, $m));
        return html_entity_decode($m[1], ENT_QUOTES);
    }

    public function testAnActionPageMovesThroughItsStepsThenRuns(): void
    {
        $page = self::request('GET', '/en/admin/rental-dialogs/action/row/schedule/1', self::options());
        self::assertSame(200, $page['status'], (string) $page['error']);
        self::assertSame('visitor', self::current($page['body']));
        self::assertStringNotContainsString('fa-btn-primary">Schedule a viewing</button>', $page['body'], 'the action button waits for the last step');
        $options = self::options(['session' => $page['session']]);
        $token = self::operation($page['body']);

        $empty = self::post('/en/admin/rental-dialogs/action/row/schedule/1', ['_operation' => $token, 'visitor' => '', 'at' => '', '_wizard' => 'next', '_wizard_step' => 'visitor'], $options);
        self::assertSame('visitor', self::current($empty['body']));
        self::assertStringContainsString('Visitor is required', $empty['body']);
        self::assertStringNotContainsString('Time is required', $empty['body']);

        $next = self::post('/en/admin/rental-dialogs/action/row/schedule/1', ['_operation' => $token, 'visitor' => 'Sara', 'at' => '', '_wizard_step' => 'visitor'], $options);
        self::assertSame('time', self::current($next['body']), 'Enter before the last step means Next');
        self::assertStringContainsString('fa-btn-primary">Schedule a viewing</button>', $next['body']);

        $done = self::post('/en/admin/rental-dialogs/action/row/schedule/1', ['_operation' => $token, 'visitor' => 'Sara', 'at' => '17:30', 'message' => '', '_wizard_step' => 'time', '_wizard_reached' => 'time'], $options);
        self::assertTrue($done['redirected'], (string) $done['error']);
        self::assertSame('Viewing with Sara at 17:30:00', self::row('rentals', 1)['notes']);
    }

    public function testABulkActionPageMovesThroughItsStepsThenRuns(): void
    {
        $page = self::request('GET', '/en/admin/rental-dialogs/bulk/reprice?ids[]=1', self::options());
        self::assertSame(200, $page['status'], (string) $page['error']);
        self::assertSame('amount', self::current($page['body']));
        $options = self::options(['session' => $page['session']]);
        $token = self::operation($page['body']);
        $next = self::post('/en/admin/rental-dialogs/bulk/reprice', ['_operation' => $token, '_ids' => '["1"]', 'percent' => '5', 'reason' => '', '_wizard' => 'next', '_wizard_step' => 'amount'], $options);
        self::assertSame('why', self::current($next['body']));
        self::assertStringNotContainsString('is required', $next['body']);
        $failed = self::post('/en/admin/rental-dialogs/bulk/reprice', ['_operation' => $token, '_ids' => '["1"]', 'percent' => '', 'reason' => 'Season', '_wizard_step' => 'why', '_wizard_reached' => 'why'], $options);
        self::assertSame('amount', self::current($failed['body']), 'the final submit opens the step with the error');
        $done = self::post('/en/admin/rental-dialogs/bulk/reprice', ['_operation' => $token, '_ids' => '["1"]', 'percent' => '5', 'reason' => 'Season', '_wizard_step' => 'why', '_wizard_reached' => 'why'], $options);
        self::assertTrue($done['redirected'], (string) $done['error']);
        self::assertSame('Repriced by 5%: Season', self::row('rentals', 1)['notes']);
    }

    public function testACustomPageFormMovesThroughItsSteps(): void
    {
        $page = self::request('GET', '/en/admin/moving-in', self::options());
        self::assertSame(200, $page['status'], (string) $page['error']);
        self::assertSame('who', self::current($page['body']));
        self::assertStringNotContainsString('name="_save"', $page['body']);
        $options = self::options(['session' => $page['session']]);
        $next = self::post('/en/admin/moving-in', ['_page_form' => '1', 'tenant' => 'Sara', 'month' => '', '_wizard' => 'next', '_wizard_step' => 'who'], $options);
        self::assertSame('when', self::current($next['body']));
        self::assertStringContainsString('name="_save"', $next['body']);
        // A live field (a later step depends on the month) refreshes the form: same step, nothing sent.
        $refresh = self::post('/en/admin/moving-in', ['_page_form' => '1', 'tenant' => 'Sara', 'month' => 'feb', '_refresh' => '1', '_wizard_step' => 'when', '_wizard_reached' => 'when'], $options);
        self::assertSame('when', self::current($refresh['body']));
        self::assertStringContainsString('Step 2 of 3', $refresh['body']);
        self::assertArrayNotHasKey('moving_in', $refresh['session']);
        $sent = self::post('/en/admin/moving-in', ['_page_form' => '1', 'tenant' => 'Sara', 'month' => 'jan', '_save' => '1', '_wizard_step' => 'when', '_wizard_reached' => 'when'], $options);
        self::assertTrue($sent['redirected'], (string) $sent['error']);
        self::assertSame('Sara/jan/', $sent['session']['moving_in'] ?? null);
    }

    public function testThePageLevelLanguageSwitchStaysAboveTheSteps(): void
    {
        $page = self::request('GET', '/en/admin/rental-pages/edit/1?editing=ar', self::options());
        self::assertSame(200, $page['status'], (string) $page['error']);
        self::assertStringContainsString('name="_editing" value="ar"', $page['body']);
        self::assertLessThan(strpos($page['body'], 'data-admin-wizard'), strpos($page['body'], 'fa-record-switch'));
        $moved = self::post('/en/admin/rental-pages/edit/1', self::answers(['type' => 'rent', 'lease_months' => '12', 'title' => ['en' => 'Villa', 'ar' => 'فيلا'], '_editing' => 'ar', '_wizard' => 'go:pricing', '_wizard_step' => 'details']), self::options());
        self::assertSame('pricing', self::current($moved['body']));
        self::assertStringContainsString('name="_editing" value="ar"', $moved['body'], 'the language shown stays');
        $saved = self::post('/en/admin/rental-pages/edit/1', self::answers(['type' => 'rent', 'lease_months' => '12', 'price' => '2400', 'title' => ['en' => 'Villa', 'ar' => 'فيلا'], '_editing' => 'ar', '_continue' => '1', '_wizard_step' => 'pricing']), self::options());
        preg_match('/fa-notification-title">([^<]*)/', $saved['body'], $flash);
        self::assertTrue($saved['redirected'], (string) $saved['error'] . ($flash[1] ?? ''));
        self::assertSame('{"en":"Villa","ar":"فيلا"}', self::row('rentals', 1)['title']);
    }
}
