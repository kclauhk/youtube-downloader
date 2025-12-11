<?php

namespace YouTube\Responses;

use YouTube\Models\Chapter;
use YouTube\Models\InitialPlayerResponse;
use YouTube\Models\VideoInfo;
use YouTube\Models\YouTubeConfigData;
use YouTube\VideoInfoMapper;
use YouTube\Utils\Utils;

class WatchVideoPage extends HttpResponse
{
    protected const REGEX_YTCFG = '/ytcfg\.set\s*\(\s*({.+})\s*\)\s*;/';
    protected const REGEX_INITIAL_PLAYER_RESPONSE = '/ytInitialPlayerResponse\s*=\s*({.+})\s*;/';
    protected const REGEX_INITIAL_DATA = '/ytInitialData\s*=\s*({.+})\s*;<\/script>/';
    protected const REGEX_MARKERS_MAP = '/markersMap"\s*:\s*\[\s*\{.+?(\{"chapters"\s*:\s*\[\s*\{.+?\}\}\})\}(?:\]\s*,\s*"|,\s*\{)/';

    public function isTooManyRequests(): bool
    {
        return
            strpos($this->getResponseBody(), 'We have been receiving a large volume of requests') !== false
            || strpos($this->getResponseBody(), 'systems have detected unusual traffic') !== false
            || strpos($this->getResponseBody(), '/recaptcha/') !== false;
    }

    public function isVideoNotFound(): bool
    {
        return strpos($this->getResponseBody(), '<title> - YouTube</title>') !== false;
    }

    public function hasPlayableVideo(): bool
    {
        $playerResponse = $this->getPlayerResponse();
        return $this->getResponse()->status == 200 && $playerResponse->isPlayabilityStatusOkay();
    }

    /**
     * Look for a player script URL. E.g:
     * <script src="//s.ytimg.com/yts/jsbin/player-fr_FR-vflHVjlC5/base.js" name="player/base"></script>
     *
     * @return string|null
     */
    public function getPlayerScriptUrl(): ?string
    {
        // check what player version that video is using
        if (preg_match('@<script\s*src="([^"]+player[^"]+js)@', $this->getResponseBody(), $matches)) {
            return Utils::relativeToAbsoluteUrl($matches[1], 'https://www.youtube.com');
        }

        return null;
    }

    // returns very similar response to what you get when you query /youtubei/v1/player
    public function getPlayerResponse(): ?InitialPlayerResponse
    {
        if (preg_match(self::REGEX_INITIAL_PLAYER_RESPONSE, $this->getResponseBody(), $matches)) {
            $data = json_decode($matches[1], true);
        }
        if (
            empty($data)
            && preg_match('/ytInitialPlayerResponse\s*=\s*({.+?})\s*;/', $this->getResponseBody(), $matches)
        ) {
            $data = json_decode($matches[1], true);
        }
        if (!empty($data)) {
            return new InitialPlayerResponse($data);
        }

        return null;
    }

    public function getYouTubeConfigData(): ?YouTubeConfigData
    {
        if (preg_match(self::REGEX_YTCFG, $this->getResponseBody(), $matches)) {
            $data = json_decode($matches[1], true);
            return new YouTubeConfigData($data);
        }

        return null;
    }

    protected function getInitialData(): ?array
    {
        // TODO: this does not appear for mobile
        if (preg_match(self::REGEX_INITIAL_DATA, $this->getResponseBody(), $matches)) {
            $json = $matches[1];
            return json_decode($json, true);
        }

        return null;
    }

    /**
     * Parse whatever info we can just from this page without making any additional requests
     * @return VideoInfo
     */
    public function getVideoInfo(?string $lang = null): ?VideoInfo
    {
        $playerResponse = $this->getPlayerResponse();

        if ($playerResponse) {
            $result = VideoInfoMapper::fromInitialPlayerResponse($playerResponse);

            if (preg_match('/^[a-zA-Z-]+$/', (string) $lang, $matches)) {
                $initialData = $this->getInitialData();

                if ($initialData) {
                    $info = VideoInfoMapper::fromInitialData($initialData);
                    $result->title = $info->title;
                    $result->description = $info->description;
                    $result->channelTitle = $info->channelTitle;
                }
            }

            $chapters = $this->getChapterInfo();
            if (!empty($chapters)) {
                $result->chapters = $chapters;
            }

            return $result;
        }

        return null;
    }

    /**
     * Parse chapter info from this page without making any additional requests
     * @return array|null;
     */
    protected function getChapterInfo(): ?array
    {
        if (preg_match(self::REGEX_MARKERS_MAP, $this->getResponseBody(), $matches)) {
            foreach (json_decode($matches[1], JSON_OBJECT_AS_ARRAY)['chapters'] as $chapter) {
                $ch = new Chapter();
                $ch->title = Utils::arrayGet($chapter, 'chapterRenderer.title');
                $ch->timeRangeStartMillis = Utils::arrayGet($chapter, 'chapterRenderer.timeRangeStartMillis');
                $ch->thumbnails = Utils::arrayGet($chapter, 'chapterRenderer.thumbnail.thumbnails');
                $result[] = $ch;
            }

            return $result;
        }

        return null;
    }
}
