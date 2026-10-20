<?php
declare(strict_types=1);

namespace FloCMS\Admin\Mailings;

use FloCMS\Admin\Activity\Activity;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Mail\Mailer;
use FloCMS\Admin\Mail\MailException;
use FloCMS\Admin\Support\Actor;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Users\UsersResource;
use FloCMS\Core\App;
use FloCMS\Core\Database;

/**
 * Mailings (0.28): an email to many users, sent in batches at the rate the
 * host allows. A mailing keeps its recipients in a table; each step sends the
 * next few (at most 'per_batch', and never more than 'per_hour' in the last
 * hour for the whole site). Steps come from the open Mailings page and from
 * `php flo admin:mailings` (cron). Each email has an unsubscribe link; people
 * who unsubscribed are left out of later mailings.
 *
 * @internal
 */
final class Mailings
{
    public const STATUSES = ['sending', 'paused', 'cancelled', 'finished'];
    private const ATTEMPTS = 3;
    /** Seconds a mailing waits after the host refused an email. */
    private const COOLDOWN = 60;

    private static ?string $status = null;
    private static ?Database $statusFor = null;

    /** 'off', 'missing' (no tables yet) or 'ready'. */
    public static function status(?Database $db = null): string
    {
        $config = Admin::config();
        if (!$config->isInstalled() || !$config->mailings()['enabled']) { return 'off'; }
        $db ??= App::db();
        if ($db === null) { return 'missing'; }
        if (self::$status === null || self::$statusFor !== $db) {
            self::$statusFor = $db;
            try {
                $db->query('SELECT `id`, `status`, `locked_until` FROM `' . self::table() . '` WHERE 1 = 0');
                $db->query('SELECT `id`, `mailing_id`, `sent_at` FROM `' . self::recipientsTable() . '` WHERE 1 = 0');
                $db->query('SELECT `email_hash` FROM `' . self::optoutsTable() . '` WHERE 1 = 0');
                self::$status = 'ready';
            } catch (\Throwable) {
                self::$status = 'missing';
            }
        }
        return self::$status;
    }

    public static function available(?Database $db = null): bool { return self::status($db) === 'ready'; }
    public static function reset(): void { self::$status = null; self::$statusFor = null; }
    public static function table(): string { return Admin::config()->mailings()['table']; }
    public static function recipientsTable(): string { return Admin::config()->mailings()['recipients_table']; }
    public static function optoutsTable(): string { return Admin::config()->mailings()['optouts_table']; }

    private static function db(): Database
    {
        return App::db() ?? throw new \RuntimeException('Mailings need a database connection.');
    }

    /**
     * The users an audience reaches, as id => [email, name]: everyone, a role,
     * a status, or chosen ids; active only unless asked; people who
     * unsubscribed left out (counted apart).
     *
     * @param array{type: string, value?: string|list<string>, active_only?: bool} $audience
     * @return array{people: array<int, array{email: string, name: string}>, unsubscribed: int}
     */
    public static function audience(array $audience): array
    {
        $db = self::db();
        $query = $db->table('users')->select(['id', 'email', 'full_name', 'status']);
        $type = (string) ($audience['type'] ?? 'everyone');
        $value = $audience['value'] ?? '';
        if ($type === 'role') { $query = $query->where('role', '=', (int) $value); }
        if ($type === 'status') { $query = $query->where('status', '=', (int) $value); }
        if ($type === 'users') {
            $ids = array_values(array_filter(array_map('intval', is_array($value) ? $value : explode(',', (string) $value)), static fn (int $id): bool => $id > 0));
            if ($ids === []) { return ['people' => [], 'unsubscribed' => 0]; }
            $query = $query->whereIn('id', array_slice($ids, 0, 1000));
        }
        // Suspended users only when the audience is "Suspended" itself.
        if (($audience['active_only'] ?? true) && $type !== 'status') { $query = $query->where('status', '=', UsersResource::ACTIVE); }
        elseif ($type !== 'status') { $query = $query->where('status', '!=', UsersResource::SUSPENDED); }
        $people = []; $unsubscribed = 0;
        $optouts = self::available($db) && self::hasKey() ? array_flip(array_map(static fn ($row): string => (string) $row->email_hash, $db->table(self::optoutsTable())->select(['email_hash'])->get())) : [];
        foreach ($query->orderBy('id', 'asc')->limit(100000)->get() as $row) {
            $email = trim((string) ($row->email ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) { continue; }
            // A chosen user gets a direct email whatever they unsubscribed from; mailings respect it.
            if ($type !== 'users' && $optouts !== [] && isset($optouts[self::emailHash($email)])) { ++$unsubscribed; continue; }
            $people[(int) $row->id] = ['email' => $email, 'name' => trim((string) ($row->full_name ?? ''))];
        }
        return ['people' => $people, 'unsubscribed' => $unsubscribed];
    }

    /**
     * Save a mailing and its recipients; sending starts with the next step.
     *
     * @param array{type: string, value?: mixed, active_only?: bool} $audience
     */
    public static function start(string $subject, string $body, array $audience): int
    {
        $db = self::db();
        $found = self::audience($audience);
        if ($found['people'] === []) { throw new \InvalidArgumentException('Nobody to send to.'); }
        self::key();
        $now = gmdate('Y-m-d H:i:s');
        return (int) $db->transaction(static function (Database $db) use ($subject, $body, $audience, $found, $now): int {
            $id = $db->table(self::table())->insert([
                'subject' => mb_substr($subject, 0, 255), 'body' => $body,
                'audience' => json_encode($audience, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'status' => 'sending', 'total' => count($found['people']), 'sent' => 0, 'failed' => 0, 'skipped' => $found['unsubscribed'],
                'user_id' => Actor::id(), 'user_name' => Actor::name(), 'lang' => AdminLang::language(),
                'created_at' => $now, 'finished_at' => null, 'locked_until' => null,
            ]);
            foreach (array_chunk($found['people'], 200, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $userId => $person) {
                    $rows[] = ['mailing_id' => $id, 'user_id' => $userId, 'email' => mb_substr($person['email'], 0, 190), 'name' => mb_substr($person['name'], 0, 190), 'status' => 'waiting', 'attempts' => 0, 'error' => null, 'sent_at' => null];
                }
                foreach ($rows as $row) { $db->table(self::recipientsTable())->insert($row); }
            }
            Activity::record('mailing_started', 'mailings', (string) $id, $subject, meta: ['count' => count($found['people'])], db: $db);
            return $id;
        });
    }

    public static function find(int $id): ?Mailing
    {
        $row = self::db()->table(self::table())->where('id', '=', $id)->first();
        return $row !== null ? Mailing::fromRow((object) $row) : null;
    }

    /**
     * @return array{data: list<Mailing>, total: int}
     */
    public static function list(int $page, int $perPage = 20): array
    {
        $db = self::db();
        $total = $db->table(self::table())->count();
        $rows = $db->table(self::table())->orderBy('id', 'desc')->limit($perPage)->offset(max(0, ($page - 1) * $perPage))->get();
        return ['data' => array_map(static fn ($row): Mailing => Mailing::fromRow((object) $row), $rows), 'total' => $total];
    }

    /** How many mailing emails the site may still send this hour. */
    public static function allowance(): int
    {
        $settings = Admin::config()->mailings();
        $sent = self::db()->table(self::recipientsTable())->where('status', '=', 'sent')->where('sent_at', '>', gmdate('Y-m-d H:i:s', time() - 3600))->count();
        return max(0, $settings['per_hour'] - $sent);
    }

    /**
     * Send the next batch of a mailing that is sending. One step at a time per
     * mailing (a lock), so the page and cron never send the same email twice.
     *
     * @return array{state: string, sent: int}  state: sent | waiting (hour's limit reached) | busy | done | stopped
     */
    public static function step(int $id, ?int $limit = null): array
    {
        $db = self::db();
        $mailing = self::find($id);
        if ($mailing === null || $mailing->status !== 'sending') { return ['state' => 'stopped', 'sent' => 0]; }
        if (!self::hasKey()) { return ['state' => 'waiting', 'sent' => 0]; }
        $allowance = min(self::allowance(), $limit ?? Admin::config()->mailings()['per_batch']);
        if ($allowance <= 0) { return ['state' => 'waiting', 'sent' => 0]; }
        $token = self::claim($db, $id);
        if ($token === null) { return ['state' => 'busy', 'sent' => 0]; }
        $sentNow = 0; $release = true;
        try {
            // Paused or cancelled between the check above and the lock: nothing is sent.
            $mailing = self::find($id);
            if ($mailing === null || $mailing->status !== 'sending') { return ['state' => 'stopped', 'sent' => 0]; }
            // A step that ended halfway (a timeout) left its emails "sending": they may have gone out, so
            // they are not sent again but marked failed; Retry failed sends them once more.
            $db->table(self::recipientsTable())->where('mailing_id', '=', $id)->where('status', '=', 'sending')->update(['status' => 'failed', 'error' => 'Interrupted while sending']);
            $recipients = $db->table(self::recipientsTable())->where('mailing_id', '=', $id)->where('status', '=', 'waiting')->orderBy('id', 'asc')->limit($allowance)->get();
            $lang = $mailing->lang !== '' ? $mailing->lang : AdminLang::language();
            foreach ($recipients as $recipient) {
                // The lock is renewed for each email, and each email is taken before it is sent:
                // a slow host never lets a second step send the same email.
                $token = self::renew($db, $id, $token);
                if ($token === null) { $release = false; break; }
                if ($db->query('UPDATE `' . self::recipientsTable() . '` SET `status` = ? WHERE `id` = ? AND `status` = ?', ['sending', (int) $recipient->id, 'waiting'])->rowCount() !== 1) { continue; }
                $update = ['attempts' => (int) $recipient->attempts + 1];
                $failed = false;
                try {
                    Mailer::send(MailMessage::make($mailing->subject, $mailing->body, (string) $recipient->email, (string) $recipient->name, $lang, true));
                    $update += ['status' => 'sent', 'sent_at' => gmdate('Y-m-d H:i:s'), 'error' => null];
                    ++$sentNow;
                } catch (MailException|\InvalidArgumentException $error) {
                    $update += ['error' => mb_substr($error->getMessage(), 0, 255), 'status' => $update['attempts'] >= self::ATTEMPTS ? 'failed' : 'waiting'];
                    $failed = $error instanceof MailException;
                }
                $db->table(self::recipientsTable())->where('id', '=', (int) $recipient->id)->update($update);
                if ($failed) {
                    // The host refused: the mailing waits a minute before trying again, so three
                    // attempts are spread out and a busy host isn't asked again at once.
                    $db->query('UPDATE `' . self::table() . '` SET `locked_until` = ? WHERE `id` = ? AND `locked_until` = ?', [(time() + self::COOLDOWN) . '|cooldown', $id, $token]);
                    $release = false;
                    self::recount($db, $id);
                    return ['state' => 'waiting', 'sent' => $sentNow];
                }
            }
            self::recount($db, $id);
            $left = $db->table(self::recipientsTable())->where('mailing_id', '=', $id)->where('status', '=', 'waiting')->count();
            if ($left === 0) {
                $db->table(self::table())->where('id', '=', $id)->where('status', '=', 'sending')->update(['status' => 'finished', 'finished_at' => gmdate('Y-m-d H:i:s')]);
                $done = self::find($id);
                Activity::record('mailing_finished', 'mailings', (string) $id, $mailing->subject, meta: ['count' => $done?->sent ?? 0, 'failed' => $done?->failed ?? 0], db: $db);
                return ['state' => 'done', 'sent' => $sentNow];
            }
            return ['state' => 'sent', 'sent' => $sentNow];
        } finally {
            if ($release && $token !== null) { self::release($db, $id, $token); }
        }
    }

    /** Send what the hour allows for every mailing that is sending (cron). @return int emails sent */
    public static function run(int $seconds = 50): int
    {
        $until = time() + max(1, $seconds); $total = 0;
        foreach (self::db()->table(self::table())->where('status', '=', 'sending')->orderBy('id', 'asc')->get() as $row) {
            while (time() < $until) {
                $result = self::step((int) $row->id);
                $total += $result['sent'];
                if ($result['state'] !== 'sent') { break; }
            }
            if (time() >= $until || self::allowance() === 0) { break; }
        }
        self::purge();
        return $total;
    }

    public static function pause(int $id): bool { return self::move($id, 'sending', 'paused', 'mailing_paused'); }
    public static function resume(int $id): bool { return self::move($id, 'paused', 'sending', null); }

    public static function cancel(int $id): bool
    {
        $db = self::db();
        $mailing = self::find($id);
        if ($mailing === null || !in_array($mailing->status, ['sending', 'paused'], true)) { return false; }
        if ($db->table(self::table())->where('id', '=', $id)->where('status', '=', $mailing->status)->update(['status' => 'cancelled', 'finished_at' => gmdate('Y-m-d H:i:s')]) === false) { return false; }
        Activity::record('mailing_cancelled', 'mailings', (string) $id, $mailing->subject, meta: ['count' => $mailing->sent], db: $db);
        return true;
    }

    /** Send the failed ones again (a finished mailing goes back to sending). */
    public static function retry(int $id): bool
    {
        $db = self::db();
        $mailing = self::find($id);
        if ($mailing === null || $mailing->failed === 0 || !in_array($mailing->status, ['finished', 'paused', 'sending'], true)) { return false; }
        $db->table(self::recipientsTable())->where('mailing_id', '=', $id)->where('status', '=', 'failed')->update(['status' => 'waiting', 'attempts' => 0]);
        // A paused mailing stays paused; a finished one goes on sending.
        $db->table(self::table())->where('id', '=', $id)->update(['status' => $mailing->status === 'paused' ? 'paused' : 'sending', 'finished_at' => null]);
        self::recount($db, $id);
        return true;
    }

    /** Recipient lists of mailings finished more than 'keep_days' ago are deleted. */
    public static function purge(): void
    {
        $db = self::db();
        $before = gmdate('Y-m-d H:i:s', time() - Admin::config()->mailings()['keep_days'] * 86400);
        foreach ($db->table(self::table())->whereIn('status', ['finished', 'cancelled'])->where('finished_at', '<', $before)->select(['id'])->get() as $row) {
            $db->table(self::recipientsTable())->where('mailing_id', '=', (int) $row->id)->delete();
        }
    }

    // Unsubscribing

    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', 'optout|' . mb_strtolower(trim($email)), self::key());
    }

    /** The token in a mailing's unsubscribe link: the address's hash and a signature. */
    public static function unsubscribeToken(string $email): string
    {
        $hash = self::emailHash($email);
        return $hash . '.' . substr(hash_hmac('sha256', 'unsubscribe|' . $hash, self::key()), 0, 32);
    }

    /** The address hash a valid token names, or null. */
    public static function tokenHash(string $token): ?string
    {
        if (!self::hasKey() || preg_match('/^([a-f0-9]{64})\.([a-f0-9]{32})$/D', $token, $m) !== 1) { return null; }
        return hash_equals(substr(hash_hmac('sha256', 'unsubscribe|' . $m[1], self::key()), 0, 32), $m[2]) ? $m[1] : null;
    }

    public static function unsubscribe(string $hash): void
    {
        $db = self::db();
        if ($db->table(self::optoutsTable())->where('email_hash', '=', $hash)->first() !== null) { return; }
        try { $db->table(self::optoutsTable())->insert(['email_hash' => $hash, 'created_at' => gmdate('Y-m-d H:i:s')]); }
        catch (\PDOException) { /* unsubscribed twice at the same moment */ }
    }

    public static function isUnsubscribed(string $email): bool
    {
        return self::available() && self::hasKey() && self::db()->table(self::optoutsTable())->where('email_hash', '=', self::emailHash($email))->first() !== null;
    }

    /** The site's APP_KEY signs unsubscribe links; mailings are not available without one. */
    public static function hasKey(): bool { return strlen(self::rawKey()) >= 16; }

    private static function rawKey(): string { return (string) ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: ''); }

    private static function key(): string
    {
        if (!self::hasKey()) { throw new \RuntimeException('Mailings need APP_KEY in .env (at least 16 characters) to sign unsubscribe links.'); }
        return self::rawKey();
    }

    private static function move(int $id, string $from, string $to, ?string $activity): bool
    {
        $db = self::db();
        $mailing = self::find($id);
        if ($mailing === null || $mailing->status !== $from) { return false; }
        $db->table(self::table())->where('id', '=', $id)->where('status', '=', $from)->update(['status' => $to]);
        if ($activity !== null) { Activity::record($activity, 'mailings', (string) $id, $mailing->subject, db: $db); }
        return true;
    }

    private static function recount(Database $db, int $id): void
    {
        $counts = ['sent' => 0, 'failed' => 0];
        foreach ($db->table(self::recipientsTable())->selectRaw('`status` AS s, COUNT(*) AS n')->where('mailing_id', '=', $id)->groupBy('status')->get() as $row) {
            $row = (array) $row;
            if (isset($counts[$row['s']])) { $counts[$row['s']] = (int) $row['n']; }
        }
        $db->table(self::table())->where('id', '=', $id)->update($counts);
    }

    /** A lock for one step: "<unix time>|<random>", taken only when none is held or the last one ran out. */
    private static function claim(Database $db, int $id): ?string
    {
        $row = $db->table(self::table())->where('id', '=', $id)->first();
        $current = (string) ($row->locked_until ?? '');
        if ($current !== '' && (int) explode('|', $current)[0] > time()) { return null; }
        $token = (time() + 120) . '|' . bin2hex(random_bytes(8));
        $claimed = $current === ''
            ? $db->query('UPDATE `' . self::table() . '` SET `locked_until` = ? WHERE `id` = ? AND `locked_until` IS NULL', [$token, $id])->rowCount()
            : $db->query('UPDATE `' . self::table() . '` SET `locked_until` = ? WHERE `id` = ? AND `locked_until` = ?', [$token, $id, $current])->rowCount();
        return $claimed === 1 ? $token : null;
    }

    /** The lock again for another 120 seconds, or null when it was lost. */
    private static function renew(Database $db, int $id, string $token): ?string
    {
        $next = (time() + 120) . '|' . explode('|', $token, 2)[1];
        if ($next === $token) { return $token; }
        return $db->query('UPDATE `' . self::table() . '` SET `locked_until` = ? WHERE `id` = ? AND `locked_until` = ?', [$next, $id, $token])->rowCount() === 1 ? $next : null;
    }

    private static function release(Database $db, int $id, string $token): void
    {
        $db->table(self::table())->where('id', '=', $id)->where('locked_until', '=', $token)->update(['locked_until' => null]);
    }
}
