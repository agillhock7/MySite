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
            'fetchedAt' => gmdate('c'),
            'posts' => [],
            'pages' => [],
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
    $maxPages = max(1, min(20, (int) ($wp['max_pages'] ?? 6)));
    $maxCategories = max(1, min(50, (int) ($wp['max_categories'] ?? 12)));
    $maxTags = max(1, min(50, (int) ($wp['max_tags'] ?? 12)));

    $apiBase = $baseUrl . '/wp-json/wp/v2';

    $postsRes = mysite_wp_fetch_json(
        $apiBase . '/posts?per_page=' . $maxPosts . '&_fields=id,link,title,excerpt,date,modified,categories,tags',
        $timeout
    );

    $pagesRes = mysite_wp_fetch_json(
        $apiBase . '/pages?per_page=' . $maxPages . '&_fields=id,link,title,excerpt,date,modified',
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

    $errors = [];
    foreach ([$postsRes, $pagesRes, $categoriesRes, $tagsRes] as $res) {
        if (!$res['ok']) {
            $errors[] = (string) $res['error'];
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
            'modified' => (string) ($post['modified'] ?? '')
        ];
    }

    $pages = [];
    foreach (($pagesRes['data'] ?? []) as $page) {
        if (!is_array($page)) {
            continue;
        }

        $title = mysite_wp_strip_text((string) (($page['title']['rendered'] ?? '') ?: 'Untitled'));
        $excerpt = mysite_wp_strip_text((string) (($page['excerpt']['rendered'] ?? '') ?: ''));

        $pages[] = [
            'id' => (int) ($page['id'] ?? 0),
            'title' => $title,
            'excerpt' => $excerpt,
            'link' => (string) ($page['link'] ?? ''),
            'date' => (string) ($page['date'] ?? ''),
            'modified' => (string) ($page['modified'] ?? '')
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
        'available' => count($posts) > 0 || count($pages) > 0,
        'baseUrl' => $baseUrl,
        'fetchedAt' => gmdate('c'),
        'posts' => $posts,
        'pages' => $pages,
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

    foreach (($snapshot['pages'] ?? []) as $page) {
        $searchCorpus[] = strtolower((string) ($page['title'] ?? ''));
        $searchCorpus[] = strtolower((string) ($page['excerpt'] ?? ''));
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
        ['topic' => 'Pro Suite Onboarding', 'match' => ['pro suite', 'onboard', 'hiops', 'dark horse virtue'], 'priority' => 'high'],
        ['topic' => 'Hosting Plan CTA', 'match' => ['hosting plan', 'hosting', 'infrastructure'], 'priority' => 'high'],
        ['topic' => 'FAQ', 'match' => ['faq', 'questions'], 'priority' => 'medium'],
        ['topic' => 'Contact CTA', 'match' => ['contact', 'book call', 'get in touch'], 'priority' => 'high']
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

function mysite_wp_conversion_profile(array $intent): array
{
    $brandName = 'Alexander J Gill';
    $companyName = 'Dark Horse Virtue';
    $hostingStartUrl = 'https://alexanderjgill.com';
    $proSuiteOnboardingUrl = 'https://hiops.darkhorsevirtue.io';

    $goalText = strtolower((string) ($intent['goal'] ?? ''));
    $topics = $intent['primaryTopics'] ?? [];
    $topicText = strtolower(implode(' ', is_array($topics) ? $topics : []));

    $searchText = trim($goalText . ' ' . $topicText);

    $intentType = 'pro_suite_onboarding';

    if (mysite_wp_contains_any($searchText, ['host', 'hosting', 'plan', 'infrastructure'])) {
        $intentType = 'hosting_plan';
    }

    if (mysite_wp_contains_any($searchText, ['pro suite', 'whmcs', 'dark horse virtue', 'onboarding', 'hiops'])) {
        $intentType = 'pro_suite_onboarding';
    }

    if (mysite_wp_contains_any($searchText, ['portfolio', 'work', 'project', 'case study'])) {
        $intentType = 'portfolio_review';
    }

    if (mysite_wp_contains_any($searchText, ['read', 'blog', 'article', 'learn'])) {
        $intentType = 'content_learning';
    }

    $primaryGoal = 'pro_suite_onboarding';
    if ($searchText === '') {
        $intentType = $primaryGoal;
    }

    $profiles = [
        'pro_suite_onboarding' => [
            'intentType' => 'pro_suite_onboarding',
            'heroTitle' => 'Start your Pro Suite onboarding with ' . $companyName,
            'heroSubtitle' => 'We tailor your path into HiOps so you can activate client operations quickly.',
            'heroCtaLabel' => 'Start Pro Suite Onboarding',
            'heroCtaUrl' => $proSuiteOnboardingUrl,
            'primaryActionLabel' => 'Open HiOps Onboarding',
            'primaryActionUrl' => $proSuiteOnboardingUrl,
            'secondaryActionLabel' => 'View Hosting Plan Options',
            'secondaryActionUrl' => $hostingStartUrl
        ],
        'hosting_plan' => [
            'intentType' => 'hosting_plan',
            'heroTitle' => 'Choose a hosting plan that fits your growth path',
            'heroSubtitle' => 'This experience helps visitors move from research to a clear hosting decision.',
            'heroCtaLabel' => 'Start Hosting Plan',
            'heroCtaUrl' => $hostingStartUrl,
            'primaryActionLabel' => 'Start Hosting Plan',
            'primaryActionUrl' => $hostingStartUrl,
            'secondaryActionLabel' => 'Need Managed Onboarding? Open HiOps',
            'secondaryActionUrl' => $proSuiteOnboardingUrl
        ],
        'portfolio_review' => [
            'intentType' => 'portfolio_review',
            'heroTitle' => 'See how ' . $brandName . ' executes across strategy, systems, and delivery',
            'heroSubtitle' => 'Portfolio-minded visitors can browse work, then move into hosting or onboarding when ready.',
            'heroCtaLabel' => 'Explore Work',
            'heroCtaUrl' => 'https://alexanderjgill.com/work/',
            'primaryActionLabel' => 'Explore Work',
            'primaryActionUrl' => 'https://alexanderjgill.com/work/',
            'secondaryActionLabel' => 'Start Pro Suite Onboarding',
            'secondaryActionUrl' => $proSuiteOnboardingUrl
        ],
        'content_learning' => [
            'intentType' => 'content_learning',
            'heroTitle' => 'Explore practical guidance from ' . $brandName,
            'heroSubtitle' => 'Learning-focused visitors can read first, then transition into hosting or Pro Suite onboarding.',
            'heroCtaLabel' => 'Read Latest Insights',
            'heroCtaUrl' => 'https://alexanderjgill.com/read/',
            'primaryActionLabel' => 'Open Reading Hub',
            'primaryActionUrl' => 'https://alexanderjgill.com/read/',
            'secondaryActionLabel' => 'Start Hosting Plan',
            'secondaryActionUrl' => $hostingStartUrl
        ]
    ];

    $fallbackKey = array_key_exists($primaryGoal, $profiles) ? $primaryGoal : 'pro_suite_onboarding';
    return $profiles[$intentType] ?? $profiles[$fallbackKey];
}

function mysite_wp_pick_priority_pages(array $snapshot): array
{
    $pages = $snapshot['pages'] ?? [];
    $priorityKeywords = [
        'work',
        'lab',
        'read',
        'bio',
        'market',
        'service',
        'pricing',
        'contact'
    ];

    $selected = [];

    foreach ($priorityKeywords as $keyword) {
        foreach ($pages as $page) {
            $title = strtolower((string) ($page['title'] ?? ''));
            if (strpos($title, $keyword) === false) {
                continue;
            }

            $link = (string) ($page['link'] ?? '');
            if ($link === '') {
                continue;
            }

            $selected[$link] = [
                'label' => (string) ($page['title'] ?? ucfirst($keyword)),
                'action' => $link
            ];

            break;
        }
    }

    return array_values($selected);
}

function mysite_wp_content_bundle(array $snapshot, array $gapSuggestions, array $intent): array
{
    $posts = $snapshot['posts'] ?? [];
    $pages = $snapshot['pages'] ?? [];
    $conversionProfile = mysite_wp_conversion_profile($intent);

    $heroTitle = (string) ($conversionProfile['heroTitle'] ?? 'Explore tailored content from alexanderjgill.com');
    $heroSubtitle = (string) ($conversionProfile['heroSubtitle'] ?? 'Personalized from your intent and live WordPress content.');
    $heroCtaLabel = (string) ($conversionProfile['heroCtaLabel'] ?? 'Visit Source Site');
    $heroCtaUrl = (string) ($conversionProfile['heroCtaUrl'] ?? ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com'));

    if (count($posts) > 0 && (string) ($posts[0]['title'] ?? '') !== '') {
        $heroTitle = $heroTitle . ' · ' . (string) $posts[0]['title'];
    }

    $gridItems = [];
    foreach (array_slice($posts, 0, 6) as $post) {
        $gridItems[] = [
            'title' => (string) ($post['title'] ?? 'Untitled'),
            'description' => (string) (($post['excerpt'] ?? '') !== '' ? $post['excerpt'] : 'No excerpt available.')
        ];
    }

    if (count($gridItems) === 0) {
        $gridItems[] = [
            'title' => 'No recent posts discovered',
            'description' => 'Publish or expose recent posts in WP REST to enrich this personalized experience.'
        ];
    }

    $listItems = [
        [
            'title' => 'Primary conversion path',
            'detail' => (string) ($conversionProfile['primaryActionLabel'] ?? 'Start Pro Suite Onboarding')
        ],
        [
            'title' => 'Secondary conversion path',
            'detail' => (string) ($conversionProfile['secondaryActionLabel'] ?? 'Start Hosting Plan')
        ]
    ];

    foreach (array_slice($pages, 0, 5) as $page) {
        $listItems[] = [
            'title' => (string) ($page['title'] ?? 'Untitled'),
            'detail' => (string) (($page['excerpt'] ?? '') !== '' ? $page['excerpt'] : 'No page summary available.')
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
            'label' => (string) ($conversionProfile['primaryActionLabel'] ?? 'Start Pro Suite Onboarding'),
            'action' => (string) ($conversionProfile['primaryActionUrl'] ?? 'https://hiops.darkhorsevirtue.io')
        ],
        [
            'label' => (string) ($conversionProfile['secondaryActionLabel'] ?? 'Start Hosting Plan'),
            'action' => (string) ($conversionProfile['secondaryActionUrl'] ?? ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com'))
        ],
        [
            'label' => 'View Main Site',
            'action' => (string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com')
        ]
    ];

    foreach (mysite_wp_pick_priority_pages($snapshot) as $pageAction) {
        $actions[] = $pageAction;
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
            'answer' => 'The onboarding intent determines which conversion path is prioritized first.'
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
            'ctaUrl' => $heroCtaUrl
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
        ]
    ];
}

function mysite_wp_summary_for_prompt(array $snapshot, array $gapSuggestions, array $intent): array
{
    $postTitles = [];
    foreach (array_slice(($snapshot['posts'] ?? []), 0, 8) as $post) {
        $postTitles[] = (string) ($post['title'] ?? '');
    }

    $pageTitles = [];
    foreach (array_slice(($snapshot['pages'] ?? []), 0, 8) as $page) {
        $pageTitles[] = (string) ($page['title'] ?? '');
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
        'fetchedAt' => (string) ($snapshot['fetchedAt'] ?? gmdate('c')),
        'postCount' => count($snapshot['posts'] ?? []),
        'pageCount' => count($snapshot['pages'] ?? []),
        'categoryCount' => count($snapshot['categories'] ?? []),
        'postTitles' => $postTitles,
        'pageTitles' => $pageTitles,
        'categoryNames' => $categoryNames,
        'gapTopics' => $gapTopics,
        'conversionProfile' => mysite_wp_conversion_profile($intent),
        'errors' => $snapshot['errors'] ?? []
    ];
}
