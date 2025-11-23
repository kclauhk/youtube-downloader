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

