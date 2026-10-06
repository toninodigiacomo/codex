<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/LibraryScanner.php';

/**
 * The checks behind the admin console's "Cohérence" tab. Read-only: nothing
 * here ever edits an item, a series or a file — it only reports, and
 * remembers which reports the admin chose to ignore. Acting on a finding
 * (merging two series, deleting an empty one, editing a title) goes through
 * the same routes the rest of the console already uses.
 *
 * Every rule below was written against the real catalogue first, not in the
 * abstract, and the ones that looked sensible on paper but drowned the real
 * signal in noise were dropped: "title doesn't start with the series name"
 * (90% of correct fiches — titles are story titles), "series has no
 * description" (all of them), "file name prefix differs from series name"
 * (a third of them — year prefixes, "(The)" suffixes, "Hors Série" naming),
 * "same (series, number) twice" across libraries (6000+ groups — Comics and
 * Sorties Périodiques hold the same issues by design). Those cases are what
 * a later, AI-assisted pass is for; rules only keep what they can be
 * precise about.
 *
 * Three groups:
 *  - inconsistency: something that is probably wrong
 *  - files: the database and the disk disagree (needs a walk of the library
 *    folder, so computed on demand for one library at a time)
 *  - info: something missing or incomplete — not wrong, but worth knowing
 */
final class Coherence
{
    /** id => [group, severity, needs the disk] — also the display order. */
    private const RULES = [
        'series_equivalent'     => ['inconsistency', 'warning', false],
        'series_similar'        => ['inconsistency', 'warning', false],
        'number_duplicate'      => ['inconsistency', 'warning', false],
        'number_title_mismatch' => ['inconsistency', 'warning', false],
        'number_empty_text'     => ['inconsistency', 'warning', false],
        'number_year_like'      => ['inconsistency', 'warning', false],
        'title_whitespace'      => ['inconsistency', 'warning', false],
        'title_control_chars'   => ['inconsistency', 'warning', false],
        'publisher_variants'    => ['inconsistency', 'warning', false],
        'series_empty'          => ['inconsistency', 'warning', false],
        'file_missing'          => ['files', 'warning', true],
        'file_conflicted'       => ['files', 'warning', true],
        'file_unindexed'        => ['files', 'warning', true],
        'files_rejected'        => ['files', 'info', true],
        'numbers_missing'       => ['info', 'info', false],
        'synopsis_missing'      => ['info', 'info', false],
        'publisher_missing'     => ['info', 'info', false],
        'cover_missing'         => ['info', 'info', false],
    ];

    /** How many items a grouped finding carries as examples — the full set stays reachable through the item search. */
    private const SAMPLE_ITEMS = 8;

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /** @return array<int, array{id: string, group: string, severity: string, needs_disk: bool}> */
    public static function rules(): array
    {
        $out = [];
        foreach (self::RULES as $id => [$group, $severity, $disk]) {
            $out[] = ['id' => $id, 'group' => $group, 'severity' => $severity, 'needs_disk' => $disk];
        }
        return $out;
    }

    public static function isRule(string $rule): bool
    {
        return isset(self::RULES[$rule]);
    }

    public static function needsDisk(string $rule): bool
    {
        return self::RULES[$rule][2] ?? false;
    }

    /**
     * Counts per rule, for the summary view — everything except the rules that
     * need the disk (null there: they only run when the admin asks, for one
     * library). "by_library" counts a grouped finding once per library it
     * touches, so its values can add up to more than "total".
     * @return array<string, array{total: int, dismissed: int, by_library: array<int, int>}|null>
     */
    public static function summary(): array
    {
        $out = [];
        foreach (self::RULES as $rule => [, , $disk]) {
            if ($disk) {
                $out[$rule] = null;
                continue;
            }
            $dismissedKeys = array_flip(self::dismissedKeys($rule));
            $total = 0;
            $dismissed = 0;
            $byLibrary = [];
            foreach (self::compute($rule, null) as $f) {
                if (isset($dismissedKeys[$f['key']])) {
                    $dismissed++;
                    continue;
                }
                $total++;
                foreach ($f['library_ids'] as $libraryId) {
                    $byLibrary[$libraryId] = ($byLibrary[$libraryId] ?? 0) + 1;
                }
            }
            $out[$rule] = ['total' => $total, 'dismissed' => $dismissed, 'by_library' => $byLibrary];
        }
        return $out;
    }

    /**
     * The findings of one rule, minus the ones the admin ignored (or only
     * those, with $dismissed). Rules that need the disk require a library:
     * they walk that library's folder every time they're called.
     * @return array<int, array<string, mixed>>
     */
    public static function findings(string $rule, ?int $libraryId = null, bool $dismissed = false): array
    {
        if (!self::isRule($rule)) {
            throw new InvalidArgumentException('Règle inconnue');
        }
        if (self::needsDisk($rule) && $libraryId === null) {
            throw new InvalidArgumentException('Cette vérification porte sur une bibliothèque : choisis-en une');
        }
        $dismissedKeys = array_flip(self::dismissedKeys($rule));
        $out = [];
        foreach (self::compute($rule, $libraryId) as $f) {
            if ($libraryId !== null && !self::needsDisk($rule) && !in_array($libraryId, $f['library_ids'], true)) {
                continue;
            }
            if (isset($dismissedKeys[$f['key']]) !== $dismissed) {
                continue;
            }
            $f['dismissed'] = $dismissed;
            $out[] = $f;
        }
        return $out;
    }

    /** @return array<int, string> */
    public static function dismissedKeys(string $rule): array
    {
        $stmt = Database::connection()->prepare('SELECT finding_key FROM coherence_dismissed WHERE rule = ?');
        $stmt->execute([$rule]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<int, string> $keys */
    public static function dismiss(string $rule, array $keys): int
    {
        if (!self::isRule($rule)) {
            throw new InvalidArgumentException('Règle inconnue');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO coherence_dismissed (rule, finding_key) VALUES (?, ?)');
        $n = 0;
        $pdo->beginTransaction();
        try {
            foreach ($keys as $key) {
                $stmt->execute([$rule, (string) $key]);
                $n += $stmt->rowCount();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $n;
    }

    /** @param array<int, string> $keys */
    public static function restore(string $rule, array $keys): int
    {
        if (!self::isRule($rule)) {
            throw new InvalidArgumentException('Règle inconnue');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM coherence_dismissed WHERE rule = ? AND finding_key = ?');
        $n = 0;
        $pdo->beginTransaction();
        try {
            foreach ($keys as $key) {
                $stmt->execute([$rule, (string) $key]);
                $n += $stmt->rowCount();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $n;
    }

    /** A title with runs of spaces, tabs and line breaks collapsed to one space, and trimmed. ASCII whitespace only — a non-breaking space is left alone. */
    public static function cleanTitle(string $title): string
    {
        return trim((string) preg_replace('/[ \t\r\n]+/', ' ', $title));
    }

    /**
     * Applies cleanTitle() to one item. The clean title is recomputed here, from
     * what is in the database right now, never taken from the caller — the
     * button only says "this one", not what to write.
     * @return array{title: string, changed: bool}
     */
    public static function fixTitle(int $itemId): array
    {
        $rows = self::fetchAll('SELECT id, title FROM items WHERE id = ?', [$itemId]);
        if ($rows === []) {
            throw new InvalidArgumentException('Fiche introuvable');
        }
        $title = (string) $rows[0]['title'];
        $clean = self::cleanTitle($title);
        if ($clean === '') {
            throw new InvalidArgumentException('Le titre deviendrait vide : corrige-le à la main');
        }
        if ($clean === $title) {
            return ['title' => $title, 'changed' => false];
        }
        $stmt = self::pdo()->prepare('UPDATE items SET title = ? WHERE id = ?');
        $stmt->execute([$clean, $itemId]);
        return ['title' => $clean, 'changed' => true];
    }

    /**
     * Name comparison key: accents, case, punctuation and a leading/trailing
     * article ("The Twelve" / "Twelve (The)") don't matter; digits do —
     * "Avengers (2011)" and "Avengers (2012)" are different series.
     *
     * The accent table is spelled out letter by letter on purpose rather than
     * relying on iconv's //TRANSLIT, whose result depends on the server's
     * locale (in the C locale "é" becomes "?"), and doesn't need mbstring.
     */
    public static function normalize(string $s): string
    {
        static $map = null;
        if ($map === null) {
            $groups = [
                'a' => 'àáâãäåāăąÀÁÂÃÄÅĀĂĄ', 'c' => 'çćčÇĆČ', 'd' => 'ďđĎĐ',
                'e' => 'èéêëēėęěÈÉÊËĒĖĘĚ', 'i' => 'ìíîïīįÌÍÎÏĪĮ', 'l' => 'łŁ',
                'n' => 'ñńňÑŃŇ', 'o' => 'òóôõöøōőÒÓÔÕÖØŌŐ', 'r' => 'ŕřŔŘ',
                's' => 'śšşŚŠŞ', 't' => 'ťŤ', 'u' => 'ùúûüūůűųÙÚÛÜŪŮŰŲ',
                'y' => 'ýÿÝŸ', 'z' => 'źżžŹŻŽ',
            ];
            $map = [];
            foreach ($groups as $base => $variants) {
                foreach (preg_split('//u', $variants, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                    $map[$ch] = $base;
                }
            }
            $map += ['œ' => 'oe', 'Œ' => 'oe', 'æ' => 'ae', 'Æ' => 'ae', 'ß' => 'ss',
                '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-'];
        }
        $s = strtr($s, $map);
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        $s = str_replace('&', ' and ', $s);
        $s = (string) preg_replace('/\((the|le|la|les|l\'|un|une)\)/', ' ', $s);
        $s = (string) preg_replace('/^(the|le|la|les|l\')\s+/', '', trim($s));
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    // ------------------------------------------------------------------
    // Dispatch
    // ------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private static function compute(string $rule, ?int $libraryId): array
    {
        if (self::needsDisk($rule)) {
            return self::diskFindings($rule, (int) $libraryId);
        }
        $method = 'rule' . str_replace(' ', '', ucwords(str_replace('_', ' ', $rule)));
        return self::$method();
    }

    /**
     * @param array<int, int|string|null> $libraryIds
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $series
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function finding(
        string $rule,
        string $key,
        string $label,
        ?string $detail = null,
        array $libraryIds = [],
        array $items = [],
        ?int $itemsTotal = null,
        array $series = [],
        array $data = []
    ): array {
        $libs = array_values(array_unique(array_map('intval', array_filter($libraryIds, fn($v) => $v !== null && $v !== ''))));
        sort($libs);
        return [
            'rule' => $rule,
            'key' => $key,
            'label' => $label,
            'detail' => $detail,
            'library_ids' => $libs,
            'items' => array_values($items),
            'items_total' => $itemsTotal ?? count($items),
            'series' => array_values($series),
            'data' => $data,
        ];
    }

    private static function pdo(): PDO
    {
        return Database::connection();
    }

    /** @return array<int, array<string, mixed>> */
    private static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array{id: int, title: string, path: string, library_id: ?int} */
    private static function itemRef(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'path' => (string) $row['path'],
            'library_id' => $row['library_id'] !== null ? (int) $row['library_id'] : null,
        ];
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>> id => item row
     */
    private static function itemsByIds(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            foreach (self::fetchAll("SELECT id, title, path, library_id FROM items WHERE id IN ($marks)", $chunk) as $row) {
                $out[(int) $row['id']] = $row;
            }
        }
        return $out;
    }

    /** @return array<int, array<int, int>> series id => library ids its items live in */
    private static function seriesLibraries(): array
    {
        $map = [];
        foreach (self::fetchAll('SELECT series_id, library_id FROM items WHERE series_id IS NOT NULL GROUP BY series_id, library_id') as $row) {
            if ($row['library_id'] !== null) {
                $map[(int) $row['series_id']][] = (int) $row['library_id'];
            }
        }
        return $map;
    }

    private static function formatNumber(float|int|string $n): string
    {
        $f = (float) $n;
        return $f == floor($f) ? (string) (int) $f : rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.');
    }

    /** [1,2,3,7,9,10] => "1–3, 7, 9–10" */
    private static function ranges(array $numbers): string
    {
        sort($numbers);
        $parts = [];
        $start = $prev = null;
        foreach ($numbers as $n) {
            if ($start === null) {
                $start = $prev = $n;
            } elseif ($n === $prev + 1) {
                $prev = $n;
            } else {
                $parts[] = $start === $prev ? (string) $start : "{$start}–{$prev}";
                $start = $prev = $n;
            }
        }
        if ($start !== null) {
            $parts[] = $start === $prev ? (string) $start : "{$start}–{$prev}";
        }
        return implode(', ', $parts);
    }

    private static function sortByLabel(array &$findings): void
    {
        usort($findings, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));
    }

    // ------------------------------------------------------------------
    // Rules on the catalogue
    // ------------------------------------------------------------------

    /** Series whose names differ only by case, accents, punctuation or an article — one series split in two. */
    private static function ruleSeriesEquivalent(): array
    {
        $groups = [];
        foreach (self::fetchAll('SELECT s.id, s.name, COUNT(i.id) AS n FROM series s LEFT JOIN items i ON i.series_id = s.id GROUP BY s.id') as $s) {
            $norm = self::normalize((string) $s['name']);
            if ($norm !== '') {
                $groups[$norm][] = ['id' => (int) $s['id'], 'name' => (string) $s['name'], 'count' => (int) $s['n']];
            }
        }
        $libraries = self::seriesLibraries();
        $out = [];
        foreach ($groups as $norm => $members) {
            if (count($members) < 2) {
                continue;
            }
            usort($members, fn($a, $b) => [$b['count'], $a['id']] <=> [$a['count'], $b['id']]);
            $libs = [];
            $total = 0;
            foreach ($members as $m) {
                $libs = array_merge($libs, $libraries[$m['id']] ?? []);
                $total += $m['count'];
            }
            $out[] = self::finding('series_equivalent', (string) $norm, $members[0]['name'], null, $libs, [], $total, $members, ['target_id' => $members[0]['id']]);
        }
        usort($out, fn($a, $b) => [$b['items_total'], $a['label']] <=> [$a['items_total'], $b['label']]);
        return $out;
    }

    /**
     * A small series (1–3 fiches) whose name is one or two typos away from a
     * bigger one — "New Mutant" / "New Mutants", "Marvel Heros" / "Marvel
     * Heroes". Names that differ by a digit are never compared: "Avengers
     * (2011)" and "Avengers (2012)" are two real volumes. A suspicion, not a
     * verdict — "Marvel" and "Marvels" are genuinely different — hence the
     * Ignore button.
     */
    private static function ruleSeriesSimilar(): array
    {
        $all = [];
        foreach (self::fetchAll('SELECT s.id, s.name, COUNT(i.id) AS n FROM series s LEFT JOIN items i ON i.series_id = s.id GROUP BY s.id') as $s) {
            $norm = self::normalize((string) $s['name']);
            $all[(int) $s['id']] = [
                'id' => (int) $s['id'], 'name' => (string) $s['name'], 'count' => (int) $s['n'],
                'norm' => $norm, 'digits' => (string) preg_replace('/\D+/', '', $norm), 'len' => strlen($norm),
            ];
        }
        $libraries = self::seriesLibraries();
        $pairs = [];
        foreach ($all as $small) {
            if ($small['count'] < 1 || $small['count'] > 3 || $small['len'] < 6) {
                continue;
            }
            $best = null;
            foreach ($all as $other) {
                if ($other['id'] === $small['id'] || $other['norm'] === $small['norm'] || $other['count'] < $small['count']
                    || $other['digits'] !== $small['digits'] || abs($other['len'] - $small['len']) > 2) {
                    continue;
                }
                $d = levenshtein($small['norm'], $other['norm']);
                // Two typos in a short name ("Asgard" / "Bastard!!") is just two different words
                if ($d < 1 || $d > ($small['len'] < 10 ? 1 : 2)) {
                    continue;
                }
                if ($best === null || [$d, -$other['count']] < [$best['d'], -$best['other']['count']]) {
                    $best = ['d' => $d, 'other' => $other];
                }
            }
            if ($best === null) {
                continue;
            }
            $other = $best['other'];
            $key = min($small['id'], $other['id']) . '-' . max($small['id'], $other['id']);
            if (isset($pairs[$key])) {
                continue;
            }
            $members = [
                ['id' => $small['id'], 'name' => $small['name'], 'count' => $small['count']],
                ['id' => $other['id'], 'name' => $other['name'], 'count' => $other['count']],
            ];
            $pairs[$key] = self::finding(
                'series_similar', $key, $small['name'], null,
                array_merge($libraries[$small['id']] ?? [], $libraries[$other['id']] ?? []),
                [], $small['count'] + $other['count'], $members,
                ['from_id' => $small['id'], 'into_id' => $other['id'], 'distance' => $best['d']]
            );
        }
        $out = array_values($pairs);
        self::sortByLabel($out);
        return $out;
    }

    /**
     * The same (series, number) held by several fiches of one library. Counted
     * per library on purpose: Comics and Sorties Périodiques both hold
     * "Spider-Man #81" by design. Within ScanTrad it is also normal (an issue
     * belongs to several story arcs) — use the library filter and "ignore all".
     */
    private static function ruleNumberDuplicate(): array
    {
        $groups = self::fetchAll(
            "SELECT series_id, issue_number, library_id, COUNT(*) AS n, GROUP_CONCAT(id) AS ids FROM items
             WHERE series_id IS NOT NULL AND typeof(issue_number) IN ('integer','real')
             GROUP BY series_id, issue_number, library_id HAVING COUNT(*) > 1"
        );
        $series = array_column(self::fetchAll('SELECT id, name FROM series'), 'name', 'id');
        $allIds = [];
        foreach ($groups as $g) {
            foreach (explode(',', (string) $g['ids']) as $id) {
                $allIds[] = (int) $id;
            }
        }
        $itemRows = self::itemsByIds($allIds);
        $out = [];
        foreach ($groups as $g) {
            $number = self::formatNumber($g['issue_number']);
            $sid = (int) $g['series_id'];
            $name = (string) ($series[$sid] ?? '?');
            $ids = array_map('intval', explode(',', (string) $g['ids']));
            sort($ids);
            $items = [];
            foreach (array_slice($ids, 0, self::SAMPLE_ITEMS) as $id) {
                if (isset($itemRows[$id])) {
                    $items[] = self::itemRef($itemRows[$id]);
                }
            }
            $out[] = self::finding(
                'number_duplicate', "$sid:$number:" . (int) $g['library_id'], "$name #$number", null,
                [(int) $g['library_id']], $items, (int) $g['n'], [['id' => $sid, 'name' => $name]]
            );
        }
        self::sortByLabel($out);
        return $out;
    }

    /** A number written in the title ("#4") that the fiche's own number contradicts. A title naming several numbers is fine if any of them matches. */
    private static function ruleNumberTitleMismatch(): array
    {
        $out = [];
        foreach (self::fetchAll("SELECT id, title, path, library_id, issue_number FROM items WHERE typeof(issue_number) IN ('integer','real') AND title LIKE '%#%'") as $row) {
            if (!preg_match_all('/#\s*(\d+(?:\.\d+)?)/', (string) $row['title'], $m)) {
                continue;
            }
            $inTitle = array_map('floatval', $m[1]);
            $mine = (float) $row['issue_number'];
            $matches = false;
            foreach ($inTitle as $n) {
                if (abs($n - $mine) < 1e-9) {
                    $matches = true;
                    break;
                }
            }
            if ($matches) {
                continue;
            }
            $out[] = self::finding(
                'number_title_mismatch', (string) $row['id'], (string) $row['title'], null,
                [$row['library_id']], [self::itemRef($row)], 1, [],
                ['title_numbers' => array_map([self::class, 'formatNumber'], $inTitle), 'issue_number' => self::formatNumber($mine)]
            );
        }
        self::sortByLabel($out);
        return $out;
    }

    /** issue_number stored as text (always an empty string in practice) instead of NULL or a number. */
    private static function ruleNumberEmptyText(): array
    {
        $out = [];
        foreach (self::fetchAll("SELECT id, title, path, library_id, issue_number FROM items WHERE typeof(issue_number) = 'text'") as $row) {
            $out[] = self::finding(
                'number_empty_text', (string) $row['id'], (string) $row['title'], null,
                [$row['library_id']], [self::itemRef($row)], 1, [], ['value' => (string) $row['issue_number']]
            );
        }
        self::sortByLabel($out);
        return $out;
    }

    /** An issue number between 1900 and 2100 is almost certainly a year read as a number. */
    private static function ruleNumberYearLike(): array
    {
        $out = [];
        foreach (self::fetchAll("SELECT id, title, path, library_id, issue_number FROM items WHERE typeof(issue_number) IN ('integer','real') AND issue_number BETWEEN 1900 AND 2100 AND issue_number = CAST(issue_number AS INTEGER)") as $row) {
            $out[] = self::finding(
                'number_year_like', (string) $row['id'], (string) $row['title'], null,
                [$row['library_id']], [self::itemRef($row)], 1, [], ['value' => self::formatNumber($row['issue_number'])]
            );
        }
        self::sortByLabel($out);
        return $out;
    }

    /** Leading/trailing spaces and runs of spaces in a title. */
    private static function ruleTitleWhitespace(): array
    {
        $out = [];
        foreach (self::fetchAll("SELECT id, title, path, library_id FROM items WHERE title LIKE '%  %' OR title != TRIM(title)") as $row) {
            $title = (string) $row['title'];
            $clean = self::cleanTitle($title);
            if ($clean === $title) {
                continue;
            }
            $out[] = self::finding(
                'title_whitespace', (string) $row['id'], $title, null,
                [$row['library_id']], [self::itemRef($row)], 1, [], ['suggested' => $clean]
            );
        }
        self::sortByLabel($out);
        return $out;
    }

    /** Line breaks or tabs inside a title (typically a multi-issue compilation's ComicInfo "Title" pasted verbatim). */
    private static function ruleTitleControlChars(): array
    {
        $out = [];
        foreach (self::fetchAll("SELECT id, title, path, library_id FROM items WHERE title LIKE '%' || char(10) || '%' OR title LIKE '%' || char(13) || '%' OR title LIKE '%' || char(9) || '%'") as $row) {
            $title = (string) $row['title'];
            $clean = self::cleanTitle($title);
            $out[] = self::finding(
                'title_control_chars', (string) $row['id'], str_replace(["\r\n", "\n", "\r", "\t"], ['⏎', '⏎', '⏎', '→'], $title), null,
                [$row['library_id']], [self::itemRef($row)], 1, [], ['suggested' => $clean]
            );
        }
        self::sortByLabel($out);
        return $out;
    }

    /** The same publisher spelled several ways: "Editions Oxymore" / "Éditions Oxymore", "Tabou" / "tabou". */
    private static function rulePublisherVariants(): array
    {
        $groups = [];
        foreach (self::fetchAll("SELECT publisher, COUNT(*) AS c FROM items WHERE publisher IS NOT NULL AND TRIM(publisher) != '' GROUP BY publisher") as $row) {
            $norm = self::normalize((string) $row['publisher']);
            if ($norm !== '') {
                $groups[$norm][] = ['name' => (string) $row['publisher'], 'count' => (int) $row['c']];
            }
        }
        $out = [];
        foreach ($groups as $norm => $variants) {
            if (count($variants) < 2) {
                continue;
            }
            usort($variants, fn($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);
            $names = array_column($variants, 'name');
            $marks = implode(',', array_fill(0, count($names), '?'));
            $libs = array_column(self::fetchAll("SELECT DISTINCT library_id FROM items WHERE publisher IN ($marks)", $names), 'library_id');
            $sample = array_map([self::class, 'itemRef'], self::fetchAll(
                "SELECT id, title, path, library_id FROM items WHERE publisher IN ($marks) AND publisher != ? ORDER BY id LIMIT " . self::SAMPLE_ITEMS,
                array_merge($names, [$names[0]])
            ));
            $out[] = self::finding(
                'publisher_variants', (string) $norm, $variants[0]['name'], null, $libs, $sample,
                array_sum(array_column($variants, 'count')), [], ['variants' => $variants]
            );
        }
        self::sortByLabel($out);
        return $out;
    }

    /** A series no fiche points at any more. */
    private static function ruleSeriesEmpty(): array
    {
        $out = [];
        foreach (self::fetchAll('SELECT s.id, s.name FROM series s WHERE NOT EXISTS (SELECT 1 FROM items i WHERE i.series_id = s.id)') as $row) {
            $out[] = self::finding(
                'series_empty', (string) $row['id'], (string) $row['name'], null, [], [], 0,
                [['id' => (int) $row['id'], 'name' => (string) $row['name'], 'count' => 0]]
            );
        }
        self::sortByLabel($out);
        return $out;
    }

    /**
     * Holes in a series' numbering. Only where a hole is meaningful: at least
     * five numbered fiches, no more than 30% of the span missing, a span of at
     * most 600 — beyond that a series is a partial collection, not a series
     * with gaps. Informational: a hole is a missing issue, not a mistake.
     */
    private static function ruleNumbersMissing(): array
    {
        $by = [];
        foreach (self::fetchAll("SELECT series_id, issue_number FROM items WHERE series_id IS NOT NULL AND typeof(issue_number) IN ('integer','real') AND issue_number = CAST(issue_number AS INTEGER) AND issue_number > 0") as $row) {
            $by[(int) $row['series_id']][(int) $row['issue_number']] = true;
        }
        $names = array_column(self::fetchAll('SELECT id, name FROM series'), 'name', 'id');
        $libraries = self::seriesLibraries();
        $out = [];
        foreach ($by as $sid => $set) {
            $numbers = array_keys($set);
            sort($numbers);
            if (count($numbers) < 5) {
                continue;
            }
            $first = $numbers[0];
            $last = $numbers[count($numbers) - 1];
            $span = $last - $first + 1;
            $missing = [];
            for ($n = $first; $n <= $last; $n++) {
                if (!isset($set[$n])) {
                    $missing[] = $n;
                }
            }
            if ($missing === [] || count($missing) / $span > 0.30 || $span > 600) {
                continue;
            }
            $out[] = self::finding(
                'numbers_missing', $sid . ':' . substr(md5(implode(',', $missing)), 0, 8), (string) ($names[$sid] ?? '?'), null,
                $libraries[$sid] ?? [], [], count($numbers), [['id' => $sid, 'name' => (string) ($names[$sid] ?? '?')]],
                ['present' => count($numbers), 'from' => $first, 'to' => $last, 'missing' => self::ranges($missing), 'missing_count' => count($missing)]
            );
        }
        usort($out, fn($a, $b) => [$b['data']['missing_count'], $a['label']] <=> [$a['data']['missing_count'], $b['label']]);
        return $out;
    }

    private static function ruleSynopsisMissing(): array
    {
        return self::simpleItemRule('synopsis_missing', "synopsis IS NULL OR TRIM(synopsis) = ''");
    }

    private static function rulePublisherMissing(): array
    {
        return self::simpleItemRule('publisher_missing', "publisher IS NULL OR TRIM(publisher) = ''");
    }

    private static function ruleCoverMissing(): array
    {
        return self::simpleItemRule('cover_missing', "cover_path IS NULL OR TRIM(cover_path) = ''");
    }

    private static function simpleItemRule(string $rule, string $where): array
    {
        $out = [];
        foreach (self::fetchAll("SELECT id, title, path, library_id FROM items WHERE $where") as $row) {
            $out[] = self::finding($rule, (string) $row['id'], (string) $row['title'], null, [$row['library_id']], [self::itemRef($row)], 1);
        }
        self::sortByLabel($out);
        return $out;
    }

    // ------------------------------------------------------------------
    // Rules on the disk (one library at a time)
    // ------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private static function diskFindings(string $rule, int $libraryId): array
    {
        static $scans = [];
        if (!isset($scans[$libraryId])) {
            $library = self::fetchAll('SELECT * FROM libraries WHERE id = ?', [$libraryId])[0] ?? null;
            if ($library === null) {
                throw new InvalidArgumentException('Bibliothèque introuvable');
            }
            $scans[$libraryId] = ['library' => $library, 'scan' => LibraryScanner::inspect($library)];
        }
        $scan = $scans[$libraryId]['scan'];
        $out = [];

        switch ($rule) {
            case 'file_missing':
                foreach ($scan['orphaned'] as $o) {
                    $out[] = self::finding(
                        $rule, (string) $o['id'], (string) $o['title'], (string) $o['path'], [$libraryId],
                        [['id' => $o['id'], 'title' => $o['title'], 'path' => $o['path'], 'library_id' => $libraryId]], 1
                    );
                }
                break;
            case 'file_conflicted':
                foreach ($scan['conflicted'] as $c) {
                    $out[] = self::finding($rule, $c['path'], basename($c['path']), $c['path'], [$libraryId], [], 0, [], ['owner_library_id' => $c['owner_library_id']]);
                }
                break;
            case 'file_unindexed':
                foreach ($scan['unindexed'] as $path) {
                    $out[] = self::finding($rule, $path, basename($path), $path, [$libraryId]);
                }
                break;
            case 'files_rejected':
                foreach ($scan['rejected'] as $folder => $info) {
                    $out[] = self::finding(
                        $rule, (string) $folder, basename((string) $folder), (string) $folder, [$libraryId], [], $info['count'], [],
                        ['count' => $info['count'], 'extensions' => $info['extensions']]
                    );
                }
                break;
        }
        self::sortByLabel($out);
        return $out;
    }
}
