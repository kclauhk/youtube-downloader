<?php

namespace YouTube;

use Curl\Response;
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
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.5 Safari/605.1.15'
        );
    }

    // Specify network options to be used in all network requests
    public function getBrowser(): Browser
    {
        return $this->client;
    }

    // Client info to be used in API call
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

        $context = $clients[$client_id]['context'];
        if (!empty($context['client']['userAgent'])) {
            $this->client->setUserAgent($context['client']['userAgent']);
        }
        $headers = [];
        if (!empty($context['thirdParty']['embedUrl'])) {
            $headers['Referer'] = $context['thirdParty']['embedUrl'];
        }
        if (!empty($clients[$client_id]['config_url'])) {
            $config_url = str_replace('{$video_id}', $video_id, $clients[$client_id]['config_url']);
            $config = new WatchVideoPage($this->client->get($config_url, [], $headers));
            if (!empty($config->getYouTubeConfigData())) {
                $configData = $config->getYouTubeConfigData();
            }
            $context = $configData->getContext();
            $context = Utils::arrayMergeRecursive($context, $clients[$client_id]['context']);
        }
        foreach (['hl' => 'en', 'timeZone' => 'UTC', 'utcOffsetMinutes' => 0] as $k => $v) {
            $context['client'][$k] = $v;
        }

        if (strpos(print_r($configData, true), '&embeds_enable_encrypted_host_flags_enforcement=true') !== false) {
            $encrypted_context = $configData->getEncryptedHostFlags();
        } else {
            $encrypted_context = null;
        }

        $response = $this->client->post(
            'https://www.youtube.com/youtubei/v1/player?prettyPrint=false',
            json_encode(
                array_filter(
                    [
                        'videoId' => $video_id,
                        'context' => $context,
                        'playbackContext' => [
                            'contentPlaybackContext' => array_filter(
                                [
                                    'html5Preference' => 'HTML5_PREF_WANTS',
                                    'signatureTimestamp' => $sig_timestamp,
                                    'encryptedHostFlags' => $encrypted_context,
                                ],
                                function ($v) {
                                    return !is_null($v);
                                }
                            ),
                        ],
                        'racyCheckOk' => true,
                        'params' => ($clients[$client_id]['params'] ?? null),
                    ],
                    function ($v) {
                        return !is_null($v);
                    }
                )
            ),
            array_merge(
                array_filter(
                    [
                        'Content-Type' => 'application/json',
                        'Origin' => 'https://www.youtube.com',
                        'Referer' => $config_url ?? null,
                        'X-Origin' => 'https://www.youtube.com',
                        'X-Goog-PageId' => $page_id,
                        'X-Goog-Visitor-Id' => $visitor_id,
                        'X-Youtube-Client-Name' => ($clients[$client_id]['client_name'] ?? null),
                        'X-Youtube-Client-Version' => $context['client']['clientVersion'],
                    ],
                    function ($v) {
                        return !is_null($v);
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

        $clients = $this->api_clients::$clients;
        $lang = null;
        $client_ids = ['android_vr'];
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
            if (
                !empty($client_ids)
                && (count(array_filter($client_ids, function ($v) use ($clients) {
                    return isset($clients[$v]);
                })) === 0)
            ) {
                throw new YouTubeException('Player client "' . implode('", "', $client_ids) . '" not defined');
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
        $dash_url = $hls_url = $sabr_url = null;
        foreach ($client_ids as $i => $client_id) {
            try {
                if (empty($clients[$client_id]['skip_api'])) {
                    // the most reliable way of fetching all download links no matter what
                    // query: /youtubei/v1/player for some additional data
                    $player_response = $this->getPlayerApiResponse($video_id, strtolower($client_id), $youtube_config_data);
                } else {
                    // use InitialPlayerResponse
                    $response = new Response();
                    $response->body = json_encode($page->getPlayerResponse()->toArray());
                    $player_response = new PlayerApiResponse($response);
                }

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

                $parsed = SignatureLinkParser::parseLinks($player_response);
                if (count($client_ids) > 1) {
                    foreach ($parsed['adaptive'] as $k => $v) {
                        $parsed['adaptive'][$k]->_pref = -$i;
                    }
                }
                $links = array_merge($links, $parsed['adaptive']);

                $dash_url ??= $parsed['dash'];
                $hls_url ??= $parsed['hls'];
                $sabr_url ??= $parsed['sabr'];
            } catch (YouTubeException $e) {
                if ($i === count($client_ids) - 1 && empty($links)) {
                    throw new YouTubeException($e->getMessage());
                }
            }
        }

        if (count($client_ids) > 1) {
            // sorting order:   combined (smaller itag first)
            //                  >> video (higher resolution >> smaller itag)
            //                  >> audio (lower quality first)
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
