<?php

declare(strict_types=1);

require_once __DIR__ . '/MiniZip.php';
require_once __DIR__ . '/ComicInfo.php';
require_once __DIR__ . '/ItemPages.php';

/**
 * Admin-only .cbz editing: reorder/delete pages, edit ComicRack-schema
 * metadata, renumber the kept pages to a clean P00001.jpg-style sequence,
 * and rewrite the archive. Every save() rebuilds the ENTIRE archive fresh
 * in a temp file next to the original and only replaces the original —
 * atomically, via rename() — once that new file has been independently
 * re-read and verified. The original is never opened for writing, never
 * modified in place, and never touched at all if anything along the way
 * fails; a bad save leaves the reader's copy exactly as it was.
 *
 * This does NOT protect against every possible failure mode — a power
 * loss or a killed process between the temp file being verified and the
 * rename() completing is (astronomically unlikely but) not literally
 * impossible, and rename() itself isn't attempted anywhere unsafe (same
 * directory, same filesystem, so no partial-copy window). This is why
 * the admin UI carries an explicit backup warning before every save:
 * Codex's own care here reduces the risk a great deal, it doesn't make a
 * backup unnecessary.
 */
final class CbzEditor
{
    /**
     * Current pages in reading order, exactly as the reader itself would
     * show them (same sort as ItemPages::sortedImageNamesInZip) — the
     * editor's starting point before any reordering/deletion.
     * @return list<string> entry names
     */
    public static function listPages(string $absolutePath): array
    {
        return ItemPages::sortedImageNamesInZip($absolutePath);
    }

    /**
     * Rebuilds the archive: keeps only $keepEntryNames, in that exact
     * order, renamed to a clean P00001.{ext}, P00002.{ext}... sequence
     * (each page keeps its own original extension — a mixed-format
     * archive, jpg and png pages together, stays mixed), replaces
     * ComicInfo.xml with one freshly built from $metaFields, and only
     * then atomically replaces $absolutePath.
     *
     * Works one page at a time throughout — read a page's bytes, write
     * them out, let them go before reading the next — rather than ever
     * holding the whole comic's decompressed pages in memory together.
     * That "read everything, then write everything" shape is exactly
     * what caused a real memory-exhaustion crash on a large enough book;
     * peak memory here is roughly one page's size, however many pages
     * the comic has.
     *
     * @param list<string> $keepEntryNames original entry names to keep, in the desired final order
     * @param array<string, mixed> $metaFields same shape ComicInfo::write() expects
     * @return array{ok: bool, error?: string, pageCount?: int}
     */
    public static function save(string $absolutePath, array $keepEntryNames, array $metaFields): array
    {
        if (!$keepEntryNames) {
            return ['ok' => false, 'error' => "Une bande dessinée doit conserver au moins une page."];
        }

        $dir = dirname($absolutePath);
        $tmpPath = $dir . '/.codex-cbz-edit-' . bin2hex(random_bytes(6)) . '.tmp';

        // expectedSizes collects just name => byte count as each page is
        // written — cheap bookkeeping (a handful of integers), not a
        // second copy of the actual page data — used to verify the
        // rewritten file afterward without a second full read of it.
        $expectedSizes = [];
        $writeResult = MiniZip::withEntries($absolutePath, function ($fh, array $sourceEntries) use ($keepEntryNames, $metaFields, $tmpPath, &$expectedSizes) {
            $writer = MiniZip::beginWrite($tmpPath);
            if ($writer === null) {
                return "Échec de l'écriture de la nouvelle archive — le fichier d'origine n'a pas été touché.";
            }
            $index = 1;
            foreach ($keepEntryNames as $oldName) {
                if (!isset($sourceEntries[$oldName])) {
                    @fclose($writer->fh);
                    return "Page introuvable dans l'archive : " . $oldName;
                }
                $data = MiniZip::readEntryData($fh, $sourceEntries[$oldName]);
                if ($data === null) {
                    @fclose($writer->fh);
                    return "Impossible de lire la page : " . $oldName;
                }
                $ext = strtolower(pathinfo($oldName, PATHINFO_EXTENSION)) ?: 'jpg';
                $newName = sprintf('P%05d.%s', $index, $ext);
                if (!MiniZip::streamWriteEntry($writer, $newName, $data)) {
                    @fclose($writer->fh);
                    return "Échec de l'écriture de la page : " . $oldName;
                }
                $expectedSizes[$newName] = strlen($data);
                unset($data); // done with this page's bytes before the next iteration reads another
                $index++;
            }

            $pageCount = count($expectedSizes);
            $comicInfo = ComicInfo::write($metaFields, $pageCount);
            if (!MiniZip::streamWriteEntry($writer, 'ComicInfo.xml', $comicInfo)) {
                @fclose($writer->fh);
                return "Échec de l'écriture des métadonnées.";
            }
            $expectedSizes['ComicInfo.xml'] = strlen($comicInfo);

            // true, not null, on success — withEntries()/withCentralDirectory()
            // itself returns null when the archive can't even be opened/
            // parsed at all, *before* this callback ever runs; reusing null
            // here for "everything worked" would make that failure
            // indistinguishable from success once it comes back out of
            // withEntries() below. A string return anywhere above is always
            // a specific, already-worded error.
            return MiniZip::finishWrite($writer) ? true : "Échec de la finalisation de la nouvelle archive.";
        });

        if ($writeResult === null) {
            @unlink($tmpPath);
            return ['ok' => false, 'error' => "Impossible de lire l'archive d'origine — rien n'a été modifié."];
        }
        if ($writeResult !== true) {
            @unlink($tmpPath);
            return ['ok' => false, 'error' => $writeResult];
        }

        // Independently re-open the file we just wrote, from disk, before
        // trusting it with anything — checking entry names and sizes off
        // the central directory (cheap metadata) rather than re-reading
        // every page's full content a second time, which would reintroduce
        // the exact memory problem this whole rewrite avoids.
        $verifyOk = MiniZip::withEntries($tmpPath, function ($fh, array $entries) use ($expectedSizes) {
            if (count($entries) !== count($expectedSizes)) {
                return false;
            }
            foreach ($expectedSizes as $name => $size) {
                if (!isset($entries[$name]) || $entries[$name]['uncompSize'] !== $size) {
                    return false;
                }
            }
            return true;
        });
        if ($verifyOk !== true) {
            @unlink($tmpPath);
            return ['ok' => false, 'error' => "La nouvelle archive n'a pas pu être vérifiée après écriture — annulé, le fichier d'origine n'a pas été touché."];
        }

        if (!@rename($tmpPath, $absolutePath)) {
            @unlink($tmpPath);
            return ['ok' => false, 'error' => "Impossible de remplacer le fichier d'origine sur le disque."];
        }

        return ['ok' => true, 'pageCount' => count($expectedSizes) - 1]; // -1: ComicInfo.xml isn't a page
    }
}
