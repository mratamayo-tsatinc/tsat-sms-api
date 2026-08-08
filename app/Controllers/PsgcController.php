<?php

namespace App\Controllers;

// ─────────────────────────────────────────────────────────────────────────
// PsgcController
// Serves PSGC (Philippine Standard Geographic Code) province, city, and
// barangay data from the psgc.cloud API with a 6-hour file cache.
// Reusable by any module with an address field.
// ─────────────────────────────────────────────────────────────────────────
class PsgcController
{
    private const BASE = 'https://psgc.cloud/api/v2';
    private const TTL_SECONDS = 21600;
    private const CACHE_DIR = '/tmp/psgc_cache';

    private function _cacheGet(string $key)
    {
        $file = self::CACHE_DIR . '/' . md5($key) . '.json';
        if (!is_file($file)) return null;
        if (time() - filemtime($file) > self::TTL_SECONDS) return null;
        $data = json_decode((string)file_get_contents($file), true);
        return $data ?: null;
    }

    private function _cacheSet(string $key, $data): void
    {
        if (!is_dir(self::CACHE_DIR)) mkdir(self::CACHE_DIR, 0775, true);
        file_put_contents(self::CACHE_DIR . '/' . md5($key) . '.json', json_encode($data));
    }

    private function _fetchJson(string $url)
    {
        $ctx = stream_context_create(['http' => ['timeout' => 10]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) throw new \Exception('PSGC request failed: ' . $url);
        return json_decode($body, true);
    }

    // GET /api/geo/provinces
    public function provinces()
    {
        $cached = $this->_cacheGet('provinces');
        if ($cached) { echo json_encode($cached); return; }

        try {
            $data = $this->_fetchJson(self::BASE . '/provinces/');
        } catch (\Exception $e) {
            http_response_code(502);
            echo json_encode(['error' => 'PSGC province fetch failed: ' . $e->getMessage()]);
            return;
        }

        $out = array_map(fn($p) => [
            'name' => $p['name'], 'region' => $p['region'] ?? '', 'code' => $p['code'] ?? ''
        ], $data['data'] ?? []);
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
        $this->_cacheSet('provinces', $out);
        echo json_encode($out);
    }

    // GET /api/geo/cities?province=<name>
    public function cities()
    {
        $province = trim((string)($_GET['province'] ?? ''));
        if ($province === '') {
            http_response_code(400);
            echo json_encode(['error' => 'province is required.']);
            return;
        }

        $key = 'cities_' . strtolower($province);
        $cached = $this->_cacheGet($key);
        if ($cached) { echo json_encode($cached); return; }

        try {
            $data = $this->_fetchJson(self::BASE . '/provinces/' . rawurlencode($province) . '/cities-municipalities');
        } catch (\Exception $e) {
            http_response_code(502);
            echo json_encode(['error' => 'PSGC city fetch failed for: ' . $province . ' — ' . $e->getMessage()]);
            return;
        }

        $out = array_map(fn($c) => [
            'name' => $c['name'], 'type' => $c['type'] ?? '', 'district' => $c['district'] ?? '',
            'zip_code' => $c['zip_code'] ?? '', 'code' => $c['code'] ?? ''
        ], $data['data'] ?? []);
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
        $this->_cacheSet($key, $out);
        echo json_encode($out);
    }

    // GET /api/geo/barangays?city=<name>
    public function barangays()
    {
        $city = trim((string)($_GET['city'] ?? ''));
        if ($city === '') {
            http_response_code(400);
            echo json_encode(['error' => 'city is required.']);
            return;
        }

        $key = 'barangays_' . strtolower($city);
        $cached = $this->_cacheGet($key);
        if ($cached) { echo json_encode($cached); return; }

        try {
            $data = $this->_fetchJson(self::BASE . '/cities-municipalities/' . rawurlencode($city) . '/barangays');
        } catch (\Exception $e) {
            http_response_code(502);
            echo json_encode(['error' => 'PSGC barangay fetch failed for: ' . $city . ' — ' . $e->getMessage()]);
            return;
        }

        $out = array_map(fn($b) => [
            'name' => $b['name'], 'district' => $b['district'] ?? '', 'zip_code' => $b['zip_code'] ?? ''
        ], $data['data'] ?? []);
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
        $this->_cacheSet($key, $out);
        echo json_encode($out);
    }
}
