<?php

namespace YouTube\Models;

/**
 * Class InitialPlayerResponse
 * JSON data that appears inside /watch?v= page [ytInitialPlayerResponse=]
 * @package YouTube\Models
 */
class InitialPlayerResponse extends JsonObject
{
    public ?array $responseContext = null;
    public ?array $playabilityStatus = null;
    public ?array $videoDetails = null;

    public function isPlayabilityStatusOkay(): bool
    {
        return $this->deepGet('playabilityStatus.status') == 'OK';
    }

    /**
     * If video is not playable, "reason" will include human-readable explanation
     * @return string|null
     */
    public function getPlayabilityStatusReason(): ?string
    {
        return implode(' ', $this->deepGet('playabilityStatus.messages') ?? []) ?: $this->deepGet('playabilityStatus.reason');
    }

    public function getVideoDetails(): ?array
    {
        return $this->deepGet('videoDetails');
    }
}