<?php

namespace YouTube;

use YouTube\Exception\YouTubeException;

class NSigDecoder
{
    const REGEX_GLOBAL_VAR = '@(?P<q1>["\'])use\s+strict(?P=q1);\s*(?P<code>var\s+(?P<name>[\w$]+)\s*=\s*(?P<value>(?P<q2>["\']).+(?P=q2)\.split\((?P<q3>["\'])(?:(?!(?P=q3)).)+(?P=q3)\)|\[\s*(?:(?P<q4>["\'])(?:(?!(?P=q4)).|\\.)*(?P=q4)\s*,?\s*)+\]))[;,]@x';
    // REGEX_GLOBAL_VAR                                                                                        here simplified  ^^
    const REGEX_FUNC_CODE = '@[{;,]\s*((?:function\s+{$func_name}|{$func_name}\s*=\s*function|(?:var|const|let)\s+{$func_name}\s*=\s*function)\s*\([^\)]*\)\s*{.+?};)\s@xs';
    const REGEX_RETURN_CODE = '@;\s*if\s*\(\s*typeof\s+[a-zA-Z0-9_$]+\s*===?\s*(?:(["\'])undefined\1|[\$\w]+\[\d+\])\s*\)\s*return\s+[\$\w]+;@is';

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
            $var_code = $this->extractGlobalVar($js_code);

            $n_func = $this->extractNFunction($js_code);
            if ($n_func) {
                $this->n_func_name = $n_func[0];
                $this->n_func_code = $var_code . $n_func[1];
            }

            if (!$this->n_func_name) {
                $this->n_func_name = $this->parseFunctionName($js_code);
                $this->n_func_code = $var_code . $this->extractFunctionCode($this->n_func_name, $js_code);
            }
        }

        return $this->decryptNsig($n_param, $this->n_func_name, $this->n_func_code);
    }

    protected function extractGlobalVar(string $js_code): ?string
    {
        $var_code = '';
        if (preg_match(self::REGEX_GLOBAL_VAR, $js_code, $matches)) {
            $var_code = $matches['code'] . ";\n";
        }

        return $var_code;
    }

    protected function parseFunctionName(string $js_code): ?string
    {
        $var_name = '';

        if (preg_match('@[;\n](?:(?P<f>function\s+)|(?:var\s+)?)(?P<funcname>[a-zA-Z0-9_$]+)\s*(?(f)|=\s*function\s*)\((?P<argname>[a-zA-Z0-9_$]+)\)\s*\{(?:(?!\}[;\n]).)+\}\s*catch\(\s*[a-zA-Z0-9_$]+\s*\)\s*\{\s*return\s+[\w\$]+\[\d+]\s*\+\s*(?P=argname)\s*\}\s*return\s+[^}]+\}[;\n]@xs', $js_code, $matches)) {
            return preg_quote($matches['funcname']);
        } elseif (preg_match('@(?:\.get\("n"\)\)&&\(b=|(?:b=String\.fromCharCode\(110\)|(?P<str_idx>[a-zA-Z0-9_$.]+)&&\(b="nn"\[\+(?P=str_idx)\])(?:,[a-zA-Z0-9_$]+\(a\))?,c=a\.(?:get\(b\)|[a-zA-Z0-9_$]+\[b\]\|\|null)\)&&\(c=|\b(?P<var>[a-zA-Z0-9_$]+)=)(?P<nfunc>[a-zA-Z0-9_$]+)(?:\[(?P<idx>\d+)\])?\([a-zA-Z]\)(?(var),[a-zA-Z0-9_$]+\.set\((?:"n+"|[a-zA-Z0-9_$]+)\,(?P=var)\))@x', $js_code, $matches)) {
            $var_name = preg_quote($matches['nfunc']);
        } elseif (preg_match('@;\s*(?P<name>[a-zA-Z0-9_$]+)\s*=\s*function\([a-zA-Z0-9_$]+\)\s*\{(?:(?!};).)+?return\s*(?P<q>["\'])[\w-]+_w8_(?P=q)\s*\+\s*[a-zA-Z0-9_$]+@xs', $js_code, $matches)) {
            $var_name = preg_quote($matches['name']);
        } elseif (preg_match('@var\s+[\w$]{3}\s*=\s*\[([\w$]{3})\]\s*[,;]@', $js_code, $matches)) {
            return preg_quote($matches[1]);
        }

        if (preg_match('@var\s+' . $var_name .  '\s*=\s*\[(.+?)\]\s*[,;]@', $js_code, $matches)) {
            return preg_quote($matches[1]);
        }

        throw new YouTubeException('Failed to extract n function name');
    }

    protected function extractFunctionCode(string $func_name, string $js_code): ?string
    {
        if (preg_match(str_replace('{$func_name}', $func_name, self::REGEX_FUNC_CODE), $js_code, $matches)) {
            $func_code = (strpos($matches[1], stripslashes($func_name)) === 0 ? 'var ' : '') . $matches[1];
        } elseif (preg_match('@(function\s+' . $func_name . '\s*\([\s\S]*?})\s+function@', $js_code, $matches)) {
            $func_code = $matches[1];
        }

        if (!$func_code) {
            throw new YouTubeException('Failed to extract n function code');
        }

        return preg_replace(self::REGEX_RETURN_CODE, ';', $func_code);
    }

    protected function extractNFunction(string $js_code): ?array
    {
        if (preg_match(self::REGEX_RETURN_CODE, $js_code, $matches)) {
            $ret = $matches[0];
            $pos = strpos($js_code, $ret);

            if (preg_match_all('@[,;]\s*([\w$]{3})\s*=\s*function\(\w\)\s*{@', substr($js_code, $pos - 10000, 10000), $matches)) {
                $func_name = end($matches[1]);

                if (preg_match(str_replace('{$func_name}', $func_name, self::REGEX_FUNC_CODE), $js_code, $matches)) {
                    $func_code = (strpos($matches[1], stripslashes($func_name)) === 0 ? 'var ' : '') . $matches[1];
                } elseif (preg_match("@(function\s+{$func_name}\s*\([\s\S]*?})\s+function@", $js_code, $matches)) {
                    $func_code = $matches[1];
                }
                if ($func_code) {
                    return [$func_name, str_replace($ret, ';', $func_code)];
                }
            }
        }

        return null;
    }

    protected function decryptNsig(string $n_param, string $func_name, string $func_code): string
    {
        $func_name = stripslashes($func_name);
        $result = $n_param;

        $jsrt = new JsRuntime();

        if ($jsrt->getApp()) {
            $code_str = "%sconsole.log(%s('%s'));";
            $code_arg = [$func_code, $func_name, $n_param];

            try {
                $result = $jsrt->run('n', $code_str, $code_arg, $n_param);
            } catch (YouTubeException $e) {
                throw new YouTubeException($e->getMessage());
            }
        }

        return $result;
    }
}
