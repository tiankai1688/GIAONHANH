<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentApplication;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:80'],
            'phone'        => ['required', 'string', 'regex:/^[0-9]{9,11}$/'],
            'region'       => ['required', 'string', 'max:120'],
            'channel_desc' => ['required', 'string', 'max:1000'],
        ]);

        $app = AgentApplication::create(array_merge($data, ['status' => 'pending']));

        return response()->json([
            'message' => 'Đã nhận đơn đăng ký đại lý khu vực. Chúng tôi sẽ liên hệ sớm.',
            'id'      => $app->id,
        ], 201);
    }

    public function index()
    {
        return response()->json(
            AgentApplication::latest()->paginate(20)
        );
    }

    public function approve(Request $request, AgentApplication $agent)
    {
        $agent->update(['status' => 'approved']);
        return response()->json(['ok' => true, 'status' => $agent->status]);
    }

    public function show(AgentApplication $agent)
    {
        return response()->json($agent);
    }

    public function reject(Request $request, AgentApplication $agent)
    {
        $agent->update(['status' => 'rejected']);
        return response()->json(['ok' => true, 'status' => $agent->status]);
    }

    public function update(Request $request, AgentApplication $agent)
    {
        $data = $request->validate([
            'note'           => ['sometimes', 'string', 'max:1000'],
            'share_rate'     => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'merchants_count' => ['sometimes', 'integer', 'min:0'],
            'region'         => ['sometimes', 'string', 'max:120'],
        ]);
        $agent->update($data);

        return response()->json($agent);
    }

    public function destroy(Request $request, AgentApplication $agent)
    {
        $agent->delete();

        return response()->json(['ok' => true]);
    }
}
