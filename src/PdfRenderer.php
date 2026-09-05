<?php

declare(strict_types=1);

/**
 * Renders actual PDF pages to JPEG images via poppler-utils
 * (`pdftoppm`/`pdfinfo`) — real rendering, not a heuristic. An earlier
 * version of this file scanned the raw PDF bytes for embedded
 * JPEG-compressed images and used those directly, which only worked for
 * PDFs that were literally one full-page scanned image per page. Most
 * real-world PDFs aren't that: a text document (a novel, a gamebook, an
 * export from a word processor) has actual vector text plus, at most, a
 * handful of small illustration images scattered through it — scanning
 * for embedded JPEGs found those small illustrations and displayed each
 * one alone on a blank page, with every text-only page simply invisible
 * to the old approach entirely. `pdftoppm` renders the composed page
 * exactly as a normal PDF viewer would, text and images together.
 *
 * `poppler-utils` is a mature, widely-packaged, actively-maintained
 * library — this is a meaningfully different trade-off than the
 * Ghostscript/Imagick route considered (and declined) earlier in this
 * project: it's a single common apt package, no PHP extension to
 * compile, and the interaction here is just two command-line tools
 * invoked with an argument array (never string-built shell commands),
 * with no arguments derived from unsanitized user input beyond the file
 * path itself.
 */
final class PdfRenderer
{
    private const DPI = 130; // comfortable reading resolution without producing huge files

    /** Whether poppler-utils is actually on PATH — the admin console's system status card, and the startup script's own WARN if the apt install failed, both need this without duplicating the check. */
    public static function available(): bool
    {
        $path = trim((string) shell_exec('which pdftoppm 2>/dev/null'));
        return $path !== '';
    }

    public static function pageCount(string $absolutePath): int
    {
        $result = self::run(['pdfinfo', $absolutePath]);
        if ($result === null) {
            return 0;
        }
        if (preg_match('/^Pages:\s+(\d+)/m', $result, $m) !== 1) {
            return 0;
        }
        return (int) $m[1];
    }

    /** $pageIndex is 0-based; pdftoppm's own page numbering is 1-based. */
    public static function renderPage(string $absolutePath, int $pageIndex): ?string
    {
        if ($pageIndex < 0) {
            return null;
        }
        $pageNumber = (string) ($pageIndex + 1);
        $tmpPrefix = tempnam(sys_get_temp_dir(), 'codex_pdf_');
        if ($tmpPrefix === false) {
            return null;
        }
        @unlink($tmpPrefix); // pdftoppm wants a prefix that doesn't already exist as a file; the tempnam call above just reserved a unique name

        try {
            $result = self::run([
                'pdftoppm', '-jpeg', '-f', $pageNumber, '-l', $pageNumber, '-r', (string) self::DPI,
                $absolutePath, $tmpPrefix,
            ]);
            if ($result === null) {
                return null;
            }
            // pdftoppm appends a zero-padded page number (width depends on
            // total page count) to the prefix — find whatever it actually wrote.
            $matches = glob($tmpPrefix . '-*.jpg') ?: [];
            if (!$matches) {
                return null;
            }
            $data = file_get_contents($matches[0]);
            return $data === false ? null : $data;
        } finally {
            foreach (glob($tmpPrefix . '-*.jpg') ?: [] as $f) {
                @unlink($f);
            }
        }
    }

    /** Runs a command via an argument array (never shell string interpolation) and returns stdout, or null on failure/timeout/missing binary. */
    private static function run(array $argv): ?string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($argv, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null; // most likely the binary isn't installed
        }
        stream_set_blocking($pipes[1], true);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        return ($exitCode === 0 && $stdout !== false) ? $stdout : null;
    }
}
