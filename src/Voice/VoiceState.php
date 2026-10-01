<?php

namespace NineteenNinetyFour\Ghostwriter\Voice;

use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ghostwriter\Ai\Text;

/**
 * Working state for the voice guide screen: whether a scan or refinement is
 * running, the last error, and the refine conversation. Kept beside the
 * sessions rather than in the project, because it is not content.
 */
class VoiceState
{
    public const IDLE = 'idle';

    public const WORKING = 'working';

    public const FAILED = 'failed';

    /**
     * @return array{status: string, error: ?string, task: ?string, messages: array<int, array{role: string, content: string}>, scanned: array<int, array{title: string, collection: string}>, pending: array<int, array<string, mixed>>}
     */
    public function get(): array
    {
        $defaults = ['status' => self::IDLE, 'error' => null, 'task' => null, 'messages' => [], 'scanned' => [], 'pending' => []];

        if (! File::exists($this->path())) {
            return $defaults;
        }

        return array_merge($defaults, (array) json_decode((string) File::get($this->path()), true));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(array $changes): void
    {
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), json_encode(Text::scrub(array_merge($this->get(), $changes)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function addMessage(string $role, string $content): void
    {
        $messages = $this->get()['messages'];
        $messages[] = ['role' => $role, 'content' => $content];

        $this->update(['messages' => $messages]);
    }

    protected function path(): string
    {
        return dirname((string) config('ghostwriter.sessions_path')).'/voice.json';
    }
}
