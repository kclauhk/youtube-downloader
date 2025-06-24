<?php

namespace YouTube;

use YouTube\Exception\YouTubeException;

class JsRuntime
{
    protected static string $app = '';
    protected static string $dir = '';
    protected static string $cmd = '';
    public static string $ver = '';

    public function setPath(string $path): bool
    {
        if (is_dir($path)) {
            static::$dir = $path;
            if ($this->getApp())
                return true;
        } else if (is_executable($path)) {
            static::$ver = @exec($path . ' -v');
            static::$app = $path;
            return true;
        }

        throw new YouTubeException("Deno not found: invalid path \"{$path}\"");
        return false;
    }

    public function getApp(): ?string
    {
        if (static::$app) {
            return static::$app;
        }

        $path = static::$dir ?: __DIR__;

        $files = glob($path . DIRECTORY_SEPARATOR . 'deno{.exe,}', GLOB_BRACE);
        if (empty($files) && $path != __DIR__) {
            $files = glob(__DIR__ . DIRECTORY_SEPARATOR . 'deno{.exe,}', GLOB_BRACE);
        }

        if ($files) {
            foreach ($files as $file) {
                if (is_executable($file)) {
                    static::$ver = @exec($file . ' -v');
                    static::$app = $file;
                    return static::$app;
                }
            }
            throw new YouTubeException("Failed to run \"{$files[0]}\"");
        } else {
            throw new YouTubeException('Deno not found');
        }

        return null;
    }

    public function setCmd(string $command)
    {
        static::$cmd = $command;
    }

    public function getCmd(): string
    {
        return static::$cmd;
    }
}