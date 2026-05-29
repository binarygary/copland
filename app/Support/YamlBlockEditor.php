<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Scoped-block YAML editor.
 *
 * Locates a single top-level key (e.g. `repos:`) in a YAML file, parses or
 * rewrites only that block, and splices the rendered output back into the
 * original file. Comments OUTSIDE the target block survive byte-for-byte;
 * comments INSIDE the target block are dropped on rewrite — this is the
 * documented Phase 24 trade-off (see .planning/phases/24-config-write-global-repos-list/24-CONTEXT.md D-01).
 *
 * Line-ending policy: the file's dominant line ending (CRLF vs LF) is
 * preserved across read/write. Detected by majority count on read; ties → LF.
 *
 * Used by ConfigReposAddCommand / EditCommand / RemoveCommand and reserved
 * for Phase 25 / 26 reuse against `asana_token:`, `defaults:`, `models:`, etc.
 */
class YamlBlockEditor
{
    public function __construct(private string $filePath) {}

    /**
     * Read the parsed value of a top-level block, or null if absent.
     */
    public function readBlock(string $key): mixed
    {
        $contents = $this->loadFile();
        $extracted = $this->extractBlockText($contents, $key);

        if ($extracted === null) {
            return null;
        }

        // Symfony YAML parses LF strings; normalize CRLF away first.
        $normalized = str_replace("\r\n", "\n", $extracted['block']);
        $parsed = Yaml::parse($normalized);

        if (! is_array($parsed) || ! array_key_exists($key, $parsed)) {
            return null;
        }

        return $parsed[$key];
    }

    /**
     * Rewrite a top-level block. If the block does not exist, append it at
     * end-of-file with a blank-line separator.
     */
    public function writeBlock(string $key, mixed $value): void
    {
        $contents = $this->loadFile();
        $ending = $this->detectDominantLineEnding($contents);

        // Render the new block. Symfony YAML always emits LF; we post-process
        // to match the detected dominant ending before splicing.
        $renderedLf = Yaml::dump([$key => $value], 4, 2);
        $rendered = $this->withLineEnding($renderedLf, $ending);

        $extracted = $this->extractBlockText($contents, $key);

        if ($extracted === null) {
            // Append. Ensure the original file ends with a single line-ending
            // before adding a blank-line glue + the rendered block + one
            // trailing line-ending.
            $prefix = $this->ensureSingleTrailingNewline($contents, $ending);
            $glue = ($prefix === '') ? '' : $ending; // blank line between prior content and new block
            $rendered = $this->ensureSingleTrailingNewline($rendered, $ending);
            $this->writeFile($prefix.$glue.$rendered);

            return;
        }

        // Splice the rendered block in place of the original block region.
        $before = substr($contents, 0, $extracted['start']);
        $after = substr($contents, $extracted['end']);

        // Ensure the rendered block ends with a single line-ending so the
        // post-block region's leading line-ending (if any) is preserved 1:1.
        $rendered = $this->ensureSingleTrailingNewline($rendered, $ending);

        $result = $before.$rendered.$after;

        // If the original file had no trailing newline AND the post-region
        // was empty (block was at EOF), make sure result still ends in one \n.
        if ($after === '' && substr($result, -strlen($ending)) !== $ending) {
            $result .= $ending;
        }

        $this->writeFile($result);
    }

    /**
     * Remove a top-level block from the file (header + all continuation lines).
     */
    public function deleteBlock(string $key): void
    {
        $contents = $this->loadFile();
        $extracted = $this->extractBlockText($contents, $key);

        if ($extracted === null) {
            return;
        }

        $before = substr($contents, 0, $extracted['start']);
        $after = substr($contents, $extracted['end']);

        $this->writeFile($before.$after);
    }

    private function loadFile(): string
    {
        if (! file_exists($this->filePath)) {
            throw new RuntimeException("YAML file not found: {$this->filePath}");
        }

        $contents = file_get_contents($this->filePath);

        if ($contents === false) {
            throw new RuntimeException("Failed to read YAML file: {$this->filePath}");
        }

        return $contents;
    }

    private function writeFile(string $contents): void
    {
        if (file_put_contents($this->filePath, $contents) === false) {
            throw new RuntimeException("Failed to write YAML file: {$this->filePath}");
        }
    }

    /**
     * Locate the block headed by `<key>:` at column 0 (NOT commented out).
     *
     * Returns [start: int, end: int, block: string] where the substring
     * [start, end) covers the header line + all continuation lines (indented
     * or list-marker lines belonging to the block). Returns null if no
     * uncommented, top-level instance of the key exists.
     */
    private function extractBlockText(string $contents, string $key): ?array
    {
        // Normalize CRLF → LF for line scanning, but remember each line's
        // length in the ORIGINAL contents so the splice offsets are correct.
        // Strategy: split on /\r?\n/ but track offsets via successive strpos.
        $offset = 0;
        $len = strlen($contents);
        $lineRanges = []; // [[start, endExclusive, text], ...]

        while ($offset <= $len) {
            // Find next LF.
            $lf = strpos($contents, "\n", $offset);
            if ($lf === false) {
                // Last line without trailing newline.
                if ($offset < $len) {
                    $text = substr($contents, $offset);
                    // Strip trailing CR if present (shouldn't happen w/o LF after).
                    $text = rtrim($text, "\r");
                    $lineRanges[] = [$offset, $len, $text];
                }
                break;
            }
            $rawText = substr($contents, $offset, $lf - $offset);
            // Strip trailing \r if CRLF.
            $text = rtrim($rawText, "\r");
            $lineRanges[] = [$offset, $lf + 1, $text];
            $offset = $lf + 1;
        }

        $headerPattern = '/^'.preg_quote($key, '/').':(\s|$)/';

        $headerLineIdx = null;
        foreach ($lineRanges as $idx => [$s, $e, $text]) {
            if (preg_match($headerPattern, $text) === 1) {
                $headerLineIdx = $idx;
                break;
            }
        }

        if ($headerLineIdx === null) {
            return null;
        }

        // Find end of block: the next line at column 0 that is NOT a comment
        // and NOT a blank line and NOT an indented continuation and NOT a
        // top-level list marker belonging to this block.
        //
        // For top-level keys like `repos:`, continuation = lines starting
        // with whitespace OR `-` (list-marker continuation, which YAML
        // allows but is rare for top-level keys).
        $endLineIdx = count($lineRanges); // default: through EOF
        for ($i = $headerLineIdx + 1; $i < count($lineRanges); $i++) {
            $lineText = $lineRanges[$i][2];

            if ($lineText === '') {
                // Blank line — could be glue between block and next block,
                // or could be inside the block before more entries arrive.
                // Peek forward: if the next non-blank line is a continuation,
                // this blank is part of the block. Otherwise it's the glue
                // belonging to the post-block region.
                $nextNonBlank = $this->peekNextNonBlank($lineRanges, $i + 1);
                if ($nextNonBlank === null || ! $this->isContinuationLine($nextNonBlank)) {
                    $endLineIdx = $i; // exclude the blank from the block
                    break;
                }

                continue;
            }

            if ($this->isContinuationLine($lineText)) {
                continue;
            }

            // First non-blank, non-continuation line: end of block.
            $endLineIdx = $i;
            break;
        }

        $startOffset = $lineRanges[$headerLineIdx][0];
        $endOffset = $endLineIdx >= count($lineRanges)
            ? $len
            : $lineRanges[$endLineIdx][0];

        $block = substr($contents, $startOffset, $endOffset - $startOffset);

        return [
            'start' => $startOffset,
            'end' => $endOffset,
            'block' => $block,
        ];
    }

    private function isContinuationLine(string $text): bool
    {
        if ($text === '') {
            return false; // blank lines handled separately
        }

        // Indented (whitespace at start) = continuation.
        if ($text[0] === ' ' || $text[0] === "\t") {
            return true;
        }

        // Top-level list marker `-` followed by space or EOL = continuation
        // (rare for `repos:` but valid YAML).
        if ($text === '-' || str_starts_with($text, '- ')) {
            return true;
        }

        return false;
    }

    private function peekNextNonBlank(array $lineRanges, int $from): ?string
    {
        for ($i = $from; $i < count($lineRanges); $i++) {
            if ($lineRanges[$i][2] !== '') {
                return $lineRanges[$i][2];
            }
        }

        return null;
    }

    private function detectDominantLineEnding(string $contents): string
    {
        $crlf = substr_count($contents, "\r\n");
        $bareLf = substr_count($contents, "\n") - $crlf;

        // Strict majority for CRLF; ties (or empty file) → LF per policy.
        return $crlf > $bareLf ? "\r\n" : "\n";
    }

    private function withLineEnding(string $text, string $ending): string
    {
        if ($ending === "\n") {
            return $text; // Yaml::dump emits LF already
        }

        // Normalize then re-emit with CRLF.
        $lfOnly = str_replace("\r\n", "\n", $text);

        return str_replace("\n", $ending, $lfOnly);
    }

    private function ensureSingleTrailingNewline(string $text, string $ending): string
    {
        // Strip any number of trailing LFs/CRLFs, then add exactly one $ending.
        $stripped = rtrim($text, "\r\n");

        if ($stripped === '') {
            return '';
        }

        return $stripped.$ending;
    }
}
