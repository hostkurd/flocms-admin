<?php
declare(strict_types=1);

namespace FloCMS\Admin\Users;

use FloCMS\Admin\Form\Fields\Toggle;

/**
 * "Send an invitation" on the new-account form (0.35). Not a column: the
 * account is saved Pending and the invitation goes out after the save.
 *
 * @internal
 */
final class InviteToggle extends Toggle
{
    public function storesInColumn(): bool
    {
        return false;
    }

    public function isHiddenFromActivity(): bool
    {
        return true;
    }

    public function isHiddenFromExport(): bool
    {
        return true;
    }
}
