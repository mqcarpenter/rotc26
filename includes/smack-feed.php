<?php
/**
 * includes/smack-feed.php
 * Fetches the newest topics from the bbPress "Smack Board" forum at
 * /community/ and maps them into the $smack_items shape
 * templates/sidefeed.php expects — pulled from bbPress's built-in
 * Topics RSS feed (all forums, all topics), standard WP RSS2 format.
 *
 * Server-to-server call, cached to a local file so we don't hit the
 * forum on every pageview. Falls back to a stale cache, then to no
 * items (letting sidefeed.php's own placeholder default take over)
 * if the forum is unreachable.
 *
 * Override the forum base with a ROTC_FORUM_BASE env var if it ever
 * moves; defaults to the live community board.
 */

function rotc_smack_cached_get(string $url, int $ttlSeconds): ?string {
    $cacheDir = sys_get_temp_dir() . '/rotc-wp-cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
    $cacheFile = $cacheDir . '/smack-topics-' . md5($url) . '.xml';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttlSeconds) {
        return file_get_contents($cacheFile);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_USERAGENT      => 'ROTC-SmackFeed/1.0',
    ]);
    $body = curl_exec($ch);
    $ok = $body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);

    if ($ok) {
        @file_put_contents($cacheFile, $body);
        return $body;
    }

    return file_exists($cacheFile) ? file_get_contents($cacheFile) : null;
}

function rotc_fetch_smack_items(int $count = 6): array {
    $forumBase = getenv('ROTC_FORUM_BASE') ?: 'https://www.returnofthechampions.com/community/';
    $url = rtrim($forumBase, '/') . '/?type=rss2&forum=g&topic=g';

    $body = rotc_smack_cached_get($url, 300);
    if ($body === null) return [];

    if (PHP_VERSION_ID < 80000) {
        $prevLibxmlSetting = libxml_disable_entity_loader(true);
    }
    $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if (PHP_VERSION_ID < 80000) {
        libxml_disable_entity_loader($prevLibxmlSetting);
    }

    if ($xml === false || !isset($xml->channel->item)) return [];

    $items = [];
    foreach ($xml->channel->item as $item) {
        if (count($items) >= $count) break;

        $title = trim((string) $item->title);
        $link  = trim((string) $item->link);
        if ($title === '' || $link === '') continue;

        $desc = trim(strip_tags((string) $item->description));
        if (strlen($desc) > 140) {
            $desc = rtrim(substr($desc, 0, 140)) . '…';
        }

        $items[] = [
            'tag'   => 'Forum',
            'title' => $title,
            'desc'  => $desc,
            'url'   => $link,
        ];
    }

    return $items;
}
