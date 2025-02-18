<?php

namespace YouTube;

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
            //Could not parse n function name
            return $n_param;
        }

        $func_code = $this->extractFunctionCode($func_name, $js_code);

        if (!$func_code) {
            //Could not parse n function code
            return $n_param;
        }

        return $this->decryptNsig($n_param, $func_code);
    }

    protected function parseFunctionName(string $js_code): ?string
    {
        if (preg_match('@(?:\.get\("n"\)\)&&\(b=|(?:b=String\.fromCharCode\(110\)|(?P<str_idx>[a-zA-Z0-9_$.]+)&&\(b="nn"\[\+(?P=str_idx)\])(?:,[a-zA-Z0-9_$]+\(a\))?,c=a\.(?:get\(b\)|[a-zA-Z0-9_$]+\[b\]\|\|null)\)&&\(c=|\b(?P<var>[a-zA-Z0-9_$]+)=)(?P<nfunc>[a-zA-Z0-9_$]+)(?:\[(?P<idx>\d+)\])?\([a-zA-Z]\)(?(var),[a-zA-Z0-9_$]+\.set\((?:"n+"|[a-zA-Z0-9_$]+)\,(?P=var)\))@is', $js_code, $matches)) {
            $func_name = preg_quote($matches['nfunc']);
        } else if (preg_match('@;\s*(?P<name>[a-zA-Z0-9_$]+)\s*=\s*function\([a-zA-Z0-9_$]+\)\s*\{(?:(?!};).)+?return\s*(?P<q>["\'])[\w-]+_w8_(?P=q)\s*\+\s*[a-zA-Z0-9_$]+@is', $js_code, $matches)) {
            $func_name = preg_quote($matches['name']);
        }

        if (preg_match('@var ' . $func_name . '=\[(\S+)\];@is', $js_code, $matches)) {
            return preg_quote($matches[1]);
        }

        return null;
    }

    protected function extractFunctionCode(string $func_name, string $js_code): ?string
    {
        if (preg_match('@' . $func_name . '=function\(\w+\){[\s\S]+?};@is', $js_code, $matches)) {
            return $matches[0];
        }

        return null;
    }

    protected function decryptNsig(string $n_param, string $func_code): string
    {
        $deno = (new Deno())->getApp();
        if ($deno) {
            $cache_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $n_param;

            $func_name = substr($func_code, 0, strpos($func_code, '=function'));

            if (file_put_contents("$cache_path.dump", 'var ' . preg_replace('@if\(typeof \w+==="undefined"\)return C;@is', '', $func_code) . "console.log($func_name('$n_param'));") === false) {
                throw new YouTubeException('Failed to write file to ' . sys_get_temp_dir());

            } else {
                exec($deno . " run $cache_path.dump >$cache_path.nsig.tmp");
                $nsig = trim(file_get_contents("$cache_path.nsig.tmp"));

                unlink("$cache_path.dump");
                unlink("$cache_path.nsig.tmp");
            }
        }

        return $nsig ?: $n_param;
    }
}
