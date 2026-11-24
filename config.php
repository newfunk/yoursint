<?php
/**
 * YourSint config — secrets via Doppler, nooit hardcoded.
 */
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access forbidden');
}

if (!function_exists('doppler_get')) {
    $dopplerCandidates = [
        // Dev/prd onder yoursitehulp: yourgame/yoursint → ../../shared
        dirname(__DIR__, 2) . '/shared/doppler.php',
        // Dieper of andere layout
        dirname(__DIR__, 3) . '/shared/doppler.php',
        // Eigen domein / losse deploy: sibling of parent shared
        dirname(__DIR__) . '/shared/doppler.php',
        '/var/www/vhosts/awps01.argewebhosting.nl/dev.yoursitehulp.nl/shared/doppler.php',
        '/var/www/vhosts/awps01.argewebhosting.nl/yoursitehulp.nl/shared/doppler.php',
    ];
    foreach ($dopplerCandidates as $dopplerFile) {
        if (is_readable($dopplerFile)) {
            require_once $dopplerFile;
            break;
        }
    }
}
if (!function_exists('doppler_get')) {
    throw new RuntimeException('YourSint: Doppler-client ontbreekt (shared/doppler.php)');
}

$ysEnv = (str_contains(__DIR__, '/dev.yoursitehulp.nl/') || str_contains(__DIR__, '/dev.'))
    ? 'dev'
    : 'prd';

return [
    'openai_api_key'     => doppler_get('OPENAI_API_KEY', $ysEnv),
    'openai_model'       => 'gpt-5.2',
    'apiflash_key'       => doppler_get('APIFLASH_KEY', $ysEnv),
    'elevenlabs_api_key' => doppler_get('ELEVENLABS_API_KEY', $ysEnv),
    'elevenlabs_music'   => 'music_v2_5',
];
