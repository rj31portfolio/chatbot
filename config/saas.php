<?php
return [
    'brand' => env('APP_NAME', 'AI Lead Agent'),
    'primary_color' => '#f97316',
    'secondary_color' => '#18181b',
    'currency' => 'INR',
    'trial_days' => 14,
    'scoring' => ['name'=>10,'phone'=>15,'email'=>10,'requirement'=>10,'service'=>10,'budget'=>10,'timeline'=>10,'buying'=>15,'appointment'=>10,'returning'=>5,'pricing'=>5],
    'plans' => [
        'Free' => ['price'=>0,'conversations'=>100,'ai_messages'=>300,'leads'=>100,'website_pages'=>10,'knowledge_documents'=>30,'team_members'=>1,'widgets'=>1,'domains'=>1,'ai_tokens'=>200000],
        'Starter' => ['price'=>999,'conversations'=>1000,'ai_messages'=>3000,'leads'=>1000,'website_pages'=>100,'knowledge_documents'=>300,'team_members'=>3,'widgets'=>3,'domains'=>3,'ai_tokens'=>2000000],
        'Professional' => ['price'=>2999,'conversations'=>5000,'ai_messages'=>15000,'leads'=>5000,'website_pages'=>500,'knowledge_documents'=>1000,'team_members'=>10,'widgets'=>10,'domains'=>10,'ai_tokens'=>10000000],
        'Agency' => ['price'=>7999,'conversations'=>20000,'ai_messages'=>60000,'leads'=>20000,'website_pages'=>2000,'knowledge_documents'=>4000,'team_members'=>30,'widgets'=>30,'domains'=>30,'ai_tokens'=>40000000],
    ],
];
