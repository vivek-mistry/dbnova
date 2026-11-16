<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Settings\UserPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's DBNova preferences. The .env values are the defaults and the maximums.
 */
class PreferencesController extends Controller
{
    public function __construct(private UserPreferences $preferences) {}

    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $saved = $user->preferences ?? [];

        $fields = [];

        foreach (array_keys(UserPreferences::LIMITS) as $key) {
            $fields[$key] = [
                ...$this->preferences->range($key),
                'value' => $this->preferences->effective($user)[$key],
                'isDefault' => ! is_int($saved[$key] ?? null),
            ];
        }

        return Inertia::render('settings/Preferences', ['fields' => $fields]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];

        foreach (array_keys(UserPreferences::LIMITS) as $key) {
            ['min' => $min, 'max' => $max] = $this->preferences->range($key);
            // An empty value means "use the default".
            $rules[$key] = ['nullable', 'integer', 'min:'.$min, 'max:'.$max];
        }

        // The page shows the timeout in seconds.
        $timeout = $this->preferences->range('query_timeout_ms');
        $messages = [
            'query_timeout_ms.min' => 'The query timeout must be at least '.intdiv($timeout['min'], 1000).' seconds.',
            'query_timeout_ms.max' => 'The query timeout may not be more than '.intdiv($timeout['max'], 1000).' seconds.',
        ];

        $validated = $request->validate($rules, $messages, [
            'page_size' => 'rows per page',
            'query_row_cap' => 'row limit',
            'query_timeout_ms' => 'query timeout',
        ]);

        /** @var User $user */
        $user = $request->user();
        $preferences = array_map(intval(...), array_filter($validated, fn (mixed $value): bool => $value !== null));
        $user->forceFill(['preferences' => $preferences === [] ? null : $preferences])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Preferences saved.']);

        return to_route('preferences.edit');
    }
}
