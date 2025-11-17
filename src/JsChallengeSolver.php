<?php

namespace YouTube;

use YouTube\Exception\YouTubeException;

class JsChallengeSolver
{
    /**
     * @param array $n_params
     * @param array $signatures
     * @param string $js_code Complete source code for YouTube's player.js
     * @return string
     */
    public function solve(array $n_params, array $signatures, string $js_code): ?array
    {
        if (!function_exists('exec') || @exec('echo EXEC') != 'EXEC') {
            throw new YouTubeException('exec() has been disabled for security reasons');
        }

        if ($core_js = $this->getJsCode()) {
            $jsc = json_encode([
                'type' => 'player',
                'player' => $js_code,
                'requests' => [
                    [
                        'type' => 'n',
                        'challenges' => $n_params,
                    ],
                    [
                        'type' => 'sig',
                        'challenges' => $signatures,
                    ],
                ],
                'output_preprocessed' => true
            ]);
            $func_code = $core_js[0]
                . "\nObject.assign(globalThis, lib);\n"
                . $core_js[1]
                . "\nconsole.log(JSON.stringify(jsc("
                . str_replace('\/', '/', $jsc)
                . ')));';

            if ($result = $this->execute($func_code)) {
                $result = json_decode($result, true);
                if (is_array($result)
                    && !empty($result['responses'])
                    && $result['responses'][0]['type'] == 'result'
                    && $result['responses'][1]['type'] == 'result'
                ) {
                    return $result['responses'];
                }
            }
        }

        return null;
    }

    protected function getJsCode(): array
    {
        $lib_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yt.lib.json';
        $jsc_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yt.solver.core.js';
        $context = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
            ),
        );

        if (file_exists($lib_file) && file_exists($jsc_file)) {
            $hashes = json_decode(file_get_contents(
                'https://github.com/kclauhk/yt-solver/raw/refs/heads/main/js/_hashes.json',
                false,
                stream_context_create($context),
            ), true);
            $lib_json = file_get_contents($lib_file);
            $core_js = file_get_contents($jsc_file);
            if (hash('sha3-512', $lib_json) == $hashes['lib.json']
                && hash('sha3-512', $core_js) == $hashes['yt.solver.core.js']
            ) {
                return [
                    json_decode($lib_json)->data->code,
                    $core_js,
                ];
            }
        }

        if (!$lib_json = file_get_contents(
            'https://github.com/kclauhk/yt-solver/raw/refs/heads/main/js/lib.json',
            false,
            stream_context_create($context))
        ) {
            throw new YouTubeException('Failed to download file "lib.json"');
        }
        if (!$core_js = file_get_contents(
            'https://github.com/kclauhk/yt-solver/raw/refs/heads/main/js/yt.solver.core.js',
            false,
            stream_context_create($context))
        ) {
            throw new YouTubeException('Failed to download file "yt.solver.core.js"');
        }

        file_put_contents($lib_file, $lib_json);
        file_put_contents($jsc_file, $core_js);

        return [
            json_decode($lib_json)->data->code,
            $core_js,
        ];
    }

    protected function execute(string $func_code): string
    {
        $jsrt = new JsRuntime();

        if ($jsrt->getApp()) {
            $cache_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yt_' . hash('sha1', uniqid('', true));

            if (file_put_contents("{$cache_file}.dump", $func_code) === false) {
                throw new YouTubeException('Failed to write file to ' . sys_get_temp_dir());

            } else {
                $result = exec($jsrt->getApp() . ' ' . $jsrt->getArg() . " {$cache_file}.dump", $output, $result_code);
                unlink("{$cache_file}.dump");
            }

            if (!$result) {
                if (!empty($result_code)) {
                    throw new YouTubeException("Exit status {$result_code} '{$jsrt::$ver}'");
                } else {
                    $jsr_exe = basename($jsrt->getApp());
                    throw new YouTubeException("Failed to solve JS challenges ('{$jsr_exe} {$jsrt::$ver}')");
                }
            }
        }

        return $result ?: '';
    }
}