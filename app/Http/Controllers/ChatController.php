<?php

namespace App\Http\Controllers;

use App\AI\Agents\RagAgent;
use App\AI\Services\OllamaEmbeddingService;
use App\Http\Requests\ChatRequest;
use App\Models\AgentConversation;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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
            ->where('is_enabled', true)
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
            ->filter(fn(Message $m) => in_array($m->role->value, ['user', 'assistant']))
            ->map(fn(Message $m) => ['role' => $m->role->value, 'content' => $m->content])
            ->values()
            ->all();

        $hasDocuments = Document::query()
            ->where('user_id', $userId)
            ->where('is_enabled', true)
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
     * Enhanced retrieveContext() — pgvector (PostgreSQL) compatible.
     *
     * Uses correct pgvector operators:
     *   <=>  cosine distance      (most common for embeddings)
     *   <->  L2 / Euclidean distance
     *   <#>  negative inner product
     *
     * Pipeline:
     *  1. Vector search via pgvector <=> operator (top-20, no hard threshold)
     *  2. BM25 keyword search via PostgreSQL full-text search (top-20)
     *  3. Reciprocal Rank Fusion merge + deduplicate
     *  4. Neighbour expansion (chunk_index ± 1)
     *  5. LLM re-rank → top-5
     *  6. Format context grouped by source
     */
    protected function retrieveContext(
        int $userId,
        string $query,
        OllamaEmbeddingService $embeddingService
    ): string {
        $queryEmbedding = $embeddingService->embed($query);

        // ── Step 1: pgvector cosine similarity search ─────────────────────────────
        // <=> = cosine distance (0 = identical, 2 = opposite).
        // ORDER BY distance ASC = most similar first.
        $vectorHits = Document::query()
            ->where('user_id', $userId)
            ->where('is_enabled', true)
            ->orderByRaw('embedding <=> ?::vector', [$this->toVectorLiteral($queryEmbedding)])
            ->limit(20)
            ->get();

        // ── Step 2: PostgreSQL full-text keyword search ───────────────────────────
        $keywordHits = $this->keywordSearch($userId, $query, limit: 20);

        // ── Step 3: Reciprocal Rank Fusion ────────────────────────────────────────
        $merged = $this->reciprocalRankFusion([$vectorHits, $keywordHits]);

        // ── Step 4: Neighbour expansion (chunk_index ± 1) ────────────────────────
        $expanded = $this->expandNeighbours($userId, $merged);

        // ── Step 5: Deduplicate ───────────────────────────────────────────────────
        $unique = $expanded
            ->unique(fn(Document $d) => $d->source . '_' . $d->chunk_index)
            ->values();

        // ── Step 6: Re-rank → top-5 ──────────────────────────────────────────────
        $reranked = $this->rerankWithLLM($query, $unique, topK: 5);

        if ($reranked->isEmpty()) {
            Log::info('No relevant documents found for query: ' . $query);
            return '';
        }

        Log::info(sprintf(
            '[RAG] vector=%d keyword=%d after_expansion=%d final=%d | query: %s',
            $vectorHits->count(),
            $keywordHits->count(),
            $unique->count(),
            $reranked->count(),
            $query
        ));

        return $this->formatContext($reranked);
    }


// ── Private helpers ───────────────────────────────────────────────────────────

    /**
     * Convert a PHP float array to a pgvector literal string.
     * pgvector expects the format: '[0.1,0.2,0.3]'
     */
    private function toVectorLiteral(array $embedding): string
    {
        return '[' . implode(',', $embedding) . ']';
    }

    /**
     * PostgreSQL full-text search + exact component tag matching.
     *
     * Component tags in electrical docs (-K1, -X3M, +MC, RS+, CR-)
     * are poor candidates for embedding similarity but match perfectly
     * with LIKE or full-text search.
     */
    private function keywordSearch(int $userId, string $query, int $limit): Collection
    {
        // Extract component tags: -K1, +MC, -X3M, RS+, CR-, -Q1 etc.
        preg_match_all('/[-+][A-Z][A-Z0-9]*\d*/', $query, $tagMatches);
        $tags = $tagMatches[0] ?? [];

        // Convert query to PostgreSQL tsquery (replace spaces with &)
        $tsQuery = implode(' & ', array_filter(
            array_map('trim', preg_split('/\s+/', trim($query))),
            fn(string $w) => mb_strlen($w) >= 2
        ));

        return Document::query()
            ->where('user_id', $userId)
            ->where('is_enabled', true)
            ->where(function ($q) use ($tsQuery, $tags) {
                // Full-text match on content column
                if (!empty($tsQuery)) {
                    $q->whereRaw(
                        "to_tsvector('english', content) @@ plainto_tsquery('english', ?)",
                        [$tsQuery]
                    );
                }
                // Exact tag match — catches -K3, -X3M, RS+ even if FTS misses them
                foreach ($tags as $tag) {
                    $q->orWhere('content', 'LIKE', '%' . $tag . '%');
                }
            })
            ->limit($limit)
            ->get();
    }

    /**
     * Reciprocal Rank Fusion over multiple ranked lists.
     * Score = Σ 1 / (k + rank).  Higher = more relevant across lists.
     *
     * @param  Collection[]  $lists
     */
    private function reciprocalRankFusion(array $lists, int $k = 60): Collection
    {
        $scores = [];
        $docMap = [];

        foreach ($lists as $list) {
            foreach ($list->values() as $rank => $doc) {
                $key = $doc->source . '_' . $doc->chunk_index;
                $scores[$key] = ($scores[$key] ?? 0) + (1 / ($k + $rank + 1));
                $docMap[$key] ??= $doc;
            }
        }

        arsort($scores);

        return collect(array_map(fn(string $k) => $docMap[$k], array_keys($scores)));
    }

    /**
     * Fetch chunk_index ± 1 for every retrieved chunk (same source file only).
     * Keeps related content together without relying on retrieval luck.
     */
    private function expandNeighbours(int $userId, Collection $chunks): Collection
    {
        if ($chunks->isEmpty()) {
            return $chunks;
        }

        $neighbours = Document::query()
            ->where('user_id', $userId)
            ->where('is_enabled', true)
            ->where(function ($q) use ($chunks) {
                foreach ($chunks as $doc) {
                    $q->orWhere(function ($q2) use ($doc) {
                        $q2->where('source', $doc->source)
                            ->whereIn('chunk_index', [
                                $doc->chunk_index - 1,
                                $doc->chunk_index + 1,
                            ]);
                    });
                }
            })
            ->get();

        return $chunks->concat($neighbours);
    }

    /**
     * Re-rank candidates using a lightweight Ollama call.
     * Asks the LLM to score each chunk 0-10 against the query.
     * Returns JSON int array in chunk order.
     * Falls back to RRF order on any failure.
     */
    private function rerankWithLLM(string $query, Collection $candidates, int $topK = 5): Collection
    {
        if ($candidates->count() <= $topK) {
            return $candidates->take($topK);
        }

        try {
            $numbered = $candidates->values()->map(function (Document $doc, int $i) {
                $preview = mb_substr(strip_tags($doc->content), 0, 250);
                return "CHUNK {$i}:\n{$preview}";
            })->implode("\n\n");

            $prompt = <<<PROMPT
Score each chunk below from 0-10 based on how directly it helps answer the query.
0 = irrelevant. 10 = directly answers the query.
Reply ONLY with a JSON array of integers, one per chunk, in order. No explanation.
Example for 4 chunks: [8,1,10,3]

QUERY: {$query}

{$numbered}

SCORES:
PROMPT;

            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->post(config('services.ollama.url') . '/api/generate', [
                    'model'  => config('services.ollama.model'),
                    'prompt' => $prompt,
                    'stream' => false,
                ]);

            $raw    = $response->json('response', '');
            $scores = $this->parseScores($raw, $candidates->count());

            return $candidates
                ->values()
                ->map(fn(Document $doc, int $i) => [$doc, $scores[$i] ?? 0])
                ->sortByDesc(fn(array $pair) => $pair[1])
                ->take($topK)
                ->map(fn(array $pair) => $pair[0])
                ->values();
        } catch (\Throwable $e) {
            Log::warning('[RAG] Re-rank failed, using RRF order. Error: ' . $e->getMessage());
            return $candidates->take($topK);
        }
    }

    /**
     * Parse the LLM re-rank response into an int array.
     * Extracts the first JSON array found in the response string.
     */
    private function parseScores(string $response, int $count): array
    {
        preg_match('/\[[\d,\s]+\]/', $response, $matches);

        if (empty($matches[0])) {
            return array_fill(0, $count, 5);
        }

        $scores = json_decode($matches[0], true) ?? [];

        // Pad with 0 if LLM returned fewer scores than chunks
        while (count($scores) < $count) {
            $scores[] = 0;
        }

        return array_slice($scores, 0, $count);
    }

    /**
     * Format final context for the LLM.
     * Sorts by source + chunk_index so adjacent chunks read in natural order.
     */
    private function formatContext(Collection $docs): string
    {
        return $docs
            ->sortBy([
                fn($a, $b) => strcmp($a->source, $b->source),
                fn($a, $b) => $a->chunk_index <=> $b->chunk_index,
            ])
            ->map(
                fn(Document $doc) =>
                "[Source: {$doc->source}, chunk {$doc->chunk_index}]\n{$doc->content}"
            )
            ->implode("\n\n---\n\n");
    }
}
