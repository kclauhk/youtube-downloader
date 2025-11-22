# JavaScript Runtime

In additional to Deno, you can use other JS runtimes such as:

## [Node.js](https://nodejs.org)

1. set the location of Node.js executable
    ```php
    $youtube->getJsrt()->setPath('path of node/node.exe');
    ```
2. set command-line arguments for better security
    ```php
    // for Node.js versions prior to v23.5.0
    $arg = '--experimental-permission --no-warnings=ExperimentalWarning';
    // for Node.js versions >= v23.5.0
    $arg = '--permission';
    // depends on system, permission to read files in the system temp directory may be needed, then:
    // $arg = '--permission --allow-fs-read=' . sys_get_temp_dir();
    
    $youtube->getJsrt()->setArg($arg);
    ```


## [Deno Deploy](https://deno.com/deploy)

### 🔧 Setup Deno Deploy

1. sign in to [Deno Deploy](https://console.deno.com/login)  
   _(you may use [Deploy Classic](https://dash.deno.com/login) [https://dash.deno.com](https://dash.deno.com) which is much faster IMO)_
2. create a **New Playground**
3. copy and paste the following code into the Playground _(remove all existing code)_
4. save & deploy
5. the project URL (https://???.deno.dev or .deno.net) will be accessible if deployed successfully  
   _(keep the URL secret and DON'T make the project public)_
6. set the url
    ```php
    $youtube->getJsrt()->setPath('your Deploy project URL);
    ```

#### 📄
```
// caution: keep this script's URL secret and DON'T make the project public
// due to the use of eval() without validating input, this script will run any code supplied, including malicious code
Deno.serve(async (req) => {
  if (req.method !== "POST") {
    return new Response('Method Not Allowed', { status: 405 });
  }
  if (req.body) {
    const js = await req.text();
    if (js.length > 0) {
      return new Response(eval(js), { status: 200 });
    }
  }
  return new Response('Bad Request', { status: 400 });
})
```
