<?php

namespace YouTube;

use YouTube\Models\InitialPlayerResponse;
use YouTube\Models\VideoInfo;
use YouTube\Utils\Utils;

class VideoInfoMapper
{
    public static function fromInitialPlayerResponse(InitialPlayerResponse $initialPlayerResponse): VideoInfo
    {
        // "videoDetails" appears in a bunch of other places too
        $videoDetails = $initialPlayerResponse->getVideoDetails();

        $result = new VideoInfo();

        $result->id = Utils::arrayGet($videoDetails, 'videoId');
        $result->title = Utils::arrayGet($videoDetails, 'title');
        $result->description = Utils::arrayGet($videoDetails, 'shortDescription');

        $result->channelId = Utils::arrayGet($videoDetails, 'channelId');
        $result->channelTitle = Utils::arrayGet($videoDetails, 'author');

        $microformat = Utils::arrayGet($initialPlayerResponse->toArray(), 'microformat');

        $date = Utils::arrayGet($microformat, 'playerMicroformatRenderer.uploadDate');

        $result->uploadDate = new \DateTime($date);

        $result->category = Utils::arrayGet($microformat, 'playerMicroformatRenderer.category');

        $result->durationSeconds = Utils::arrayGet($videoDetails, 'lengthSeconds');
        $result->viewCount = Utils::arrayGet($videoDetails, 'viewCount');

        $result->keywords = Utils::arrayGet($videoDetails, 'keywords', []);
        $result->regionsAllowed = Utils::arrayGet($microformat, 'playerMicroformatRenderer.availableCountries', []);

        return $result;
    }

    public static function fromInitialData($initialData, VideoInfo $referenceInfo): VideoInfo
    {
        $primaryInfo = Utils::arrayGet(
            $initialData,
            'contents.twoColumnWatchNextResults.results.results.contents.0.videoPrimaryInfoRenderer',
        );
        $secondaryInfo = Utils::arrayGet(
            $initialData,
            'contents.twoColumnWatchNextResults.results.results.contents.1.videoSecondaryInfoRenderer',
        );

        $result = new VideoInfo();

        if ($primaryInfo) {
            $result->title = Utils::arrayGetText($primaryInfo, 'title', $referenceInfo->title);
        }
        if ($secondaryInfo) {
            $result->description = self::getDescription(
                Utils::arrayGet($secondaryInfo, 'attributedDescription', []),
                $referenceInfo->description
            );
            $result->channelTitle = Utils::arrayGetText(
                Utils::arrayGet($secondaryInfo, 'owner.videoOwnerRenderer', []),
                'title',
                $referenceInfo->channelTitle
            );
        }

        return $result;
    }

    private static function getDescription(array $attributedDescription, ?string $referenceDesc): string
    {
        $content = Utils::arrayGet($attributedDescription, 'content');
        if (!$content) {
            return $referenceDesc;
        }

        // convert to UTF-16LE to ease handling of emoticons
        $content = mb_convert_encoding($content, 'UTF-16LE', 'UTF-8');
        if ($cmdRuns = Utils::arrayGet($attributedDescription, 'commandRuns')) {
            $urls = [];
            foreach ($cmdRuns as $v) {
                if ($c = Utils::arrayGet($v, 'onTap.innertubeCommand')) {
                    if ($url = Utils::arrayGet($c, 'browseEndpoint.canonicalBaseUrl')) {
                        if ($url[0] == '/' && strpos($url, '://') === false) {
                            $url = "https://www.youtube.com{$url}";
                        }
                    } elseif ($videoId = Utils::arrayGet($c, 'reelWatchEndpoint.videoId')) {
                            $url = "https://www.youtube.com/shorts/{videoId}";
                    } elseif ($url = Utils::arrayGet($c, 'urlEndpoint.url')) {
                        if (strpos($url, '://www.youtube.com/redirect?') !== false) {
                            parse_str($url, $qs);
                            $url = $qs['q'];
                        }
                    } elseif ($videoId = Utils::arrayGet($c, 'watchEndpoint.videoId')) {
                        if (strpos($referenceDesc, "://youtu.be/{$videoId}") !== false) {
                            $url = "https://youtu.be/{$videoId}";
                        } else {
                            if (strpos($referenceDesc, "://music.youtube.com/watch?v={$videoId}")) {
                                $domain = 'https://music.youtube.com';
                            } else {
                                $domain = 'https://www.youtube.com';
                            }
                            if ($path = Utils::arrayGet($c, 'commandMetadata.webCommandMetadata.url')) {
                                $url = "{$domain}{$path}";
                            } else {
                                $playlistId = Utils::arrayGet($c['watchEndpoint'], 'playlistId');
                                $l = $playlistId ? "&list={$playlistId}" : '';
                                $index = Utils::arrayGet($c['watchEndpoint'], 'index', $default=0);
                                $i = $index > 1 ? "&index={$index}" : '';
                                $startTime = Utils::arrayGet($c['watchEndpoint'], 'startTimeSeconds', $default=0);
                                $t = $startTime > 0 ? "&t={$startTime}s" : '';
                                $url = "{$domain}/watch?v={$videoId}{$l}{$i}{$t}";
                            }
                        }
                    }
                    if (!empty($url)) {
                        if (
                            $url[-1] == '/'
                            && preg_match('@' . preg_quote($url) . '(?:\s|$)@', $referenceDesc) === 0
                            && preg_match('@' . preg_quote(rtrim($url, '/')) . '(?:\s|$)@', $referenceDesc)
                        ) {
                            $url = rtrim($url, '/');
                        }
                        $urls[] = array(
                            'startIndex' => $v['startIndex'],
                            'length' => $v['length'],
                            'url' => $url,
                        );
                    }
                }
            }
            foreach (array_reverse($urls) as $v) {
                $url = mb_convert_encoding($v['url'], 'UTF-16LE', 'UTF-8');
                // double the index due to UTF-16LE conversion
                $fore = substr($content, 0, $v['startIndex'] * 2);
                $last = substr($content, ($v['startIndex'] + $v['length']) * 2);
                $content = "{$fore}{$url}{$last}";
            }
        }

        return mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
    }
}
