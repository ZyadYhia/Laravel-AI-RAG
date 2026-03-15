<?php

namespace App\Jobs;

use App\AI\Services\OllamaEmbeddingService;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
    }
}
