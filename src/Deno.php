<?php

namespace YouTube;

use YouTube\Exception\YouTubeException;

class Deno
{
    protected static $_deno_dir;
    protected static $_deno_app;

    public static function setPath(string $path)
    {
        static::$_deno_dir = $path;
    }

    public static function getApp(): ?string
    {
        if (static::$_deno_app) {
            return static::$_deno_app;
        }

        $path = static::$_deno_dir ?: __DIR__;

        $files = glob($path . DIRECTORY_SEPARATOR . 'deno{.exe,}', GLOB_BRACE);
        if (empty($files) && $path != __DIR__) {
            $files = glob(__DIR__ . DIRECTORY_SEPARATOR . 'deno{.exe,}', GLOB_BRACE);
        }

        try {
            if ($files) {
                foreach ($files as $file) {
                    if (is_executable($file)) {
                        static::$_deno_app = $file;
                        return static::$_deno_app;
                    }
                }
                throw new YouTubeException("Failed to run {$files[0]}");
            } else {
                throw new YouTubeException('Deno not found');
            }
        } catch (YouTubeException $e) {
            throw new YouTubeException($e->getMessage());

            return null;
        }
    }
}
