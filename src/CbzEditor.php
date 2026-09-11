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
     * @param list<string> $keepEntryNames original entry names to keep, in the desired final order
     * @param array<string, mixed> $metaFields same shape ComicInfo::write() expects
     * @return array{ok: bool, error?: string, pageCount?: int}
     */
    public static function save(string $absolutePath, array $keepEntryNames, array $metaFields): array
    {
        if (!$keepEntryNames) {
            return ['ok' => false, 'error' => "Une bande dessinée doit conserver au moins une page."];
        }

        $original = MiniZip::readAllEntries($absolutePath);
        if ($original === null) {
            return ['ok' => false, 'error' => "Impossible de lire l'archive d'origine — rien n'a été modifié."];
        }

        $newEntries = [];
        $index = 1;
        foreach ($keepEntryNames as $oldName) {
            if (!isset($original[$oldName])) {
                return ['ok' => false, 'error' => "Page introuvable dans l'archive : " . $oldName];
            }
            $ext = strtolower(pathinfo($oldName, PATHINFO_EXTENSION)) ?: 'jpg';
            $newName = sprintf('P%05d.%s', $index, $ext);
            $newEntries[$newName] = $original[$oldName];
            $index++;
        }
        $pageCount = count($newEntries);
        $newEntries['ComicInfo.xml'] = ComicInfo::write($metaFields, $pageCount);

        $dir = dirname($absolutePath);
        $tmpPath = $dir . '/.codex-cbz-edit-' . bin2hex(random_bytes(6)) . '.tmp';

        if (!MiniZip::write($tmpPath, $newEntries)) {
            @unlink($tmpPath);
            return ['ok' => false, 'error' => "Échec de l'écriture de la nouvelle archive — le fichier d'origine n'a pas été touché."];
        }

        // Independently re-read the file we just wrote, from disk, before
        // trusting it with anything — this is the whole point of writing
        // to a temp file first rather than straight to $absolutePath.
        $verify = MiniZip::readAllEntries($tmpPath);
        if ($verify === null || count($verify) !== count($newEntries)) {
            @unlink($tmpPath);
            return ['ok' => false, 'error' => "La nouvelle archive n'a pas pu être vérifiée après écriture — annulé, le fichier d'origine n'a pas été touché."];
        }
        foreach ($newEntries as $name => $data) {
            if (!isset($verify[$name]) || $verify[$name] !== $data) {
                @unlink($tmpPath);
                return ['ok' => false, 'error' => "La nouvelle archive ne correspond pas à ce qui a été demandé — annulé, le fichier d'origine n'a pas été touché."];
            }
        }

        if (!@rename($tmpPath, $absolutePath)) {
            @unlink($tmpPath);
            return ['ok' => false, 'error' => "Impossible de remplacer le fichier d'origine sur le disque."];
        }

        return ['ok' => true, 'pageCount' => $pageCount];
    }
}
