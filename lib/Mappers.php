<?php

declare(strict_types=1);

namespace AnixartAddon;

/**
 * Anixart objects -> Stremio protocol objects. Video ids: ax:{releaseId}:{position}.
 */
final class Mappers
{
    public const ADDON_ID = 'sutoca.anixart';

    /** first non-empty string, the JS `a || b || c` equivalent */
    private static function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    private static function genres(array $release): array
    {
        $raw = $release['genres'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $g): bool => $g !== ''));
    }

    public static function title(array $release): string
    {
        return self::firstNonEmpty($release['title_ru'] ?? null, $release['title_original'] ?? null) ?? 'Untitled';
    }

    private static function description(array $release): ?string
    {
        $raw = $release['description'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $text = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return $text !== '' ? $text : null;
    }

    /**
     * their search matches any word anywhere (incl. translations), so
     * "death note" returns "Death March" and friends. every query word must
     * appear in title_ru or title_original; phrase match ranks first.
     */
    public static function filterAndRank(array $releases, string $query): array
    {
        $query = trim($query);
        $tokens = array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower($query)) ?: [],
            static fn (string $token): bool => $token !== '',
        ));
        if (!$tokens) {
            return array_values(array_filter($releases, 'is_array'));
        }
        $queryLower = mb_strtolower($query);

        $kept = [];
        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }
            $ru = mb_strtolower((string) ($release['title_ru'] ?? ''));
            $orig = mb_strtolower((string) ($release['title_original'] ?? ''));
            $matched = 0;
            $allInRu = true;
            $allInOrig = true;
            foreach ($tokens as $token) {
                $inRu = $ru !== '' && mb_strpos($ru, $token) !== false;
                $inOrig = $orig !== '' && mb_strpos($orig, $token) !== false;
                if ($inRu || $inOrig) {
                    $matched++;
                }
                $allInRu = $allInRu && $inRu;
                $allInOrig = $allInOrig && $inOrig;
            }
            if ($matched < count($tokens)) {
                continue;
            }
            $kept[] = [
                'release' => $release,
                'phrase' => (int) (($ru !== '' && mb_strpos($ru, $queryLower) !== false)
                    || ($orig !== '' && mb_strpos($orig, $queryLower) !== false)),
                'coherent' => (int) ($allInRu || $allInOrig),
                'favs' => (float) ($release['favorites_count'] ?? 0),
            ];
        }

        usort($kept, static fn (array $a, array $b): int =>
            [$b['phrase'], $b['coherent'], $b['favs']] <=> [$a['phrase'], $a['coherent'], $a['favs']]);
        return array_column($kept, 'release');
    }

    /**
     * s.anixmirai.com posters won't load for some clients (broken tls chain),
     * so serve them through the addon: /img/{base64url of the full url}.
     */
    public static function proxied(array $meta, string $addonBaseUrl): array
    {
        foreach (['poster', 'background'] as $key) {
            $url = $meta[$key] ?? null;
            if (is_string($url) && str_contains($url, 'anixmirai.com')) {
                $meta[$key] = $addonBaseUrl . '/img/' . rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
            }
        }
        return $meta;
    }

    public static function toMetaPreview(array $release): array
    {
        $preview = [
            'id' => 'ax:' . $release['id'],
            'type' => 'series',
            'name' => self::title($release),
            'poster' => self::firstNonEmpty($release['image'] ?? null),
            'posterShape' => 'poster',
            'description' => self::description($release),
            'releaseInfo' => self::firstNonEmpty($release['year'] ?? null),
        ];
        $genres = self::genres($release);
        if ($genres) {
            $preview['genres'] = $genres;
        }
        return $preview;
    }

    /**
     * episode number from the title, position is only a fallback.
     * positions are unreliable: sibnet counts from 0, some dubs miss ep1 and
     * have their positions squeezed.
     */
    public static function episodeNumber(array $episode): int
    {
        $position = (int) ($episode['position'] ?? 0);
        $name = is_string($episode['name'] ?? null) ? trim($episode['name']) : '';
        if ($name !== '' && preg_match('/(\d{1,4})\s*(?:серия|эпизод|ep)\b/ui', $name, $m)) {
            return (int) $m[1];
        }
        if ($name !== '' && preg_match('/(\d{1,4})/u', $name, $m) && (int) $m[1] >= 1) {
            return (int) $m[1];
        }
        return $position;
    }

    /** @param array<int, array{position: int, name: ?string}> $episodes */
    public static function toMeta(array $release, array $episodes, string $sourceLabel): array
    {
        $byNumber = [];
        foreach ($episodes as $episode) {
            $number = self::episodeNumber($episode);
            if ($number < 1 || isset($byNumber[$number])) {
                continue;
            }
            $name = is_string($episode['name'] ?? null) && $episode['name'] !== '' ? $episode['name'] : null;
            $byNumber[$number] = [
                'id' => "ax:{$release['id']}:{$number}",
                'title' => $name ?? "Series {$number}",
                'season' => 1,
                'episode' => $number,
                'released' => isset($release['creation_date']) && is_numeric($release['creation_date'])
                    ? gmdate('c', (int) $release['creation_date'])
                    : null,
                'overview' => $sourceLabel !== '' ? "Source: {$sourceLabel}" : null,
                'available' => true,
            ];
        }
        ksort($byNumber);
        $videos = array_values($byNumber);

        $meta = [
            'id' => 'ax:' . $release['id'],
            'type' => 'series',
            'name' => self::title($release),
            'poster' => self::firstNonEmpty($release['image'] ?? null),
            'posterShape' => 'poster',
            'background' => self::firstNonEmpty($release['image'] ?? null),
            'description' => self::description($release),
            'releaseInfo' => self::firstNonEmpty($release['year'] ?? null),
            'videos' => $videos,
        ];
        $genres = self::genres($release);
        if ($genres) {
            $meta['genres'] = $genres;
        }
        return $meta;
    }
}
