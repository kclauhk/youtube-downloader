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
    const REGEX_SID = array(
        'SAPISID' => '/\.youtube\.com[ \t]+.+SAPISID[ \t]+([^\s]+)/',
        'SAPISID1P' => '/\.youtube\.com[ \t]+.+__Secure-1PAPISID[ \t]+([^\s]+)/',
        'SAPISID3P' => '/\.youtube\.com[ \t]+.+__Secure-3PAPISID[ \t]+([^\s]+)/'
    );

    protected Browser $client;

    function __construct()
    {
        $this->client = new Browser();
        $this->api_clients = new PlayerApiClients();

        $this->client->setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36');
    }

    // Specify network options to be used in all network requests
    public function getBrowser(): Browser
    {
        return $this->client;
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
            'q' => $query
        ]);
        $json = json_decode($response->body, true);

        if (is_array($json) && count($json) >= 2) {
            return $json[1];
        }

        return [];
    }

    public function getVideoInfo(string $video_id): ?VideoInfo
    {
        $page = $this->getPage($video_id);
        return $page->getVideoInfo();
    }

    public function getPage(string $url): WatchVideoPage
    {
        $video_id = Utils::extractVideoId($url);

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
        $sid_hash = array();
        foreach (['SAPISID3P', 'SAPISID', 'SAPISID1P', 'SAPISID3P'] as $i => $scheme) {
            if (preg_match(self::REGEX_SID[$scheme], $cookies, $matches)) {
                $sid = trim($matches[1]);
            }
            if ($i > 0 && !empty($user_session_id) && !empty($sid)) {
                $sid_hash[] = "{$scheme}HASH {$timestamp}_" . sha1("{$user_session_id} {$timestamp} {$sid} https://www.youtube.com") . '_u';
            }
        }

        return empty($sid_hash) ? [] : array(
            'Authorization' => implode(' ', $sid_hash),
            'X-Goog-Authuser' => $session_index,
            'X-Youtube-Bootstrap-Logged-In' => true,
        );
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
            foreach (['hl' => 'en', 'timeZone' => 'UTC', 'utcOffsetMinutes' => 0] as $k => $v){
                $context['client'][$k] = $v;
            }
        }

        $response = $this->client->post('https://www.youtube.com/youtubei/v1/player?key=' . $configData->getApiKey(), json_encode([
                'context' => $context,
                'videoId' => $video_id,
                'playbackContext' => [
                    'contentPlaybackContext' => [
                        'html5Preference' => 'HTML5_PREF_WANTS',
                        'signatureTimestamp' => (int)$sig_timestamp,
                    ]
                ],
                'racyCheckOk' => true,
            ]), array_merge(array_filter([
                'Content-Type' => 'application/json',
                'Origin' => 'https://www.youtube.com',
                'X-Origin' => 'https://www.youtube.com',
                'X-Goog-PageId' => $page_id,
                'X-Goog-Visitor-Id' => $visitor_id,
                'X-Youtube-Client-Name' => $clients[$client_id]['client_name'],
                'X-Youtube-Client-Version' => $context['client']['clientVersion'],
            ]), $this->setAuthHeaders($session_index, $user_session_id)));

        return new PlayerApiResponse($response);
    }

    /**
     *
     * @param string $video_id
     * @param array/string $extra     array or comma-delimited string of player client IDs (the 1st in the list has the highest preference)
     * @return DownloadOptions
     * @throws TooManyRequestsException
     * @throws VideoNotFoundException
     * @throws YouTubeException
     */
    public function getDownloadLinks(string $video_id, $extra = 'ios'): DownloadOptions
    {
        $video_id = Utils::extractVideoId($video_id);

        if (!$video_id) {
            throw new \InvalidArgumentException('Invalid video ID: ' . $video_id);
        }

        $page = $this->getPage($video_id);

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
        $client_ids = is_array($extra) ? $extra : explode(',', preg_replace('/\s+/', '', $extra));
        foreach ($client_ids as $i => $client_id) {
            // the most reliable way of fetching all download links no matter what
            // query: /youtubei/v1/player for some additional data
            $player_response = $this->getPlayerApiResponse($video_id, strtolower($client_id), $youtube_config_data);

            // throws exception if player response does not belong to the requested video
            preg_match('/videoId"\s*:\s*"([^"]+)"/', print_r($player_response, true), $matches);
            if (($matches[1] ?? '') != $video_id)
                throw new YouTubeException('Invalid player response: got player response for video "' . ($matches[1] ?? '') . '" instead of "' . $video_id .'"');

            // get player.js location that holds URL signature decipher function
            $player_url = $page->getPlayerScriptUrl();
            $response = $this->client->get($player_url);
            $player = new VideoPlayerJs($response);

            $parsed = SignatureLinkParser::parseLinks($player_response, $player);
            foreach ($parsed as $k => $v) {
                $parsed[$k]->pref = -$i;
            }
            $links = array_merge($links, $parsed);

            $hlsManifestUrl = $hlsManifestUrl ?? $player_response->getHlsManifestUrl();
        }

        if (count($client_ids) > 1) {
            // sorting order: combined (smaller itag first) >> video (higher resolution >> smaller itag) >> audio (lower quality first)
            usort($links, fn($a,$b) => $b->mimeType[0] <=> $a->mimeType[0] ?:
                                       ($a->mimeType[0]=='v' ? ((bool)$a->audioQuality ? $a->itag : 999) : str_replace(['_','D','H'], ['L','M','S'], substr($a->audioQuality,-4,1)))
                                           <=> ($b->mimeType[0]=='v' ? ((bool)$b->audioQuality ? $b->itag : 999) : str_replace(['_','D','H'], ['L','M','S'], substr($b->audioQuality,-4,1))) ?:
                                       $b->height <=> $a->height ?:
                                       $a->itag <=> $b->itag ?:
                                       $a->isDrc <=> $b->isDrc ?:
                                       $b->pref <=> $a->pref
            );
            // remove duplicated formats
            foreach ($links as $k => $v) {
                if ($v->itag === ($i ?? 0) && $v->isDrc === ($c ?? false)) {
                    unset($links[$k]);
                } else {
                    unset($links[$k]->pref);
                    $i = $v->itag;
                    $c = $v->isDrc;
                }
            }
        }

        // since we already have that information anyways...
        $info = $page->getVideoInfo();
        $captions = $this->getCaptions($player_response);

        return new DownloadOptions($links, $hlsManifestUrl, $info, $captions);
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
                'default' => "https://img.youtube.com/vi/{$video_id}/default.jpg",
                'medium' => "https://img.youtube.com/vi/{$video_id}/mqdefault.jpg",
                'high' => "https://img.youtube.com/vi/{$video_id}/hqdefault.jpg",
                'standard' => "https://img.youtube.com/vi/{$video_id}/sddefault.jpg",
                'maxres' => "https://img.youtube.com/vi/{$video_id}/maxresdefault.jpg",
            ];
        }

        return [];
    }
}
