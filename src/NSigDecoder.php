<?php

namespace YouTube;

use YouTube\Exception\YouTubeException;

class NSigDecoder
{
    private string $n_func_name = '';
    private string $n_func_code = '';

    /**
     * @param string $n_param
     * @param string $js_code Complete source code for YouTube's player.js
     * @return string
     */
    public function decode(string $n_param, string $js_code): string
    {
        if (empty($this->n_func_code)) {
            $this->n_func_name = $this->parseFunctionName($js_code);

            if (!$this->n_func_name) {
                throw new YouTubeException('Failed to extract n function name');
            }

            $this->n_func_code = $this->extractFunctionCode($this->n_func_name, $js_code);
        }

        if (!$this->n_func_code) {
            throw new YouTubeException('Failed to extract n function code');
        }

        return $this->decryptNsig($n_param, $this->n_func_name, $this->n_func_code);
    }

    protected function parseFunctionName(string $js_code): ?string
    {
        if (preg_match('@[;\n](?:(?P<f>function\s+)|(?:var\s+)?)(?P<funcname>[a-zA-Z0-9_$]+)\s*(?(f)|=\s*function\s*)\((?P<argname>[a-zA-Z0-9_$]+)\)\s*\{(?:(?!\}[;\n]).)+\}\s*catch\(\s*[a-zA-Z0-9_$]+\s*\)\s*\{\s*return\s+[\w\$]+\[\d+]\s*\+\s*(?P=argname)\s*\}\s*return\s+[^}]+\}[;\n]@xs', $js_code, $matches)) {
            return preg_quote($matches['funcname']);
        } else if (preg_match('@(?:\.get\("n"\)\)&&\(b=|(?:b=String\.fromCharCode\(110\)|(?P<str_idx>[a-zA-Z0-9_$.]+)&&\(b="nn"\[\+(?P=str_idx)\])(?:,[a-zA-Z0-9_$]+\(a\))?,c=a\.(?:get\(b\)|[a-zA-Z0-9_$]+\[b\]\|\|null)\)&&\(c=|\b(?P<var>[a-zA-Z0-9_$]+)=)(?P<nfunc>[a-zA-Z0-9_$]+)(?:\[(?P<idx>\d+)\])?\([a-zA-Z]\)(?(var),[a-zA-Z0-9_$]+\.set\((?:"n+"|[a-zA-Z0-9_$]+)\,(?P=var)\))@x', $js_code, $matches)) {
            $func_name = preg_quote($matches['nfunc']);
        } else if (preg_match('@;\s*(?P<name>[a-zA-Z0-9_$]+)\s*=\s*function\([a-zA-Z0-9_$]+\)\s*\{(?:(?!};).)+?return\s*(?P<q>["\'])[\w-]+_w8_(?P=q)\s*\+\s*[a-zA-Z0-9_$]+@xs', $js_code, $matches)) {
            $func_name = preg_quote($matches['name']);
        }

        if (preg_match('@var\s+' . $func_name .  '\s*=\s*\[(.+?)\]\s*[,;]@', $js_code, $matches)) {
            return preg_quote($matches[1]);
        }

        return null;
    }

    protected function extractFunctionCode(string $func_name, string $js_code): ?string
    {
        $var_code = '';
        //                                                                                               here simplified  vv
        if (preg_match('@(?P<q1>["\'])use\s+strict(?P=q1);\s*(?P<code>var\s+(?P<name>[\w$]+)\s*=\s*(?P<value>(?P<q2>["\']).+(?P=q2)\.split\((?P<q3>["\'])(?:(?!(?P=q3)).)+(?P=q3)\)|\[\s*(?:(?P<q4>["\'])(?:(?!(?P=q4)).|\\.)*(?P=q4)\s*,?\s*)+\]))[;,]@x', $js_code, $matches)) {
            $var_code = $matches['code'] . ";\n";
        }

        if (preg_match("@[{;,]\s*((?:function\s+{$func_name}|{$func_name}\s*=\s*function|(?:var|const|let)\s+{$func_name}\s*=\s*function)\s*\([^\)]*\)\s*{.+?};)@xs", $js_code, $matches)) {
            $func_code = (strpos($matches[1], stripslashes($func_name)) === 0 ? 'var ' : '') . $matches[1];
        } else if (preg_match('@(function\s+' . $func_name . '\s*\([\s\S]*?})\s+function@', $js_code, $matches)) {
            $func_code = $matches[1];
        }

        return $var_code .
            preg_replace('@;\s*if\s*\(\s*typeof\s+[a-zA-Z0-9_$]+\s*===?\s*(?:(["\'])undefined\1|[\$\w]+\[\d+\])\s*\)\s*return\s+[\$\w]+;@is', ';', $func_code);
    }

    protected function decryptNsig(string $n_param, string $func_name, string $func_code): string
    {
        $func_name = stripslashes($func_name);

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
