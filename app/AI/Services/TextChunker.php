<?php

namespace App\AI\Services;

use Smalot\PdfParser\Parser;

class TextChunker
{
    /**
     * Split text into overlapping chunks for embedding.
     *
     * @return string[]
     */
    public static function chunk(string $text, int $chunkSize = 800, int $overlap = 100): array
    {
        $text = preg_replace('/\s+/', ' ', trim($text));

        if (mb_strlen($text) <= $chunkSize) {
            return [$text];
        }

        $chunks = [];
        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $chunk = mb_substr($text, $offset, $chunkSize);

            if (mb_strlen($chunk) > 0) {
                $chunks[] = trim($chunk);
            }

            $offset += $chunkSize - $overlap;
        }

        return array_filter($chunks, fn (string $c) => mb_strlen($c) >= 20);
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
            $parser = new Parser;
            $pdf = $parser->parseFile($path);

            return $pdf->getText();
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
                    $text .= ' '.strip_tags(str_replace('<', ' <', $content));
                }
            }
        }

        $zip->close();

        return preg_replace('/\s+/', ' ', trim($text));
    }
}
