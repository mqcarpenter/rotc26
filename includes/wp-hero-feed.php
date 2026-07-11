<?php
/**
 * includes/wp-hero-feed.php
 * Fetches the newest WordPress posts server-side and maps them into
 * the $slides shape templates/hero-carousel.php expects — each
 * slide's featured image becomes the full-bleed background via the
 * existing .rotc-pc-img CSS (background-size:cover), same pattern
 * as the old rotc-carousel.html module.
 *
 * Server-to-server call, like the MFL ticker in api/matchup-ticker.php,
 * so there's no CORS issue. Cached to a local file so we don't hit the
 * WP REST API on every pageview. Falls back to a stale cache, then to
 * no slides at all (letting hero-carousel.php's own placeholder
 * default take over) if the WP site is unreachable.
 *
 * Override the WP site with a ROTC_WP_BASE env var if it ever moves;
 * defaults to the live editorial hub.
 */

function rotc_wp_cached_get(string $url, int $ttlSeconds): ?string {
    $cacheDir = sys_get_temp_dir() . '/rotc-wp-cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
    $cacheFile = $cacheDir . '/hero-posts-' . md5($url) . '.json';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttlSeconds) {
        return file_get_contents($cacheFile);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_USERAGENT      => 'ROTC-HeroCarousel/1.0',
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

function rotc_fetch_hero_slides(int $count = 5, string $fallbackImage = ''): array {
    $wpBase = getenv('ROTC_WP_BASE') ?: 'https://returnofthechampions.com';
    $url = rtrim($wpBase, '/') . '/wp-json/wp/v2/posts?per_page=' . $count . '&_embed=1';

    $body = rotc_wp_cached_get($url, 300);
    if ($body === null) return [];

    $posts = json_decode($body, true);
    if (!is_array($posts)) return [];

    $slides = [];
    foreach ($posts as $post) {
        $media = $post['_embedded']['wp:featuredmedia'][0] ?? null;
        $image = $fallbackImage;
        if ($media) {
            $image = $media['media_details']['sizes']['large']['source_url']
                ?? $media['media_details']['sizes']['medium_large']['source_url']
                ?? $media['source_url']
                ?? $fallbackImage;
        }

        $slides[] = [
            'date'     => date('M j, Y', strtotime($post['date'] ?? 'now')),
            'headline' => html_entity_decode(strip_tags($post['title']['rendered'] ?? ''), ENT_QUOTES),
            'excerpt'  => trim(html_entity_decode(strip_tags($post['excerpt']['rendered'] ?? ''), ENT_QUOTES)),
            'image'    => $image,
            'url'      => $post['link'] ?? '#',
        ];
    }

    return $slides;
}
