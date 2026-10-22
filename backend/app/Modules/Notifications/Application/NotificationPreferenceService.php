<?php

namespace App\Modules\Notifications\Application;

use App\Models\User;
use App\Modules\Notifications\Models\CommunicationPreference;

/**
 * Read/update the actor's own communication preferences.
 *
 * The first read creates the documented defaults (in-app + push on, LINE off,
 * Thai, immediate). Writes only ever touch `communication_preferences`: there
 * is no code path here that could record, grant, or withdraw consent (ABD-016).
 */
final class NotificationPreferenceService
{
    public function forUser(User|int $user): CommunicationPreference
    {
        $userId = $user instanceof User ? $user->id : $user;

        return CommunicationPreference::query()->firstOrCreate(
            ['user_id' => $userId],
            CommunicationPreference::defaults(),
        );
    }

    /**
     * @param  array<string, mixed>  $changes  already validated by the request
     */
    public function update(User $user, array $changes): CommunicationPreference
    {
        $preference = $this->forUser($user);

        if (array_key_exists('channels', $changes)) {
            $current = is_array($preference->channels) ? $preference->channels : [];

            // PATCH semantics: merge only the channels supplied, keep the rest.
            $preference->channels = array_merge($current, $changes['channels']);
        }

        if (array_key_exists('quiet_hours', $changes)) {
            $preference->quiet_hours = $changes['quiet_hours'];
        }

        if (array_key_exists('locale', $changes)) {
            $preference->locale = $changes['locale'];
        }

        if (array_key_exists('digest', $changes)) {
            $preference->digest = $changes['digest'];
        }

        $preference->save();

        return $preference->refresh();
    }
}
