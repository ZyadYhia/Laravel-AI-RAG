<?php

namespace App\Jobs;

use App\AI\Services\OllamaEmbeddingService;
use App\Events\DocumentsEmbedded;
use App\Events\DocumentsEmbedding;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class EmbedDocumentChunks implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string[]  $chunks
     */
    public function __construct(
        public int $userId,
        public array $chunks,
        public string $source,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(OllamaEmbeddingService $embeddingService): void
    {
        DocumentsEmbedding::dispatch($this->userId, $this->source, count($this->chunks));
        Log::info("Embedding document chunks for user {$this->userId}, source: {$this->source}, total chunks: ".count($this->chunks));
        $embeddings = $embeddingService->embedMany($this->chunks);

        foreach ($this->chunks as $index => $chunk) {
            Document::query()->create([
                'user_id' => $this->userId,
                'content' => $chunk,
                'embedding' => $embeddings[$index],
                'source' => $this->source,
                'chunk_index' => $index,
            ]);
        }

        DocumentsEmbedded::dispatch($this->userId, $this->source, count($this->chunks));
        Log::info("Finished embedding document chunks for user {$this->userId}, source: {$this->source}, total chunks: ".count($this->chunks));
    }
}
