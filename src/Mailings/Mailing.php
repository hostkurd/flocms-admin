<?php
declare(strict_types=1);

namespace FloCMS\Admin\Mailings;

/** One mailing as stored (0.28). @internal */
final class Mailing
{
    /** @param array<string, mixed> $audience */
    public function __construct(
        public readonly int $id,
        public readonly string $subject,
        public readonly string $body,
        public readonly array $audience,
        public readonly string $status,
        public readonly int $total,
        public readonly int $sent,
        public readonly int $failed,
        public readonly int $skipped,
        public readonly ?int $userId,
        public readonly string $userName,
        public readonly string $lang,
        public readonly string $createdAt,
        public readonly ?string $finishedAt,
    ) {
    }

    public static function fromRow(object $row): self
    {
        $audience = json_decode((string) ($row->audience ?? '{}'), true);
        return new self((int) $row->id, (string) $row->subject, (string) $row->body, is_array($audience) ? $audience : [],
            (string) $row->status, (int) $row->total, (int) $row->sent, (int) $row->failed, (int) $row->skipped,
            $row->user_id !== null ? (int) $row->user_id : null, (string) ($row->user_name ?? ''), (string) ($row->lang ?? ''),
            (string) $row->created_at, $row->finished_at !== null ? (string) $row->finished_at : null);
    }

    /** Waiting to be sent. */
    public function waiting(): int
    {
        return max(0, $this->total - $this->sent - $this->failed);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['sending', 'paused'], true);
    }
}
