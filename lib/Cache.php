<?php

declare(strict_types=1);

namespace AnixartAddon;

// file cache with TTL, values live as json blobs in the temp dir
final class Cache
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'anixart-stremio-cache');
    }

    public function get(string $key): mixed
    {
        $raw = @file_get_contents($this->path($key));
        if ($raw === false) {
            return null;
        }
        $entry = json_decode($raw, true);
        if (!is_array($entry) || !isset($entry['expires_at'], $entry['value'])) {
            return null;
        }
        if ($entry['expires_at'] <= time()) {
            @unlink($this->path($key));
            return null;
        }
        return $entry['value'];
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0777, true) && !is_dir($this->dir)) {
            return;
        }
        $entry = ['expires_at' => time() + $ttlSeconds, 'value' => $value];
        @file_put_contents($this->path($key), json_encode($entry, JSON_UNESCAPED_UNICODE), LOCK_EX);

        if (random_int(1, 100) === 1) {
            $this->gc();
        }
    }

    // no cross-request dedup needed: every http call is its own php process here
    public function wrap(string $key, int $ttlSeconds, callable $producer): mixed
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $producer();
        if ($value !== null) {
            $this->set($key, $value, $ttlSeconds);
        }
        return $value;
    }

    private function path(string $key): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . md5($key) . '.json';
    }

    // expires_at sits at the start of the json, a partial read is enough.
    // careful: large entries are truncated here, so never require valid json —
    // only the expiry field decides, garbage headers are the only unlink reason
    private function gc(): void
    {
        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        shuffle($files);
        $checked = 0;
        $now = time();
        foreach ($files as $file) {
            if ($checked >= 200) {
                break;
            }
            $checked++;
            $raw = @file_get_contents($file, false, null, 0, 256);
            if ($raw === false || !preg_match('/"expires_at":(\d+)/', $raw, $m)) {
                @unlink($file);
                continue;
            }
            if ((int) $m[1] <= $now) {
                @unlink($file);
            }
        }
    }
}
