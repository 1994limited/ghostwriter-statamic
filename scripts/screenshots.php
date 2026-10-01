#!/usr/bin/env php
<?php

/**
 * Marketplace screenshots, taken from the real product in a local site
 * with headless Chrome over the DevTools protocol. No dependencies: a
 * small WebSocket client is below.
 *
 * Usage:
 *   GW_SHOT_URL=https://1994.test GW_SHOT_EMAIL=… GW_SHOT_PASSWORD=… \
 *   GW_SHOT_SESSION=<a session id with a draft> \
 *   php scripts/screenshots.php [output-dir]
 *
 * It signs in as that user, visits each screen, and saves PNGs at 1600×900
 * and 3200×1800 (device pixel ratio 2) to docs/store/raw/. Set them in the
 * brand frames with scripts/frame.php.
 *
 * Needs Google Chrome (GW_SHOT_CHROME to point at the binary) and a user
 * who may use Ghostwriter. Make a throwaway user for it and delete it after.
 */
$url = rtrim((string) getenv('GW_SHOT_URL'), '/');
$email = (string) getenv('GW_SHOT_EMAIL');
$password = (string) getenv('GW_SHOT_PASSWORD');
$session = (string) getenv('GW_SHOT_SESSION');
$chrome = getenv('GW_SHOT_CHROME') ?: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
$out = $argv[1] ?? __DIR__.'/../docs/store/raw';

if ($url === '' || $email === '' || $password === '') {
    fwrite(STDERR, "Set GW_SHOT_URL, GW_SHOT_EMAIL and GW_SHOT_PASSWORD.\n");
    exit(1);
}

if (! is_file($chrome)) {
    fwrite(STDERR, "Chrome not found at {$chrome}; set GW_SHOT_CHROME.\n");
    exit(1);
}

@mkdir($out, 0777, true);

/**
 * The screens, in Marketplace order. `after` is JavaScript run once the
 * page has loaded, before the shot, to open or settle things; `wait` is
 * extra seconds for animations.
 */
$shots = [
    '01-writing-panel' => [
        'resolve' => fn () => $session !== '' ? null : 'GW_SHOT_SESSION is not set; skipping the writing panel.',
        'url' => fn () => $url.'/cp/ghostwriter/sessions/'.$session.'/open',
        'wait' => 3,
    ],
    '02-content-plan' => ['url' => fn () => $url.'/cp/ghostwriter/plan', 'wait' => 1.5],
    '03-image-choices' => [
        'resolve' => fn () => $session !== '' ? null : 'GW_SHOT_SESSION is not set; skipping the image choices.',
        'url' => fn () => $url.'/cp/ghostwriter/sessions/'.$session.'/open',
        'after' => "document.querySelector('[data-gw-images]')?.scrollIntoView({block: 'start'})",
        'wait' => 3,
    ],
    '04-voice-guide' => ['url' => fn () => $url.'/cp/ghostwriter/voice', 'wait' => 1.5],
];

$port = 9222 + random_int(1, 500);
$profile = sys_get_temp_dir().'/ghostwriter-shots-'.getmypid();
$process = proc_open(
    [$chrome, '--headless=new', "--remote-debugging-port={$port}", "--user-data-dir={$profile}", '--window-size=1600,900', '--hide-scrollbars', '--force-device-scale-factor=1', '--ignore-certificate-errors', 'about:blank'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
);

register_shutdown_function(function () use ($process, $profile): void {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }

    exec('rm -rf '.escapeshellarg($profile));
});

// Wait for Chrome to listen.
$version = null;

for ($i = 0; $i < 50 && $version === null; $i++) {
    usleep(200000);
    $version = @file_get_contents("http://127.0.0.1:{$port}/json/version");
}

if ($version === null) {
    fwrite(STDERR, "Chrome did not start.\n");
    exit(1);
}

$context = stream_context_create(['http' => ['method' => 'PUT']]);
$target = json_decode((string) file_get_contents("http://127.0.0.1:{$port}/json/new?about:blank", false, $context), true);
$ws = new DevToolsSocket($target['webSocketDebuggerUrl']);

$ws->send('Page.enable');
$ws->send('Runtime.enable');
$ws->send('Emulation.setDeviceMetricsOverride', ['width' => 1600, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);

// Sign in. The form is Vue, so values go in through the native setter and an
// input event, as typing would.
$ws->navigate($url.'/cp/auth/login');
$ws->evaluate(<<<'JS'
    (() => {
        const set = (el, value) => { const s = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set; s.call(el, value); el.dispatchEvent(new Event('input', {bubbles: true})); };
        set(document.querySelector('input[type=email], input[name=email], input[type=text]'), EMAIL);
        set(document.querySelector('input[type=password]'), PASSWORD);
        document.querySelector('button[type=submit]').click();
    })()
JS, ['EMAIL' => $email, 'PASSWORD' => $password]);
$ws->waitFor(fn () => str_contains((string) $ws->evaluate('document.location.pathname'), '/cp') && ! str_contains((string) $ws->evaluate('document.location.pathname'), '/auth/login'), 15);

echo "Signed in.\n";

foreach ($shots as $name => $shot) {
    if (isset($shot['resolve']) && ($skip = $shot['resolve']()) !== null) {
        echo "{$name}: {$skip}\n";

        continue;
    }

    $ws->navigate($shot['url']());
    usleep((int) (($shot['wait'] ?? 1) * 1000000));

    if (! empty($shot['after'])) {
        $ws->evaluate($shot['after']);
        usleep(600000);
    }

    foreach ([1 => '', 2 => '-2x'] as $scale => $suffix) {
        $ws->send('Emulation.setDeviceMetricsOverride', ['width' => 1600, 'height' => 900, 'deviceScaleFactor' => $scale, 'mobile' => false]);
        usleep(400000);
        $png = base64_decode($ws->send('Page.captureScreenshot', ['format' => 'png', 'captureBeyondViewport' => false])['data']);
        file_put_contents("{$out}/{$name}{$suffix}.png", $png);
    }

    echo "{$name}: saved.\n";
}

echo "Done. Raw shots are in {$out}.\n";

/**
 * Just enough of a WebSocket client for the DevTools protocol: handshake,
 * masked text frames out, unmasked frames in, with lengths up to 2^63.
 */
class DevToolsSocket
{
    /** @var resource */
    private $socket;

    private int $id = 0;

    public function __construct(string $url)
    {
        $parts = parse_url($url);
        $this->socket = stream_socket_client("tcp://{$parts['host']}:{$parts['port']}", $code, $error, 10)
            ?: throw new RuntimeException("Could not open the DevTools socket: {$error}");

        $key = base64_encode(random_bytes(16));
        fwrite($this->socket, "GET {$parts['path']} HTTP/1.1\r\nHost: {$parts['host']}:{$parts['port']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");

        $response = '';

        while (! str_contains($response, "\r\n\r\n")) {
            $response .= fgets($this->socket) ?: '';
        }

        if (! str_contains($response, ' 101 ')) {
            throw new RuntimeException("The DevTools handshake failed:\n{$response}");
        }

        stream_set_timeout($this->socket, 60);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function send(string $method, array $params = []): array
    {
        $id = ++$this->id;
        $this->write((string) json_encode(['id' => $id, 'method' => $method, 'params' => (object) $params]));

        while (true) {
            $message = json_decode($this->read(), true);

            if (($message['id'] ?? null) === $id) {
                if (isset($message['error'])) {
                    throw new RuntimeException("{$method}: {$message['error']['message']}");
                }

                return $message['result'] ?? [];
            }
        }
    }

    public function navigate(string $url): void
    {
        $this->send('Page.navigate', ['url' => $url]);

        // Load events arrive out of band; read until one does.
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $message = json_decode($this->read(), true);

            if (($message['method'] ?? null) === 'Page.loadEventFired') {
                usleep(500000);

                return;
            }
        }
    }

    /**
     * @param  array<string, string>  $constants  Names replaced in the script with JSON-encoded values.
     */
    public function evaluate(string $script, array $constants = []): mixed
    {
        foreach ($constants as $name => $value) {
            $script = str_replace($name, (string) json_encode($value), $script);
        }

        $result = $this->send('Runtime.evaluate', ['expression' => $script, 'returnByValue' => true, 'awaitPromise' => true]);

        return $result['result']['value'] ?? null;
    }

    public function waitFor(callable $condition, int $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            usleep(500000);
        }

        throw new RuntimeException('Timed out waiting for the page.');
    }

    private function write(string $payload): void
    {
        $length = strlen($payload);
        $frame = chr(0x81);

        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $frame .= chr(0x80 | 126).pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127).pack('J', $length);
        }

        $mask = random_bytes(4);
        $masked = '';

        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        fwrite($this->socket, $frame.$mask.$masked);
    }

    private function read(): string
    {
        $message = '';

        do {
            $header = $this->bytes(2);
            $final = (ord($header[0]) & 0x80) !== 0;
            $opcode = ord($header[0]) & 0x0F;
            $length = ord($header[1]) & 0x7F;

            if ($length === 126) {
                $length = unpack('n', $this->bytes(2))[1];
            } elseif ($length === 127) {
                $length = unpack('J', $this->bytes(8))[1];
            }

            $chunk = $length > 0 ? $this->bytes($length) : '';

            if ($opcode === 0x8) {
                throw new RuntimeException('The DevTools socket closed.');
            }

            if ($opcode === 0x9) {
                // A ping: answer and carry on.
                $this->write($chunk);

                continue;
            }

            $message .= $chunk;
        } while (! $final || $opcode === 0x9);

        return $message;
    }

    private function bytes(int $count): string
    {
        $data = '';

        while (strlen($data) < $count) {
            $more = fread($this->socket, $count - strlen($data));

            if ($more === false || $more === '') {
                $info = stream_get_meta_data($this->socket);

                throw new RuntimeException($info['timed_out'] ? 'The DevTools socket timed out.' : 'The DevTools socket closed.');
            }

            $data .= $more;
        }

        return $data;
    }
}
