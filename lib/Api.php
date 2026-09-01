<?php

declare(strict_types=1);

namespace AnixartAddon;

class AnixartException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}

// anixart mobile api client, reverse engineered from the apk. anonymous access is enough.
final class Api
{
    public const PAGE_SIZE = 25;

    private const TTL_CATALOG = 600;
    private const TTL_RELEASE = 1800;
    private const TTL_SOURCES = 1800;
    private const TTL_ACTIVE_HOST = 3600;
    private const TTL_STALE_SEARCH = 86400;

    private const TIMEOUT_MS = 15000;

    /** @var string[] */
    private readonly array $hosts;
    private Cache $cache;
    private int $activeHost = 0;

    public function __construct(?array $hosts = null, ?Cache $cache = null)
    {
        // mirrors list lives at anixhelper.github.io/pages/urls.json
        $this->hosts = $hosts ?? ['https://api.anixsekai.com', 'https://api-s.anixsekai.com'];
        $this->cache = $cache ?? new Cache();

        $remembered = $this->cache->get('__active_host');
        if (is_int($remembered) && isset($this->hosts[$remembered])) {
            $this->activeHost = $remembered;
        }
    }

    private function get(string $path, int $ttl): mixed
    {
        return $this->cache->wrap('GET|' . $path, $ttl, fn (): mixed => $this->request('GET', $path));
    }

    public function catalog(int $page): array
    {
        $page = max(0, $page);
        // pages past the end answer 404, that's just "no more items"
        $data = $this->cache->wrap("filter|{$page}", self::TTL_CATALOG, function () use ($page): mixed {
            try {
                return $this->request('GET', "/filter/{$page}");
            } catch (AnixartException $e) {
                if ($e->status === 404) {
                    return ['content' => []];
                }
                throw $e;
            }
        });
        return self::listContent($data);
    }

    // their search backend is flaky as hell: sometimes it just answers empty.
    // retry, then fall back to mirrors, and bank good results for a whole day.
    // it also flips between two response shapes ('content' legacy / 'releases' v2)
    public function search(string $query, int $page): array
    {
        $page = max(0, $page);
        $key = 's2|' . mb_strtolower(trim($query)) . "|{$page}";
        $staleKey = 's2stale|' . mb_strtolower(trim($query)) . "|{$page}";

        $fresh = $this->cache->get($key);
        if (is_array($fresh)) {
            return $fresh;
        }

        // exact payload the app sends, API-Version: v2 header included
        $body = ['query' => $query, 'searchBy' => 0];

        // dead-phase marker: for a minute after a fail don't waste time on retries
        $phaseDown = (bool) $this->cache->get('search_phase_down');
        $attempts = ($page === 0 && !$phaseDown) ? 2 : 1;

        $items = [];
        try {
            for ($i = 0; $i < $attempts; $i++) {
                $items = self::listContent($this->request('POST', "/search/releases/{$page}", $body));
                if ($items !== []) {
                    break;
                }
                if ($i < $attempts - 1) {
                    usleep(400000);
                }
            }
            if (!$items && $page === 0 && !$phaseDown) {
                $items = self::listContent($this->searchAnyMirror($body));
            }
        } catch (AnixartException $e) {
            if ($e->status !== 404) {
                throw $e;
            }
        }

        if ($items !== []) {
            $this->cache->set($key, $items, self::TTL_CATALOG);
            $this->cache->set($staleKey, $items, self::TTL_STALE_SEARCH);
            $this->cache->set('search_phase_down', false, 60);
            return $items;
        }

        $this->cache->set('search_phase_down', true, 60);
        $stale = $this->cache->get($staleKey);
        return is_array($stale) ? $stale : [];
    }

    // several search pages at once, cache hits served from disk
    public function searchMulti(string $query, array $pages): array
    {
        $merged = [];
        $missing = [];
        foreach ($pages as $page) {
            $cached = $this->cache->get('s2|' . mb_strtolower(trim($query)) . "|{$page}");
            if (is_array($cached)) {
                array_push($merged, ...$cached);
            } else {
                $missing[] = $page;
            }
        }
        if (!$missing) {
            return $merged;
        }

        // dead phase: no live requests, search() serves stale in one quick attempt
        if ($this->cache->get('search_phase_down') !== null) {
            foreach ($missing as $page) {
                array_push($merged, ...$this->search($query, $page));
            }
            return $merged;
        }

        foreach ($this->searchMultiRaw($query, $missing) as $page => $items) {
            if ($items !== []) {
                $this->cache->set('s2|' . mb_strtolower(trim($query)) . "|{$page}", $items, self::TTL_CATALOG);
                $this->cache->set('s2stale|' . mb_strtolower(trim($query)) . "|{$page}", $items, self::TTL_STALE_SEARCH);
                array_push($merged, ...$items);
            } elseif ($page === 0) {
                // single-page path carries the whole retry ladder
                array_push($merged, ...$this->search($query, 0));
            }
        }
        return $merged;
    }

    public function release(int $releaseId): ?array
    {
        try {
            $data = $this->get("/release/{$releaseId}", self::TTL_RELEASE);
        } catch (AnixartException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
        return is_array($data['release'] ?? null) ? $data['release'] : null;
    }

    /** @return array<int, array{id: int, name: string, is_sub: bool}> */
    public function episodeTypes(int $releaseId): array
    {
        try {
            $data = $this->get("/episode/{$releaseId}", self::TTL_SOURCES);
        } catch (AnixartException $e) {
            if ($e->status === 404) {
                return [];
            }
            throw $e;
        }
        return is_array($data['types'] ?? null) ? $data['types'] : [];
    }

    /** @return array<int, array{id: int, name: string}> */
    public function episodeSources(int $releaseId, int $typeId): array
    {
        try {
            $data = $this->get("/episode/{$releaseId}/{$typeId}", self::TTL_SOURCES);
        } catch (AnixartException $e) {
            if ($e->status === 404) {
                return [];
            }
            throw $e;
        }
        return is_array($data['sources'] ?? null) ? $data['sources'] : [];
    }

    /** @return array<int, array{position: int, name: ?string, url: string, iframe: bool}> */
    public function episodes(int $releaseId, int $typeId, int $sourceId): array
    {
        try {
            $data = $this->get("/episode/{$releaseId}/{$typeId}/{$sourceId}", self::TTL_SOURCES);
        } catch (AnixartException $e) {
            if ($e->status === 404) {
                return [];
            }
            throw $e;
        }
        return is_array($data['episodes'] ?? null) ? $data['episodes'] : [];
    }

    public function healthProbe(): array
    {
        $catalog = -1;
        $search = -1;
        try {
            $data = json_decode($this->httpJson('GET', $this->hosts[$this->activeHost] . '/filter/0', []), true);
            $catalog = count(self::listContent($data));
        } catch (\Throwable) {
        }
        try {
            $data = json_decode($this->httpJson('POST', $this->hosts[$this->activeHost] . '/search/releases/0',
                ['query' => 'anime', 'searchBy' => 0]), true);
            $search = count(self::listContent($data));
        } catch (\Throwable) {
        }
        return [
            'catalog' => $catalog > 0 ? "ok, {$catalog} releases" : 'down',
            'search' => $search > 0
                ? "alive, {$search} results"
                : ($search === 0 ? 'empty phase (backend answers with nothing)' : 'down'),
        ];
    }

    private function request(string $method, string $path, array $body = []): mixed
    {
        $lastError = null;
        $count = count($this->hosts);
        for ($i = 0; $i < $count; $i++) {
            $index = ($this->activeHost + $i) % $count;
            $url = $this->hosts[$index] . $path;
            try {
                $bodyText = $this->httpJson($method, $url, $body);
                $data = json_decode($bodyText, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    throw new AnixartException("{$path} → unexpected response");
                }
                if ($index !== $this->activeHost) {
                    $this->activeHost = $index;
                    $this->cache->set('__active_host', $index, self::TTL_ACTIVE_HOST);
                }
                return $data;
            } catch (AnixartException $e) {
                // 4xx means our request is wrong, mirrors won't help
                if ($e->status >= 400 && $e->status < 500) {
                    throw $e;
                }
                $lastError = $e;
            } catch (\JsonException) {
                $lastError = new AnixartException("{$path} → invalid json from mirror");
            }
        }
        throw $lastError ?? new AnixartException("{$path} → all mirrors are down");
    }

    // search/filter answers come in two shapes: legacy 'content' and v2 'releases'
    private static function listContent(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        foreach (['content', 'releases'] as $key) {
            if (is_array($data[$key] ?? null) && $data[$key] !== []) {
                return $data[$key];
            }
        }
        return [];
    }

    // last resort: hit every mirror at once, first non-empty answer wins
    private function searchAnyMirror(array $body): ?array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($this->hosts as $host) {
            $ch = curl_init($host . '/search/releases/0');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'API-Version: v2'],
                CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
                CURLOPT_CONNECTTIMEOUT_MS => 8000,
                CURLOPT_USERAGENT => 'Anixart/9.0 (Android)',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $stillRunning);
            if ($stillRunning) {
                curl_multi_select($mh, 1.0);
            }
        } while ($stillRunning && $status === CURLM_OK);

        $result = null;
        foreach ($handles as $ch) {
            $bodyText = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($result !== null || !is_string($bodyText)) {
                continue;
            }
            $data = json_decode($bodyText, true);
            if (self::listContent($data) !== []) {
                $result = $data;
            }
        }
        curl_multi_close($mh);
        return $result;
    }

    /** @return array<int, array> items per requested page */
    private function searchMultiRaw(string $query, array $pages): array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($pages as $page) {
            $ch = curl_init($this->hosts[$this->activeHost] . "/search/releases/{$page}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'searchBy' => 0], JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'API-Version: v2'],
                CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
                CURLOPT_CONNECTTIMEOUT_MS => 8000,
                CURLOPT_USERAGENT => 'Anixart/9.0 (Android)',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$page] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $stillRunning);
            if ($stillRunning) {
                curl_multi_select($mh, 1.0);
            }
        } while ($stillRunning && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $page => $ch) {
            $body = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $out[$page] = self::listContent(json_decode((string) $body, true));
        }
        curl_multi_close($mh);
        return $out;
    }

    private function httpJson(string $method, string $url, array $body): string
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json', 'API-Version: v2'];
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 8000,
            CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Anixart/9.0 (Android)',
        ]);
        $text = curl_exec($ch);
        if ($text === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new AnixartException($error !== '' ? $error : 'network error');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            throw new AnixartException("HTTP {$status}", $status);
        }
        return (string) $text;
    }
}
