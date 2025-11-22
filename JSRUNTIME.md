# JavaScript Runtime

In addition to Deno, you can use other JS runtimes such as:

## [Bun](https://bun.com/)

set path to the binary executable and command-line arguments:
```php
    $youtube->getJsrt()->setPath('path to bun/bun.exe');
    $arg = '--no-install --prefer-offline --no-addons --no-macros --bun';
    $youtube->getJsrt()->setArg($arg);
```

## [Node.js](https://nodejs.org)

1. set path to the binary executable:
    ```php
    $youtube->getJsrt()->setPath('path to node/node.exe');
    ```
2. set command-line arguments to control system resources access:
    ```php
    // for versions >= v20.0.0
    $arg = '--experimental-permission --no-warnings=ExperimentalWarning';
    // for versions >= v23.5.0
    $arg = '--permission';
    // depends on your system, permission to read files in the temp directory may be needed
    // add this argument to grant permission:    --allow-fs-read=/temp directory/
    // temp directory: 'upload_tmp_dir' in php.ini, or system temp directory if upload_tmp_dir not set
    $youtube->getJsrt()->setArg($arg);
    ```
\
Alternatively, you can use serverless JS platforms.
_(Note: It is highly recommended to enable the zlib extension)_

## [Deno Deploy](https://deno.com/deploy)

### 🔧 Setup Deno Deploy

1. choose an API key and generate the hash value of the key using the SHA3-512 algorithm, then the SHA3-384 algorithm.  
   If you don't have the tool to generate the hash value, visit [https://onlinephp.io/](https://onlinephp.io/) and execute:
   ```php
   <?php
   echo hash("sha3-384", hash("sha3-512", "put your API key here"));
   ```
2. sign in to [Deno Deploy](https://console.deno.com/login)  
   _(you may use [Deploy Classic](https://dash.deno.com/login) [https://dash.deno.com](https://dash.deno.com) which is much faster IMO)_
3. create a **New Playground**
4. copy and paste the following code into the Playground _(remove all existing code)_
5. put the hash value into the code
6. save & deploy
7. the project URL (https://???.deno.dev or .deno.net) will be accessible if deployed successfully  
   _(keep the URL and the API key secret and DON'T make the project public)_
8. set the API key, and then the URL:
    ```php
    $youtube->getJsrt()->setApiKey('your API key');
    $youtube->getJsrt()->setPath('your project URL');
    ```

#### 📄 code to be deployed:
```javascript
// caution: keep this script's URL secret and DON'T make your Deploy project public
// due to the use of eval() without input validation, this script will run any code provided, including malicious code
import zlib from "node:zlib";
const { createHash } = await import('node:crypto');
Deno.serve(async (req: Request) => {
  if (req.method !== "POST") {
    return new Response('Method Not Allowed', { status: 405 });
  } else {
    const hash = 'put the hash value of the API key here';
    const token = req.headers.get('x-token');
    if (createHash('sha3-384').update(token).digest('hex') != hash)
      return new Response('Forbidden', { status: 403 });
  }
  if (req.body) {
    const encoding = req.headers.get('content-encoding');
    if (encoding == 'gzip') {
      const data = await req.arrayBuffer();
      const decompressed = zlib.gunzipSync(new Uint8Array(data));
      var text = new TextDecoder().decode(decompressed);
    } else {
      var text = await req.text();
    }
    if (text.length > 0) {
      let headers = new Headers();
      if (encoding == 'gzip') {
        var data = zlib.gzipSync(eval(text).toString(), {
          level: zlib.constants.Z_BEST_COMPRESSION,
        });
        headers.set('content-encoding', 'gzip');
      } else {
        var data = eval(text);
      }
      return new Response(data, {
        status: 200,
        headers: headers,
      });
    }
  }
  return new Response('Bad Request', { status: 400 });
})
```
