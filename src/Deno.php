<?php

namespace YouTube;

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

        if ($files) {
            static::$_deno_app = $files[0];
            return static::$_deno_app;
        }

        return null;
    }
}
