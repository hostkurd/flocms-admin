<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Console\Stub;

/**
 * The Users page (0.28) through whole requests: the list (avatar, email,
 * tabs, filter), the row and bulk actions (greyed out with reasons, skipped
 * in bulk), photos, and the emails they send. Ali (Admin, role 2) manages
 * Sara (Editor) and Dara (User); Root is above him; he is user 2 himself.
 */
final class UsersPageRequestTest extends RequestTestCase
{
    private const MAIL = ['MAIL_TRANSPORT' => 'log', 'MAIL_FROM' => 'panel@example.test', 'APP_URL' => 'https://example.test'];

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        foreach (['admin_password_resets', 'admin_trash', 'admin_activity'] as $table) { $pdo->exec('DROP TABLE IF EXISTS ' . $table); }
        foreach ([['password-resets-migration', ['table' => 'admin_password_resets']], ['trash-activity-migration', ['trash_table' => 'admin_trash', 'activity_table' => 'admin_activity']]] as [$stub, $values]) {
            $file = self::$root . '/' . $stub . '.php';
            file_put_contents($file, Stub::render($stub, $values));
            (require $file)->up(new \FloCMS\Core\Database($pdo));
        }
        $pdo->exec("UPDATE users SET status = 2 WHERE id = 4"); // Dara is pending
        $pdo->exec("UPDATE users SET is_verified = 1 WHERE id = 1");
        @unlink(self::mailLog());
    }

    protected function tearDown(): void
    {
        foreach (['admin_password_resets', 'admin_trash', 'admin_activity'] as $table) { self::pdo()->exec('DROP TABLE IF EXISTS ' . $table); }
        parent::tearDown();
    }

    private static function admin(array $extra = []): array
    {
        return ['session' => self::ADMIN, 'admin_config' => ['password_reset' => true] + ($extra['admin_config'] ?? []), 'env' => self::MAIL] + $extra;
    }

    private static function mailLog(): string { return self::$root . '/storage/logs/admin-mail.log'; }

    /** @return list<string> */
    private static function mails(): array
    {
        $log = is_file(self::mailLog()) ? (string) file_get_contents(self::mailLog()) : '';
        return array_values(array_filter(explode('==== ', $log)));
    }

    private static function operation(array $page): string
    {
        self::assertSame(1, preg_match('/name="_operation" value="([^"]+)"/', $page['body'], $match), $page['body']);
        return $match[1];
    }

    /** Open a row action's page and submit it. */
    private static function runAction(string $action, int $id, array $input = [], array $options = []): array
    {
        $page = self::request('GET', '/en/admin/accounts/action/row/' . $action . '/' . $id, self::admin($options));
        self::assertSame(200, $page['status'], $page['body']);
        return self::post('/en/admin/accounts/action/row/' . $action . '/' . $id, ['_operation' => self::operation($page)] + $input, ['session' => $page['session']] + self::admin($options));
    }

    public function testTheListShowsPeopleWithTabsAndAFilter(): void
    {
        $page = self::request('GET', '/en/admin/accounts', self::admin());
        self::assertSame(200, $page['status'], $page['body']);
        $body = $page['body'];
        self::assertMatchesRegularExpression('~<span class="fa-avatar fa-avatar-size fa-avatar-c[1-6]" aria-hidden="true">S</span>~', $body, 'Sara\'s letter');
        self::assertStringContainsString('<span class="fa-cell-sub"><bdi>sara@example.test</bdi>', $body);
        self::assertStringContainsString('<span class="fa-sr">Email verified</span>', $body, 'Sara is verified');
        self::assertStringContainsString('<a class="fa-row-link" href="https://example.test/en/admin/accounts/edit/1">Sara</a>', $body, 'the name, not the whole cell, is the link');
        self::assertStringNotContainsString('<th scope="col" class="">Id</th>', $body);
        self::assertStringContainsString('<span class="fa-status fa-status-yellow"><span class="fa-status-dot" aria-hidden="true"></span>Pending</span>', $body);
        // Tabs with counts: All 4, Active 3, Pending 1, Suspended 0
        self::assertMatchesRegularExpression('~class="fa-list-tab is-current"[^>]*><span>All</span><span class="fa-tab-count">4</span>~', $body);
        self::assertMatchesRegularExpression('~<span>Pending</span><span class="fa-tab-count">1</span>~', $body);
        self::assertMatchesRegularExpression('~<span>Suspended</span><span class="fa-tab-count">0</span>~', $body);
        self::assertStringNotContainsString('Email users', $body, 'the header action needs users.mailing, which Admins don\'t have by default');

        $pending = self::request('GET', '/en/admin/accounts?filter[status]=2', self::admin())['body'];
        self::assertStringContainsString('dara@example.test', $pending);
        self::assertStringNotContainsString('sara@example.test', $pending);
        self::assertMatchesRegularExpression('~class="fa-list-tab is-current"[^>]*><span>Pending</span>~', $pending);
        self::assertStringContainsString('<input type="hidden" name="filter[status]" value="2" data-admin-filter="status">', $pending, 'the Filters menu keeps the tab');

        $editors = self::request('GET', '/en/admin/accounts?filter[role]=1', self::admin())['body'];
        self::assertStringContainsString('sara@example.test', $editors);
        self::assertStringNotContainsString('dara@example.test', $editors);
        // The search also looks in the email column
        $found = self::request('GET', '/en/admin/accounts?q=dara%40example', self::admin())['body'];
        self::assertStringContainsString('dara@example.test', $found);
        self::assertStringNotContainsString('sara@example.test', $found);
    }

    public function testTheRowMenuGreysOutWhatDoesNotApply(): void
    {
        $body = self::request('GET', '/en/admin/accounts', self::admin())['body'];
        // Sara is active: Activate greyed out with the reason
        self::assertMatchesRegularExpression('~<span class="fa-menu-item is-disabled" role="menuitem" aria-disabled="true" aria-describedby="[^"]+-activate-1-reason" data-admin-action="activate">.*?<small id="[^"]+">Already active</small>~s', $body);
        self::assertStringContainsString('/en/admin/accounts/action/row/suspend/1', $body, 'Suspend is there for Sara');
        // Ali himself: no suspend, no reset link
        self::assertMatchesRegularExpression('~data-admin-action="suspend">.*?<small[^>]*>You can’t do this to your own account</small>~s', $body);
        // Root is above Ali
        self::assertStringContainsString('Your role can’t manage this user', $body);
        // Dara is pending: no reset link yet
        self::assertStringContainsString('Activate the account first', $body);
        self::assertStringContainsString('/en/admin/accounts/action/row/activate/4', $body);
        // A greyed-out action is refused on the server too
        self::assertSame(403, self::request('GET', '/en/admin/accounts/action/row/activate/1', self::admin())['status']);
        self::assertSame(403, self::request('GET', '/en/admin/accounts/action/row/suspend/2', self::admin())['status'], 'not yourself');
        self::assertSame(403, self::request('GET', '/en/admin/accounts/action/row/suspend/3', self::admin())['status'], 'not a higher role');
        // Without password reset turned on, the reset link is greyed out with the setting
        $off = self::request('GET', '/en/admin/accounts', ['session' => self::ADMIN, 'admin_config' => ['password_reset' => false]])['body'];
        self::assertStringContainsString('Password reset is off (config/admin.php)', $off);
    }

    public function testSuspendAndActivate(): void
    {
        $suspended = self::runAction('suspend', 1, ['reason' => 'Spam listings', 'notify' => '1']);
        self::assertTrue($suspended['redirected'], $suspended['body']);
        self::assertSame(3, (int) self::row('users', 1)['status']);
        $activity = self::pdo()->query("SELECT action, record_id, meta FROM admin_activity WHERE action = 'user_suspended'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('1', $activity['record_id']);
        self::assertSame('Spam listings', json_decode((string) $activity['meta'], true)['reason']);
        $mails = self::mails();
        self::assertCount(1, $mails);
        self::assertStringContainsString('Subject: Your Test Panel account is suspended', $mails[0]);
        self::assertStringNotContainsString('Spam listings', $mails[0], 'the reason is never sent');

        // Activate a pending user: verified too, no email unless asked
        $active = self::runAction('activate', 4);
        self::assertTrue($active['redirected'], $active['body']);
        self::assertSame(1, (int) self::row('users', 4)['status']);
        self::assertSame(1, (int) self::row('users', 4)['is_verified']);
        self::assertCount(1, self::mails());
    }

    public function testResetLinkSignsOutWhenAsked(): void
    {
        $before = self::row('users', 1)['password'];
        $sent = self::runAction('reset_link', 1, ['sign_out' => '1']);
        self::assertTrue($sent['redirected'], $sent['body']);
        $mails = self::mails();
        self::assertCount(1, $mails);
        self::assertStringContainsString('To: sara@example.test', $mails[0]);
        self::assertMatchesRegularExpression('#/admin/password/reset/[A-Za-z0-9_-]{43}#', $mails[0]);
        self::assertNotSame($before, self::row('users', 1)['password'], 'the current password stops working');
        self::assertSame(1, (int) self::pdo()->query("SELECT COUNT(*) FROM admin_activity WHERE action = 'password_link_sent' AND record_id = '1'")->fetchColumn());
        // Without sign-out the password stays
        $kept = self::row('users', 1)['password'];
        self::assertTrue(self::runAction('reset_link', 1)['redirected']);
        self::assertSame($kept, self::row('users', 1)['password']);
    }

    public function testBulkActionsSkipWhatTheyDoNotApplyTo(): void
    {
        // Suspend Sara, Ali (himself) and Root (higher): only Sara is suspended
        $open = self::request('GET', '/en/admin/accounts/bulk/suspend?ids[]=1&ids[]=2&ids[]=3', self::admin());
        self::assertSame(200, $open['status'], $open['body']);
        self::assertStringContainsString('2 are skipped:', $open['body']);
        self::assertStringContainsString('You can’t do this to your own account', $open['body']);
        preg_match('/name="_operation" value="([^"]+)"/', $open['body'], $match);
        $done = self::post('/en/admin/accounts/bulk/suspend', ['_operation' => $match[1], '_ids' => '["1","2","3"]'], ['session' => $open['session']] + self::admin());
        self::assertTrue($done['redirected'], $done['body']);
        self::assertSame(3, (int) self::row('users', 1)['status']);
        self::assertSame(1, (int) self::row('users', 2)['status']);
        self::assertSame(1, (int) self::row('users', 3)['status']);
        // Nothing left to do: refused with the reasons
        $none = self::request('GET', '/en/admin/accounts/bulk/suspend?ids[]=2&ids[]=3', self::admin());
        self::assertTrue($none['redirected'], 'opening a bulk action that applies to none goes back to the list');
        self::assertStringContainsString('Nothing to do', (string) json_encode($none['session'] ?? [], JSON_UNESCAPED_UNICODE), 'with the reasons');

        // Send email to a selection opens the composer with the ids
        $email = self::request('GET', '/en/admin/accounts/bulk/email?ids[]=1&ids[]=4', self::admin(['permissions' => array_replace(self::PERMISSIONS, [2 => ['content.*', 'users.manage', 'users.email']])]));
        self::assertTrue($email['redirected'], $email['body']);
    }

    public function testAPhotoIsStoredPublicAndShownInTheList(): void
    {
        // A photo staged as the panel's upload does it (the field's FileDrop), then saved with the form
        $dir = self::$root . '/storage/admin-uploads';
        if (!is_dir($dir . '/pending')) { mkdir($dir . '/pending', 0700, true); }
        $pending = 'pending/' . bin2hex(random_bytes(10)) . '.png';
        $image = imagecreatetruecolor(400, 300); imagefill($image, 0, 0, imagecolorallocate($image, 200, 80, 40)); imagepng($image, $dir . '/' . $pending); imagedestroy($image);
        $token = bin2hex(random_bytes(32));
        $session = self::ADMIN;
        $session['_flo_admin_uploads'] = [$token => ['path' => $pending, 'name' => 'me.png', 'size' => filesize($dir . '/' . $pending), 'issued' => time(), 'scope' => json_encode(['accounts', '1', 'image']), 'actor' => '2', 'csrf' => hash('sha256', 'tok')]];
        $edit = self::request('GET', '/en/admin/accounts/edit/1', self::admin());
        self::assertSame(200, $edit['status'], $edit['body']);
        self::assertStringContainsString('fa-upload fa-upload-avatar', $edit['body']);
        $saved = self::post('/en/admin/accounts/edit/1', ['full_name' => 'Sara', 'email' => 'sara@example.test', 'login' => 'sara@example.test', 'role' => '1', 'status' => '1', 'image' => '@upload:' . $token, '_close' => '1'],
            ['session' => $session] + self::admin());
        self::assertTrue($saved['redirected'], $saved['body']);
        $path = (string) self::row('users', 1)['image'];
        self::assertMatchesRegularExpression('~^accounts/image/[a-f0-9]{40}\.png$~', $path);
        $file = self::$root . '/public/uploads/admin/' . $path;
        self::assertFileExists($file, 'in the public uploads folder');
        self::assertSame([256, 256], array_slice(getimagesize($file) ?: [], 0, 2), 'cropped to a 256 px square');
        $list = self::request('GET', '/en/admin/accounts', self::admin())['body'];
        self::assertStringContainsString('<img class="fa-avatar fa-avatar-size fa-avatar-img" src="/uploads/admin/' . $path . '"', $list);
        // Removing it leaves '' (the skeleton's column is NOT NULL)
        $edit = self::request('GET', '/en/admin/accounts/edit/1', self::admin());
        $removed = self::post('/en/admin/accounts/edit/1', ['full_name' => 'Sara', 'email' => 'sara@example.test', 'login' => 'sara@example.test', 'role' => '1', 'status' => '1', '_remove_upload' => ['image' => '1'], '_upload_control' => ['image' => '1'], '_close' => '1'], ['session' => $edit['session']] + self::admin());
        self::assertTrue($removed['redirected'], $removed['body']);
        self::assertSame('', self::row('users', 1)['image']);
        self::assertFileDoesNotExist($file, 'the old photo is deleted');
    }

    public function testPeopleSetTheirOwnPhotoOnTheProfilePage(): void
    {
        $dir = self::$root . '/storage/admin-uploads';
        if (!is_dir($dir . '/pending')) { mkdir($dir . '/pending', 0700, true); }
        $pending = 'pending/' . bin2hex(random_bytes(10)) . '.png';
        $image = imagecreatetruecolor(300, 300); imagepng($image, $dir . '/' . $pending); imagedestroy($image);
        $token = bin2hex(random_bytes(32));
        $session = self::EDITOR;
        $session['_flo_admin_uploads'] = [$token => ['path' => $pending, 'name' => 'me.png', 'size' => filesize($dir . '/' . $pending), 'issued' => time(), 'scope' => json_encode(['accounts', '1', 'image']), 'actor' => '1', 'csrf' => hash('sha256', 'tok')]];
        $page = self::request('GET', '/en/admin/profile', ['session' => self::EDITOR]);
        self::assertStringContainsString('fa-upload fa-upload-avatar', $page['body']);
        $saved = self::post('/en/admin/profile', ['full_name' => 'Sara', 'email' => 'sara@example.test', 'login' => 'sara@example.test', 'password' => '', 'current_password' => '', 'image' => '@upload:' . $token], ['session' => $session]);
        self::assertTrue($saved['redirected'], $saved['body']);
        $path = (string) self::row('users', 1)['image'];
        self::assertMatchesRegularExpression('~^accounts/image/[a-f0-9]{40}\.png$~', $path);
        $top = self::request('GET', '/en/admin/dashboard', ['session' => self::EDITOR])['body'];
        self::assertStringContainsString('<img class="fa-avatar fa-avatar-size fa-avatar-img" src="/uploads/admin/' . $path . '"', $top, 'the top bar shows it');
    }
}
