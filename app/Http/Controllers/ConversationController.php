<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    /**
     * List conversations for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $conversations = DB::table('agent_conversations')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->get(['id', 'title', 'updated_at']);

        return response()->json($conversations);
    }

    /**
     * Show messages for a specific conversation.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $conversation = DB::table('agent_conversations')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $conversation) {
            abort(404);
        }

        $messages = DB::table('agent_conversation_messages')
            ->where('conversation_id', $id)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->get(['role', 'content']);

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'messages' => $messages,
        ]);
    }

    /**
     * Delete a conversation and its messages.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $conversation = DB::table('agent_conversations')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $conversation) {
            abort(404);
        }

        DB::table('agent_conversation_messages')
            ->where('conversation_id', $id)
            ->delete();

        DB::table('agent_conversations')
            ->where('id', $id)
            ->delete();

        return response()->json(['message' => 'Conversation deleted.']);
    }
}
