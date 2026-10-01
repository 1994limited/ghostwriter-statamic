<?php

namespace NineteenNinetyFour\Ghostwriter\Sessions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One piece of content being written: the questionnaire answers it started
 * from, the conversation that refined it, and the draft as it stands.
 */
class Session
{
    public const IDLE = 'idle';

    public const WORKING = 'working';

    public const FAILED = 'failed';

    /**
     * @param  array<string, string>  $answers
     * @param  array<int, array{role: string, content: string, at: string}>  $messages
     * @param  array{input: int, output: int}  $usage
     * @param  array<int, string>  $examples  Entries this piece is modelled on, chosen with the brief.
     * @param  string|null  $source  The existing entry being edited, when the session is not for something new.
     * @param  string|null  $blueprint  That entry's blueprint, where the collection has several.
     * @param  string|null  $appliedAt  When the draft was last put into a publish form.
     * @param  array<string, array{status: string, path?: string, url?: string, error?: ?string, direction?: string}>  $images  Generated images, keyed by the field each is for.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public array $answers = [],
        public array $messages = [],
        public ?string $draft = null,
        public string $status = self::IDLE,
        public ?string $error = null,
        public ?string $entryId = null,
        public ?string $userId = null,
        public array $usage = ['input' => 0, 'output' => 0],
        public array $examples = [],
        public array $images = [],
        public ?string $source = null,
        public ?string $blueprint = null,
        public ?string $appliedAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
        $this->createdAt ??= Carbon::now()->toIso8601String();
        $this->updatedAt ??= $this->createdAt;
    }

    /**
     * @param  array<string, string>  $answers
     * @param  array<int, string>  $examples
     */
    public static function start(string $type, array $answers, ?string $userId = null, array $examples = []): self
    {
        return new self(id: (string) Str::ulid(), type: $type, answers: $answers, userId: $userId, examples: $examples);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            type: (string) $data['type'],
            answers: (array) ($data['answers'] ?? []),
            messages: (array) ($data['messages'] ?? []),
            draft: $data['draft'] ?? null,
            status: (string) ($data['status'] ?? self::IDLE),
            error: $data['error'] ?? null,
            entryId: $data['entry_id'] ?? null,
            userId: $data['user_id'] ?? null,
            usage: array_merge(['input' => 0, 'output' => 0], (array) ($data['usage'] ?? [])),
            examples: (array) ($data['examples'] ?? []),
            images: (array) ($data['images'] ?? []),
            source: $data['source'] ?? null,
            blueprint: $data['blueprint'] ?? null,
            appliedAt: $data['applied_at'] ?? null,
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
        );
    }

    public function addMessage(string $role, string $content): void
    {
        $this->messages[] = ['role' => $role, 'content' => $content, 'at' => Carbon::now()->toIso8601String()];
    }

    /**
     * The draft's title, for lists, before any entry exists.
     */
    public function title(): string
    {
        if ($this->draft && preg_match('/^title:\s*(.+)$/m', $this->draft, $m)) {
            return trim($m[1], " \t\"'");
        }

        return (string) (collect($this->answers)->filter()->first() ?? 'Untitled');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'answers' => $this->answers,
            'messages' => $this->messages,
            'draft' => $this->draft,
            'status' => $this->status,
            'error' => $this->error,
            'entry_id' => $this->entryId,
            'user_id' => $this->userId,
            'usage' => $this->usage,
            'examples' => $this->examples,
            'images' => $this->images,
            'source' => $this->source,
            'blueprint' => $this->blueprint,
            'applied_at' => $this->appliedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
