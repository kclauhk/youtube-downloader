<?php

namespace YouTube;

use YouTube\Exception\YouTubeException;

/*
 *
 * Go into YouTube's player.js, find a code that looks like this:
 *
 * ZKa = function(a) {
    a = a.split("");
    YKa.uB(a, 2);
    YKa.Us(a, 43);
    YKa.fY(a, 50);
    return a.join("")
};

and translate it to PHP
 *
 */

class SignatureDecoder
{
    private string $s_func_name = '';
    private array  $instructions = [];
    private bool $exec_disabled = false;

    /**
     * @param string $signature
     * @param string $js_code Complete source code for YouTube's player.js
     * @return string|null
     */
    public function decode(string $signature, string $js_code): ?string
    {
        if (count($this->instructions) === 0) {
            $this->s_func_name = $this->parseFunctionName($js_code);

            if (!$this->s_func_name) {
                //Could not parse signature function name
                return null;
            }

            $this->instructions = (array)$this->parseFunctionCode($this->s_func_name, $js_code);
        }

        if (count($this->instructions) === 0) {
            // Could not parse any signature instructions
            return null;
        }

        if ($this->instructions['type'] == 'instructions') {
            foreach ($this->instructions[0] as $opt) {

                $command = $opt[0];
                $value = $opt[1];

                if ($command == 'swap') {

                    $temp = $signature[0];
                    $signature[0] = $signature[$value % strlen($signature)];
                    $signature[$value] = $temp;

                } elseif ($command == 'splice') {
                    $signature = substr($signature, $value);
                } elseif ($command == 'reverse') {
                    $signature = strrev($signature);
                }
            }

        } else if ($this->instructions['type'] == 'js') {
            $func_code = implode(";\n", $this->instructions[0]) . ";\n";

            $signature = $this->decryptSignature($signature, $this->s_func_name, $func_code);
        }

        return trim($signature);
    }

    protected function parseFunctionName(string $js_code): ?string
    {
        if (preg_match('@\b(?P<var>[a-zA-Z0-9_$]+)&&\((?P=var)=(?P<sig>[a-zA-Z0-9_$]{2,})\(decodeURIComponent\((?P=var)\)\)@is', $js_code, $matches)) {
            return preg_quote($matches['sig']);
        } else if (preg_match('@(?P<sig>[a-zA-Z0-9_$]+)\s*=\s*function\(\s*(?P<arg>[a-zA-Z0-9_$]+)\s*\)\s*{\s*(?P=arg)\s*=\s*(?P=arg)\.split\(\s*""\s*\)\s*;\s*[^}]+;\s*return\s+(?P=arg)\.join\(\s*""\s*\)@is', $js_code, $matches)) {
            return preg_quote($matches['sig']);
        } else if (preg_match('@(?:\b|[^a-zA-Z0-9_$])(?P<sig>[a-zA-Z0-9_$]{2,})\s*=\s*function\(\s*a\s*\)\s*{\s*a\s*=\s*a\.split\(\s*""\s*\)(?:;[a-zA-Z0-9_$]{2}\.[a-zA-Z0-9_$]{2}\(a,\d+\))?@is', $js_code, $matches)) {
            return preg_quote($matches['sig']);
        }

        return null;
    }

    // convert JS code for signature decipher to PHP code
    protected function parseFunctionCode(string $func_name, string $player_html): ?array
    {
        // extract code block from that function
        // single quote in case function name contains $dollar sign
        // xm=function(a){a=a.split("");wm.zO(a,47);wm.vY(a,1);wm.z9(a,68);wm.zO(a,21);wm.z9(a,34);wm.zO(a,16);wm.z9(a,41);return a.join("")};
        if (preg_match('/function\s+' . $func_name . '.*{(.*?)}/', $player_html, $matches)) {
            $js_code = $matches[1];
            $js_func = $matches[0];
        } else if (preg_match('/' . $func_name . '=\s*function\s*\(\s*\S+\s*\)\s*{(.*?)}/', $player_html, $matches)) {
            $js_code = $matches[1];
            $js_func = 'var ' . $matches[0];
        }

        if ($js_code) {
            // extract all relevant statements within that block
            // wm.vY(a,1);
            if (preg_match_all('/([a-z0-9$]{2})\.([a-z0-9]{2})\([^,]+,(\d+)\)/i', $js_code, $matches) != false) {

                // wm
                $obj_list = $matches[1];

                // vY
                $func_list = $matches[2];

                // extract javascript code for each one of those statement functions
                preg_match_all('/(' . implode('|', $func_list) . '):function(.*?)\}/m', $player_html, $matches2, PREG_SET_ORDER);

                $functions = array();

                // translate each function according to its use
                foreach ($matches2 as $m) {

                    if (strpos($m[2], '.splice') !== false) {
                        $functions[$m[1]] = 'splice';
                    } elseif (strpos($m[2], '.length') !== false) {
                        $functions[$m[1]] = 'swap';
                    } elseif (strpos($m[2], '.reverse') !== false) {
                        $functions[$m[1]] = 'reverse';
                    }
                }

                // FINAL STEP! convert it all to instructions set
                $instructions = array();

                foreach ($matches[2] as $index => $name) {
                    $instructions[] = array($functions[$name], $matches[3][$index]);
                }

                return array($instructions, 'type' => 'instructions');

            } else if (preg_match_all('/[;{]([\w$]+)\[/', $js_code, $matches)) {

                if ((new JsRuntime())->getApp()) {

                    $fn_names = array_unique($matches[1]);

                    $instructions = array();

                    foreach ($fn_names as $fn_name) {
                        $fn_name = preg_quote($fn_name);
                        if (preg_match("@(?:(?:var|const|let)\s+{$fn_name}\s*=|(?:function\s+{$fn_name}|[{;,]\s*{$fn_name}\s*=\s*function|(?:var|const|let)\s+{$fn_name}\s*=\s*function)\s*\([^)]*\))\s*{.+?};@xs", $player_html, $matches)) {
                            $instructions[] = $matches[0];
                        }
                    }

                    //                                                                                               here simplified  vv
                    if (preg_match('@(?P<q1>["\'])use\s+strict(?P=q1);\s*(?P<code>var\s+(?P<name>[\w$]+)\s*=\s*(?P<value>(?P<q2>["\']).+(?P=q2)\.split\((?P<q3>["\'])(?:(?!(?P=q3)).)+(?P=q3)\)|\[\s*(?:(?P<q4>["\'])(?:(?!(?P=q4)).|\\.)*(?P=q4)\s*,?\s*)+\]))[;,]@x', $player_html, $matches)) {
                        $instructions[] = $matches['code'];
                    }

                    $instructions[] = $js_func;

                    return array($instructions, 'type' => 'js');

                }
            }
        }

        return null;
    }

    protected function decryptSignature(string $signature, string $func_name, string $func_code): ?string
    {
        $func_name = stripslashes($func_name);

        $jsrt = new JsRuntime();

        if ($jsrt->getApp()) {
            if ($jsrt::$ver == '(remote)') {
                $sig = file_get_contents($jsrt->getApp(), false, stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header'  => 'Content-Type: text/plain',
                        'content' => $func_code . "{$func_name}('{$signature}');",
                        'ignore_errors' => true,
                ]]));
                if (strpos(($http_response_header ?? [''])[0], ' 200 ') === false)
                    throw new YouTubeException("Status '" . (empty($http_response_header) ? 'no response' : $http_response_header[0]) . "'");
            } elseif (!$this->exec_disabled) {
                $cache_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yt_' . substr(preg_replace('/\W/', '-', $signature), 0, 40);

                if (file_put_contents("{$cache_path}.dump", $func_code . "console.log({$func_name}('{$signature}'));") === false) {
                    throw new YouTubeException('Failed to write file to ' . sys_get_temp_dir());

                } else {
                    $sig = exec($jsrt->getApp() . ' ' . $jsrt->getCmd() . " {$cache_path}.dump", $output, $result_code);
                    unlink("{$cache_path}.dump");
                }
            }
            if (!$sig || ($sig == $signature)) {
                if (!empty($result_code)) {
                    throw new YouTubeException("Exit status {$result_code} '{$jsrt::$ver}'");
                } elseif ($jsrt::$ver != '(remote)' && ($this->exec_disabled || @exec('echo EXEC') != 'EXEC')) {
                    $this->exec_disabled = true;
                    throw new YouTubeException('exec() has been disabled for security reasons');
                } else {
                    $jsr_exe = basename($jsrt->getApp());
                    throw new YouTubeException("Failed to decrypt sig (func:'{$func_name}', '{$jsr_exe} {$jsrt::$ver}')");
                }
            }
        }

        return $sig ?: null;
    }
}