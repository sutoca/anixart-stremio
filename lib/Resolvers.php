<?php

declare(strict_types=1);

namespace AnixartAddon;

/**
 * Turns episode links from Anixart into direct player URLs.
 * Schemes lifted from the app (SibnetParser/KodikParser) and the kodik player js:
 *  - sibnet: shell.php page holds /v/{token}/N.mp4, that url 302s to the cdn
 *    (dvNN.sibnet.ru/...mp4?st=token&noip=1, no ip binding, ~1 day ttl)
 *  - rutube: /play/embed/{hash} -> public play/options api -> balancer m3u8
 *  - kodik: player page carries signed vars (d/d_sign/pd/pd_sign/ref/ref_sign),
 *    POST /ftor with type/id/hash returns links.{quality}[0].src, which is
 *    rot18 (+18 letter shift with wrap) over base64 -> hls on solodcdn (~12h)
 */
final class Resolvers
{
    private const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    private const TIMEOUT_MS = 12000;
    private const KODIK_REFERER = 'https://anixsekai.com/';

    private Cache $cache;

    public function __construct(?Cache $cache = null)
    {
        $this->cache = $cache ?? new Cache();
    }

    /** @return 'sibnet'|'rutube'|'kodik'|'direct'|'unknown' */
    public static function classify(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (str_contains($host, 'sibnet.ru')) {
            return 'sibnet';
        }
        if (str_contains($host, 'rutube.ru')) {
            return 'rutube';
        }
        if (str_contains($host, 'kodik')) {
            return 'kodik';
        }
        if (preg_match('#\.(mp4|m3u8)(\?|$)#i', $url)) {
            return 'direct';
        }
        return 'unknown';
    }

    /**
     * All direct urls for an episode. Kodik gives several qualities at once.
     * Failures get a short negative cache so dead sources don't get hammered.
     *
     * @return array<int, array{url: string, hls: bool, label: ?string}>
     */
    public function resolveAll(string $url): array
    {
        $kind = self::classify($url);
        $key = 'res|' . $kind . '|' . md5($url);
        $negKey = 'resneg|' . md5($url);

        if ($this->cache->get($negKey) !== null) {
            return [];
        }
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $resolved = match ($kind) {
            'sibnet' => $this->single($this->resolveSibnet($url), false, null),
            'rutube' => $this->single($this->resolveRutube($url), true, null),
            'kodik' => $this->resolveKodik($url),
            'direct' => $this->single(preg_match('#\.(mp4|m3u8)(\?|$)#i', $url) ? $url : null,
                (bool) preg_match('#\.m3u8(\?|$)#i', $url), null),
            default => null,
        };

        if (is_array($resolved) && $resolved !== []) {
            $this->cache->set($key, $resolved, 3600);
            return $resolved;
        }
        // remember the failure for 2 minutes
        $this->cache->set($negKey, 1, 120);
        return [];
    }

    /** Raw GET of any url, used by the poster proxy. */
    public function fetch(string $url): ?string
    {
        [$body, $status] = $this->send($url, 'GET');
        return ($body !== null && $status >= 200 && $status < 300) ? $body : null;
    }

    /** @return array<int, array{url: string, hls: bool, label: ?string}>|null */
    private function single(?string $url, bool $hls, ?string $label): ?array
    {
        return $url !== null ? [['url' => $url, 'hls' => $hls, 'label' => $label]] : null;
    }

    private function resolveSibnet(string $url): ?string
    {
        if (!preg_match('#videoid=(\d+)#i', $url, $m)) {
            return null;
        }
        $videoId = $m[1];
        $pageUrl = 'https://video.sibnet.ru/shell.php?videoid=' . $videoId;
        [$page, $status] = $this->send($pageUrl, 'GET', null, ['Referer' => 'https://video.sibnet.ru/']);
        if ($status !== 200 || !is_string($page) || !preg_match('#/v/[a-f0-9]+/' . $videoId . '\.mp4#i', $page, $file)) {
            return null;
        }
        // the /v/ link answers 302 with a signed cdn location, that's the real link
        [, $status, $location] = $this->send('https://video.sibnet.ru' . $file[0], 'GET', null,
            ['Referer' => $pageUrl], false);
        return ($status >= 300 && $status < 400 && is_string($location) && $location !== '') ? $location
            : ($status === 200 ? 'https://video.sibnet.ru' . $file[0] : null);
    }

    private function resolveRutube(string $url): ?string
    {
        if (!preg_match('#rutube\.ru/play/embed/([0-9a-f]+)#i', $url, $m)) {
            return null;
        }
        [$body, $status] = $this->send('https://rutube.ru/api/play/options/' . $m[1] . '/?format=hls', 'GET');
        if ($status !== 200 || !is_string($body)) {
            return null;
        }
        $data = json_decode($body, true);
        $m3u8 = is_array($data) ? ($data['video_balancer']['m3u8'] ?? null) : null;
        return is_string($m3u8) && $m3u8 !== '' ? $m3u8 : null;
    }

    private function resolveKodik(string $url): ?array
    {
        // https://kodikplayer.com/seria/580563/{hash}/720p -> type, id, hash
        if (!preg_match('#^https?://[^/]+/([a-z]+)/(\d+)/([0-9a-f]{16,})#i', $url, $m)) {
            return null;
        }
        $base = substr($url, 0, (int) strpos($url, '/', 8));
        $jar = tempnam(sys_get_temp_dir(), 'kdk');
        if ($jar === false) {
            return null;
        }

        try {
            [$page, $status] = $this->send($url, 'GET', null, ['Referer' => self::KODIK_REFERER], true, $jar);
            if ($status !== 200 || !is_string($page)) {
                return null;
            }
            $vars = [];
            foreach (['domain', 'd_sign', 'pd', 'pd_sign', 'ref', 'ref_sign'] as $name) {
                if (!preg_match('/var\s+' . $name . '\s*=\s*([\'"])(.*?)\1/s', $page, $vm)) {
                    return null;
                }
                $vars[$name] = $vm[2];
            }

            $payload = http_build_query(array_merge($vars, [
                'bad_user' => 'false',
                'cdn_is_working' => 'false',
                'type' => $m[1],
                'id' => $m[2],
                'hash' => $m[3],
            ]));
            [$body, $status] = $this->send($base . '/ftor', 'POST', $payload, [
                'Referer' => $url,
                'Origin' => $base,
                'X-Requested-With' => 'XMLHttpRequest',
                'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            ], true, $jar);
            if ($status !== 200 || !is_string($body)) {
                return null;
            }
            $data = json_decode($body, true);
            $links = is_array($data) ? ($data['links'] ?? null) : null;
            if (!is_array($links)) {
                return null;
            }

            $result = [];
            foreach ($links as $quality => $items) {
                $src = is_array($items) && isset($items[0]['src']) && is_string($items[0]['src'])
                    ? $items[0]['src']
                    : null;
                if ($src === null || $src === '') {
                    continue;
                }
                $decoded = str_starts_with($src, '//') ? 'https:' . $src : self::rot18Base64($src);
                if ($decoded === null || $decoded === '') {
                    continue;
                }
                $result[] = [
                    'url' => $decoded,
                    'hls' => true,
                    'label' => $quality . 'p',
                ];
            }
            usort($result, static fn (array $a, array $b): int =>
                (int) filter_var($b['label'], FILTER_SANITIZE_NUMBER_INT)
                <=> (int) filter_var($a['label'], FILTER_SANITIZE_NUMBER_INT));
            return $result ?: null;
        } finally {
            @unlink($jar);
        }
    }

    // kodik obfuscation: shift letters +18 with wrap, then base64
    public static function rot18Base64(string $s): ?string
    {
        $out = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($c >= 'A' && $c <= 'Z') {
                $o = ord($c) + 18;
                $out .= chr($o <= 90 ? $o : $o - 26);
            } elseif ($c >= 'a' && $c <= 'z') {
                $o = ord($c) + 18;
                $out .= chr($o <= 122 ? $o : $o - 26);
            } else {
                $out .= $c;
            }
        }
        $decoded = base64_decode($out, true);
        return $decoded === false ? null : $decoded;
    }

    private function send(string $url, string $method = 'GET', ?string $payload = null,
        array $headers = [], bool $follow = true, ?string $cookieJar = null): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 8000,
            CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
            CURLOPT_USERAGENT => self::BROWSER_UA,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $name, string $value): string => "{$name}: {$value}",
                array_keys($headers),
                $headers,
            ),
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $payload ?? '';
        }
        if ($cookieJar !== null) {
            $opts[CURLOPT_COOKIEJAR] = $cookieJar;
            $opts[CURLOPT_COOKIEFILE] = $cookieJar;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
        curl_close($ch);
        return [is_string($body) ? $body : null, $status, $redirect];
    }
}
