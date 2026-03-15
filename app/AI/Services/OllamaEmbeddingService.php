<?php

namespace App\AI\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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
            $response = Http::timeout(120)
                ->post("{$this->baseUrl}/api/embed", [
                    'model' => $this->model,
                    'input' => $texts,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Cannot connect to Ollama at {$this->baseUrl}. Ensure Ollama is running.", previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException("Ollama embedding request failed: {$response->body()}");
        }

        $data = $response->json();

        if (! isset($data['embeddings'])) {
            throw new RuntimeException('Unexpected Ollama response: missing embeddings key.');
        }

        return $data['embeddings'];
    }
}
