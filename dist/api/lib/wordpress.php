<?php
declare(strict_types=1);

function mysite_wp_strip_text(string $value): string
{
    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $stripped = strip_tags($decoded);
    $compact = preg_replace('/\s+/', ' ', $stripped) ?? '';
    return trim($compact);
}

function mysite_wp_contains_any(string $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        if ($needle === '') {
            continue;
        }

        if (strpos($haystack, strtolower($needle)) !== false) {
            return true;
        }
    }

    return false;
}

function mysite_wp_normalize_url(string $url): string
{
    $trimmed = trim($url);
    if ($trimmed === '') {
        return '';
    }

    $parts = parse_url($trimmed);
    if (!is_array($parts)) {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '') {
        return '';
    }

    $path = (string) ($parts['path'] ?? '/');
    if ($path === '') {
        $path = '/';
    }

    $normalized = $scheme . '://' . $host . rtrim($path, '/');
    if ($normalized === $scheme . '://' . $host) {
        $normalized .= '/';
    }

    return $normalized;
}

function mysite_wp_extract_post_image(array $post): string
{
    $embeddedMedia = $post['_embedded']['wp:featuredmedia'][0] ?? null;
    if (is_array($embeddedMedia)) {
        $sourceUrl = trim((string) ($embeddedMedia['source_url'] ?? ''));
        if ($sourceUrl !== '') {
            return $sourceUrl;
        }

        $sizes = $embeddedMedia['media_details']['sizes'] ?? null;
        if (is_array($sizes)) {
            foreach (['large', 'medium_large', 'medium'] as $sizeKey) {
                $candidate = trim((string) (($sizes[$sizeKey]['source_url'] ?? '') ?: ''));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }
    }

    $yoastOgImage = $post['yoast_head_json']['og_image'][0]['url'] ?? null;
    if (is_string($yoastOgImage) && trim($yoastOgImage) !== '') {
        return trim($yoastOgImage);
    }

    $contentRendered = (string) (($post['content']['rendered'] ?? '') ?: '');
    if ($contentRendered !== '' && preg_match('/<img[^>]+src=[\"\']([^\"\']+)[\"\']/i', $contentRendered, $matches) === 1) {
        $candidate = trim((string) ($matches[1] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function mysite_wp_estimated_read_minutes(string $contentHtml): int
{
    $plain = mysite_wp_strip_text($contentHtml);
    if ($plain === '') {
        return 1;
    }

    $wordCount = str_word_count($plain);
    if ($wordCount <= 0) {
        return 1;
    }

    return max(1, (int) ceil($wordCount / 220));
}

function mysite_wp_sanitize_html(string $html): string
{
    $clean = $html;

    // Remove script/style tags and their contents.
    $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $clean) ?? '';
    $clean = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $clean) ?? '';

    // Remove inline event handlers such as onclick.
    $clean = preg_replace('/\son[a-z]+\s*=\s*("|\')(.*?)\1/i', '', $clean) ?? $clean;

    // Prevent javascript: URIs in href/src.
    $clean = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '$1="#"', $clean) ?? $clean;

    return $clean;
}

function mysite_wp_extract_terms(array $post): array
{
    $categories = [];
    $tags = [];

    $terms = $post['_embedded']['wp:term'] ?? null;
    if (!is_array($terms)) {
        return [
            'categories' => $categories,
            'tags' => $tags
        ];
    }

    foreach ($terms as $taxonomyTerms) {
        if (!is_array($taxonomyTerms)) {
            continue;
        }

        foreach ($taxonomyTerms as $term) {
            if (!is_array($term)) {
                continue;
            }

            $taxonomy = (string) ($term['taxonomy'] ?? '');
            $name = mysite_wp_strip_text((string) ($term['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            if ($taxonomy === 'category') {
                $categories[] = $name;
            } elseif ($taxonomy === 'post_tag') {
                $tags[] = $name;
            }
        }
    }

    return [
        'categories' => array_values(array_unique($categories)),
        'tags' => array_values(array_unique($tags))
    ];
}

function mysite_wp_fetch_json(string $url, int $timeoutSeconds): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);

    $result = curl_exec($curl);
    if ($result === false) {
        $error = curl_error($curl);
        curl_close($curl);
        return ['ok' => false, 'error' => $error, 'status' => 0, 'data' => []];
    }

    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $decoded = json_decode((string) $result, true);
    if ($status >= 400) {
        return ['ok' => false, 'error' => 'HTTP ' . $status, 'status' => $status, 'data' => []];
    }

    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid JSON response', 'status' => $status, 'data' => []];
    }

    return ['ok' => true, 'error' => '', 'status' => $status, 'data' => $decoded];
}

function mysite_wp_fetch_post_detail(array $config, int $postId): array
{
    if ($postId <= 0) {
        return [
            'available' => false,
            'post' => null,
            'related' => [],
            'errors' => ['Invalid post id']
        ];
    }

    $snapshot = mysite_wp_fetch_snapshot($config);
    $baseUrl = (string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com');
    $timeout = (int) (($config['wordpress']['timeout_seconds'] ?? 12));
    if ($timeout < 3) {
        $timeout = 3;
    }
    if ($timeout > 40) {
        $timeout = 40;
    }

    $postRes = mysite_wp_fetch_json(
        $baseUrl . '/wp-json/wp/v2/posts/' . $postId . '?_embed=wp:featuredmedia,author,wp:term',
        $timeout
    );

    if (!$postRes['ok'] || !is_array($postRes['data'] ?? null)) {
        return [
            'available' => false,
            'post' => null,
            'related' => [],
            'errors' => ['Unable to load post detail']
        ];
    }

    $post = $postRes['data'];
    $title = mysite_wp_strip_text((string) (($post['title']['rendered'] ?? '') ?: 'Untitled'));
    $excerpt = mysite_wp_strip_text((string) (($post['excerpt']['rendered'] ?? '') ?: ''));
    $contentHtml = mysite_wp_sanitize_html((string) (($post['content']['rendered'] ?? '') ?: ''));
    $authorName = mysite_wp_strip_text((string) (($post['_embedded']['author'][0]['name'] ?? '') ?: ''));
    $terms = mysite_wp_extract_terms($post);

    $related = [];
    foreach (array_slice($snapshot['posts'] ?? [], 0, 8) as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }

        $candidateId = (int) ($candidate['id'] ?? 0);
        if ($candidateId <= 0 || $candidateId === $postId) {
            continue;
        }

        $related[] = [
            'id' => $candidateId,
            'title' => (string) ($candidate['title'] ?? 'Untitled'),
            'excerpt' => (string) ($candidate['excerpt'] ?? ''),
            'href' => '/story/' . (string) $candidateId,
            'imageUrl' => (string) (($candidate['imageUrl'] ?? '') ?: ''),
            'date' => (string) ($candidate['date'] ?? '')
        ];

        if (count($related) >= 4) {
            break;
        }
    }

    return [
        'available' => true,
        'post' => [
            'id' => (int) ($post['id'] ?? $postId),
            'title' => $title,
            'excerpt' => $excerpt,
            'contentHtml' => $contentHtml,
            'canonicalUrl' => (string) ($post['link'] ?? ''),
            'date' => (string) ($post['date'] ?? ''),
            'modified' => (string) ($post['modified'] ?? ''),
            'imageUrl' => mysite_wp_extract_post_image($post),
            'author' => $authorName,
            'categories' => $terms['categories'],
            'tags' => $terms['tags'],
            'readMinutes' => mysite_wp_estimated_read_minutes($contentHtml)
        ],
        'related' => $related,
        'errors' => []
    ];
}

function mysite_wp_fetch_snapshot(array $config): array
{
    $wp = is_array($config['wordpress'] ?? null) ? $config['wordpress'] : [];
    $enabled = (bool) ($wp['enabled'] ?? true);

    $baseUrl = trim((string) ($wp['base_url'] ?? 'https://alexanderjgill.com'));
    $baseUrl = rtrim($baseUrl, '/');

    if (!$enabled || $baseUrl === '') {
        return [
            'available' => false,
            'baseUrl' => $baseUrl,
            'site' => [
                'name' => 'Alexander Gill',
                'description' => 'Power plays.',
                'home' => $baseUrl
            ],
            'fetchedAt' => gmdate('c'),
            'posts' => [],
            'categories' => [],
            'tags' => [],
            'errors' => ['WordPress integration disabled']
        ];
    }

    $timeout = (int) ($wp['timeout_seconds'] ?? 12);
    if ($timeout < 3) {
        $timeout = 3;
    }
    if ($timeout > 40) {
        $timeout = 40;
    }

    $maxPosts = max(1, min(20, (int) ($wp['max_posts'] ?? 6)));
    $maxCategories = max(1, min(50, (int) ($wp['max_categories'] ?? 12)));
    $maxTags = max(1, min(50, (int) ($wp['max_tags'] ?? 12)));

    $apiBase = $baseUrl . '/wp-json/wp/v2';

    $postsRes = mysite_wp_fetch_json(
        $apiBase . '/posts?per_page=' . $maxPosts . '&_embed=wp:featuredmedia',
        $timeout
    );

    $categoriesRes = mysite_wp_fetch_json(
        $apiBase . '/categories?per_page=' . $maxCategories . '&_fields=id,name,count',
        $timeout
    );

    $tagsRes = mysite_wp_fetch_json(
        $apiBase . '/tags?per_page=' . $maxTags . '&_fields=id,name,count',
        $timeout
    );
    $siteRes = mysite_wp_fetch_json(
        $baseUrl . '/wp-json',
        $timeout
    );

    $errors = [];
    foreach ([$postsRes, $categoriesRes, $tagsRes] as $res) {
        if (!$res['ok']) {
            $errors[] = (string) $res['error'];
        }
    }

    $siteInfo = [
        'name' => 'Alexander Gill',
        'description' => 'Power plays.',
        'home' => $baseUrl
    ];
    if (is_array($siteRes['data'] ?? null)) {
        $siteData = $siteRes['data'];
        $siteName = trim(mysite_wp_strip_text((string) ($siteData['name'] ?? '')));
        $siteDescription = trim(mysite_wp_strip_text((string) ($siteData['description'] ?? '')));
        $siteHome = trim((string) ($siteData['home'] ?? $baseUrl));

        if ($siteName !== '') {
            $siteInfo['name'] = $siteName;
        }

        if ($siteDescription !== '') {
            $siteInfo['description'] = $siteDescription;
        }

        if ($siteHome !== '') {
            $siteInfo['home'] = $siteHome;
        }
    }

    $posts = [];
    foreach (($postsRes['data'] ?? []) as $post) {
        if (!is_array($post)) {
            continue;
        }

        $title = mysite_wp_strip_text((string) (($post['title']['rendered'] ?? '') ?: 'Untitled'));
        $excerpt = mysite_wp_strip_text((string) (($post['excerpt']['rendered'] ?? '') ?: ''));

        $posts[] = [
            'id' => (int) ($post['id'] ?? 0),
            'title' => $title,
            'excerpt' => $excerpt,
            'link' => (string) ($post['link'] ?? ''),
            'date' => (string) ($post['date'] ?? ''),
            'modified' => (string) ($post['modified'] ?? ''),
            'imageUrl' => mysite_wp_extract_post_image($post)
        ];
    }

    $categories = [];
    foreach (($categoriesRes['data'] ?? []) as $category) {
        if (!is_array($category)) {
            continue;
        }

        $categories[] = [
            'id' => (int) ($category['id'] ?? 0),
            'name' => mysite_wp_strip_text((string) ($category['name'] ?? '')),
            'count' => (int) ($category['count'] ?? 0)
        ];
    }

    $tags = [];
    foreach (($tagsRes['data'] ?? []) as $tag) {
        if (!is_array($tag)) {
            continue;
        }

        $tags[] = [
            'id' => (int) ($tag['id'] ?? 0),
            'name' => mysite_wp_strip_text((string) ($tag['name'] ?? '')),
            'count' => (int) ($tag['count'] ?? 0)
        ];
    }

    return [
        'available' => count($posts) > 0,
        'baseUrl' => $baseUrl,
        'site' => $siteInfo,
        'fetchedAt' => gmdate('c'),
        'posts' => $posts,
        'categories' => $categories,
        'tags' => $tags,
        'errors' => $errors
    ];
}

function mysite_wp_gap_suggestions(array $snapshot): array
{
    $searchCorpus = [];

    foreach (($snapshot['posts'] ?? []) as $post) {
        $searchCorpus[] = strtolower((string) ($post['title'] ?? ''));
        $searchCorpus[] = strtolower((string) ($post['excerpt'] ?? ''));
    }

    foreach (($snapshot['categories'] ?? []) as $category) {
        $searchCorpus[] = strtolower((string) ($category['name'] ?? ''));
    }

    foreach (($snapshot['tags'] ?? []) as $tag) {
        $searchCorpus[] = strtolower((string) ($tag['name'] ?? ''));
    }

    $haystack = implode(' ', $searchCorpus);

    $rules = [
        ['topic' => 'Services', 'match' => ['service', 'offer', 'consult'], 'priority' => 'high'],
        ['topic' => 'Case Studies', 'match' => ['case study', 'project', 'result'], 'priority' => 'high'],
        ['topic' => 'Testimonials', 'match' => ['testimonial', 'review', 'client feedback'], 'priority' => 'high'],
        ['topic' => 'About / Bio', 'match' => ['bio', 'about', 'profile'], 'priority' => 'medium'],
        ['topic' => 'Contact CTA', 'match' => ['contact', 'book call', 'get in touch'], 'priority' => 'high'],
        ['topic' => 'FAQ', 'match' => ['faq', 'questions'], 'priority' => 'medium'],
        ['topic' => 'Editorial Hub', 'match' => ['read', 'insight', 'blog', 'article'], 'priority' => 'medium']
    ];

    $missing = [];
    foreach ($rules as $rule) {
        $found = false;
        foreach ($rule['match'] as $needle) {
            if (strpos($haystack, $needle) !== false) {
                $found = true;
                break;
            }
        }

        if ($found) {
            continue;
        }

        $missing[] = [
            'topic' => $rule['topic'],
            'priority' => $rule['priority'],
            'reason' => 'No strong signal found in existing titles, taxonomy, or excerpts.',
            'suggestedAction' => 'Create a focused section or post for this topic in WordPress.'
        ];
    }

    return $missing;
}

function mysite_wp_conversion_profile(array $intent, array $snapshot): array
{
    $siteInfo = is_array($snapshot['site'] ?? null) ? $snapshot['site'] : [];
    $brandName = trim((string) ($siteInfo['name'] ?? ''));
    if ($brandName === '') {
        $brandName = 'Alexander Gill';
    }
    $siteRootUrl = (string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com');
    $posts = is_array($snapshot['posts'] ?? null) ? $snapshot['posts'] : [];

    $latestPostUrl = $siteRootUrl;
    $secondPostUrl = $siteRootUrl;
    $thirdPostUrl = $siteRootUrl;

    if (isset($posts[0]) && is_array($posts[0])) {
        $latestPostUrl = (string) (($posts[0]['link'] ?? '') ?: $siteRootUrl);
    }
    if (isset($posts[1]) && is_array($posts[1])) {
        $secondPostUrl = (string) (($posts[1]['link'] ?? '') ?: $latestPostUrl);
    } else {
        $secondPostUrl = $latestPostUrl;
    }
    if (isset($posts[2]) && is_array($posts[2])) {
        $thirdPostUrl = (string) (($posts[2]['link'] ?? '') ?: $secondPostUrl);
    } else {
        $thirdPostUrl = $secondPostUrl;
    }

    $goalText = strtolower((string) ($intent['goal'] ?? ''));
    $topics = $intent['primaryTopics'] ?? [];
    $topicText = strtolower(implode(' ', is_array($topics) ? $topics : []));

    $searchText = trim($goalText . ' ' . $topicText);

    $intentType = 'site_discovery';

    if (mysite_wp_contains_any($searchText, ['portfolio', 'work', 'project', 'case study'])) {
        $intentType = 'portfolio_review';
    }

    if (mysite_wp_contains_any($searchText, ['read', 'blog', 'article', 'learn'])) {
        $intentType = 'content_learning';
    }

    if (mysite_wp_contains_any($searchText, ['bio', 'about', 'profile'])) {
        $intentType = 'bio_profile';
    }

    if (mysite_wp_contains_any($searchText, ['contact', 'call', 'reach', 'email'])) {
        $intentType = 'contact_start';
    }

    $primaryGoal = 'site_discovery';
    if ($searchText === '') {
        $intentType = $primaryGoal;
    }

    $profiles = [
        'site_discovery' => [
            'intentType' => 'site_discovery',
            'heroTitle' => 'Explore the latest from ' . $brandName,
            'heroSubtitle' => 'A personalized front-end view generated from live WordPress content.',
            'heroCtaLabel' => 'Read Latest Post',
            'heroCtaUrl' => $latestPostUrl,
            'primaryActionLabel' => 'Read Latest Post',
            'primaryActionUrl' => $latestPostUrl,
            'secondaryActionLabel' => 'Explore Main Site',
            'secondaryActionUrl' => $siteRootUrl
        ],
        'portfolio_review' => [
            'intentType' => 'portfolio_review',
            'heroTitle' => 'Featured stories from ' . $brandName,
            'heroSubtitle' => 'Proof-focused visitors can start with highlighted posts and continue through related stories.',
            'heroCtaLabel' => 'Read Featured Story',
            'heroCtaUrl' => $latestPostUrl,
            'primaryActionLabel' => 'Read Featured Story',
            'primaryActionUrl' => $latestPostUrl,
            'secondaryActionLabel' => 'Read Next Story',
            'secondaryActionUrl' => $secondPostUrl
        ],
        'content_learning' => [
            'intentType' => 'content_learning',
            'heroTitle' => 'Explore practical guidance from ' . $brandName,
            'heroSubtitle' => 'Learning-focused visitors can read in sequence with a curated editorial path.',
            'heroCtaLabel' => 'Read Latest Insights',
            'heroCtaUrl' => $latestPostUrl,
            'primaryActionLabel' => 'Read Latest Insights',
            'primaryActionUrl' => $latestPostUrl,
            'secondaryActionLabel' => 'Read Another Insight',
            'secondaryActionUrl' => $secondPostUrl
        ],
        'bio_profile' => [
            'intentType' => 'bio_profile',
            'heroTitle' => 'Get to know ' . $brandName,
            'heroSubtitle' => 'Narrative-first visitors can start with a key story and then browse the broader archive.',
            'heroCtaLabel' => 'Read Intro Story',
            'heroCtaUrl' => $latestPostUrl,
            'primaryActionLabel' => 'Read Intro Story',
            'primaryActionUrl' => $latestPostUrl,
            'secondaryActionLabel' => 'Read Follow-up Story',
            'secondaryActionUrl' => $secondPostUrl
        ],
        'contact_start' => [
            'intentType' => 'contact_start',
            'heroTitle' => 'Start with context from ' . $brandName,
            'heroSubtitle' => 'This path prioritizes key reading context before direct outreach.',
            'heroCtaLabel' => 'Read Context Post',
            'heroCtaUrl' => $latestPostUrl,
            'primaryActionLabel' => 'Read Context Post',
            'primaryActionUrl' => $latestPostUrl,
            'secondaryActionLabel' => 'Read More Context',
            'secondaryActionUrl' => $thirdPostUrl
        ]
    ];

    $fallbackKey = array_key_exists($primaryGoal, $profiles) ? $primaryGoal : 'site_discovery';
    return $profiles[$intentType] ?? $profiles[$fallbackKey];
}

function mysite_wp_pick_priority_posts(array $snapshot): array
{
    $posts = $snapshot['posts'] ?? [];
    $selected = [];
    foreach (array_slice($posts, 0, 6) as $post) {
        $link = (string) ($post['link'] ?? '');
        if ($link === '') {
            continue;
        }

        $title = trim((string) ($post['title'] ?? 'Latest post'));
        if ($title === '') {
            $title = 'Latest post';
        }

        $label = strlen($title) > 42 ? substr($title, 0, 39) . '...' : $title;
        $selected[$link] = [
            'label' => $label,
            'action' => $link
        ];
    }

    return array_values($selected);
}

function mysite_wp_content_bundle(array $snapshot, array $gapSuggestions, array $intent): array
{
    $posts = $snapshot['posts'] ?? [];
    $conversionProfile = mysite_wp_conversion_profile($intent, $snapshot);
    $siteInfo = is_array($snapshot['site'] ?? null) ? $snapshot['site'] : [];
    $brandName = trim((string) ($siteInfo['name'] ?? ''));
    if ($brandName === '') {
        $brandName = 'Alexander Gill';
    }
    $brandTagline = trim((string) ($siteInfo['description'] ?? 'Power plays.'));
    if ($brandTagline === '') {
        $brandTagline = 'Power plays.';
    }

    $heroTitle = (string) ($conversionProfile['heroTitle'] ?? 'Explore tailored content from alexanderjgill.com');
    $heroSubtitle = (string) ($conversionProfile['heroSubtitle'] ?? 'Personalized from your intent and live WordPress content.');
    $heroCtaLabel = (string) ($conversionProfile['heroCtaLabel'] ?? 'Visit Source Site');
    $heroCtaUrl = (string) ($conversionProfile['heroCtaUrl'] ?? ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com'));
    $heroImageUrl = '';

    if (count($posts) > 0 && (string) ($posts[0]['title'] ?? '') !== '') {
        $heroTitle = $heroTitle . ' · ' . (string) $posts[0]['title'];
        $heroImageUrl = trim((string) (($posts[0]['imageUrl'] ?? '') ?: ''));
    }

    $heroSubtitle = $brandTagline . ' ' . $heroSubtitle;

    $gridItems = [];
    foreach (array_slice($posts, 0, 6) as $post) {
        $postId = (int) ($post['id'] ?? 0);
        $dateRaw = (string) ($post['date'] ?? '');
        $dateLabel = '';
        if ($dateRaw !== '') {
            $timestamp = strtotime($dateRaw);
            if ($timestamp !== false) {
                $dateLabel = gmdate('M j, Y', $timestamp);
            }
        }

        $gridItems[] = [
            'id' => $postId,
            'title' => (string) ($post['title'] ?? 'Untitled'),
            'description' => (string) (($post['excerpt'] ?? '') !== '' ? $post['excerpt'] : 'No excerpt available.'),
            'href' => $postId > 0 ? '/story/' . (string) $postId : (string) ($post['link'] ?? ''),
            'canonicalUrl' => (string) ($post['link'] ?? ''),
            'imageUrl' => (string) (($post['imageUrl'] ?? '') ?: ''),
            'meta' => $dateLabel !== '' ? $dateLabel : 'Latest post'
        ];
    }

    if (count($gridItems) === 0) {
        $gridItems[] = [
            'title' => 'No recent posts discovered',
            'description' => 'Publish or expose recent posts in WP REST to enrich this personalized experience.',
            'href' => (string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com')
        ];
    }

    $listItems = [];
    foreach (array_slice($posts, 0, 6) as $post) {
        $postId = (int) ($post['id'] ?? 0);
        $title = (string) ($post['title'] ?? 'Untitled');
        $excerpt = (string) ($post['excerpt'] ?? '');
        $dateRaw = (string) ($post['date'] ?? '');
        $dateLabel = '';
        if ($dateRaw !== '') {
            $timestamp = strtotime($dateRaw);
            if ($timestamp !== false) {
                $dateLabel = gmdate('M j, Y', $timestamp);
            }
        }

        $listItems[] = [
            'id' => $postId,
            'title' => $title,
            'detail' => $excerpt !== '' ? $excerpt : ('Published ' . ($dateLabel !== '' ? $dateLabel : 'recently')),
            'href' => $postId > 0 ? '/story/' . (string) $postId : (string) ($post['link'] ?? ''),
            'canonicalUrl' => (string) ($post['link'] ?? ''),
            'imageUrl' => (string) (($post['imageUrl'] ?? '') ?: '')
        ];
    }

    if (count($listItems) === 0) {
        $listItems[] = [
            'title' => 'No recent posts discovered',
            'detail' => 'Publish posts in WordPress to populate this editorial list.',
            'href' => (string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com')
        ];
    }

    foreach (array_slice($gapSuggestions, 0, 3) as $gap) {
        $listItems[] = [
            'title' => 'Gap: ' . (string) ($gap['topic'] ?? 'Untitled'),
            'detail' => (string) ($gap['suggestedAction'] ?? 'Add content in WordPress for this topic.')
        ];
    }

    $actions = [
        [
            'label' => 'Work',
            'action' => (string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com') . '/#work'
        ],
        [
            'label' => 'Lab',
            'action' => (string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com') . '/#lab'
        ],
        [
            'label' => 'Read',
            'action' => (string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com') . '/#read'
        ],
        [
            'label' => 'Bio',
            'action' => (string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com') . '/#bio'
        ],
        [
            'label' => 'Markets',
            'action' => (string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com') . '/#markets'
        ],
        [
            'label' => (string) ($conversionProfile['primaryActionLabel'] ?? 'Explore Main Site'),
            'action' => (string) ($conversionProfile['primaryActionUrl'] ?? 'https://alexanderjgill.com')
        ],
        [
            'label' => (string) ($conversionProfile['secondaryActionLabel'] ?? 'Read Latest Insights'),
            'action' => (string) ($conversionProfile['secondaryActionUrl'] ?? ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com'))
        ],
        [
            'label' => 'View Main Site',
            'action' => (string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com')
        ]
    ];

    if (isset($posts[0]) && is_array($posts[0])) {
        $latestId = (int) ($posts[0]['id'] ?? 0);
        if ($latestId > 0) {
            array_unshift($actions, [
                'label' => 'Read Latest Story',
                'action' => '/story/' . (string) $latestId
            ]);
        }
    }

    foreach (mysite_wp_pick_priority_posts($snapshot) as $postAction) {
        $actions[] = $postAction;
        if (count($actions) >= 8) {
            break;
        }
    }

    if (count($posts) > 0 && (string) ($posts[0]['link'] ?? '') !== '' && count($actions) < 8) {
        $actions[] = ['label' => 'Read Latest Post', 'action' => (string) $posts[0]['link']];
    }

    $faqItems = [
        [
            'question' => 'Is this modifying the live WordPress site?',
            'answer' => 'No. Integration is read-only and only fetches public data from the WordPress REST API.'
        ],
        [
            'question' => 'How is this personalized per visitor?',
            'answer' => 'Visitor signals and a per-visitor design seed drive layout, styling, and content emphasis.'
        ],
        [
            'question' => 'What should be added next?',
            'answer' => count($gapSuggestions) > 0
                ? 'Start with: ' . (string) ($gapSuggestions[0]['topic'] ?? 'high-priority content')
                : 'Current content coverage looks healthy based on this heuristic scan.'
        ]
    ];

    return [
        'heroWelcome' => [
            'title' => $heroTitle,
            'subtitle' => $heroSubtitle,
            'ctaLabel' => $heroCtaLabel,
            'ctaUrl' => $heroCtaUrl,
            'imageUrl' => $heroImageUrl,
            'brandName' => $brandName,
            'brandTagline' => $brandTagline
        ],
        'featuredGrid' => [
            'items' => $gridItems
        ],
        'nextStepsList' => [
            'items' => $listItems
        ],
        'quickStartActions' => [
            'actions' => $actions
        ],
        'faqGeneral' => [
            'items' => $faqItems
        ],
        'brandMeta' => [
            'name' => $brandName,
            'tagline' => $brandTagline,
            'sections' => ['Work', 'Lab', 'Read', 'Bio', 'Markets']
        ]
    ];
}

function mysite_wp_summary_for_prompt(array $snapshot, array $gapSuggestions, array $intent): array
{
    $postTitles = [];
    $postLinks = [];
    foreach (array_slice(($snapshot['posts'] ?? []), 0, 8) as $post) {
        $postTitles[] = (string) ($post['title'] ?? '');
        $link = trim((string) ($post['link'] ?? ''));
        if ($link !== '') {
            $postLinks[] = $link;
        }
    }

    $categoryNames = [];
    foreach (array_slice(($snapshot['categories'] ?? []), 0, 12) as $category) {
        $categoryNames[] = (string) ($category['name'] ?? '');
    }

    $gapTopics = [];
    foreach (array_slice($gapSuggestions, 0, 8) as $gap) {
        $gapTopics[] = (string) ($gap['topic'] ?? '');
    }

    return [
        'baseUrl' => (string) ($snapshot['baseUrl'] ?? ''),
        'site' => $snapshot['site'] ?? [
            'name' => 'Alexander Gill',
            'description' => 'Power plays.'
        ],
        'fetchedAt' => (string) ($snapshot['fetchedAt'] ?? gmdate('c')),
        'postCount' => count($snapshot['posts'] ?? []),
        'categoryCount' => count($snapshot['categories'] ?? []),
        'postTitles' => $postTitles,
        'postLinks' => $postLinks,
        'categoryNames' => $categoryNames,
        'gapTopics' => $gapTopics,
        'conversionProfile' => mysite_wp_conversion_profile($intent, $snapshot),
        'errors' => $snapshot['errors'] ?? []
    ];
}
