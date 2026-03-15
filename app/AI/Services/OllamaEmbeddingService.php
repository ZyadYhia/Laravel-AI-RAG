<?php

namespace App\AI\Services;

use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use RuntimeException;

class OllamaEmbeddingService
{
    protected string $baseUrl;

    protected string $model;

    protected int $dimensions;

    public function __construct(
        ?string $baseUrl = null,
        string $model = 'qwen3-embedding:latest',
        int $dimensions = 4096,
    ) {
        $this->baseUrl = rtrim($baseUrl ?? config('ai.providers.ollama.url', 'http://localhost:11434'), '/');
        $this->model = $model;
        $this->dimensions = $dimensions;
    }

    /**
     * Generate an embedding for a single text input.
     *
     * @return float[]
     */
    public function embed(string $text): array
    {
        return $this->embedMany([$text])[0];
    }

    /**
     * Generate embeddings for multiple text inputs.
     *
     * @param  string[]  $texts
     * @return float[][]
     */
    public function embedMany(array $texts): array
    {
        try {
            $response = Embeddings::for($texts)
                ->dimensions($this->dimensions)
                ->generate(Lab::Ollama, $this->model);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Cannot connect to Ollama at {$this->baseUrl}. Ensure Ollama is running.", previous: $e);
        }

        return $response->embeddings;
    }
}
