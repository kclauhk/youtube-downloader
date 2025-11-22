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
     * @param array  $n_params
     * @param array  $signatures
     * @param string $js_code   Complete source code for YouTube's player.js
     * @return string
     */
    public function solve(array $n_params, array $signatures, string $js_code): ?array
    {
        if (self::$jsrt->getApp()) {
            if ($solverJs = $this->getJsCode()) {
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

                if (self::$jsrt::$ver == '(remote)') {
                    $codeStr = "%s\nObject.assign(globalThis, lib);\n%s\nJSON.stringify(jsc(%s));";
                } else {
                    $codeStr = "%s\nObject.assign(globalThis, lib);\n%s\nconsole.log(JSON.stringify(jsc(%s)));";
                }
                $codeArg = [$solverJs[0], $solverJs[1], str_replace('\/', '/', $jsc)];

                try {
                    if ($result = self::$jsrt->run('JS challenges', $codeStr, $codeArg)) {
                        $result = json_decode($result, true);
                        if (
                            is_array($result)
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
        $jsCode = array();
        $context = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
            ),
        );
        $tmpDir = self::$jsrt->getTempDir();
        if (
            $hashes = file_get_contents(
                'https://github.com/kclauhk/yt-ejs/raw/refs/heads/main/js/_hashes.json',
                false,
                stream_context_create($context)
            )
        ) {
            $hashes = json_decode($hashes, true);
            if (is_array($hashes)) {
                $jsFiles = array_keys($hashes);
                if (
                    $jsFiles == array_filter($jsFiles, function ($v) use ($tmpDir) {
                        return file_exists("{$tmpDir}{$v}");
                    })
                ) {
                    if (
                        $hashes == array_filter($hashes, function ($v, $k) use (&$jsCode, $tmpDir) {
                            $c = file_get_contents("{$tmpDir}{$k}");
                            if (strpos($k, 'lib') !== false) {
                                array_unshift($jsCode, $c);
                            } elseif (strpos($k, 'core') !== false) {
                                $jsCode[] = $c;
                            }
                            return hash('sha3-512', $c) == $v;
                        }, ARRAY_FILTER_USE_BOTH)
                    ) {
                        return $jsCode;
                    }
                }
            }
        } elseif (
            ($jsFiles = array_filter(['yt.solver.lib.min.js', 'yt.solver.core.min.js'], function ($v) use ($tmpDir) {
                    return file_exists("{$tmpDir}{$v}");
            })) && count($jsFiles) == 2
        ) {
            foreach ($jsFiles as $file) {
                $c = file_get_contents("{$tmpDir}{$file}");
                if (strpos($file, 'lib') !== false) {
                    array_unshift($jsCode, $c);
                } elseif (strpos($file, 'core') !== false) {
                    $jsCode[] = $c;
                }
            }
            return $jsCode;
        }
        // solver JS files outdated/not yet downloaded
        foreach (($jsFiles ?? ['yt.solver.lib.min.js', 'yt.solver.core.min.js']) as $file) {
            if (
                !$data = file_get_contents(
                    "https://github.com/kclauhk/yt-ejs/raw/refs/heads/main/js/{$file}",
                    false,
                    stream_context_create($context)
                )
            ) {
                throw new YouTubeException("Failed to download challenge solver \"{$file}\" script");
            } else {
                file_put_contents("{$tmpDir}{$file}", $data);
                if (strpos($file, 'lib') !== false) {
                    array_unshift($jsCode, $data);
                } elseif (strpos($file, 'core') !== false) {
                    $jsCode[] = $data;
                }
            }
        }
        return $jsCode;
    }
}
