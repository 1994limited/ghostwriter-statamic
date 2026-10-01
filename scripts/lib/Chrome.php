<?php

/**
 * Starts headless Chrome on a free port with a throwaway profile, waits for
 * it to listen, and hands back a DevTools socket to its first tab. Chrome
 * and the profile go when the script ends.
 */
class Chrome
{
    /**
     * @param  array<int, string>  $flags  Extra Chrome flags.
     */
    public static function launch(string $chrome, array $flags = []): DevToolsSocket
    {
        if (! is_file($chrome)) {
            fwrite(STDERR, "Chrome not found at {$chrome}; set GW_SHOT_CHROME.\n");
            exit(1);
        }

        $port = 9222 + random_int(1, 500);
        $profile = sys_get_temp_dir().'/ghostwriter-chrome-'.getmypid();
        $process = proc_open(
            [$chrome, '--headless=new', "--remote-debugging-port={$port}", "--user-data-dir={$profile}", '--window-size=1600,900', '--hide-scrollbars', '--force-device-scale-factor=1', '--ignore-certificate-errors', ...$flags, 'about:blank'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$profile}.log", 'a'], 2 => ['file', "{$profile}.log", 'a']],
            $pipes,
        );

        register_shutdown_function(function () use ($process, $profile): void {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }

            usleep(300000);
            exec('rm -rf '.escapeshellarg($profile).' 2>/dev/null');
            @unlink("{$profile}.log");
        });

        $version = null;

        for ($i = 0; $i < 50 && $version === null; $i++) {
            usleep(200000);
            $version = @file_get_contents("http://127.0.0.1:{$port}/json/version");
        }

        if ($version === null) {
            fwrite(STDERR, "Chrome did not start; see {$profile}.log.\n");
            exit(1);
        }

        // The port can answer before the first tab is ready to be listed.
        $target = null;

        for ($i = 0; $i < 50 && ! $target; $i++) {
            usleep(300000);
            $targets = (array) json_decode((string) @file_get_contents("http://127.0.0.1:{$port}/json/list"), true);
            $target = current(array_filter($targets, fn ($candidate) => is_array($candidate) && ($candidate['type'] ?? null) === 'page')) ?: null;
        }

        if (! $target) {
            fwrite(STDERR, "Chrome opened no page to drive; see {$profile}.log.\n");
            exit(1);
        }

        return new DevToolsSocket($target['webSocketDebuggerUrl']);
    }
}
