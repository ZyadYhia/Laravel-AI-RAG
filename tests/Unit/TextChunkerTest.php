<?php

use App\AI\Services\TextChunker;

test('short text returns single chunk', function () {
    $text = 'This is a short text.';
    $chunks = TextChunker::chunk($text);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toBe($text);
});

test('long text is split into multiple chunks', function () {
    $text = str_repeat('Word ', 500);
    $chunks = TextChunker::chunk($text, chunkSize: 100, overlap: 20);

    expect(count($chunks))->toBeGreaterThan(1);
});

test('very short chunks are filtered out', function () {
    // Chunks shorter than 20 characters are filtered
    $text = str_repeat('A', 25).str_repeat('B', 25);
    $chunks = TextChunker::chunk($text, chunkSize: 30, overlap: 5);

    expect(count($chunks))->toBeGreaterThanOrEqual(1);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeGreaterThanOrEqual(20);
    }
});

test('extracts text from txt files', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'test_').'.txt';
    file_put_contents($tmpFile, 'Hello world');

    $text = TextChunker::extractText($tmpFile);
    expect($text)->toBe('Hello world');

    unlink($tmpFile);
});

test('extracts text from md files', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'test_').'.md';
    file_put_contents($tmpFile, '# Title'.PHP_EOL.PHP_EOL.'Content here');

    $text = TextChunker::extractText($tmpFile);
    expect($text)->toContain('Title');

    unlink($tmpFile);
});
