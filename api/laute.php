<?php
require_once __DIR__ . '/config.php';

/**
 * Minimal YAML loader for data/laute.yaml.
 * Returns: [ ['slug'=>..., 'label'=>..., 'group'=>..., 'neighbors'=>[...] ], ... ]
 */
function getLaute(): array {
    $file = __DIR__ . '/../data/laute.yaml';
    if (!file_exists($file)) return [];
    $lines = explode("\n", file_get_contents($file));
    $items = [];
    $current = null;
    foreach ($lines as $line) {
        $rtrim = rtrim($line);
        if ($rtrim === '' || preg_match('/^\s*#/', $rtrim)) continue;
        if (preg_match('/^- slug:\s*(.+)$/', $rtrim, $m)) {
            if ($current !== null) $items[] = $current;
            $current = ['slug' => trim($m[1]), 'label' => '', 'group' => '', 'neighbors' => []];
        } elseif ($current !== null && preg_match('/^\s+label:\s*["\']?(.*?)["\']?\s*$/u', $rtrim, $m)) {
            $current['label'] = $m[1];
        } elseif ($current !== null && preg_match('/^\s+group:\s*(.+)$/', $rtrim, $m)) {
            $current['group'] = trim($m[1]);
        } elseif ($current !== null && preg_match('/^\s+neighbors:\s*\[(.*)\]\s*$/', $rtrim, $m)) {
            $parts = array_map('trim', explode(',', $m[1]));
            $current['neighbors'] = array_values(array_filter($parts, fn($p) => $p !== ''));
        }
    }
    if ($current !== null) $items[] = $current;
    return $items;
}

/**
 * Path on disk for a slug's recorded audio. Slug is validated against the catalog.
 */
function lauteAudioPath(string $slug): string {
    return __DIR__ . '/../audio/laute/' . $slug . '.mp3';
}

function lauteAudioDir(): string {
    return __DIR__ . '/../audio/laute';
}

function lauteSlugs(): array {
    return array_map(fn($l) => $l['slug'], getLaute());
}

// HTTP dispatch (only when called directly, not via require_once)
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'laute.php') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
    $action = $_GET['action'] ?? '';
    if ($action === 'list') {
        $items = getLaute();
        foreach ($items as &$it) {
            $it['has_audio'] = file_exists(lauteAudioPath($it['slug']));
        }
        unset($it);
        echo json_encode(['items' => $items]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
}
