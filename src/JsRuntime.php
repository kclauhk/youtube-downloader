<?php

namespace YouTube;

use YouTube\Exception\YouTubeException;

class JsRuntime
{
    protected static ?string $app = '';
    protected static string $dir = '';
    protected static string $arg = '';
    public static string $ver = '';

    public function setPath(string $path): bool
    {
        if (preg_match('/^https?:\/\//', $path)) {
            if ($result = file_get_contents($path, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header'  => 'Content-Type: text/plain',
                    'content' => 'decodeURIComponent("%3Fx%3Dtest")',
                ]]))
            ) {
                if ($result == '?x=test') {
                    static::$app = $path;
                    static::$ver = '(remote)';
                    return true;
                }
            }
        } elseif ($this->isExecAvailable() && realpath($path)) {
            if (is_dir($path)) {
                static::$dir = $path;
                if ($this->getApp())
                    return true;
            } elseif (is_executable($path)) {
                static::$ver = exec($path . ' -v');
                static::$app = $path;
                return true;
            }
        }

        throw new YouTubeException("JS runtime not found: invalid path \"{$path}\"");
    }

    public function getApp(): ?string
    {
        if (static::$app != '') {
            return static::$app;
        } elseif ($this->isExecAvailable()) {
            static::$app = null;
        }

        $path = static::$dir ?: __DIR__;

        $files = array_filter(glob($path . DIRECTORY_SEPARATOR . 'deno{.exe,}', GLOB_BRACE), 'is_file');
        if (empty($files) && $path != __DIR__) {
            $files = glob(__DIR__ . DIRECTORY_SEPARATOR . 'deno{.exe,}', GLOB_BRACE);
        }

        if ($files) {
            foreach ($files as $file) {
                if (is_executable($file)) {
                    static::$ver = exec($file . ' -v');
                    static::$app = $file;
                    return static::$app;
                }
            }
            throw new YouTubeException("Failed to run \"{$files[0]}\"");
        } else {
            throw new YouTubeException('Deno not found');
        }

        return static::$app;
    }

    public function setArg(string $arguments)
    {
        static::$arg = $arguments;
    }

    public function getArg(): string
    {
        return static::$arg;
    }

    public function run(string $type, string $func_code, array $code_args, string $value = null): ?string
    {
        if (empty(static::$app))
            throw new YouTubeException('JS runtime not available');

        $result = null;
        try {
            $js_code = empty($code_args) ? $func_code : vsprintf($func_code, $code_args);
        } catch (\Throwable $e) {
            throw new YouTubeException('Function code error (' . $e->getMessage() . ')');
        }
        if (static::$ver == '(remote)') {
            $result = file_get_contents(static::$app, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header'  => 'Content-Type: text/plain',
                    'content' => $js_code,
                    'ignore_errors' => true,
            ]]));
            if (strpos(($http_response_header ?? [''])[0], ' 200 ') === false)
                throw new YouTubeException(
                    "Status '" . (empty($http_response_header) ? 'no response' : $http_response_header[0]) . "'");

        } else {
            $tmp_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yt_' . hash('sha1', uniqid('', true));
            if (file_put_contents("{$tmp_file}.dump", $js_code) === false) {
                throw new YouTubeException('Failed to write file to ' . sys_get_temp_dir());

            } else {
                $result = exec(static::$app . ' ' . static::$arg . " {$tmp_file}.dump", $output, $result_code);
                unlink("{$tmp_file}.dump");
            }

            if (empty($result) || ($result == $value)) {
                $jsr_exe = basename(static::$app);
                if (!empty($result_code)) {
                    throw new YouTubeException(
                        "Exit status {$result_code} (func:'" . substr($func_code, 0, 5) . "...' '{$jsr_exe} " . static::$ver . "')");
                } else {
                    throw new YouTubeException(
                        "Failed to solve {$type} ("
                        . (!empty($func_args) ? "func:'{$func_args[1]}' " : '')
                        . "'{$jsr_exe} " . static::$ver . "')");
                }
            }
        }

        return $result;
    }

    protected function isExecAvailable(): bool
    {
        if (!function_exists('exec') || @exec('echo EXEC') != 'EXEC')
            throw new YouTubeException('exec() is not available therefore JS runtimes cannot be used');

        return true;
    }
}
