<?php

namespace YouTube;

use YouTube\Exception\YouTubeException;

class NSigDecoder
{
    /**
     * @param string $n_param
     * @param string $js_code Complete source code for YouTube's player.js
     * @return string
     */
    public function decode(string $n_param, string $js_code): string
    {
        $func_name = $this->parseFunctionName($js_code);

        if (!$func_name) {
            throw new YouTubeException('Failed to extract n function name');
        }

        $func_code = $this->extractFunctionCode($func_name, $js_code);

        if (!$func_code) {
            throw new YouTubeException('Failed to extract n function code');
        }

        return $this->decryptNsig($n_param, $func_name, $func_code);
    }

    protected function parseFunctionName(string $js_code): ?string
    {
        if (preg_match('@(?:\.get\("n"\)\)&&\(b=|(?:b=String\.fromCharCode\(110\)|(?P<str_idx>[a-zA-Z0-9_$.]+)&&\(b="nn"\[\+(?P=str_idx)\])(?:,[a-zA-Z0-9_$]+\(a\))?,c=a\.(?:get\(b\)|[a-zA-Z0-9_$]+\[b\]\|\|null)\)&&\(c=|\b(?P<var>[a-zA-Z0-9_$]+)=)(?P<nfunc>[a-zA-Z0-9_$]+)(?:\[(?P<idx>\d+)\])?\([a-zA-Z]\)(?(var),[a-zA-Z0-9_$]+\.set\((?:"n+"|[a-zA-Z0-9_$]+)\,(?P=var)\))@is', $js_code, $matches)) {
            $func_name = preg_quote($matches['nfunc']);
        } else if (preg_match('@;\s*(?P<name>[a-zA-Z0-9_$]+)\s*=\s*function\([a-zA-Z0-9_$]+\)\s*\{(?:(?!};).)+?return\s*(?P<q>["\'])[\w-]+_w8_(?P=q)\s*\+\s*[a-zA-Z0-9_$]+@is', $js_code, $matches)) {
            $func_name = preg_quote($matches['name']);
        }

        if (preg_match('@var\s+' . $func_name . '=\[(\S+)\];@is', $js_code, $matches)) {
            return preg_quote($matches[1]);
        }

        return null;
    }

    protected function extractFunctionCode(string $func_name, string $js_code): ?string
    {
        $var_code = '';
        if (preg_match('@(?P<q1>["\'])use\s+strict(?P=q1);\s*(?P<code>var\s+(?P<name>[a-zA-Z0-9_$]+)\s*=\s*(?P<value>(?P<q2>["\'])(?:(?!(?P=q2)).|\\.)+(?P=q2)\.split\((?P<q3>["\'])(?:(?!(?P=q3)).)+(?P=q3)\)|\[\s*(?:(?P<q4>["\'])(?:(?!(?P=q4)).|\\.)*(?P=q4)\s*,?\s*)+\]))[;,]@is', $js_code, $matches)) {
            $var_code = $matches['code'] . ";\n";
        }

        if (preg_match('@' . $func_name . '\s*=\s*function\s*\(\s*\w+\s*\)\s*{[\s\S]+?};@is', $js_code, $matches)) {
            $func_code = 'var ' . $matches[0];
        } else if (preg_match('@(function\s+' . $func_name . '\s*\([\s\S]*?})\s+function@is', $js_code, $matches)) {
            $func_code = $matches[1];
        }

        return $var_code .
            preg_replace('@;\s*if\s*\(\s*typeof\s+[a-zA-Z0-9_$]+\s*===?\s*(?:(["\'])undefined\1|[\$\w]+\[\d+\])\s*\)\s*return\s+[\$\w]+;@is', ';', $func_code);
    }

    protected function decryptNsig(string $n_param, string $func_name, string $func_code): string
    {
        $jsrt = new JsRuntime();
        if ($jsrt->getApp()) {
            $cache_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yt_' . $n_param;

            if (file_put_contents("{$cache_path}.dump", $func_code . "console.log({$func_name}('{$n_param}'));") === false) {
                throw new YouTubeException('Failed to write file to ' . sys_get_temp_dir());

            } else {
                exec($jsrt->getApp() . ' ' . $jsrt->getCmd() . " {$cache_path}.dump >{$cache_path}.nsig.tmp");
                $nsig = trim(file_get_contents("{$cache_path}.nsig.tmp"));

                unlink("{$cache_path}.dump");
                unlink("{$cache_path}.nsig.tmp");
            }
            if (!$nsig || ($nsig == $n_param)) {
                throw new YouTubeException('Failed to decrypt nsig');
            }
        }

        return $nsig ?: $n_param;
    }
}
