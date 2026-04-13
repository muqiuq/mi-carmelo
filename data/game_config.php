<?php
// Game Parameters explicitly extracted as per requirements

return [
    // How many seconds before the chicken gets hungry
    // Default: 8 hours (28800 seconds)
    'feeding_interval_seconds' => 28800,

    // Scoring points
    'points_first_try' => 10,
    'points_second_try' => 5,
    'points_third_plus_try' => 1,

    // Rewards thresholds
    'correct_words_for_diamond' => 10,
    'diamonds_for_star' => 10,

    // Knowledge tracking
    // Difference between correct and incorrect attempts to be considered "known well"
    'knows_well_threshold' => 3,

    // Death: chicken dies if not fed within this many seconds
    // Default: 48 hours (172800 seconds)
    'death_timeout_seconds' => 172800,

    // Access token: require a valid token in the URL to load the app
    // Set to true to enable, false to disable
    'require_access_token' => true,

    // Base URL for generating token links (include trailing slash)
    // Example: 'https://micarmelo.example.com/'
    'base_url' => 'http://localhost:8080/'
];
