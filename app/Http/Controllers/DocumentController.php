<?php

namespace App\Http\Controllers;

use App\AI\Services\TextChunker;
use App\Http\Requests\DocumentUploadRequest;
use App\Jobs\EmbedDocumentChunks;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    /**
     * Display the documents page.
     */
    public function index(Request $request): Response
    {
        $documents = Document::query()
            ->where('user_id', $request->user()->id)
            ->select('id', 'source', 'chunk_index', 'is_enabled', 'created_at')
            ->latest()
            ->get()
            ->groupBy('source')
            ->map(fn($chunks, $source) => [
                'source' => $source,
                'chunks' => $chunks->count(),
                'is_enabled' => $chunks->first()->is_enabled,
                'uploaded_at' => $chunks->first()->created_at->toDateTimeString(),
            ])
            ->values();

        return Inertia::render('documents/index', [
            'documents' => $documents,
        ]);
    }

    /**
     * Upload and embed documents.
     */
    public function store(DocumentUploadRequest $request): RedirectResponse
    {
        $user = $request->user();
        $files = $request->file('files');
        $totalChunks = 0;

        foreach ($files as $file) {
            $text = TextChunker::extractText($file->getRealPath(), $file->getClientOriginalExtension());
            $chunks = TextChunker::chunk(
                text: $text,
                filename: $file->getClientOriginalName(),
                chunkSize: 800,
                overlapSentences: 1
            );
            $filename = $file->getClientOriginalName();

            EmbedDocumentChunks::dispatch($user->id, $chunks, $filename);

            $totalChunks += count($chunks);
        }

        return Inertia::flash('status', "Processing {$totalChunks} chunks from " . count($files) . ' file(s). They will appear shortly.')->back();
    }

    /**
     * Delete all chunks for a given source file.
     */
    public function destroy(Request $request, string $source): RedirectResponse
    {
        Document::query()
            ->where('user_id', $request->user()->id)
            ->where('source', $source)
            ->delete();

        return Inertia::flash('status', "Deleted all chunks from {$source}.")->back();
    }

    /**
     * Toggle the is_enabled flag for all chunks of a given source.
     */
    public function toggleEnabled(Request $request, string $source): RedirectResponse
    {
        $documents = Document::query()
            ->where('user_id', $request->user()->id)
            ->where('source', $source);

        $isEnabled = ! $documents->first()?->is_enabled;

        $documents->update(['is_enabled' => $isEnabled]);

        $status = $isEnabled ? 'enabled' : 'disabled';

        return Inertia::flash('status', "Document '{$source}' {$status}.")->back();
    }
}
