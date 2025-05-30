<?php

namespace YouTube\Utils;

class ITagUtils
{
    public static function downloadFormats(): array
    {
        $data = file_get_contents("https://raw.githubusercontent.com/ytdl-org/youtube-dl/master/youtube_dl/extractor/youtube.py");

        // https://github.com/ytdl-org/youtube-dl/blob/master/youtube_dl/extractor/youtube.py#L429
        if (preg_match('/_formats = ({(.*?)})\s*_/s', $data, $matches)) {

            $json = $matches[1];

            // only "double" quotes are valid in JSON
            $json = str_replace("'", "\"", $json);

            // remove comments
            $json = preg_replace('/\s*#(.*)/', '', $json);

            // remove comma from last JSON item
            $json = preg_replace('/,\s*}/', '}', $json);

            return json_decode($json, true);
        }

        return array();
    }

    public static function transformFormats(array $formats): array
    {
        $results = [];

        foreach ($formats as $itag => $format) {

            $temp = [];

            if (!empty($format['ext'])) {
                $temp[] = $format['ext'];
            }

            if (!empty($format['vcodec'])) {
                $temp[] = 'video';
            }

            if (!empty($format['height'])) {
                $temp[] = $format['height'] . 'p';
            }

            if (!empty($format['acodec']) && $format['acodec'] !== 'none') {
                $temp[] = 'audio';
            }

            $results[$itag] = implode(', ', $temp);
        }

        return $results;
    }

    public static function parseItagInfo(int $itag): string
    {
        if (array_key_exists($itag, static::$itag_detailed)) {
            return static::$itag_detailed[$itag];
        }

        return 'Unknown';
    }

    // itag info does not change frequently, that is why we cache it here as a plain static array
    private static array $itag_detailed = array(
        5 => 'flv, video, 240p, audio',
        6 => 'flv, video, 270p, audio',
        13 => '3gp, video, audio',
        17 => '3gp, video, 144p, audio',
        18 => 'mp4, video, 360p, audio',
        22 => 'mp4, video, 720p, audio',
        34 => 'flv, video, 360p, audio',
        35 => 'flv, video, 480p, audio',
        36 => '3gp, video, audio',
        37 => 'mp4, video, 1080p, audio',
        38 => 'mp4, video, 3072p, audio',
        43 => 'webm, video, 360p, audio',
        44 => 'webm, video, 480p, audio',
        45 => 'webm, video, 720p, audio',
        46 => 'webm, video, 1080p, audio',
        59 => 'mp4, video, 480p, audio',
        78 => 'mp4, video, 480p, audio',
        82 => 'mp4, video, 360p, audio',
        83 => 'mp4, video, 480p, audio',
        84 => 'mp4, video, 720p, audio',
        85 => 'mp4, video, 1080p, audio',
        100 => 'webm, video, 360p, audio',
        101 => 'webm, video, 480p, audio',
        102 => 'webm, video, 720p, audio',
        91 => 'mp4, video, 144p, audio',
        92 => 'mp4, video, 240p, audio',
        93 => 'mp4, video, 360p, audio',
        94 => 'mp4, video, 480p, audio',
        95 => 'mp4, video, 720p, audio',
        96 => 'mp4, video, 1080p, audio',
        132 => 'mp4, video, 240p, audio',
        151 => 'mp4, video, 72p, audio',
        133 => 'mp4, video, 240p',
        134 => 'mp4, video, 360p',
        135 => 'mp4, video, 480p',
        136 => 'mp4, video, 720p',
        137 => 'mp4, video, 1080p',
        138 => 'mp4, video',
        160 => 'mp4, video, 144p',
        212 => 'mp4, video, 480p',
        264 => 'mp4, video, 1440p',
        298 => 'mp4, video, 720p',      // 60 FPS
        299 => 'mp4, video, 1080p',     // 60 FPS
        266 => 'mp4, video, 2160p',
        139 => 'm4a, audio',
        140 => 'm4a, audio',
        141 => 'm4a, audio',
        256 => 'm4a, audio',
        258 => 'm4a, audio',
        325 => 'm4a, audio',
        328 => 'm4a, audio',
        167 => 'webm, video, 360p',
        168 => 'webm, video, 480p',
        169 => 'webm, video, 720p',
        170 => 'webm, video, 1080p',
        218 => 'webm, video, 480p',
        219 => 'webm, video, 480p',
        278 => 'webm, video, 144p',
        242 => 'webm, video, 240p',
        243 => 'webm, video, 360p',
        244 => 'webm, video, 480p',
        245 => 'webm, video, 480p',
        246 => 'webm, video, 480p',
        247 => 'webm, video, 720p',
        248 => 'webm, video, 1080p',
        271 => 'webm, video, 1440p',
        272 => 'webm, video, 2160p',
        302 => 'webm, video, 720p',     // 60 FPS
        303 => 'webm, video, 1080p',    // 60 FPS
        308 => 'webm, video, 1440p',    // 60 FPS
        313 => 'webm, video, 2160p',
        315 => 'webm, video, 2160p',    // 60 FPS
        171 => 'webm, audio',
        172 => 'webm, audio',
        249 => 'webm, audio',
        250 => 'webm, audio',
        251 => 'webm, audio',
        600 => 'webm, audio',           // ultralow
        599 => 'm4a, audio',            // ultralow
        256 => 'm4a, audio',            // 6-CH
        258 => 'm4a, audio',            // 6-CH
        328 => 'm4a, audio',            // 6-CH, ec-3
        380 => 'm4a, audio',            // 6-CH, ac-3
        598 => 'webm, video, 144p',     // 15 FPS
        330 => 'webm, video, 144p',     // 60 FPS, HDR
        331 => 'webm, video, 240p',     // 60 FPS, HDR
        332 => 'webm, video, 360p',     // 60 FPS, HDR
        333 => 'webm, video, 480p',     // 60 FPS, HDR
        334 => 'webm, video, 720p',     // 60 FPS, HDR
        335 => 'webm, video, 1080p',    // 60 FPS, HDR
        336 => 'webm, video, 1440p',    // 60 FPS, HDR
        337 => 'webm, video, 2160p',    // 60 FPS, HDR
        597 => 'mp4, video, 144p',      // 15 FPS
        394 => 'mp4, video, 144p',      // AV1
        395 => 'mp4, video, 240p',      // AV1
        396 => 'mp4, video, 360p',      // AV1
        397 => 'mp4, video, 480p',      // AV1
        398 => 'mp4, video, 720p',      // AV1
        399 => 'mp4, video, 1080p',     // AV1
        400 => 'mp4, video, 1440p',     // AV1
        401 => 'mp4, video, 2160p',     // AV1
        571 => 'mp4, video, 4320p',     // AV1
        694 => 'mp4, video, 144p',      // AV1, 60 FPS, HDR
        695 => 'mp4, video, 240p',      // AV1, 60 FPS, HDR
        696 => 'mp4, video, 360p',      // AV1, 60 FPS, HDR
        697 => 'mp4, video, 480p',      // AV1, 60 FPS, HDR
        698 => 'mp4, video, 720p',      // AV1, 60 FPS, HDR
        699 => 'mp4, video, 1080p',     // AV1, 60 FPS, HDR
        700 => 'mp4, video, 1440p',     // AV1, 60 FPS, HDR
        701 => 'mp4, video, 2160p',     // AV1, 60 FPS, HDR
        702 => 'mp4, video, 4320p',     // AV1, 60 FPS, HDR
        773 => 'm4a, audio',            // IAMF/Eclipsa Audio
    );
}