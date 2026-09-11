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

    /**
     * Builds a ComicInfo.xml document from an item's current fields —
     * the reverse of read() above, using the same two field maps so the
     * tag names stay in sync automatically rather than needing to be
     * kept in two separate places by hand. $pageCount drives both the
     * <PageCount> element and a <Pages> list (0-based Image index,
     * matching how every page-serving route elsewhere in this app
     * already indexes pages) — the first page is marked Type="FrontCover"
     * per the schema's own convention, the same assumption
     * CoverExtractor::forItem() makes for a comic with no ComicInfo.xml
     * at all.
     *
     * Values are escaped by hand before being handed to addChild() —
     * SimpleXMLElement does *not* escape its own text-content argument
     * (it's parsed as XML fragment, not literal text), so skipping this
     * would silently corrupt anything containing '&', '<', or '>', or
     * outright break the file if a value happened to look like a tag.
     */
    public static function write(array $fields, int $pageCount): string
    {
        $xml = new SimpleXMLElement(
            '<?xml version="1.0" encoding="utf-8"?>' .
            '<ComicInfo xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema"></ComicInfo>'
        );

        $addIfSet = static function (string $tag, $value) use ($xml): void {
            if ($value === null || $value === '') {
                return;
            }
            $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
            $xml->addChild($tag, $escaped);
        };

        $addIfSet('Title', $fields['title'] ?? null);
        $addIfSet('Series', $fields['series_name'] ?? null);
        $addIfSet('Number', $fields['issue_number'] ?? null);
        $addIfSet('Summary', $fields['synopsis'] ?? null);
        $addIfSet('Publisher', $fields['publisher'] ?? null);
        foreach (self::DETAIL_FIELD_MAP as $tag => $field) {
            $addIfSet($tag, $fields[$field] ?? null);
        }
        $xml->addChild('PageCount', (string) $pageCount);

        if ($pageCount > 0) {
            $pages = $xml->addChild('Pages');
            for ($i = 0; $i < $pageCount; $i++) {
                $page = $pages->addChild('Page');
                $page->addAttribute('Image', (string) $i);
                if ($i === 0) {
                    $page->addAttribute('Type', 'FrontCover');
                }
            }
        }

        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = true;
        return $dom->saveXML();
    }
}
