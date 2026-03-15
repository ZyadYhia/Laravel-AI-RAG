<?php

namespace App\Http\Controllers;

use App\AI\Agents\RagAgent;
use App\AI\Services\OllamaEmbeddingService;
use App\Http\Requests\ChatRequest;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    /**
     * Display the chat page.
     */
    public function index(Request $request): Response
    {
        $hasDocuments = Document::query()
            ->where('user_id', $request->user()->id)
            ->exists();

        return Inertia::render('chat/index', [
            'hasDocuments' => $hasDocuments,
        ]);
    }

    /**
     * Send a message and get a response.
     */
    public function store(ChatRequest $request, OllamaEmbeddingService $embeddingService): JsonResponse
    {
        $user = $request->user();
        $message = $request->validated('message');
        $conversationId = $request->validated('conversation_id');

        $context = $this->retrieveContext($user->id, $message, $embeddingService);

        $agent = new RagAgent($user, $context);

        if ($conversationId) {
            $agent = $agent->continue($conversationId, as: $user);
        } else {
            $agent = $agent->forUser($user);
        }

        $response = $agent->prompt($message);

        return response()->json([
            'text' => $response->text,
            'conversationId' => $response->conversationId ?? null,
        ]);
    }

    /**
     * Retrieve relevant document chunks using vector similarity.
     */
    protected function retrieveContext(int $userId, string $query, OllamaEmbeddingService $embeddingService): string
    {
        $queryEmbedding = $embeddingService->embed($query);

        $documents = Document::query()
            ->where('user_id', $userId)
            ->whereVectorSimilarTo('embedding', $queryEmbedding, minSimilarity: 0.3)
            ->limit(5)
            ->get();

        if ($documents->isEmpty()) {
            return '';
        }

        return $documents
            ->map(fn (Document $doc) => "[Source: {$doc->source}, chunk {$doc->chunk_index}]\n{$doc->content}")
            ->implode("\n\n---\n\n");
    }
}
