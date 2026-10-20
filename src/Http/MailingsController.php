<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Activity\Activity;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\Fields\RichEditor;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormRenderer;
use FloCMS\Admin\Mail\Mailer;
use FloCMS\Admin\Mail\MailException;
use FloCMS\Admin\Mailings\Mailing;
use FloCMS\Admin\Mailings\Mailings;
use FloCMS\Admin\Mailings\MailMessage;
use FloCMS\Admin\Notifications\Notification;
use FloCMS\Admin\Support\Actor;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Users\UsersResource;
use FloCMS\Admin\Validation\FormValidator;
use FloCMS\Core\Auth;
use FloCMS\Core\HttpException;
use FloCMS\Core\Session;
use FloCMS\Core\TemplateEngine;

/**
 * Emails to users (0.28).
 *
 *  - /admin/mailings/compose: the composer. Up to 20 chosen users get a direct
 *    email at once (users.email); more, a role, a status or everyone get a
 *    mailing (users.mailing), sent in batches.
 *  - /admin/mailings: Site → Mailings, the mailings with their progress.
 *  - /admin/mailings/show/<id>: one mailing; the page sends its batches.
 *  - /admin/mailings/unsubscribe/<token>: the email's unsubscribe page (no sign-in).
 */
final class MailingsController extends PanelController
{
    public const DIRECT_LIMIT = 20;
    /** Chosen users the composer keeps; for more, send to a role, a status or everyone. */
    public const MAX_CHOSEN = 500;
    private const SESSION_KEY = 'admin_direct_mail';

    protected array $actionPermissions = [
        'admin_index' => 'users.mailing', 'admin_show' => 'users.mailing', 'admin_step' => 'users.mailing',
        'admin_pause' => 'users.mailing', 'admin_resume' => 'users.mailing', 'admin_cancel' => 'users.mailing', 'admin_retry' => 'users.mailing',
        'admin_compose' => null, 'admin_unsubscribe' => null,
    ];

    public function admin_index(): string|\FloCMS\Core\Http\Response
    {
        if (!$this->request->isMethod('GET', 'HEAD')) { header('Allow: GET'); throw new HttpException(405); }
        $ready = Mailings::available();
        $page = max(1, (int) ($this->request->queryValue('page', '1') ?? 1));
        $result = $ready ? Mailings::list($page) : ['data' => [], 'total' => 0];
        $active = array_values(array_filter($result['data'], static fn (Mailing $m): bool => $m->isActive()));
        $html = TemplateEngine::renderFile(PageView::viewPath('mailings'), [
            't' => PageView::translator(), 'ready' => $ready, 'composeUrl' => Admin::url('mailings/compose'), 'cardHtml' => self::cardRenderer(),
            'description' => AdminLang::get('mailings.description', ['batch' => Admin::config()->mailings()['per_batch'], 'hour' => Admin::config()->mailings()['per_hour']]),
            'active' => array_map(fn (Mailing $m): array => $this->card($m), $active),
            'rows' => array_map(fn (Mailing $m): array => $this->row($m), array_values(array_filter($result['data'], static fn (Mailing $m): bool => !$m->isActive()))),
            'plusIcon' => \FloCMS\Admin\Support\Icons::svg('plus'),
            'prevUrl' => $page > 1 ? Admin::url('mailings', ['page' => $page - 1 > 1 ? $page - 1 : null]) : null,
            'nextUrl' => $page * 20 < $result['total'] ? Admin::url('mailings', ['page' => $page + 1]) : null,
        ]);
        return $this->panel(AdminLang::get('mailings.title'), $html, 'mailings');
    }

    public function admin_show(): string|\FloCMS\Core\Http\Response
    {
        if (!$this->request->isMethod('GET', 'HEAD')) { header('Allow: GET'); throw new HttpException(405); }
        $mailing = $this->mailing();
        $html = TemplateEngine::renderFile(PageView::viewPath('mailing'), [
            't' => PageView::translator(), 'card' => $this->card($mailing), 'listUrl' => Admin::url('mailings'), 'cardHtml' => self::cardRenderer(),
            'backIcon' => \FloCMS\Admin\Support\Icons::svg('chevron-left', 'fa-icon fa-flip-rtl'),
            'body' => MailMessage::html($mailing->body, MailMessage::values((string) (Session::get('email') ?: 'you@example.com'), Actor::name() ?? '')),
            'richCss' => \FloCMS\Admin\Assets::richTextUrl(),
        ]);
        return $this->panel($mailing->subject, $html, 'mailings');
    }

    /** POST: send the next batch (the page calls it every few seconds; without JavaScript, the Continue button). */
    public function admin_step(): string|\FloCMS\Core\Http\Response
    {
        if (!$this->isPost()) { header('Allow: POST'); throw new HttpException(405); }
        $mailing = $this->mailing();
        $result = Mailings::step($mailing->id);
        $after = Mailings::find($mailing->id) ?? $mailing;
        if ($this->wantsJson()) {
            return \FloCMS\Core\Http\Response::json(['state' => $result['state'], 'status' => $after->status, 'sent' => $after->sent, 'failed' => $after->failed,
                'waiting' => $after->waiting(), 'total' => $after->total, 'html' => (self::cardRenderer())($this->card($after))])
                ->header('Cache-Control', 'no-store');
        }
        if ($result['state'] === 'waiting') { Session::setFlash(AdminLang::get('mailings.hour_limit'), 'warning'); }
        $this->redirect(Admin::url('mailings/show/' . $mailing->id));
    }

    public function admin_pause(): never { $this->change(static fn (int $id): bool => Mailings::pause($id), 'mailings.paused'); }
    public function admin_resume(): never { $this->change(static fn (int $id): bool => Mailings::resume($id), 'mailings.resumed'); }
    public function admin_cancel(): never { $this->change(static fn (int $id): bool => Mailings::cancel($id), 'mailings.cancelled'); }
    public function admin_retry(): never { $this->change(static fn (int $id): bool => Mailings::retry($id), 'mailings.retrying'); }

    /** The composer: GET shows it (?users=1,2,3 for chosen users), POST previews, tests or sends. */
    public function admin_compose(): string|\FloCMS\Core\Http\Response
    {
        // Chosen users' names and addresses are shown: the Users page's own permission comes first.
        if (!Auth::can('users.manage') || (!Auth::can('users.email') && !Auth::can('users.mailing'))) { throw new HttpException(403); }
        $form = self::form();
        $input = $this->isPost() ? $this->request->all() : [];
        $ids = self::ids((string) ($this->isPost() ? ($input['users'] ?? '') : ($this->request->queryValue('users', '') ?? '')));
        // At most MAX_CHOSEN chosen users; the composer says how many were left out.
        $dropped = max(0, count($ids) - self::MAX_CHOSEN);
        $ids = array_slice($ids, 0, self::MAX_CHOSEN);
        $audience = $this->audienceInput($input, $ids);
        $state = [];
        foreach ($form->fields() as $name => $field) { $state[$name] = $this->isPost() ? $field->stateFromInput($input, []) : ''; }
        $errors = []; $found = ['people' => [], 'unsubscribed' => 0];
        try { $found = Mailings::audience($audience); } catch (\Throwable $error) { error_log('flocms-admin: mailing audience failed: ' . $error->getMessage()); }
        $count = count($found['people']);
        $direct = $audience['type'] === 'users' && $count <= self::DIRECT_LIMIT;
        if ($this->isPost()) {
            $errors = (new FormValidator([], Languages::default(), null, true))->validate($form, $state);
            if ($count === 0) { $errors['_form'] = AdminLang::get('mailings.nobody'); }
            if (!$direct && !Auth::can('users.mailing')) { $errors['_form'] = AdminLang::get('mailings.no_mailing_permission', ['count' => self::DIRECT_LIMIT]); }
            if ($direct && !Auth::can('users.email')) { throw new HttpException(403); }
            if (!$direct && !Mailings::available()) { $errors['_form'] = AdminLang::get('mailings.not_installed'); }
            elseif (!$direct && !Mailings::hasKey()) { $errors['_form'] = AdminLang::get('mailings.no_key'); }
            $intent = isset($input['_test']) ? 'test' : (isset($input['_send']) ? 'send' : 'preview');
            if ($errors === [] && $intent === 'test') { $this->sendTest($state); }
            if ($errors === [] && $intent === 'send') {
                // A mailing to many people asks for the number once more.
                if (!$direct && $count > 100 && (string) ($input['confirm_count'] ?? '') !== (string) $count) {
                    $errors['confirm_count'] = AdminLang::get('mailings.confirm_wrong', ['count' => $count]);
                } else {
                    $direct ? $this->sendDirect($state, $found['people']) : $this->startMailing($state, $audience);
                }
            }
        }
        $preview = $found['people'] !== [] ? reset($found['people']) : ['email' => (string) (Session::get('email') ?: 'you@example.com'), 'name' => Actor::name() ?? ''];
        $html = TemplateEngine::renderFile(PageView::viewPath('compose'), [
            't' => PageView::translator(), 'csrf' => \FloCMS\Core\Csrf::field(), 'action' => Admin::url('mailings/compose'),
            'title' => AdminLang::get($direct ? 'mailings.send_email' : 'mailings.email_users'),
            'lead' => $direct ? AdminLang::get($count === 1 ? 'mailings.direct_lead_one' : 'mailings.direct_lead', ['count' => $count, 'name' => $preview['name'] !== '' ? $preview['name'] : $preview['email']]) : AdminLang::get('mailings.mailing_lead'),
            'direct' => $direct, 'canMailing' => Auth::can('users.mailing'), 'ids' => implode(',', $ids),
            'chosen' => $audience['type'] === 'users' ? array_map(static fn (array $p, int $id): array => ['name' => $p['name'] !== '' ? $p['name'] : $p['email'], 'email' => $p['email'], 'avatar' => \FloCMS\Admin\Support\Avatar::forUser($id, $p['name'], 24)], $found['people'], array_keys($found['people'])) : [],
            'audience' => $audience, 'roles' => UsersResource::roles(), 'statuses' => UsersResource::statuses(),
            'count' => $count, 'unsubscribed' => $found['unsubscribed'], 'confirm' => !$direct && $count > 100,
            'fields' => (new FormRenderer($state, $errors, [], Languages::default(), false))->render($form), 'errors' => $errors,
            'previewName' => $preview['name'] !== '' ? $preview['name'] : $preview['email'],
            'previewSubject' => strtr((string) $state['subject'], MailMessage::values($preview['email'], $preview['name'])),
            'previewBody' => MailMessage::html((string) $state['body'], MailMessage::values($preview['email'], $preview['name'])),
            'previewFooter' => $direct ? AdminLang::get('mailings.direct_footer', ['email' => $preview['email'], 'brand' => Admin::config()->brand(), 'name' => Actor::name() ?? Admin::config()->brand()]) : AdminLang::get('mailings.footer', ['brand' => Admin::config()->brand()]),
            'brand' => Admin::config()->brand(), 'brandInitial' => mb_strtoupper(mb_substr(trim(Admin::config()->brand()), 0, 1)),
            'placeholders' => MailMessage::PLACEHOLDERS, 'sendIcon' => \FloCMS\Admin\Support\Icons::svg('send'),
            'mailProblem' => Mailer::problem(), 'dropped' => $dropped, 'maxChosen' => self::MAX_CHOSEN, 'richCss' => \FloCMS\Admin\Assets::richTextUrl(),
        ]);
        return $this->panel(AdminLang::get($direct ? 'mailings.send_email' : 'mailings.email_users'), $html, 'accounts');
    }

    /** The unsubscribe page of a mailing's email: GET asks, POST unsubscribes. No sign-in. */
    public function admin_unsubscribe(): string|\FloCMS\Core\Http\Response
    {
        $hash = Mailings::tokenHash((string) ($this->params[0] ?? ''));
        $done = false;
        if ($hash !== null && $this->isPost() && Mailings::available()) { Mailings::unsubscribe($hash); $done = true; }
        $brand = Admin::config()->brand();
        $html = TemplateEngine::renderFile(PageView::viewPath('unsubscribe'), [
            't' => PageView::translator(), 'valid' => $hash !== null && Mailings::available(), 'done' => $done, 'brand' => $brand,
            'csrf' => \FloCMS\Core\Csrf::field(), 'action' => Admin::url('mailings/unsubscribe/' . (string) ($this->params[0] ?? '')),
            'lang' => AdminLang::language(), 'dir' => PanelView::direction(AdminLang::language()), 'assets' => \FloCMS\Admin\Assets::loginTags(),
            'color' => Admin::config()->primaryColor() ?? '#0b6e91', 'initial' => mb_strtoupper(mb_substr(trim($brand), 0, 1)),
        ]);
        return \FloCMS\Core\Http\Response::html($html, $hash === null ? 404 : 200)->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex')->header('X-Frame-Options', 'DENY');
    }

    /** The composer's fields: subject and message (rich text without uploads). */
    public static function form(): Form
    {
        return (new Form())->columns(1)->schema([
            TextInput::make('subject')->label(AdminLang::get('mailings.subject'))->rules('required|max:200'),
            RichEditor::make('body')->label(AdminLang::get('mailings.message'))->required()->maxLength(65535)
                ->toolbar(['heading2', 'heading3', 'bold', 'italic', 'link', 'bulletList', 'orderedList', 'blockquote', 'clearFormatting', 'undo', 'redo'])
                ->images(false)->videos(false)->embeds(false),
        ]);
    }

    /** @return list<int> */
    private static function ids(string $value): array
    {
        if (strlen($value) > 8000) { throw new HttpException(400); }
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $value)), static fn (int $id): bool => $id > 0)));
    }

    /** @param list<int> $ids @return array{type: string, value: mixed, active_only: bool} */
    private function audienceInput(array $input, array $ids): array
    {
        $type = (string) ($input['to'] ?? ($ids !== [] ? 'users' : 'everyone'));
        if (!in_array($type, ['users', 'everyone', 'role', 'status'], true) || ($type === 'users' && $ids === [])) { $type = 'everyone'; }
        $value = match ($type) {
            'users' => $ids,
            'role' => array_key_exists((int) ($input['role'] ?? -1), UsersResource::roles()) ? (string) (int) $input['role'] : (string) array_key_first(UsersResource::roles()),
            'status' => array_key_exists((int) ($input['status'] ?? -1), UsersResource::statuses()) ? (string) (int) $input['status'] : (string) UsersResource::ACTIVE,
            default => '',
        };
        // A new composer has "Active users only" ticked.
        $activeOnly = $this->isPost() ? ($input['active_only'] ?? '') === '1' : true;
        return ['type' => $type, 'value' => $value, 'active_only' => $type === 'users' ? false : $activeOnly];
    }

    private function sendTest(array $state): void
    {
        $email = (string) (Session::get('email') ?: '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) { Session::setFlash(AdminLang::get('mailings.test_no_email'), 'warning'); return; }
        try {
            Mailer::send(MailMessage::make('[' . AdminLang::get('mailings.test') . '] ' . $state['subject'], (string) $state['body'], $email, Actor::name() ?? '', AdminLang::language(), false));
            Notification::make()->title(AdminLang::get('mailings.test_sent', ['email' => $email]))->success()->send();
        } catch (MailException|\InvalidArgumentException $error) {
            error_log('flocms-admin: test email failed: ' . $error->getMessage());
            Notification::make()->title(AdminLang::get('users.mail_failed'))->danger()->send();
        }
    }

    /** @param array<int, array{email: string, name: string}> $people */
    private function sendDirect(array $state, array $people): never
    {
        $limit = Admin::config()->mailings()['direct_per_hour'];
        $recent = array_values(array_filter((array) Session::get(self::SESSION_KEY, []), static fn ($time): bool => is_int($time) && $time > time() - 3600));
        if (count($recent) + count($people) > $limit) {
            Session::setFlash(AdminLang::get('mailings.direct_limit', ['count' => $limit]), 'danger');
            $this->redirect(Admin::url('mailings/compose', ['users' => implode(',', array_keys($people))]));
        }
        $sent = 0; $failed = [];
        $replyTo = (string) (Session::get('email') ?: '');
        foreach ($people as $id => $person) {
            try {
                Mailer::send(MailMessage::make((string) $state['subject'], (string) $state['body'], $person['email'], $person['name'], AdminLang::language(), false, $replyTo));
                $recent[] = time(); ++$sent;
                Activity::record('email_sent', 'accounts', (string) $id, $person['name'] !== '' ? $person['name'] : $person['email'], meta: ['subject' => mb_substr((string) $state['subject'], 0, 200)]);
            } catch (MailException|\InvalidArgumentException $error) {
                error_log('flocms-admin: email to user ' . $id . ' failed: ' . $error->getMessage());
                $failed[] = $person['name'] !== '' ? $person['name'] : $person['email'];
            }
        }
        Session::set(self::SESSION_KEY, $recent);
        if ($sent > 0) { Notification::make()->title(AdminLang::get($sent === 1 ? 'mailings.sent_one' : 'mailings.sent_many', ['count' => $sent]))->success()->send(); }
        if ($failed !== []) { Notification::make()->title(AdminLang::get('mailings.some_failed', ['names' => implode(AdminLang::get('form.list_separator'), $failed)]))->danger()->send(); }
        $this->redirect(Admin::url('accounts'));
    }

    private function startMailing(array $state, array $audience): never
    {
        $id = Mailings::start((string) $state['subject'], (string) $state['body'], $audience);
        Notification::make()->title(AdminLang::get('mailings.started'))->success()->send();
        $this->redirect(Admin::url('mailings/show/' . $id));
    }

    private function mailing(): Mailing
    {
        if (!Mailings::available() || count($this->params) !== 1 || !ctype_digit((string) $this->params[0])) { throw new HttpException(404); }
        return Mailings::find((int) $this->params[0]) ?? throw new HttpException(404);
    }

    private function change(\Closure $change, string $done): never
    {
        if (!$this->isPost()) { header('Allow: POST'); throw new HttpException(405); }
        $mailing = $this->mailing();
        $ok = $change($mailing->id);
        Session::setFlash(AdminLang::get($ok ? $done : 'mailings.not_now'), $ok ? 'success' : 'warning');
        $this->redirect(Admin::url('mailings/show/' . $mailing->id));
    }

    private static function cardRenderer(): \Closure
    {
        return static fn (array $card): string => TemplateEngine::renderFile(PageView::viewPath('mailing-card'), ['t' => PageView::translator(), 'card' => $card]);
    }

    private function wantsJson(): bool
    {
        return str_contains((string) $this->request->header('Accept'), 'application/json');
    }

    /** @return array<string, mixed> */
    private function card(Mailing $mailing): array
    {
        $base = 'mailings/';
        $settings = Admin::config()->mailings();
        $minutes = $mailing->waiting() > 0 ? (int) ceil($mailing->waiting() / max(1, $settings['per_hour']) * 60) : 0;
        return [
            'id' => $mailing->id, 'subject' => $mailing->subject, 'status' => $mailing->status, 'statusLabel' => AdminLang::get('mailings.status_' . $mailing->status),
            'tone' => self::tone($mailing->status), 'to' => self::audienceLabel($mailing->audience),
            'by' => AdminLang::get('mailings.started_by', ['name' => $mailing->userName !== '' ? $mailing->userName : AdminLang::get('activity.system'), 'when' => \FloCMS\Admin\Support\Dates::full((int) strtotime($mailing->createdAt . ' UTC'))]),
            'total' => $mailing->total, 'sent' => $mailing->sent, 'failed' => $mailing->failed, 'skipped' => $mailing->skipped, 'waiting' => $mailing->waiting(),
            'percent' => $mailing->total > 0 ? (int) floor(($mailing->sent + $mailing->failed) / $mailing->total * 100) : 100,
            'progress' => AdminLang::get('mailings.progress', ['done' => number_format($mailing->sent), 'total' => number_format($mailing->total)]),
            'left' => $minutes > 0 ? AdminLang::get('mailings.minutes_left', ['minutes' => $minutes]) : '',
            'url' => Admin::url($base . 'show/' . $mailing->id),
            'stepUrl' => $mailing->status === 'sending' ? Admin::url($base . 'step/' . $mailing->id) : null,
            'pauseUrl' => $mailing->status === 'sending' ? Admin::url($base . 'pause/' . $mailing->id) : null,
            'resumeUrl' => $mailing->status === 'paused' ? Admin::url($base . 'resume/' . $mailing->id) : null,
            'cancelUrl' => $mailing->isActive() ? Admin::url($base . 'cancel/' . $mailing->id) : null,
            'retryUrl' => $mailing->failed > 0 && in_array($mailing->status, ['finished', 'paused'], true) ? Admin::url($base . 'retry/' . $mailing->id) : null,
            'csrf' => \FloCMS\Core\Csrf::field(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(Mailing $mailing): array
    {
        return $this->card($mailing) + ['date' => \FloCMS\Admin\Support\Dates::date((int) strtotime($mailing->createdAt . ' UTC'))];
    }

    private static function tone(string $status): string
    {
        return match ($status) { 'sending' => 'blue', 'paused' => 'yellow', 'finished' => 'green', default => 'gray' };
    }

    /** @param array<string, mixed> $audience */
    public static function audienceLabel(array $audience): string
    {
        $value = $audience['value'] ?? '';
        $active = !empty($audience['active_only']) ? ' ' . AdminLang::get('mailings.active_suffix') : '';
        return match ((string) ($audience['type'] ?? '')) {
            'role' => AdminLang::get('mailings.to_role_named', ['role' => UsersResource::roles()[(int) $value] ?? (string) $value]) . $active,
            'status' => AdminLang::get('mailings.to_status_named', ['status' => UsersResource::statuses()[(int) $value] ?? (string) $value]),
            'users' => AdminLang::get('mailings.to_chosen', ['count' => is_array($value) ? count($value) : 0]),
            default => AdminLang::get('mailings.to_everyone') . $active,
        };
    }
}
