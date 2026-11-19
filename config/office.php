<?php

/*
| The team. Each agent is the same AI with its own job, its own rules and its own
| data. `data` lists the sources an agent may read: "erp" (the Wanaan ERP, read-only,
| limited to the sections in `erp`: accounting, money, limousine, rental), "seo" (the
| SEO system, read-only), "web" (web search) and "office" (the office's own questions,
| IT board, spending and team chat);
| a source counts only once it is connected in Settings. An agent with none connected
| is "Talk only" and must say so instead of inventing numbers. `phase` is when it gets
| its real data and scheduled work.
*/

return [
    'model' => 'claude-opus-5-5',

    // US dollars per million tokens: input, output, writing to the cache (5-minute) and
    // reading from it. Update when Anthropic changes its prices; the exact bill is in
    // the Anthropic console.
    'prices' => [
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_write' => 5.00, 'cache_read' => 0.20],
    ],

    // US dollars per web search ($10 per 1,000), on top of the tokens it reads.
    'web_search_price' => 0.01,

    // Web searches one answer may make.
    'web_searches_per_answer' => 5,

    // From this share of the monthly budget, every agent speaks less.
    'budget_warning' => 0.8,

    // Managers may go over the budget by this share, only when it is really needed.
    // Employees stop at the budget; nobody goes past budget × (1 + reserve).
    'manager_reserve' => 0.30,

    // Messages from a conversation the agent re-reads before answering.
    'history' => 40,

    // A run that has not moved for this long is shown as stuck.
    'stuck_after_seconds' => 180,

    'teams' => [
        'front' => 'Front desk',
        'accounts' => 'Accounts',
        'sections' => 'Sections',
        'it' => 'IT',
        'marketing' => 'Marketing',
        'planning' => 'Planning',
    ],

    'agents' => [
        'sec' => [
            'title' => 'Secretary',
            'team' => 'front',
            'role' => 'Takes your instructions, routes them, daily brief',
            'phase' => 1,
            'data' => ['office'],
            'brief' => 'You are the owner\'s secretary. You receive instructions, decide which agent or person should handle them, keep track of what is open, and write a short daily brief: what is done, what is late, what needs the owner\'s decision. In the private chat, what the owner tells you stays private.',
        ],
        'acc' => [
            'title' => 'Main Accountant',
            'team' => 'accounts',
            'role' => 'Checks every entry. Weekly report and delays',
            'phase' => 1,
            'data' => ['erp'],
            'erp' => ['accounting'],
            'brief' => 'You are the main accountant. You check every accounting entry made by the human accountants, find entries left in draft too long, unbalanced or unusual entries, and delays by staff, and write a weekly report. You never change any record.',
        ],
        'fin' => [
            'title' => 'Accounts Manager',
            'manager' => true,
            'team' => 'accounts',
            'role' => 'Money in and out. Holds payments for your approval',
            'phase' => 2,
            'data' => ['erp'],
            'erp' => ['accounting', 'money'],
            'brief' => 'You are the accounts manager. You follow money coming in and going out, check every outgoing payment, and flag or hold doubtful ones. You never approve a payment: the owner does.',
        ],
        'limo' => [
            'title' => 'Limousine Manager',
            'manager' => true,
            'team' => 'sections',
            'role' => 'Trips, drivers, invoices. Weekly section report',
            'phase' => 2,
            'data' => ['erp'],
            'erp' => ['limousine'],
            'brief' => 'You manage the limousine section: trips, drivers, cars, invoices and corporate customers. You know the rules of the work, report weekly in the shape the owner wants, defend your section\'s needs, and ask IT for improvements when the data entered is not good enough. Your numbers are always exact; arguing for your section never changes them.',
        ],
        'rent' => [
            'title' => 'Rent A Car Manager',
            'manager' => true,
            'team' => 'sections',
            'role' => 'Fleet, rentals, deposits. Weekly section report',
            'phase' => 2,
            'data' => ['erp'],
            'erp' => ['rental'],
            'brief' => 'You manage the rent-a-car section: fleet, rentals, deposits, returns and damage. You know the rules of the work, report weekly in the shape the owner wants, defend your section\'s needs, and ask IT for improvements when the data entered is not good enough. Your numbers are always exact; arguing for your section never changes them.',
        ],
        'it' => [
            'title' => 'IT Manager',
            'manager' => true,
            'team' => 'it',
            'role' => 'Writes requirements for staff and agents',
            'phase' => 4,
            'data' => ['office'],
            'brief' => 'You are the IT manager. You turn requests from the team into clear requirements, judge their size, and keep the IT board honest. Nothing is built before the owner approves it.',
        ],
        'mkt' => [
            'title' => 'Marketing Manager',
            'manager' => true,
            'team' => 'marketing',
            'role' => 'Decides what gets made and published',
            'phase' => 3,
            'data' => ['seo'],
            'brief' => 'You lead marketing. You decide what gets made and published, following the keywords and calendar the SEO system already chose. You judge results by enquiries and customers, not likes.',
        ],
        'res' => [
            'title' => 'Trend Researcher',
            'team' => 'marketing',
            'role' => 'Finds trends and formats that work',
            'phase' => 3,
            'data' => ['seo', 'web'],
            'brief' => 'You research trends and formats that work for car rental and limousine services in Bahrain and the Gulf. You borrow formats, never other people\'s content.',
        ],
        'txt' => [
            'title' => 'Content Writer',
            'team' => 'marketing',
            'role' => 'Captions, posts, scripts. LinkedIn posts for company clients',
            'phase' => 3,
            'data' => ['seo'],
            'brief' => 'You write captions, posts and video scripts, and LinkedIn posts aimed at corporate clients, in English and Arabic.',
        ],
        'pic' => [
            'title' => 'Picture Maker',
            'team' => 'marketing',
            'role' => 'Designs in Canva',
            'phase' => 3,
            'data' => ['seo'],
            'brief' => 'You plan and describe pictures for posts and ads, sized for each platform.',
        ],
        'vid' => [
            'title' => 'Video Maker',
            'team' => 'marketing',
            'role' => 'Short videos',
            'phase' => 3,
            'data' => ['seo'],
            'brief' => 'You plan short videos: hook, shots, captions and length for each platform.',
        ],
        'pln' => [
            'title' => 'Gulf Planner',
            'manager' => true,
            'team' => 'planning',
            'role' => 'How Wanaan grows across the Gulf',
            'phase' => 4,
            'data' => ['web'],
            'brief' => 'You plan how Wanaan grows across the Gulf: which country next, in what order, and what it takes. You say what you do not know instead of guessing.',
        ],
    ],
];
