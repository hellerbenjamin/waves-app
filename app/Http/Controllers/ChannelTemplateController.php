<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreChannelTemplateRequest;
use App\Models\ChannelTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ChannelTemplateController extends Controller
{
    public function store(StoreChannelTemplateRequest $request): JsonResponse
    {
        $labels = collect($request->validated('labels'))
            ->map(fn ($label) => filled(trim((string) $label)) ? trim((string) $label) : null)
            ->all();

        $template = $request->user()->channelTemplates()->create([
            'name' => $request->validated('name'),
            'labels' => $labels,
        ]);

        return response()->json(
            $template->only('id', 'name', 'labels'),
            201,
        );
    }

    public function destroy(Request $request, ChannelTemplate $channelTemplate): Response
    {
        abort_unless($channelTemplate->user_id === $request->user()->id, 403);

        $channelTemplate->delete();

        return response()->noContent();
    }
}
