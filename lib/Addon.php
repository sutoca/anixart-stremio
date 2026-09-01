<?php

declare(strict_types=1);

namespace AnixartAddon;

/**
 * Stremio resource handlers + manifest. Video ids: ax:{releaseId}:{position}.
 * Note: meta uses the most complete source, streams try sources by priority —
 * episode numbers come from titles so the ids stay consistent between the two.
 */
final class Addon
{
    public const CATALOG_UPDATES = 'anixart-updates';

    // stremio caches per manifest version, bump on any behavior change
    public const VERSION = '1.4.0';

    public const CACHE_CATALOG_SECONDS = 600;
    public const CACHE_META_SECONDS = 600;
    public const CACHE_STREAM_SECONDS = 300;

    private const MANIFEST_MAX_BYTES = 8192;

    public function __construct(
        private readonly Api $api,
        private readonly Resolvers $resolvers,
        private readonly string $addonBaseUrl,
        private readonly Cache $cache = new Cache(),
    ) {}

    public function buildManifest(): array
    {
        $manifest = [
            'id' => Mappers::ADDON_ID,
            'version' => self::VERSION,
            'name' => 'Anixart',
            'description' => 'Anime from the Anixart catalog: direct links to Sibnet, Rutube, and Kodik (voiceovers), search, and updates.',
            'logo' => 'https://anixart.app/favicon.ico',
            'resources' => ['catalog', 'meta', 'stream'],
            'types' => ['series'],
            'idPrefixes' => ['ax:'],
            'catalogs' => [
                [
                    'type' => 'series',
                    'id' => self::CATALOG_UPDATES,
                    'name' => 'Anixart — Updates',
                    'extra' => [
                        ['name' => 'search', 'isRequired' => false],
                        ['name' => 'skip', 'isRequired' => false],
                    ],
                ],
            ],
            'behaviorHints' => [
                'configurable' => true,
                'configurationRequired' => false,
                'p2p' => false,
                'adult' => false,
            ],
        ];

        // 8kb manifest limit, description is the first thing to go
        if (strlen($this->encode($manifest)) > self::MANIFEST_MAX_BYTES) {
            unset($manifest['description']);
        }
        return $manifest;
    }

    /**
     * Catalog: updates or search. API pages are zero-based (25 items each)
     * while stremio steps skip by 100, so we stitch several pages together.
     */
    public function catalog(array $extra): array
    {
        $skip = is_numeric($extra['skip'] ?? null) ? max(0.0, (float) $extra['skip']) : 0.0;
        $search = isset($extra['search']) && is_string($extra['search']) ? trim($extra['search']) : '';
        if (isset($extra['search']) && $search === '') {
            return ['metas' => []];
        }

        $startPage = (int) floor($skip / Api::PAGE_SIZE);

        if ($search !== '') {
            // two pages in parallel, 50 results is plenty for a search
            $releases = $this->api->searchMulti($search, [$startPage, $startPage + 1]);
            $releases = Mappers::filterAndRank($releases, $search);
        } else {
            $releases = [];
            for ($i = 0; $i < 4; $i++) {
                $batch = $this->api->catalog($startPage + $i);
                if (!$batch) {
                    break;
                }
                array_push($releases, ...$batch);
                if (count($batch) < Api::PAGE_SIZE) {
                    break;
                }
            }
        }

        $metas = [];
        foreach ($releases as $release) {
            if (!is_array($release) || empty($release['id']) || !empty($release['is_deleted'])) {
                continue;
            }
            $metas[] = Mappers::proxied(Mappers::toMetaPreview($release), $this->addonBaseUrl);
        }
        return ['metas' => $metas];
    }

    public function meta(string $id): array
    {
        $parsed = self::parseStremioId($id);
        if (!$parsed) {
            return ['meta' => null];
        }

        $release = $this->api->release($parsed['releaseId']);
        if (!$release) {
            return ['meta' => null];
        }

        // pick the most complete source, some dubs miss episodes
        $episodes = [];
        $sourceLabel = '';
        $candidates = array_slice($this->candidateSources($parsed['releaseId']), 0, 4);
        $total = (int) ($release['episodes_total'] ?? 0);
        foreach ($candidates as $candidate) {
            $list = $this->api->episodes($parsed['releaseId'], $candidate['typeId'], $candidate['sourceId']);
            if (count($list) > count($episodes)) {
                $episodes = $list;
                $sourceLabel = $candidate['label'];
            }
            if ($total > 0 && count($episodes) >= $total) {
                break;
            }
        }

        return ['meta' => Mappers::proxied(
            Mappers::toMeta($release, $episodes, $sourceLabel),
            $this->addonBaseUrl,
        )];
    }

    /**
     * Poster proxy: s.anixmirai.com won't load for some clients and flakes out
     * for others. Posters are immutable — cache on disk forever after first fetch.
     */
    public function poster(string $url): ?array
    {
        // strict: https, no port/user/query, flat /posters/ path — then fetch
        // the reconstructed url instead of whatever the client sent
        $parts = parse_url($url);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 's.anixmirai.com'
            || isset($parts['port'], $parts['user'], $parts['pass'], $parts['query'])
            || !preg_match('#^/posters/[A-Za-z0-9._-]+$#', $path)) {
            return null;
        }

        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'anixart-posters';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $cached = $dir . DIRECTORY_SEPARATOR . md5($url) . '.' . $ext;
        if (is_file($cached)) {
            return ['body' => (string) file_get_contents($cached), 'type' => self::posterType($ext)];
        }

        $body = $this->resolvers->fetch('https://s.anixmirai.com' . $path);
        if ($body === null || $body === '') {
            return null;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return ['body' => $body, 'type' => self::posterType($ext)];
        }
        // atomic write: a concurrent request must never see a half-written file
        $tmp = $cached . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmp, $body) !== false) {
            @rename($tmp, $cached);
        }
        return ['body' => $body, 'type' => self::posterType($ext)];
    }

    private static function posterType(string $ext): string
    {
        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /**
     * Streams for an episode: walk sources by priority, resolve sibnet/rutube/kodik.
     * Sources are matched by episode number from the title, not by position.
     */
    public function stream(string $id): array
    {
        $parsed = self::parseStremioId($id);
        if (!$parsed || $parsed['position'] === null) {
            return ['streams' => []];
        }
        $releaseId = $parsed['releaseId'];
        $position = $parsed['position'];

        $streams = [];
        $usedSources = 0;
        foreach ($this->candidateSources($releaseId) as $candidate) {
            $episodes = $this->api->episodes($releaseId, $candidate['typeId'], $candidate['sourceId']);
            $episode = null;
            foreach ($episodes as $item) {
                if (Mappers::episodeNumber($item) === $position) {
                    $episode = $item;
                    break;
                }
            }
            $url = is_array($episode) && is_string($episode['url'] ?? null) ? $episode['url'] : null;
            if (!$url) {
                continue;
            }

            $variants = $this->resolvers->resolveAll($url);
            if (!$variants) {
                continue;
            }
            $usedSources++;

            $episodeName = is_string($episode['name'] ?? null) && $episode['name'] !== '' ? " • {$episode['name']}" : '';
            foreach ($variants as $variant) {
                $quality = $variant['label'] !== null ? ' ' . $variant['label'] : '';
                $streams[] = [
                    'url' => $variant['url'],
                    'name' => trim($candidate['label'] . $quality),
                    'description' => '🎬 Anixart • ' . $candidate['label'] . $quality . $episodeName,
                    'behaviorHints' => $variant['hls']
                        ? ['notWebReady' => true, 'bingeGroup' => "anixart-hls-{$releaseId}"]
                        : ['bingeGroup' => "anixart-mp4-{$releaseId}"],
                ];
            }

            // two working sources (or four streams) is enough
            if ($usedSources >= 2 || count($streams) >= 4) {
                break;
            }
        }

        return ['streams' => $streams];
    }

    /**
     * Sources ordered by preference: sibnet first (direct mp4), kodik last.
     * Cached per release — this walk is several requests deep.
     */
    private function candidateSources(int $releaseId): array
    {
        return $this->cache->wrap("sources|{$releaseId}", 1800, function () use ($releaseId): array {
            $candidates = [];
            foreach ($this->api->episodeTypes($releaseId) as $type) {
                $typeId = (int) ($type['id'] ?? 0);
                $typeName = is_string($type['name'] ?? null) ? $type['name'] : '';
                if ($typeId < 1) {
                    continue;
                }
                foreach ($this->api->episodeSources($releaseId, $typeId) as $source) {
                    $sourceId = (int) ($source['id'] ?? 0);
                    $sourceName = is_string($source['name'] ?? null) ? $source['name'] : '';
                    if ($sourceId < 1) {
                        continue;
                    }
                    $candidates[] = [
                        'typeId' => $typeId,
                        'sourceId' => $sourceId,
                        'label' => trim($typeName . ' • ' . $sourceName, ' •'),
                        'name' => $sourceName . ' ' . $typeName,
                        'isSub' => !empty($type['is_sub']),
                    ];
                }
            }
            usort($candidates, static function (array $a, array $b): int {
                $score = static fn (array $c): int =>
                    (str_contains($c['name'], 'Sibnet') ? 0 : (str_contains(strtolower($c['name']), 'kodik') ? 2 : 1))
                    + (!empty($c['isSub']) ? 10 : 0);
                return $score($a) <=> $score($b);
            });
            return array_map(static fn (array $c): array => [
                'typeId' => $c['typeId'],
                'sourceId' => $c['sourceId'],
                'label' => $c['label'],
            ], $candidates);
        }) ?? [];
    }

    /** id like `ax:1216:1` (release + episode) or `ax:1216`. */
    public static function parseStremioId(string $id): ?array
    {
        if (!str_starts_with($id, 'ax:')) {
            return null;
        }
        $parts = explode(':', $id);
        if (count($parts) < 2 || $parts[1] === '' || !preg_match('/^\d+$/', $parts[1])) {
            return null;
        }
        if (count($parts) === 2) {
            return ['releaseId' => (int) $parts[1], 'position' => null];
        }
        if (!preg_match('/^\d+$/', $parts[2]) || (int) $parts[2] < 1) {
            return null;
        }
        return ['releaseId' => (int) $parts[1], 'position' => (int) $parts[2]];
    }

    /** stremio puts extra args into the path: /catalog/series/{id}/search=x&skip=100.json */
    public static function parsePathExtra(string $segment): array
    {
        $extra = [];
        foreach (explode('&', $segment) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $key = rawurldecode($parts[0]);
            if ($key === '') {
                continue;
            }
            $extra[$key] = isset($parts[1]) ? rawurldecode($parts[1]) : '';
        }
        return $extra;
    }

    private function encode(array $data): string|false
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Install page. */
    public function configurePage(): string
    {
        $manifestUrl = $this->addonBaseUrl . '/manifest.json';
        $manifestForJs = json_encode($manifestUrl, JSON_UNESCAPED_SLASHES);

        return <<<HTML
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anixart — Stremio addon</title>
<style>
  :root { color-scheme: dark; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
         background:#14161a; color:#e8eaed; font:15px/1.5 system-ui, 'Segoe UI', sans-serif; padding:24px; }
  .card { width:100%; max-width:560px; background:#1d2026; border:1px solid #2c313a; border-radius:14px; padding:28px; }
  h1 { margin:0 0 4px; font-size:20px; }
  p.sub { margin:0 0 18px; color:#9aa0a8; }
  .row { padding:10px 0; border-bottom:1px solid #262b33; color:#c9ced6; }
  .actions { margin-top:22px; }
  a.button { display:block; text-align:center; background:#2e9e5b; color:#fff; text-decoration:none;
             padding:12px 16px; border-radius:10px; font-weight:600; }
  a.button:hover { background:#27894f; }
  .note { color:#9aa0a8; font-size:13px; word-break:break-all; }
  code { color:#86efac; }
</style>
</head>
<body>
<div class="card">
  <h1>Anixart for Stremio</h1>
  <p class="sub">Catalog and search for Anixart; streams — direct links to Sibnet, Rutube, and Kodik (HLS 360/480/720p).</p>
  <div class="row">Catalog updates and search — without authorization.</div>
  <div class="actions">
    <a class="button" id="install" href="#">Install in Stremio</a>
    <p class="note">If the button doesn't work, add the manifest manually:
       <code id="manifest-link"></code></p>
  </div>
</div>
<script>
  const manifestUrl = {$manifestForJs};
  document.getElementById('install').href = 'stremio://' + manifestUrl.replace(/^https?:\\/\\//, '');
  document.getElementById('manifest-link').textContent = manifestUrl;
</script>
</body>
</html>
HTML;
    }
}
