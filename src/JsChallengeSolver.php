<?php

namespace YouTube;

use YouTube\Exception\YouTubeException;

class JsChallengeSolver
{
    protected static $jsrt = null;

    public function __construct()
    {
        self::$jsrt = new JsRuntime();
    }

    /**
     * @param array $n_params
     * @param array $signatures
     * @param string $js_code Complete source code for YouTube's player.js
     * @return string
     */
    public function solve(array $n_params, array $signatures, string $js_code): ?array
    {
        if (self::$jsrt->getApp()) {
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
                    'output_preprocessed' => false,
                ]);

                $str_args = [$core_js[0], $core_js[1], str_replace('\/', '/', $jsc)];
                if (self::$jsrt::$ver == '(remote)') {
                    $code_str = "%s\nObject.assign(globalThis, lib);\n%s\nJSON.stringify(jsc(%s));";
                } else {
                    $code_str = "%s\nObject.assign(globalThis, lib);\n%s\nconsole.log(JSON.stringify(jsc(%s)));";
                }

                try {
                    if ($result = self::$jsrt->run('JS challenges', $code_str, $str_args)) {
                        $result = json_decode($result, true);
                        if (is_array($result)
                            && !empty($result['responses'])
                            && $result['responses'][0]['type'] == 'result'
                            && $result['responses'][1]['type'] == 'result'
                        ) {
                            return $result['responses'];
                        }
                    }
                } catch (YouTubeException $e) {
                    throw new YouTubeException($e->getMessage());
                }
            }
        }

        return null;
    }

    protected function getJsCode(): array
    {
        $js_code = array();
        $context = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
            ),
        );
        $tmp_dir = self::$jsrt->getTempDir();
        if ($hashes = file_get_contents(
            'https://github.com/kclauhk/yt-ejs/raw/refs/heads/main/js/_hashes.json',
            false,
            stream_context_create($context))
        ) {
            $hashes = json_decode($hashes, true);
            if (is_array($hashes)) {
                $js_files = array_keys($hashes);
                if ($js_files == array_filter($js_files, function($v) use ($tmp_dir) {
                    return file_exists("{$tmp_dir}{$v}"); })
                ) {
                    if ($hashes == array_filter($hashes, function($v, $k) use (&$js_code, $tmp_dir) {
                            $c = file_get_contents("{$tmp_dir}{$k}");
                            if (strpos($k, 'lib') !== false) {
                                array_unshift($js_code, $c);
                            } elseif (strpos($k, 'core') !== false) {
                                $js_code[] = $c;
                            }
                            return hash('sha3-512', $c) == $v;
                        }, ARRAY_FILTER_USE_BOTH)
                    ) {
                        return $js_code;
                    }
                }
            }
        }
        // solver JS files outdated/not yet downloaded
        foreach (($js_files ?? ['yt.solver.lib.min.js', 'yt.solver.core.min.js']) as $js_file) {
            if (!$data = file_get_contents(
                "https://github.com/kclauhk/yt-ejs/raw/refs/heads/main/js/{$js_file}",
                false,
                stream_context_create($context))
            ) {
                throw new YouTubeException("Failed to download challenge solver \"{$js_file}\" script");
            } else {
                file_put_contents("{$tmp_dir}{$js_file}", $data);
                if (strpos($js_file, 'lib') !== false) {
                    array_unshift($js_code, $data);
                } elseif (strpos($js_file, 'core') !== false) {
                    $js_code[] = $data;
                }
            }
        }
        return $js_code;
    }
}
