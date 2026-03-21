<?php

namespace App\AI\Agents;

use App\Models\User;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::Ollama)]
#[MaxTokens(4096)]
#[Temperature(0.3)]
#[Timeout(120)]
class RagAgent implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    public function model(): string
    {
        return config('ai.ollama_model');
    }

    public function __construct(
        public User $user,
        protected string $context = '',
    ) {}

    public function instructions(): string
    {
        $base = <<<'INSTRUCTIONS'
        You are a knowledgeable AI assistant that answers questions using the user's uploaded documents as context.

        Rules:
        - Answer the user's question based ONLY on the document context provided below.
        - Cite the source filename when possible.
        - If the context does not contain relevant information, say so honestly.
        - Be concise and accurate. Do not fabricate information.
        - When referencing document content, quote the relevant passage briefly.
        INSTRUCTIONS;

        if ($this->context !== '') {
            return $base . "\n\n--- DOCUMENT CONTEXT ---\n" . $this->context . "\n--- END CONTEXT ---";
        }

        return $base . "\n\nNo documents were found matching the user's query. Let them know and answer generally if possible.";
    }
}
