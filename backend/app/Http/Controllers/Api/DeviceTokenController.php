<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\Request;

/**
 * Stores / removes the push token a device reports after registering with
 * FCM / APNs. Any authenticated role (customer|merchant|rider|admin) may
 * register its own tokens.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'token'       => ['required', 'string', 'max:512'],
            'platform'   => ['sometimes', 'string', 'max:20'],
            'device_name' => ['sometimes', 'string', 'max:120'],
            'locale'     => ['sometimes', 'string', 'max:10'],
        ]);

        $user = $request->user();
        DeviceToken::updateOrCreate(
            ['user_id' => $user->id, 'token' => $data['token']],
            array_merge($data, ['role' => $user->role, 'user_id' => $user->id])
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request)
    {
        $request->validate(['token' => ['required', 'string']]);
        DeviceToken::where('user_id', $request->user()->id)
            ->where('token', $request->input('token'))
            ->delete();

        return response()->json(['ok' => true]);
    }
}
