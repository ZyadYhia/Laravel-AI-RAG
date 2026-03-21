<?php

namespace App\AI\Services;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

class TextChunker
{
    /**
     * Split text into overlapping chunks for embedding.
     *
     * @return string[]
     */
    public static function chunk(
        string $text,
        string $filename = 'document',
        int $chunkSize = 800,
        int $overlapSentences = 1
    ): array {
        // ── 1. Normalize whitespace ──────────────────────────────────────────
        $text = preg_replace('/[ \t]+/', ' ', trim($text));  // collapse inline spaces
        $text = preg_replace('/\n{3,}/', "\n\n", $text);     // max 2 consecutive newlines

        // ── 2. Split into sentences ──────────────────────────────────────────
        // Splits on ". ", "! ", "? ", or newlines — keeps the delimiter attached.
        $sentences = self::splitSentences($text);

        if (empty($sentences)) {
            return [];
        }

        // ── 3. Group sentences into chunks ───────────────────────────────────
        $chunks        = [];
        $currentChunk  = '';
        $currentSection = 'General';
        $overlapBuffer = [];   // sentences carried from previous chunk
        $chunkIndex    = 1;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (mb_strlen($sentence) < 5) {
                continue;
            }

            // Detect section headings — update running section label
            if (self::isSectionHeading($sentence)) {
                // If we have accumulated content, flush it as a chunk first
                if (mb_strlen($currentChunk) >= 20) {
                    $chunks[] = self::buildChunk(
                        $filename,
                        $currentSection,
                        $chunkIndex++,
                        $currentChunk
                    );
                }
                $currentSection = rtrim($sentence, ':');
                $currentChunk   = '';
                $overlapBuffer  = [];
                continue;
            }

            $addition = ($currentChunk === '' ? '' : ' ') . $sentence;

            // If adding this sentence exceeds the limit, flush and start new chunk
            if (
                mb_strlen($currentChunk) > 0
                && mb_strlen($currentChunk) + mb_strlen($addition) > $chunkSize
            ) {
                $chunks[] = self::buildChunk(
                    $filename,
                    $currentSection,
                    $chunkIndex++,
                    $currentChunk
                );

                // Carry over the last N sentences as overlap
                $overlapText   = implode(' ', array_slice($overlapBuffer, -$overlapSentences));
                $currentChunk  = ($overlapText !== '' ? $overlapText . ' ' : '') . $sentence;
                $overlapBuffer = [$sentence];
            } else {
                $currentChunk  .= $addition;
                $overlapBuffer[] = $sentence;
            }
        }

        // Flush the final chunk
        if (mb_strlen(trim($currentChunk)) >= 20) {
            $chunks[] = self::buildChunk(
                $filename,
                $currentSection,
                $chunkIndex,
                $currentChunk
            );
        }

        return $chunks;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Split text into sentences on ". ", "! ", "? ", or double newlines.
     * Keeps the trailing punctuation attached to the sentence it ends.
     */
    private static function splitSentences(string $text): array
    {
        // Split on sentence-ending punctuation followed by space/newline,
        // or on paragraph breaks (double newlines).
        $parts = preg_split(
            '/(?<=[.!?])\s+|\n{2,}/',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        return $parts ?: [];
    }

    /**
     * Heuristic: a sentence is a section heading if it is short (< 80 chars),
     * has no trailing period, and is either ALL CAPS or ends with a colon.
     */
    private static function isSectionHeading(string $sentence): bool
    {
        $trimmed = trim($sentence);
        $len     = mb_strlen($trimmed);

        if ($len === 0 || $len > 80) {
            return false;
        }

        $endsWithColon = str_ends_with($trimmed, ':');
        $isAllCaps     = $trimmed === mb_strtoupper($trimmed) && preg_match('/[A-Z]{3,}/', $trimmed);

        return $endsWithColon || $isAllCaps;
    }

    /**
     * Build the final chunk string with a metadata header.
     *
     * Header format:
     *   [FILE: circuit.pdf] [SECTION: Power Sources] [CHUNK: 3]
     *
     * This header is what lets the LLM cite its source and distinguish
     * between 400V (main power) and 230V (control) — each lives in a
     * separately labeled chunk.
     */
    private static function buildChunk(
        string $filename,
        string $section,
        int $index,
        string $body
    ): string {
        $header = sprintf(
            '[FILE: %s] [SECTION: %s] [CHUNK: %d]',
            $filename,
            trim($section),
            $index
        );

        return $header . "\n" . trim($body);
    }

    /**
     * Extract text content from an uploaded file.
     */
    public static function extractText(string $path, ?string $extension = null): string
    {
        $extension = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'txt', 'md', 'csv', 'log', 'json', 'xml', 'html', 'yml', 'yaml', 'php', 'js', 'ts', 'py' => file_get_contents($path),
            'pdf' => static::extractPdfText($path),
            'docx' => static::extractDocxText($path),
            'pages' => static::extractPagesText($path),
            default => file_get_contents($path),
        };
    }

    /**
     * Basic PDF text extraction. Falls back to raw content if smalot/pdfparser is unavailable.
     */
    protected static function extractPdfText(string $path): string
    {
        if (class_exists(Parser::class)) {
            try {
                $config = new Config;
                $config->setIgnoreEncryption(true);
                $parser = new Parser([], $config);
                $pdf = $parser->parseFile($path);

                return $pdf->getText();
            } catch (\Exception) {
                return file_get_contents($path);
            }
        }

        return file_get_contents($path);
    }

    /**
     * Extract text from a .docx file by reading its XML content.
     */
    protected static function extractDocxText(string $path): string
    {
        $zip = new \ZipArchive;

        if ($zip->open($path) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            return '';
        }

        $xml = strip_tags(str_replace('<', ' <', $xml));

        return preg_replace('/\s+/', ' ', trim($xml));
    }

    /**
     * Extract text from an Apple Pages file by reading its XML/protobuf content.
     */
    protected static function extractPagesText(string $path): string
    {
        $zip = new \ZipArchive;

        if ($zip->open($path) !== true) {
            return '';
        }

        // Pages files store content in index.xml or as protobuf in Index/Document.iwa
        $xml = $zip->getFromName('index.xml');

        if ($xml !== false) {
            $zip->close();
            $xml = strip_tags(str_replace('<', ' <', $xml));

            return preg_replace('/\s+/', ' ', trim($xml));
        }

        // Fallback: try to extract readable text from all entries
        $text = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_ends_with($name, '.xml') || str_ends_with($name, '.txt')) {
                $content = $zip->getFromIndex($i);
                if ($content !== false) {
                    $text .= ' ' . strip_tags(str_replace('<', ' <', $content));
                }
            }
        }

        $zip->close();

        return preg_replace('/\s+/', ' ', trim($text));
    }
}
