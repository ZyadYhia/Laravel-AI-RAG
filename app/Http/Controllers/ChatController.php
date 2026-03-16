<?php

namespace App\Http\Controllers;

use App\AI\Agents\RagAgent;
use App\AI\Services\OllamaEmbeddingService;
use App\Http\Requests\ChatRequest;
use App\Models\AgentConversation;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\Message;

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
            'conversationId' => null,
            'initialMessages' => [],
            'conversationTitle' => null,
        ]);
    }

    /**
     * Display the chat page with a specific conversation loaded.
     */
    public function show(Request $request, string $conversation, ConversationStore $store): Response
    {
        $userId = $request->user()->id;

        $conv = AgentConversation::query()
            ->where('user_id', $userId)
            ->findOrFail($conversation);

        $messages = $store->getLatestConversationMessages($conversation, 100)
            ->filter(fn (Message $m) => in_array($m->role->value, ['user', 'assistant']))
            ->map(fn (Message $m) => ['role' => $m->role->value, 'content' => $m->content])
            ->values()
            ->all();

        $hasDocuments = Document::query()
            ->where('user_id', $userId)
            ->exists();

        return Inertia::render('chat/index', [
            'hasDocuments' => $hasDocuments,
            'conversationId' => $conv->id,
            'initialMessages' => $messages,
            'conversationTitle' => $conv->title,
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
