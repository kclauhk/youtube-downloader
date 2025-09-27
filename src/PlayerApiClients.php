<?php

namespace YouTube;

use YouTube\Exception\YouTubeException;
use YouTube\Utils\Utils;

class PlayerApiClients
{
    public static array $clients;

    function __construct()
    {
        // InnerTube Clients
        // list of known clients: https://github.com/zerodytrash/YouTube-Internal-Clients
        static::$clients = static::$clients ?? [
            'android_vr' => [
                'context' => [
                    'client' => [
                        'clientName' => 'ANDROID_VR',
                        'clientVersion' => '1.65.10',
                        'deviceMake' => 'Oculus',
                        'deviceModel' => 'Quest 3',
                        'androidSdkVersion' => 32,
                        'userAgent' => 'com.google.android.apps.youtube.vr.oculus/1.65.10 (Linux; U; Android 12L; eureka-user Build/SQ3A.220605.009.A1) gzip',
                        'osName' => 'Android',
                        'osVersion' => '12L',
                    ],
                ],
                'client_name' => 28,
            ],
            'ios' => [
                'context' => [
                    'client' => [
                        'clientName' => 'IOS',
                        'clientVersion' => '20.10.4',
                        'deviceMake' => 'Apple',
                        'deviceModel' => 'iPhone16,2',
                        'userAgent' => 'com.google.ios.youtube/20.10.4 (iPhone16,2; U; CPU iOS 18_3_2 like Mac OS X;)',
                        'osName' => 'iPhone',
                        'osVersion' => '18.3.2.22D82',
                    ],
                ],
                'client_name' => 5,
            ],
            'tv' => [       // "tv" client requires nsig
                'context' => [
                    'client' => [
                        'clientName' => 'TVHTML5',
                        'clientVersion' => '7.20250923.13.00',
                        'userAgent' => 'Mozilla/5.0 (ChromiumStylePlatform) Cobalt/Version',
                    ],
                ],
                'client_name' => 7,
                'config_url' => 'https://www.youtube.com/tv',
            ],
            'web' => [       // "web" client with Safari UA provides muxed HLS formats
                'context' => [
                    'client' => [
                        'clientName' => 'WEB',
                        'clientVersion' => '2.20250925.01.00',
                        'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.5 Safari/605.1.15',
                    ],
                ],
                'client_name' => 1,
            ],
            'web_embedded' => [
                'context' => [
                    'client' => [
                        'clientName' => 'WEB_EMBEDDED_PLAYER',
                        'clientVersion' => '1.20250923.21.00',
                    ],
                ],
                'client_name' => 56,
            ],
        ];
    }

    /**
     * @param string $client_id
     * @param array  $context
     * @param string $config_url    (optional) the URL of client config
     * @return boolean
     */
    public function setClient(string $client_id, array $context, string $config_url = null): bool
    {
        $ctx_c = Utils::arrayGet($context, 'context.client') ?? [];
        $has_name = (bool)Utils::arrayGet($context, 'clientName') || (bool)Utils::arrayGet($ctx_c, 'clientName');
        if ($has_name) {
            $has_ver = ((bool)Utils::arrayGet($context, 'clientName') && (bool)Utils::arrayGet($context, 'clientVersion'))
                        || ((bool)Utils::arrayGet($ctx_c, 'clientName') && (bool)Utils::arrayGet($ctx_c, 'clientVersion'));
        }
        if (!$has_name || !$has_ver) {
            throw new YouTubeException('Invalid client context: ' . ($has_name ? '"clientVersion"' : '"clientName"') . ' is missing');
            return false;
        }

        static::$clients[$client_id] = empty($ctx_c) ? ['context' => ['client' => $context]] : $context;

        if (!empty($config_url)) {
            $url = filter_var($config_url, FILTER_SANITIZE_URL);
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                static::$clients[$client_id]['config_url'] = $url;
            }
        }

        return true;
    }
}