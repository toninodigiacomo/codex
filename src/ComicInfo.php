<?php

declare(strict_types=1);

require_once __DIR__ . '/MiniZip.php';


/**
 * A .cbz file is just a ZIP archive of page images, optionally with a
 * ComicInfo.xml at its root following the community "ComicRack" schema
 * (also produced/read by ComicTagger, Komga, Kavita, etc.). This reads
 * that file, if present, and maps its fields onto our schema.
 */
final class ComicInfo
{
    /** Tags that map straight onto items (base table) fields. */
    private const ITEM_FIELD_MAP = [
        'Title' => 'title',
        'Number' => 'issue_number',
        'Publisher' => 'publisher',
        'Summary' => 'synopsis',
    ];

    /** Tags that map onto comic_details columns (same names, ComicInfo uses PascalCase). */
    private const DETAIL_FIELD_MAP = [
        'Writer' => 'writer',
        'Penciller' => 'penciller',
        'Inker' => 'inker',
        'Colorist' => 'colorist',
        'Letterer' => 'letterer',
        'CoverArtist' => 'cover_artist',
        'Editor' => 'editor',
        'Genre' => 'genre',
        'Characters' => 'characters',
        'AgeRating' => 'age_rating',
    ];

    /**
     * Returns an associative array of found fields (item + comic_details
     * fields merged, plus 'series_name' as a separate hint since Series
     * is a name in the file but an id (series_id) in our schema — the
     * caller resolves/creates the Series row), or null if the archive
     * has no ComicInfo.xml (or can't be read at all). Fields that aren't
     * present in the file are simply absent from the result, never set
     * to an empty string — callers should only overwrite what was found.
     */
    public static function read(string $absolutePath): ?array
    {
        $xmlContent = MiniZip::readEntry($absolutePath, 'ComicInfo.xml');
        if ($xmlContent === null) {
            return null;
        }

        $prevSetting = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        libxml_use_internal_errors($prevSetting);
        if ($xml === false) {
            return null;
        }

        $get = static function (string $tag) use ($xml): ?string {
            if (!isset($xml->$tag)) {
                return null;
            }
            $value = trim((string) $xml->$tag);
            return $value === '' ? null : $value;
        };

        $result = [];
        foreach (self::ITEM_FIELD_MAP as $tag => $field) {
            $value = $get($tag);
            if ($value !== null) {
                $result[$field] = $field === 'issue_number' ? (float) $value : $value;
            }
        }
        foreach (self::DETAIL_FIELD_MAP as $tag => $field) {
            $value = $get($tag);
            if ($value !== null) {
                $result[$field] = $value;
            }
        }
        $series = $get('Series');
        if ($series !== null) {
            $result['series_name'] = $series;
        }

        return $result;
    }
}
