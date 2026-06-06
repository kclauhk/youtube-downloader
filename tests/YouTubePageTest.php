<?php

namespace YouTube\Tests;

use Curl\Response;
use YouTube\YouTubeDownloader;
use YouTube\Responses\WatchVideoPage;

class YouTubePageTest extends TestCase
{
    public function test_get_video_info(): void
    {
        $youtube = new YouTubeDownloader();

        $agents = [
            self::UA_FIREFOX,
            self::UA_EDGE,
            self::UA_IPHONE,
        ];

        foreach ($agents as $agent) {
            //$youtube->getBrowser()->setUserAgent($agent);

            //$page = $youtube->getPage(self::BUNNY_VIDEO_ID);

            $i = array_search($agent, $agents);
            $response = new Response();
            $response->body = file_get_contents(dirname(dirname(__FILE__)) . "/etc/youtube_page{$i}.html");
            $page = new WatchVideoPage($response);

            $info = $page->getVideoInfo();

            // we should be able to parse at least these
            $this->assertNotEmpty($info->id);
            $this->assertNotEmpty($info->title);
            $this->assertNotEmpty($info->channelTitle);
            $this->assertNotEmpty($info->description);
            $this->assertNotEmpty($info->uploadDate);
        }
    }
}
