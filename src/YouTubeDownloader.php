<?php

namespace YouTube;

use YouTube\Exception\TooManyRequestsException;
use YouTube\Exception\VideoNotFoundException;
use YouTube\Exception\YouTubeException;
use YouTube\Models\VideoInfo;
use YouTube\Models\YouTubeCaption;
use YouTube\Models\YouTubeConfigData;
use YouTube\Responses\PlayerApiResponse;
use YouTube\Responses\VideoPlayerJs;
use YouTube\Responses\WatchVideoPage;
use YouTube\Utils\Utils;

class YouTubeDownloader
{
    protected const REGEX_SID = [
        'SAPISID' => '/\.youtube\.com[ \t]+.+SAPISID[ \t]+([^\s]+)/',
        'SAPISID1P' => '/\.youtube\.com[ \t]+.+__Secure-1PAPISID[ \t]+([^\s]+)/',
        'SAPISID3P' => '/\.youtube\.com[ \t]+.+__Secure-3PAPISID[ \t]+([^\s]+)/',
    ];

    protected Browser $client;
    protected PlayerApiClients $api_clients;

    public function __construct()
    {
        $this->client = new Browser();
        $this->api_clients = new PlayerApiClients();

        $this->client->setUserAgent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.7390.54 Safari/537.36'
        );
    }

    // Specify network options to be used in all network requests
    public function getBrowser(): Browser
    {
        return $this->client;
    }

    // Specify the JavaScript runtime for n/sig decryption
    public function getJsrt(): JsRuntime
    {
        return new JsRuntime();
    }

    // Specify client for video data request
    public function getApiClients(): PlayerApiClients
    {
        return $this->api_clients;
    }

    /**
     * @param string $query
     * @return array
     */
    public function getSearchSuggestions(string $query): array
    {
        $query = rawurlencode($query);

        $response = $this->client->get('http://suggestqueries.google.com/complete/search', [
            'client' => 'firefox',
            'ds' => 'yt',
            'q' => $query,
        ]);
        $json = json_decode($response->body, true);

        if (is_array($json) && count($json) >= 2) {
            return $json[1];
        }

        return [];
    }

    public function getVideoInfo(string $video_id, ?string $lang = null): ?VideoInfo
    {
        $page = $this->getPage($video_id, $lang);
        return $page->getVideoInfo($lang);
    }

    public function getPage(string $url, ?string $lang = null): WatchVideoPage
    {
        $video_id = Utils::extractVideoId($url);

        $lang = preg_match('/^[a-zA-Z-]+$/', (string) $lang, $matches) ? $lang : 'en';
        $cookies = $this->client->getCookies();
        if (preg_match_all('/(\n\.youtube\.com.*[&\t](hl=[\w-]+))/', $cookies, $matches)) {
            $cookies = str_replace(
                end($matches[1]),
                str_replace(end($matches[2]), "hl=$lang", end($matches[1])),
                $cookies
            );
            $this->client->setCookies($cookies);
        } else {
            $this->client->setCookies($cookies . "\n.youtube.com\tTRUE\t/\tFALSE\t0\tPREF\thl=$lang\n");
        }

        $response = $this->client->get('https://www.youtube.com/watch?' . http_build_query([
            'v' => $video_id,
            'bpctr' => 9999999999,
            'has_verified' => 1,
        ]));

        return new WatchVideoPage($response);
    }

    // Generating authorization headers for cookies authentication
    protected function setAuthHeaders(?string $session_index, ?string $user_session_id): array
    {
        $cookies = $this->client->getCookies();
        $timestamp = time();
        $sid_hash = [];
        foreach (['SAPISID3P', 'SAPISID', 'SAPISID1P', 'SAPISID3P'] as $i => $scheme) {
            if (preg_match(self::REGEX_SID[$scheme], $cookies, $matches)) {
                $sid = trim($matches[1]);
            }
            if ($i > 0 && !empty($user_session_id) && !empty($sid)) {
                $sid_hash[] = "{$scheme}HASH {$timestamp}_"
                              . sha1("{$user_session_id} {$timestamp} {$sid} https://www.youtube.com")
                              . '_u';
            }
        }

        return empty($sid_hash) ? [] : [
            'Authorization' => implode(' ', $sid_hash),
            'X-Goog-Authuser' => $session_index,
            'X-Youtube-Bootstrap-Logged-In' => true,
        ];
    }

    // Downloading player API JSON
    protected function getPlayerApiResponse(string $video_id, string $client_id, YouTubeConfigData $configData): PlayerApiResponse
    {
        $clients = $this->api_clients::$clients;

        if (!array_key_exists($client_id, $clients)) {
            throw new YouTubeException('Player client "' . $client_id . '" not defined');
        }

        $visitor_id = $configData->getGoogleVisitorId();
        $sig_timestamp = $configData->getSignatureTimestamp();
        $page_id = $configData->getDelegatedSessionId();
        $session_index = $configData->getSessionIndex();
        $user_session_id = $configData->getUserSessionId();

        if (!empty($clients[$client_id]['context']['client']['userAgent'])) {
            $this->client->setUserAgent($clients[$client_id]['context']['client']['userAgent']);
        }

        if (isset($clients[$client_id]['config_url'])) {
            $response = $this->client->get($clients[$client_id]['config_url']);
            $config = new WatchVideoPage($response);
            if (!empty($config->getYouTubeConfigData())) {
                $configData = $config->getYouTubeConfigData();
            }
            $context = $configData->getContext();
        } else {
            $context = $clients[$client_id]['context'];
        }
        foreach (['hl' => 'en', 'timeZone' => 'UTC', 'utcOffsetMinutes' => 0] as $k => $v) {
            $context['client'][$k] = $v;
        }

        $response = $this->client->post(
            'https://www.youtube.com/youtubei/v1/player?key=' . $configData->getApiKey(),
            json_encode(
                array_filter(
                    [
                        'context' => $context,
                        'videoId' => $video_id,
                        'playbackContext' => [
                            'contentPlaybackContext' => [
                                'html5Preference' => 'HTML5_PREF_WANTS',
                                'signatureTimestamp' => $sig_timestamp,
                            ],
                        ],
                        'racyCheckOk' => true,
                        'params' => ($clients[$client_id]['params'] ?? null),
                    ],
                    function ($v) {
                        return ($v || is_numeric($v));
                    }
                )
            ),
            array_merge(
                array_filter(
                    [
                        'Content-Type' => 'application/json',
                        'Origin' => 'https://www.youtube.com',
                        'X-Origin' => 'https://www.youtube.com',
                        'X-Goog-PageId' => $page_id,
                        'X-Goog-Visitor-Id' => $visitor_id,
                        'X-Youtube-Client-Name' => ($clients[$client_id]['client_name'] ?? null),
                        'X-Youtube-Client-Version' => $context['client']['clientVersion'],
                    ],
                    function ($v) {
                        return ($v || is_numeric($v));
                    }
                ),
                (
                    ($clients[$client_id]['supports_cookies'] ?? true)
                        ? $this->setAuthHeaders($session_index, $user_session_id)
                        : []
                )
            )
        );

        return new PlayerApiResponse($response);
    }

    /**
     *
     * @param string $video_id
     * @param array/string $extra       array of options, e.g. ['client'=>'tv', 'lang'=>'fr'] or
     *                                  comma-delimited list of player client IDs (the 1st in the
     *                                  list has the highest preference)
     * @return DownloadOptions
     * @throws TooManyRequestsException
     * @throws VideoNotFoundException
     * @throws YouTubeException
     */
    public function getDownloadLinks(string $video, $extra = null): DownloadOptions
    {
        $video_id = Utils::extractVideoId($video);

        if (!$video_id) {
            throw new \InvalidArgumentException('Invalid video ID: ' . $video);
        }

        $lang = null;
        if ($extra) {
            if (is_array($extra)) {
                if (array_key_exists('lang', $extra)) {
                    $lang = is_string($extra['lang']) && preg_match('/^[a-zA-Z-]+$/', $extra['lang'], $matches)
                            ? $extra['lang']
                            : $lang;
                }
                if (array_key_exists('client', $extra)) {
                    $client_ids = is_array($extra['client'])
                                  ? $extra['client']
                                  : explode(',', preg_replace('/\s+/', '', $extra['client']));
                }
            } elseif (is_string($extra)) {
                $client_ids = explode(',', preg_replace('/\s+/', '', $extra)) ?: $client_ids;
            }
        }
        if (empty($client_ids)) {
            if (
                (function () {
                    try {
                        return (new JsRuntime())->getApp();
                    } catch (YouTubeException $e) {
                        return false;
                    }
                })()
                || preg_match(self::REGEX_SID['SAPISID'], $this->client->getCookies(), $matches)
            ) {
                $client_ids = ['tv'];
            } else {
                $client_ids = ['android_vr'];
            }
        }

        $page = $this->getPage($video_id, $lang);

        if ($page->isTooManyRequests()) {
            throw new TooManyRequestsException($page);
        } elseif (!$page->isStatusOkay()) {
            throw new YouTubeException('Page failed to load. HTTP error: ' . $page->getResponse()->error);
        } elseif (!$page->getPlayerResponse()) {
            throw new YouTubeException('Page failed to load.');
        } elseif ($page->getPlayerResponse()->getPlayabilityStatusReason()) {
            throw new YouTubeException($page->getPlayerResponse()->getPlayabilityStatusReason());
        } elseif ($page->isVideoNotFound()) {
            throw new VideoNotFoundException();
        }

        // a giant JSON object holding useful data
        $youtube_config_data = $page->getYouTubeConfigData();

        $links = [];
        foreach ($client_ids as $i => $client_id) {
            // the most reliable way of fetching all download links no matter what
            // query: /youtubei/v1/player for some additional data
            $player_response = $this->getPlayerApiResponse($video_id, strtolower($client_id), $youtube_config_data);

            preg_match('/videoId"\s*:\s*"([^"]+)"/', print_r($player_response, true), $matches);
            if ($status_reason = $player_response->getPlayabilityStatusReason()) {
                throw new YouTubeException("Player response: {$status_reason}");
            } elseif (($matches[1] ?? '') != $video_id) {
                if ($player_error = $player_response->getErrorMessage()) {
                    throw new YouTubeException("Player response: {$player_error}");
                }
                // throws exception if player response does not belong to the requested video
                throw new YouTubeException('Invalid player response: got player response for video "'
                                           . ($matches[1] ?? '') . '" instead of "' . $video_id . '"');
            }

            // get player.js location that holds URL signature decipher function
            $player_url = $page->getPlayerScriptUrl();
            $response = $this->client->get($player_url);
            $player = new VideoPlayerJs($response);

            $parsed = SignatureLinkParser::parseLinks($player_response, $player);
            if (count($client_ids) > 1) {
                foreach ($parsed['adaptive'] as $k => $v) {
                    $parsed['adaptive'][$k]->_pref = -$i;
                }
            }
            $links = array_merge($links, $parsed['adaptive']);

            $dash_url ??= $parsed['dash'];
            $hls_url ??= $parsed['hls'];
            $sabr_url ??= $parsed['sabr'];
        }

        if (count($client_ids) > 1) {
            // sorting order: combined (smaller itag first) >> video (higher resolution >> smaller itag) >> audio (lower quality first)
            usort(
                $links,
                fn($a, $b) => $b->mimeType[0] <=> $a->mimeType[0]
                              ?: ($a->mimeType[0] == 'v'
                                    ? ((bool) $a->audioQuality ? $a->itag : 999)
                                    : str_replace(['_','D','H'], ['L','M','S'], substr($a->audioQuality, -4, 1)))
                                 <=> ($b->mimeType[0] == 'v'
                                    ? ((bool) $b->audioQuality ? $b->itag : 999)
                                    : str_replace(['_','D','H'], ['L','M','S'], substr($b->audioQuality, -4, 1)))
                              ?: $b->height <=> $a->height
                              ?: $a->itag <=> $b->itag
                              ?: $a->isDrc <=> $b->isDrc
                              ?: $b->_pref <=> $a->_pref
            );
            // remove duplicated formats
            foreach ($links as $k => $v) {
                if ($v->itag === ($i ?? 0) && $v->isDrc === ($c ?? false)) {
                    unset($links[$k]);
                } else {
                    unset($links[$k]->_pref);
                    $i = $v->itag;
                    $c = $v->isDrc;
                }
            }
        }

        // since we already have that information anyways...
        $info = $page->getVideoInfo($lang);
        $captions = $this->getCaptions($player_response);

        return new DownloadOptions($links, [$dash_url, $hls_url, $sabr_url], $info, $captions);
    }

    /**
     * @param PlayerApiResponse $player_response
     * @return YouTubeCaption[]
     */
    protected function getCaptions(PlayerApiResponse $player_response): array
    {
        if ($player_response) {
            return array_map(function ($item) {
                $baseUrl = Utils::arrayGet($item, 'baseUrl');

                $temp = new YouTubeCaption();
                $temp->name = Utils::arrayGetText($item, 'name');
                $temp->baseUrl = Utils::relativeToAbsoluteUrl($baseUrl, 'www.youtube.com');
                $temp->languageCode = Utils::arrayGet($item, 'languageCode');
                $vss = Utils::arrayGet($item, 'vssId');
                $temp->isAutomatic = Utils::arrayGet($item, 'kind') === 'asr' || strpos($vss, 'a.') !== false;
                return $temp;
            }, $player_response->getCaptionTracks());
        }

        return [];
    }

    public function getThumbnails(string $video_id): array
    {
        $video_id = Utils::extractVideoId($video_id);

        if ($video_id) {
            return [
                'default' => "https://i.ytimg.com/vi/{$video_id}/default.jpg",
                'medium' => "https://i.ytimg.com/vi/{$video_id}/mqdefault.jpg",
                'high' => "https://i.ytimg.com/vi/{$video_id}/hqdefault.jpg",
                'standard' => "https://i.ytimg.com/vi/{$video_id}/sddefault.jpg",
                'maxres' => "https://i.ytimg.com/vi/{$video_id}/maxresdefault.jpg",
            ];
        }

        return [];
    }
}
