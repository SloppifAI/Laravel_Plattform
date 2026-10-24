<?php

namespace App\Filament\AvatarProviders;

use App\Providers\Filament\LernplattformPanelProvider;
use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Initials avatar rendered inline, replacing Filament's default ui-avatars.com image,
 * so no browser of a schoolchild talks to a third-party server just to draw two letters.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))
            ->squish()
            ->explode(' ')
            ->map(fn (string $part): string => mb_strtoupper(mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $part), 0, 1)))
            ->filter()
            ->take(2)
            ->join('');

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="%s"/><text x="50%%" y="50%%" dy=".35em" text-anchor="middle" font-family="Lexend, sans-serif" font-size="26" font-weight="600" fill="#fff">%s</text></svg>',
            LernplattformPanelProvider::KOENIGSBLAU[600],
            htmlspecialchars($initials, ENT_XML1),
        );

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
