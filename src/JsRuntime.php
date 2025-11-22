<?php

namespace YouTube;

use YouTube\Exception\YouTubeException;

class JsRuntime
{
    protected static ?string $app = '';
    protected static string $dir = '';
    protected static string $arg = '';
    protected static string $tmp = '';  // path of user-defined directory for temporary files
    protected static string $hash = ''; // hash value of API key of remote JS runtime
    public static string $ver = '';

    public function setApiKey(string $api_key)
    {
        static::$hash = hash('sha3-512', $api_key);
    }

    public function setPath(string $path): bool
    {
        if (preg_match('/^https?:\/\//', $path)) {
            if (empty(static::$hash)) {
                throw new YouTubeException("JS runtime error: API key required");
            } else {
                if (
                    $result = file_get_contents($path, false, stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header'  => "Content-Type: text/plain\r\n" .
                                     'X-Token: ' . static::$hash,
                        'content' => 'decodeURIComponent("%3Fx%3Dtest")',
                    ]]))
                ) {
                    if ($result == '?x=test') {
                        static::$app = $path;
                        static::$ver = '(remote)';
                        return true;
                    }
                } elseif (strpos(($http_response_header ?? [''])[0], ' 200 ') === false) {
                    throw new YouTubeException(
                        "JS runtime error: " . (empty($http_response_header) ? 'no response' : $http_response_header[0])
                    );
                }
            }
        } elseif ($this->isExecAvailable() && realpath($path)) {
            if (is_dir($path)) {
                static::$dir = $path;
                if ($this->getApp()) {
                    return true;
                }
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

        if (substr(static::$ver, 0, 4) == 'deno') {
            static::$arg = '--ext=js --no-code-cache --no-prompt --no-remote --no-lock --node-modules-dir=none --no-config';
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

    /**
     * @param string    $type       type of challenge
     * @param string    $codeStr    JavaScript code or format string of JavaScript code
     * @param array     $spValue    values for format specifiers
     * @param string    $value      original value of n/s
     * @return string/null          return value of JavaScript code or null if no return value
     * @throws YouTubeException
     */
    public function run(string $type, string $codeStr, array $spValue = [], string $value = null): ?string
    {
        if (empty(static::$app)) {
            throw new YouTubeException('JS runtime not available');
        }

        $result = null;
        try {
            $jsCode = empty($spValue) ? $codeStr : vsprintf($codeStr, $spValue);
        } catch (\Throwable $e) {
            throw new YouTubeException('Function code error (' . $e->getMessage() . ')');
        }

        if (static::$ver == '(remote)') {
            $header = "Content-Type: text/plain\r\n" .
                      'X-Token: ' . static::$hash;
            if (extension_loaded('zlib')) {
                $jsCode = gzencode($jsCode, 9);
                $header .= "\r\nContent-Encoding: gzip";
            }
            $result = file_get_contents(static::$app, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header'  => $header,
                    'content' => $jsCode,
                    'ignore_errors' => true,
                ]]));
            if (strpos(($http_response_header ?? [''])[0], ' 200 ') === false) {
                throw new YouTubeException(
                    "Status '" . (empty($http_response_header) ? 'no response' : $http_response_header[0]) . "'"
                );
            } elseif (preg_match('/content-encoding:\s*([^|]+)/', strtolower(implode('|', $http_response_header)), $match)) {
                if ($match[1] == 'gzip') {
                    $result = gzdecode($result);
                }
            }
        } else {
            $tmpFile = $this->getTempDir() . 'yt_' . hash('sha1', uniqid('', true)) . '.dump';
            if (file_put_contents($tmpFile, $jsCode) === false) {
                throw new YouTubeException('Failed to create files in ' . $this->getTempDir());
            } else {
                $result = exec(static::$app . ' ' . static::$arg . " {$tmpFile}", $output, $resultCode);
                unlink($tmpFile);
            }

            if (empty($result) || ($result == $value)) {
                $jsrExe = basename(static::$app);
                if (!empty($resultCode)) {
                    throw new YouTubeException("Exit status {$resultCode} ('{$jsrExe} " . static::$ver . "')");
                } else {
                    throw new YouTubeException(
                        "Failed to solve {$type} ("
                        . (!empty($spValue) ? "func:'" . substr($spValue[1], 0, 20) . "' " : '')
                        . "'{$jsrExe} " . static::$ver . "')"
                    );
                }
            }
        }

        return $result;
    }

    protected function isExecAvailable(): bool
    {
        if (!function_exists('exec') || @exec('echo EXEC') != 'EXEC') {
            throw new YouTubeException('exec() is not available therefore JS runtimes cannot be used');
        }

        return true;
    }

    public function setTempDir($path)
    {
        if (empty(realpath($path)) || !is_dir($path)) {
            trigger_error("{$path}: No such directory", E_USER_WARNING);
        } elseif (!is_writable(realpath($path))) {
            trigger_error("{$path}: Permission denied", E_USER_WARNING);
        } else {
            static::$tmp = realpath($path);
        }
    }

    public function getTempDir(): string
    {
        return realpath(static::$tmp ?: ini_get('upload_tmp_dir') ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR;
    }
}
