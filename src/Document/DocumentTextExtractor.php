<?php

namespace App\Document;

use App\Entity\Node;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Extracts plain text from uploaded Markdown, Word (.docx) and PDF documents so they can be
 * indexed for semantic search, inspected for shadow areas, and mined for candidate business rules.
 */
final class DocumentTextExtractor
{
    public function __construct(
        private readonly DocumentStorage $documentStorage,
    ) {
    }

    public function extract(Node $document): string
    {
        $format = (string) ($document->getMetadata()['format'] ?? 'md');
        $path = $this->documentStorage->absolutePath($document);
        if (!is_file($path)) {
            return '';
        }

        return match ($format) {
            'md' => $this->normalize((string) @file_get_contents($path)),
            'docx' => $this->extractDocx($path),
            'pdf' => $this->extractPdf($path),
            default => '',
        };
    }

    private function extractDocx(string $path): string
    {
        if (!class_exists(\ZipArchive::class)) {
            return '';
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return '';
        }

        try {
            $xml = $zip->getFromName('word/document.xml');
            if (!\is_string($xml) || '' === $xml) {
                return '';
            }
        } finally {
            $zip->close();
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            if (!$dom->loadXML($xml, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING)) {
                return '';
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $paragraphs = [];
        foreach ($dom->getElementsByTagNameNS($ns, 'p') as $paragraph) {
            $buffer = '';
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('w', $ns);
            $nodes = $xpath->query('.//w:t | .//w:tab | .//w:br', $paragraph);
            if (false === $nodes) {
                continue;
            }
            foreach ($nodes as $node) {
                if ('t' === $node->localName) {
                    $buffer .= $node->textContent;
                } elseif ('tab' === $node->localName) {
                    $buffer .= "\t";
                } elseif ('br' === $node->localName) {
                    $buffer .= "\n";
                }
            }
            $line = trim($buffer);
            if ('' !== $line) {
                $paragraphs[] = $line;
            }
        }

        return $this->normalize(implode("\n\n", $paragraphs));
    }

    private function extractPdf(string $path): string
    {
        $binary = (new ExecutableFinder())->find('pdftotext');
        if (null !== $binary) {
            $process = new Process([$binary, '-enc', 'UTF-8', '-nopgbrk', $path, '-']);
            $process->setTimeout(15);
            try {
                $process->run();
                if ($process->isSuccessful()) {
                    $text = $this->normalize($process->getOutput());
                    if ('' !== $text) {
                        return $text;
                    }
                }
            } catch (\Throwable) {
                // Fall back to pure-PHP extraction when pdftotext fails or times out.
            }
        }

        $raw = @file_get_contents($path);
        if (!\is_string($raw) || '' === $raw) {
            return '';
        }

        return $this->extractPdfFallback($raw);
    }

    /**
     * Best-effort pure-PHP extractor for uncompressed and FlateDecode PDF text streams (BT ... ET).
     * Returns an empty string on scanned/image-only PDFs or unsupported font encodings.
     */
    private function extractPdfFallback(string $raw): string
    {
        $chunks = [];
        if (preg_match_all('/(<<.*?>>)?\s*stream\r?\n(.*?)\r?\nendstream/s', $raw, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $dict = $match[1];
                $stream = $match[2];
                $data = $stream;
                if (str_contains($dict, '/FlateDecode') || str_contains($stream, "\x78\x9c") || str_contains($stream, "\x78\xda")) {
                    $decoded = @gzuncompress($stream);
                    if (false === $decoded) {
                        $decoded = @gzinflate($stream);
                    }
                    if (\is_string($decoded)) {
                        $data = $decoded;
                    }
                }
                foreach ($this->extractPdfTextBlocks($data) as $line) {
                    $chunks[] = $line;
                }
            }
        }

        if ([] === $chunks) {
            $chunks = $this->extractPdfTextBlocks($raw);
        }

        return $this->normalize(implode("\n", $chunks));
    }

    /**
     * @return list<string>
     */
    private function extractPdfTextBlocks(string $stream): array
    {
        if (!str_contains($stream, 'BT') || !str_contains($stream, 'ET')) {
            return [];
        }

        $lines = [];
        if (!preg_match_all('/BT(.*?)ET/s', $stream, $blocks)) {
            return [];
        }

        foreach ($blocks[1] as $block) {
            $parts = [];
            if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*Tj|\[(.*?)\]\s*TJ/s', $block, $ops, \PREG_SET_ORDER)) {
                foreach ($ops as $op) {
                    if (isset($op[1]) && '' !== $op[1]) {
                        if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/', $op[1], $strings)) {
                            $parts[] = implode('', array_map($this->unescapePdfString(...), $strings[0]));
                        }
                    } elseif (preg_match('/\((?:\\\\.|[^\\\\)])*\)/', $op[0], $single)) {
                        $parts[] = $this->unescapePdfString($single[0]);
                    }
                }
            }
            $line = trim(implode(' ', array_filter($parts, static fn (string $part): bool => '' !== trim($part))));
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function unescapePdfString(string $literal): string
    {
        $inner = substr($literal, 1, -1);
        $inner = (string) preg_replace_callback('/\\\\([0-7]{1,3})/', static fn (array $m): string => \chr(octdec($m[1]) & 0xFF), $inner);

        return strtr($inner, [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\b' => '',
            '\\f' => "\n",
            '\\(' => '(',
            '\\)' => ')',
            '\\\\' => '\\',
        ]);
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
