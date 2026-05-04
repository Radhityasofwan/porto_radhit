<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

mysqli_set_charset($conn, 'utf8mb4');

function excerptDraft(string $text, int $limit = 160): string
{
    $plain = trim(strip_tags($text));
    if ($plain === '') {
        return '';
    }

    if (strlen($plain) <= $limit) {
        return $plain;
    }

    return rtrim(substr($plain, 0, $limit - 3)) . '...';
}

function fetchRow(mysqli $conn, string $table, int $id): ?array
{
    $query = "SELECT * FROM `$table` WHERE `id` = $id LIMIT 1";
    $result = mysqli_query($conn, $query);

    if (!$result) {
        throw new RuntimeException("Failed to query {$table}: " . mysqli_error($conn));
    }

    return mysqli_fetch_assoc($result) ?: null;
}

function fillMissingDraft(mysqli $conn, string $table, int $id, array $draft): int
{
    $row = fetchRow($conn, $table, $id);
    if (!$row) {
        throw new RuntimeException("Row not found: {$table}#{$id}");
    }

    $updates = [];
    foreach ($draft as $field => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $currentValue = trim((string) ($row[$field] ?? ''));
        if ($currentValue !== '') {
            continue;
        }

        $updates[] = sprintf(
            "`%s` = '%s'",
            $field,
            mysqli_real_escape_string($conn, $value)
        );
    }

    if (!$updates) {
        return 0;
    }

    $query = "UPDATE `$table` SET " . implode(', ', $updates) . " WHERE `id` = $id";
    if (!mysqli_query($conn, $query)) {
        throw new RuntimeException("Failed to update {$table}#{$id}: " . mysqli_error($conn));
    }

    return count($updates);
}

$counts = [
    'profile' => 0,
    'projects' => 0,
    'metrics' => 0,
    'skills' => 0,
    'experience' => 0,
    'education' => 0,
    'articles' => 0,
    'hero_chat' => 0,
];

$counts['profile'] += fillMissingDraft($conn, 'profile', 1, [
    'site_title_en' => 'DICH',
    'hero_role_en' => 'Digital Marketer & Growth Engineer',
    'availability_text_en' => 'OPEN FOR PROJECTS',
    'bio_en' => 'Data-driven Digital Marketer and Growth Engineer focused on Paid Ads (Meta Ads and Google Ads), SEO, copywriting, content strategy, and social media management. I combine funnel optimization and landing page CRO with AI-powered web app and SaaS (PWA) development for campaign automation, tracking, and analytics dashboards so growth execution becomes faster, more efficient, and more measurable.',
    'projects_desc_en' => 'A curated selection of campaigns, websites, and product builds with a strong focus on measurable growth, clearer messaging, and faster execution.',
    'contact_desc_en' => 'If you need help with paid ads, SEO, landing pages, automation, or product experimentation, I would be happy to discuss the right direction for your business.',
    'seo_keywords_en' => 'Digital Marketer Jakarta, Web Developer Jakarta, Meta Ads, Google Ads, SEO Specialist, Social Media Manager, Landing Page, WordPress, PHP MySQL, SaaS, PWA',
]);

$categoryMap = [
    'Paid Ads' => 'Paid Ads',
    'Sosial Media' => 'Social Media',
    'Social Media' => 'Social Media',
    'Website Development' => 'Website Development',
    'Portal Berita/Artikel/SEO' => 'News Portal / Articles / SEO',
    'SaaS / PWA' => 'SaaS / PWA',
];

$projectDrafts = [
    1 => [
        'title_en' => 'Google Ads - App Install | Matik Laundry',
        'description_en' => 'App install campaign with clean tracking and stable CPI optimization.',
        'problem_en' => 'Install targets were high, the budget was limited, and acquisition quality needed tighter control.',
        'solution_en' => 'Built the campaign structure, set up UTM and measurement, optimized keywords and assets, and monitored performance daily to keep CPI efficient.',
        'details_en' => 'Execution focused on campaign structure, targeting, budget pacing, and daily performance review. The result was stronger install volume with a low CPI for the early acquisition stage.',
        'result_text_en' => '160 installs - CPI ~Rp937',
    ],
    2 => [
        'title_en' => 'Meta Ads - Engagement | IG/FB',
        'description_en' => 'Boosted content engagement to build warmer awareness before conversion.',
        'problem_en' => 'Engagement stayed low because the content direction and campaign objective were not aligned.',
        'solution_en' => 'Selected the right engagement objective, adjusted creative and copy, refined the audience, and reviewed the cost per interaction.',
        'details_en' => 'Execution relied on creative testing and smarter audience selection. The numbers are presented as a portfolio-friendly draft and can be replaced with live campaign data at any time.',
        'result_text_en' => '30 interactions - CPE ~Rp3,333',
    ],
    3 => [
        'title_en' => 'Meta Ads - Traffic to Instagram Profile',
        'description_en' => 'Traffic campaign to drive Instagram profile visits and support organic growth.',
        'problem_en' => 'Traffic quality can become noisy when the CTA and the landing destination are not aligned.',
        'solution_en' => 'Optimized the CTA and copy, aligned creatives with the profile experience, and reviewed visitor quality based on behavior.',
        'details_en' => 'This campaign increased profile visits with an approach that protected traffic quality. It fits the awareness stage and pairs well with organic content follow-up.',
        'result_text_en' => '250 profile visits - CPV ~Rp600',
    ],
    4 => [
        'title_en' => 'Social Media Management - Digital Brands & MSMEs',
        'description_en' => 'Managed Instagram, TikTok, and Facebook content planning, captions, scheduling, engagement, and insight evaluation.',
        'problem_en' => 'Posting was inconsistent and the content direction was not yet aligned with business goals.',
        'solution_en' => 'Built a content calendar for 3-5 posts per week, wrote SEO-friendly captions, created engagement SOPs, and ran a weekly insight review.',
        'details_en' => 'The main outcome highlighted for clients and recruiters is consistency, stronger brand communication quality, and insight-based evaluation.',
        'result_text_en' => '+20-30% engagement',
    ],
    5 => [
        'title_en' => 'Company Profile - PT Hutama Solusi Indonesia',
        'description_en' => 'Professional company profile website with clear structure, polished UI, mobile responsiveness, and basic SEO readiness.',
        'problem_en' => 'The company needed a credible corporate website that prospective clients and partners could understand quickly.',
        'solution_en' => 'Built a structured service architecture, a professional layout, and a solid baseline for performance and SEO.',
        'details_en' => 'Focus: clear information architecture, professional UI, mobile responsiveness, and basic SEO readiness. The detail page case study includes the challenge, the solution, and growth data.',
        'result_text_en' => 'Corporate Website Live',
        'chart_data_json_en' => '{"labels":["Jan","Feb","Mar","Apr"],"data":[10,50,130,300],"label":"Revenue Growth"}',
    ],
    6 => [
        'title_en' => 'Company Profile - PT Mandiri Sentosa Sinergi',
        'description_en' => 'Clean corporate website designed to strengthen trust and make service information easier to access.',
        'problem_en' => 'Corporate content needed to be concise while still sounding convincing.',
        'solution_en' => 'Organized the page architecture, tightened the copy, and created a layout that feels easy for clients and HR teams to read.',
        'details_en' => 'Focus: readability, information structure, and a professional visual tone. Result: a live website ready to serve company profile needs.',
        'result_text_en' => 'Corporate Website Live',
    ],
    7 => [
        'title_en' => 'Corporate Website - PT Matik Creative Technology',
        'description_en' => 'Main brand website that serves as the information hub and connector for the full Matik product ecosystem.',
        'problem_en' => 'Campaign traffic needed a clear and convincing hub that could guide visitors to the right product.',
        'solution_en' => 'Strengthened the content structure, CTA placement, and user flow toward products such as Laundry, POS, and QPlus.',
        'details_en' => 'The corporate website acts as the brand hub, clarifying value propositions, services, and product pathways. It works well for both branding and conversion use cases.',
        'result_text_en' => 'Brand Hub Live',
    ],
    8 => [
        'title_en' => 'Foundation Website - Nurush Shodiqin',
        'description_en' => 'Informational foundation website for the public, covering profile, programs, and activities.',
        'problem_en' => 'Program and activity information needed to be simple and easy for visitors to access.',
        'solution_en' => 'Built lightweight pages, a clear content structure, and an easy-to-manage setup.',
        'details_en' => 'The website is live and ready to support public publication of foundation activities.',
        'result_text_en' => 'Nonprofit Website Live',
    ],
    9 => [
        'title_en' => 'Business Website - Ozverlig Sportwear',
        'description_en' => 'Catalog and business profile website for a jersey manufacturer, with a strong focus on order CTAs.',
        'problem_en' => 'Many prospects arrived from Instagram, so the site needed to be mobile-first and fast.',
        'solution_en' => 'Structured the catalog, tightened the WhatsApp CTA, and built a mobile-friendly layout.',
        'details_en' => 'Result: a neat online catalog with clear CTAs, ready to be used directly from a social bio link.',
        'result_text_en' => 'Business Website Live',
    ],
    10 => [
        'title_en' => 'News Portal - Institut Kajian Strategi Nasional',
        'description_en' => 'News portal and institutional profile website for publishing articles and organizational information.',
        'problem_en' => 'The portal needed a stronger category structure and navigation so visitors could find content more easily.',
        'solution_en' => 'Refined the portal structure, content taxonomy, and overall presentation to feel more credible.',
        'details_en' => 'The portal is live and ready for ongoing content publication.',
        'result_text_en' => 'News Portal Live',
    ],
    11 => [
        'title_en' => 'Digital News Portal - PastiDigital',
        'description_en' => 'Digital news portal with an SEO-friendly structure built to scale content production.',
        'problem_en' => 'A growing content library needed cleaner categories and internal linking.',
        'solution_en' => 'Optimized the article structure, content taxonomy, and foundational internal linking.',
        'details_en' => 'The portal is live and ready to scale for SEO.',
        'result_text_en' => 'SEO-Ready Portal',
    ],
    12 => [
        'title_en' => 'Landing Page - Matik Laundry (Primary)',
        'description_en' => 'Primary landing page for ad campaigns with a clear value proposition and strong CTAs.',
        'problem_en' => 'Paid traffic needed a conversion-focused landing page instead of a purely informational one.',
        'solution_en' => 'Structured the benefits, social proof, FAQ, CTA, and copy in a way laundry business owners can grasp quickly.',
        'details_en' => 'The landing page was built to be conversion-ready and to support paid ads plus customer-service follow-up.',
        'result_text_en' => 'CTA CTR +18% (sample)',
    ],
    13 => [
        'title_en' => 'Landing Page - Matik Laundry (Variant)',
        'description_en' => 'Landing page variant built for A/B testing and segmented campaign messaging.',
        'problem_en' => 'Different audiences needed different message angles.',
        'solution_en' => 'Prepared a flexible structure for testing headlines, benefit framing, and CTA variations.',
        'details_en' => 'Ready to support A/B testing in campaign execution.',
        'result_text_en' => 'Variant for Testing',
    ],
    15 => [
        'title_en' => 'Landing Page - Cafe & Resto System',
        'description_en' => 'Landing page for the cafe and restaurant segment, emphasizing operations and efficiency.',
        'problem_en' => 'This segment required a more specific narrative and clearer benefit framing.',
        'solution_en' => 'Wrote segment-specific copy and shaped the CTA around cafe and restaurant needs.',
        'details_en' => 'A landing page tailored to segment-specific messaging.',
        'result_text_en' => 'Segmented Landing',
    ],
    16 => [
        'title_en' => 'Product Page - QPlus (Main)',
        'description_en' => 'Product page built to educate merchants and strengthen trust.',
        'problem_en' => 'Merchants needed clearer benefits and stronger trust signals before taking action.',
        'solution_en' => 'Improved the copy, trust elements, and CTA structure.',
        'details_en' => 'A merchant-ready product page focused on education and credibility.',
        'result_text_en' => 'Merchant-Ready',
    ],
    17 => [
        'title_en' => 'Product Page - Fizzi Details',
        'description_en' => 'Product detail page that is concise, clear, and easy to understand.',
        'problem_en' => 'Long product explanations can overwhelm visitors.',
        'solution_en' => 'Reworked the structure so the page focuses on benefits and a clear CTA.',
        'details_en' => 'A cleaner and more elegant detail page.',
        'result_text_en' => 'Clear Product Detail',
    ],
    18 => [
        'title_en' => 'Link Hub - link.matik.id',
        'description_en' => 'Link hub for campaign distribution and social bio traffic.',
        'problem_en' => 'Scattered links made the user journey confusing.',
        'solution_en' => 'Built a clearer link hierarchy and CTA flow tailored for Instagram and TikTok bios.',
        'details_en' => 'Improved the organization of campaign link distribution.',
        'result_text_en' => 'Campaign Link Hub',
    ],
    19 => [
        'title_en' => 'Microsite - Mager',
        'description_en' => 'Campaign microsite that is quick to launch and focused on CTA performance.',
        'problem_en' => 'The campaign needed a fast, lightweight landing experience.',
        'solution_en' => 'Prepared short-form copy and a clear CTA structure.',
        'details_en' => 'A fast landing experience for campaigns.',
        'result_text_en' => 'Fast Landing',
    ],
    20 => [
        'title_en' => 'Landing Page - QPlus (Variant)',
        'description_en' => 'QPlus landing page variant for campaign needs and alternate promotional messaging.',
        'problem_en' => 'Different audiences required different message angles.',
        'solution_en' => 'Prepared alternate copy and CTA variations for testing.',
        'details_en' => 'Campaign-ready variant page.',
        'result_text_en' => 'Campaign Variant',
    ],
    21 => [
        'title_en' => 'SEO Content & Website Optimization',
        'description_en' => 'Keyword research and SEO copywriting to improve organic traffic and visitor quality.',
        'problem_en' => 'Content was not yet aligned with search intent and internal linking was still weak.',
        'solution_en' => 'Ran keyword research with Semrush, structured the article architecture, improved internal linking, and monitored performance through GA4 and Search Console.',
        'details_en' => 'The outcome is a people-first article structure, cleaner headings, and a clearer keyword strategy. Growth figures are sample numbers and can be replaced with live data.',
        'result_text_en' => 'Organic +40% (sample)',
    ],
    22 => [
        'title_en' => 'Lenbee - SaaS E-Course Platform',
        'description_en' => 'SaaS e-course platform covering course management, users, and learning flow.',
        'problem_en' => 'The business needed a structured learning system that was easy to manage.',
        'solution_en' => 'Built course and user modules, a dashboard, and a scalable product structure.',
        'details_en' => 'Focus: core feature architecture and a clean dashboard for day-to-day e-course operations.',
        'result_text_en' => 'SaaS Platform Live',
    ],
    23 => [
        'title_en' => 'Dompetra - PWA Finance Management',
        'description_en' => 'Finance PWA with fast input, concise summaries, and a mobile-first UX.',
        'problem_en' => 'Manual record-keeping was inconsistent and difficult to monitor.',
        'solution_en' => 'Created a simple input flow, clearer summaries, and a more comfortable mobile experience.',
        'details_en' => 'A cross-device app ready for daily financial tracking needs.',
        'result_text_en' => 'PWA Finance App',
    ],
    24 => [
        'title_en' => 'ApplyBot - PWA Job Application via Email',
        'description_en' => 'Automation tool for job applications, with structured applicant data and email templates.',
        'problem_en' => 'Manual application workflows took too much time and were hard to track.',
        'solution_en' => 'Built applicant data management, email templates, and an automated sending flow.',
        'details_en' => 'Made the application process more organized and measurable.',
        'result_text_en' => 'Automation Workflow',
    ],
    25 => [
        'title_en' => 'Growth Hub - SaaS CRM & Content Calendar',
        'description_en' => 'Internal CRM for lead tracking and content calendar management.',
        'problem_en' => 'Lead follow-up and content tracking often became fragmented.',
        'solution_en' => 'Built a simple lead pipeline, note-taking flow, and a collaborative content calendar.',
        'details_en' => 'An internal tool that supports day-to-day growth execution and reporting.',
        'result_text_en' => 'Internal Growth Tool',
    ],
    26 => [
        'title_en' => 'Jalan Sehat - Event Registration Web App',
        'description_en' => 'Event registration web app for participant data collection and operational needs.',
        'problem_en' => 'Manual registration slowed down recap and participant validation.',
        'solution_en' => 'Built registration forms, participant management, and a simple validation flow.',
        'details_en' => 'A registration system ready to be used for live events.',
        'result_text_en' => 'Event System Live',
    ],
    27 => [
        'title_en' => 'FrontPhotobooth - CRM & Invoicing (PWA)',
        'description_en' => 'Dedicated CRM for a photobooth vendor, complete with invoice generation.',
        'problem_en' => 'Manual booking and invoice workflows were slow and error-prone.',
        'solution_en' => 'Built a lightweight CRM module, invoice templates, and a cleaner order flow.',
        'details_en' => 'Helped speed up administration and invoice processing.',
        'result_text_en' => 'CRM & Invoicing',
    ],
    28 => [
        'title_en' => 'Social Media Strategy - Cashier App Promotion',
        'description_en' => 'Social media promotion strategy for a point-of-sale app, focused on visibility and demand generation.',
        'problem_en' => 'The product needed clearer communication so the right audience could understand the value quickly.',
        'solution_en' => 'Prepared the social media direction, message angle, and promo structure for the target audience.',
        'details_en' => 'Draft campaign support for promoting a cashier app through social channels.',
        'result_text_en' => 'Social Promotion Strategy',
    ],
];

$projectIds = array_keys($projectDrafts);
foreach ($projectIds as $projectId) {
    $row = fetchRow($conn, 'projects', $projectId);
    if (!$row) {
        continue;
    }

    $draft = $projectDrafts[$projectId];
    $draft['category_en'] = $categoryMap[$row['category']] ?? $row['category'];
    $draft['meta_desc_en'] = excerptDraft($draft['description_en']);

    if (!isset($draft['chart_data_json_en']) && !empty($row['chart_data_json']) && $row['chart_data_json'] !== 'NULL') {
        $draft['chart_data_json_en'] = $row['chart_data_json'];
    }

    $counts['projects'] += fillMissingDraft($conn, 'projects', $projectId, $draft);
}

$metricDrafts = [
    1 => ['metric_name_en' => 'SEO Impact', 'metric_value_en' => 'Up to +40% organic traffic'],
    2 => ['metric_name_en' => 'Broadcast Engagement', 'metric_value_en' => 'Open rate 35%+'],
    3 => ['metric_name_en' => 'E-Commerce Milestone', 'metric_value_en' => '1,000+ products/month'],
    4 => ['metric_name_en' => 'Web & SaaS Delivered', 'metric_value_en' => '15+ projects shipped'],
];

foreach ($metricDrafts as $metricId => $draft) {
    $counts['metrics'] += fillMissingDraft($conn, 'impact_metrics', $metricId, $draft);
}

$skillDrafts = [
    1 => 'Meta Ads Manager (IG/FB) - Pixel - Events',
    2 => 'Google Ads (Search/Display/App Campaign)',
    3 => 'TikTok Ads Manager',
    4 => 'Google Analytics 4 (GA4) - UTM Tracking',
    5 => 'Google Search Console (SEO Monitoring)',
    6 => 'Semrush (Keyword Research & Competitor Analysis)',
    7 => 'SEO Copywriting (Search Intent)',
    8 => 'Content Calendar (IG/TikTok/Facebook)',
    9 => 'WordPress (Company Profile & Portal)',
    10 => 'PHP Native - MySQL',
    11 => 'HTML/CSS/JavaScript',
    12 => 'Bootstrap / Tailwind (Responsive UI)',
    13 => 'Landing Page CRO (CTA, Social Proof, FAQ)',
    14 => 'PWA (Mobile-First Web App)',
    15 => 'Canva (Ad & Social Design)',
    16 => 'Figma (UI Planning)',
    17 => 'Adobe Photoshop',
    18 => 'CapCut (Reels/TikTok Editing)',
];

foreach ($skillDrafts as $skillId => $skillNameEn) {
    $counts['skills'] += fillMissingDraft($conn, 'skills', $skillId, ['skill_name_en' => $skillNameEn]);
}

$experienceDrafts = [
    4 => [
        'role_en' => 'Digital Marketer and Growth Engineer',
        'year_range_en' => 'June 2024 - Present',
        'description_en' => 'Built and executed data-driven funnel strategies from awareness to conversion. Managed Paid Ads (Meta Ads and Google Ads), created campaign landing pages, and optimized SEO/SEM until organic traffic grew by up to 40 percent. Also ran segmented broadcasts with open rates above 35 percent, reported through GA4 and Meta Suite, and executed A/B tests to improve CTR.',
    ],
    5 => [
        'role_en' => 'E-Commerce Specialist',
        'year_range_en' => 'Jan 2023 - Mar 2024',
        'description_en' => 'Managed end-to-end e-commerce operations and marketing, including production, customer service, distribution, inventory, and financial reporting. Reached more than 1,000 products sold in one month in the beauty care category.',
    ],
    6 => [
        'role_en' => 'Marketing Admin',
        'year_range_en' => 'July 2022 - Dec 2022',
        'description_en' => 'Managed social media for promotional and website-based campaigns, followed up customers through payment, and handled regular stock updates. Also reviewed weekly traffic performance for campaign evaluation and improvement.',
    ],
];

foreach ($experienceDrafts as $experienceId => $draft) {
    $counts['experience'] += fillMissingDraft($conn, 'experience', $experienceId, $draft);
}

$counts['education'] += fillMissingDraft($conn, 'education', 1, [
    'degree_en' => 'Arabic Literature (GPA 3.46)',
    'year_range_en' => 'Graduated 2023',
]);

$articleDrafts = [
    1 => [
        'title_en' => 'Healthy Meta Ads: Start with the Message, Not the Budget',
        'content_en' => 'If your ads are wasteful, the problem is often not the budget, but the message. I usually start with three things: who the audience is, what problem they feel, and what value is easiest for them to understand. Only after that do I clean up the campaign structure and start creative testing. The rule is simple: test one variable, capture one insight, then iterate slowly but consistently.',
    ],
    2 => [
        'title_en' => 'Landing Pages That Are Easy to Read (and Make People Click)',
        'content_en' => 'A landing page that converts is not the noisiest one. It is the clearest one. The structure I like is simple: a specific headline, clear benefits, lightweight proof, an FAQ that answers objections, and a CTA that stands out. Bonus points if the page is mobile-friendly and loads fast. When users feel comfortable reading, the chance of action goes up.',
    ],
    3 => [
        'title_en' => 'SEO That Makes Sense: Start with User Intent, Then Keywords',
        'content_en' => 'SEO is not about stuffing as many keywords as possible. I focus on intent first: what the user is looking for, what kind of answer they expect, and which page is most relevant to serve it. After that, I refine the H1/H2 structure, internal links, and the clarity of the copy. The goal is relevant traffic, not traffic for vanity.',
    ],
    4 => [
        'title_en' => 'A Content System That Does Not Feel Repetitive: Content Pillars + Calendar',
        'content_en' => 'When content feels repetitive, the issue is usually a weak pillar system. I use a simple structure: education, proof, promotion, and behind the scenes. Then I turn that into a realistic weekly calendar. With a system like this, content production becomes easier, brand voice stays more consistent, and ideas do not run dry too quickly.',
    ],
    5 => [
        'title_en' => 'Practical Copywriting: Hook - Benefit - Proof - CTA',
        'content_en' => 'If you are not sure where to start, use this pattern: a relatable hook, a specific benefit, simple proof, and a clear CTA. This framework works for ads, landing pages, and social captions alike. It is simple, but it helps people understand the message faster and move toward action.',
    ],
];

foreach ($articleDrafts as $articleId => $draft) {
    $draft['meta_desc_en'] = excerptDraft($draft['content_en']);
    $counts['articles'] += fillMissingDraft($conn, 'articles', $articleId, $draft);
}

$chatDrafts = [
    3 => [
        'question_en' => 'Hi! Want a quick introduction about me?',
        'answer_en' => 'Nice to meet you! My name is Radhitya Sofwan Rahmat. I was born in Jakarta and I am now 25 years old.',
    ],
    6 => [
        'question_en' => 'Want to know when I started my career in Digital Marketing?',
        'answer_en' => 'My career journey started taking shape in 2021, during my fourth semester in college.',
    ],
    4 => [
        'question_en' => 'Want to know how I started my career in Digital Marketing?',
        'answer_en' => 'I started from zero as an e-commerce admin. I learned many things until I was able to build and manage my own online shop independently.',
    ],
    5 => [
        'question_en' => 'Curious about the biggest milestone I have achieved?',
        'answer_en' => 'In 2024, I reached 1,000 orders per month for skincare products with a margin of around Rp50,000 per item.',
    ],
    7 => [
        'question_en' => 'Let me also share one milestone from 2025.',
        'answer_en' => 'In 2025, I managed to double the customer base of a point-of-sale app.',
    ],
];

foreach ($chatDrafts as $chatId => $draft) {
    $counts['hero_chat'] += fillMissingDraft($conn, 'hero_chat', $chatId, $draft);
}

foreach ($counts as $table => $count) {
    echo strtoupper($table) . ': ' . $count . PHP_EOL;
}
