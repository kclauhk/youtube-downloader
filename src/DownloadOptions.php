<?php

namespace YouTube;

use YouTube\Models\StreamFormat;
use YouTube\Models\VideoInfo;
use YouTube\Utils\Utils;

// TODO: rename DownloaderResponse
class DownloadOptions
{
    /** @var StreamFormat[] $formats */
    private array $formats = [];

    /** @var VideoInfo|null */
    private VideoInfo $info;

    /** @var array|null */
    private ?array $captions;

    /** @var string|null */
    private ?string $dashManifestUrl;
    private ?string $hlsManifestUrl;
    private ?string $serverAbrStreamingUrl;

    public function __construct(array $formats, array $streaming_urls, VideoInfo $info, ?array $captions)
    {
        $this->formats = $formats;
        $this->info = $info;
        $this->captions = $captions;
        $this->dashManifestUrl = $streaming_urls[0];
        $this->hlsManifestUrl = $streaming_urls[1];
        $this->serverAbrStreamingUrl = $streaming_urls[2];
    }

    /**
     * @return StreamFormat[]
     */
    public function getAllFormats(): array
    {
        return $this->formats;
    }

    /**
     * @return VideoInfo|null
     */
    public function getInfo(): VideoInfo
    {
        return $this->info;
    }

    /**
     * @return array|null
     */
    public function getCaptions(): ?array
    {
        return $this->captions;
    }

    // Will not include Videos with Audio
    public function getVideoFormats(): array
    {
        return Utils::arrayFilterReset($this->getAllFormats(), function ($format) {
            /** @var $format StreamFormat */
            return strpos($format->mimeType, 'video') === 0 && empty($format->audioQuality);
        });
    }

    public function getAudioFormats(): array
    {
        return Utils::arrayFilterReset($this->getAllFormats(), function ($format) {
            /** @var $format StreamFormat */
            return strpos($format->mimeType, 'audio') === 0;
        });
    }

    /**
     * @return StreamFormat[]
     */
    public function getCombinedFormats(): array
    {
        return Utils::arrayFilterReset($this->getAllFormats(), function ($format) {
            /** @var $format StreamFormat */
            return strpos($format->mimeType, 'video') === 0 && !empty($format->audioQuality);
        });
    }

    /**
     * @return StreamFormat|null
     */
    public function getFirstCombinedFormat(): ?StreamFormat
    {
        $combined = $this->getCombinedFormats();
        return count($combined) ? $combined[0] : null;
    }

    /**
     * @return string|null
     */
    public function getDashManifestUrl(): ?string
    {
        return $this->dashManifestUrl;
    }

    /**
     * @return string|null
     */
    public function getHlsManifestUrl(): ?string
    {
        return $this->hlsManifestUrl;
    }

    /**
     * @return string|null
     */
    public function getServerAbrUrl(): ?string
    {
        return $this->serverAbrStreamingUrl;
    }
}
