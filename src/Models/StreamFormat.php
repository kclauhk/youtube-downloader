<?php

namespace YouTube\Models;

#[\AllowDynamicProperties]
class StreamFormat extends JsonObject
{
    public ?int $itag = null;
    public ?string $mimeType = null;
    public ?int $bitrate = null;
    public ?int $width = null;
    public ?int $height = null;
    public ?int $contentLength = null;
    public ?string $quality = null;
    public ?string $qualityLabel = null;
    public ?string $audioQuality = null;
    public ?int $audioSampleRate = null;
    public bool $isDrc = false;
    public bool $isSr = false;  // Super resolution
    public ?string $url = null;
    public ?string $signatureCipher = null;
    public ?array $indexRange = null;
    public ?array $audioTrack = null;

    public function getCleanMimeType(): ?string
    {
        return trim(preg_replace('/;.*/', '', $this->mimeType));
    }

    public function hasRateBypass(): bool
    {
        return strpos($this->url, 'ratebypass') !== false;
    }
}
