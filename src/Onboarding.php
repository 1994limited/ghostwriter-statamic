<?php

namespace NineteenNinetyFour\Ghostwriter;

use Illuminate\Support\Facades\File;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Images\ImageryGuide;
use NineteenNinetyFour\Ghostwriter\Images\ImageryState;
use NineteenNinetyFour\Ghostwriter\Planning\IdeaRepository;
use NineteenNinetyFour\Ghostwriter\Planning\PlanState;
use NineteenNinetyFour\Ghostwriter\Sessions\SessionRepository;
use NineteenNinetyFour\Ghostwriter\Types\ContentType;
use NineteenNinetyFour\Ghostwriter\Types\KindSuggestions;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\Types\TypeState;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceGuide;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceState;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Getting started: the steps that take a site from installed to writing,
 * in the order they are worth doing, each with whether it is done. Every
 * step is read from the state of the site, so nothing has to be ticked off
 * by hand and a step undone (a guide deleted) shows as undone again.
 */
class Onboarding
{
    private const KEYS = ['anthropic' => 'ANTHROPIC_API_KEY', 'openai' => 'OPENAI_API_KEY', 'gemini' => 'GEMINI_API_KEY'];

    public function __construct(
        private Studio $studio,
        private Settings $settings,
        private TypeRepository $types,
        private TypeState $typeState,
        private KindSuggestions $kinds,
        private VoiceGuide $voice,
        private VoiceState $voiceState,
        private ImageryGuide $imagery,
        private ImageryState $imageryState,
        private IdeaRepository $ideas,
        private PlanState $planState,
        private SessionRepository $sessions,
    ) {}

    /**
     * @return array<int, array{key: string, title: string, text: string, done: bool, working: bool, optional: bool, detail: ?string, action: ?array<string, mixed>}>
     */
    public function steps(): array
    {
        $collections = $this->types->collections();
        $configured = $this->studio->configured();
        $settingsUrl = $this->settings->url();

        $learned = $collections->sum(fn ($collection) => $this->types->forCollection($collection->handle())->count());
        $suggested = $collections->sum(fn ($collection) => count($this->kinds->get($collection->handle())['suggestions']));
        $kindsWorking = $collections->contains(fn ($collection) => $this->kinds->get($collection->handle())['status'] === KindSuggestions::WORKING || $this->typeState->get($collection->handle())['status'] === TypeState::WORKING);
        $ideas = $this->ideas->all()->count();
        $pending = count($this->planState->get()['pending']);
        $names = $collections->map->title()->implode(', ');

        $settingsLink = $settingsUrl ? ['type' => 'link', 'label' => 'Open the settings', 'url' => $settingsUrl] : null;

        return [
            [
                'key' => 'key',
                'title' => 'Connect a model',
                'text' => 'Ghostwriter writes with Claude, ChatGPT or Gemini, on your own account. Add the API key to your .env file; it is read from there and never stored.',
                'done' => $configured,
                'working' => false,
                'optional' => false,
                'detail' => $configured
                    ? 'Writing with '.$this->providerName($this->studio->provider()).'.'
                    : 'Add '.(self::KEYS[$this->studio->provider()] ?? 'the API key').' to .env, then reload this page.',
                'action' => $settingsLink,
            ],
            [
                'key' => 'collections',
                'title' => 'Choose where it writes',
                'text' => 'Ghostwriter writes for every collection unless you choose some. A "Write with Ghostwriter" button appears on their new entries, and "Edit with Ghostwriter" on existing ones.',
                'done' => $collections->isNotEmpty(),
                'working' => false,
                'optional' => false,
                'detail' => $this->settings->collections() === [] ? "Writing for every collection: {$names}." : "Writing for {$names}.",
                'action' => $settingsLink,
            ],
            [
                'key' => 'voice',
                'title' => 'Learn your voice',
                'text' => 'Ghostwriter reads what you have published and writes a guide to how you sound. Everything it writes follows the guide, which you can edit.',
                'done' => $this->voice->exists(),
                'working' => $this->voiceState->get()['status'] === VoiceState::WORKING,
                'optional' => false,
                'detail' => $this->voiceState->get()['status'] === VoiceState::FAILED ? $this->voiceState->get()['error'] : null,
                'action' => $this->voice->exists()
                    ? ['type' => 'link', 'label' => 'Review the guide', 'url' => cp_route('ghostwriter.voice.show')]
                    : ['type' => 'post', 'label' => 'Write the voice guide', 'url' => cp_route('ghostwriter.voice.scan'), 'data' => [], 'needs_key' => true],
            ],
            [
                'key' => 'kinds',
                'title' => 'Teach it your kinds of content',
                'text' => 'Ghostwriter looks at each collection and suggests the kinds of content in it, such as a press release or a case study. Learn the ones you write often and each gets a brief of its own.',
                'done' => $learned > 0,
                'working' => $kindsWorking,
                'optional' => false,
                'detail' => match (true) {
                    $learned > 0 => $learned === 1 ? '1 kind learned.' : "{$learned} kinds learned.",
                    $suggested > 0 => $suggested === 1 ? '1 suggestion is waiting below.' : "{$suggested} suggestions are waiting below.",
                    default => null,
                },
                'action' => $suggested > 0 || $learned > 0
                    ? null
                    : ['type' => 'suggest_kinds', 'label' => 'Suggest kinds', 'needs_key' => true],
            ],
            [
                'key' => 'imagery',
                'title' => 'Describe your images',
                'text' => 'Ghostwriter looks at the pictures your entries use and writes down the house style, so the photographs it finds and the images it makes belong beside them.',
                'done' => $this->imagery->exists(),
                'working' => $this->imageryState->get()['status'] === ImageryState::WORKING,
                'optional' => true,
                'detail' => $this->imageryState->get()['status'] === ImageryState::FAILED ? $this->imageryState->get()['error'] : null,
                'action' => $this->imagery->exists()
                    ? ['type' => 'link', 'label' => 'Review the image style', 'url' => cp_route('ghostwriter.imagery.show')]
                    : ['type' => 'post', 'label' => 'Describe the images', 'url' => cp_route('ghostwriter.imagery.scan'), 'data' => ['collections' => $collections->map->handle()->values()->all()], 'needs_key' => true],
            ],
            [
                'key' => 'plan',
                'title' => 'Plan what to write',
                'text' => 'Ghostwriter reads the whole site and suggests entries it is missing. Keep the good ones on the content plan; each opens a new entry with its brief filled in.',
                'done' => $ideas > 0,
                'working' => $this->planState->get()['status'] === PlanState::WORKING,
                'optional' => true,
                'detail' => $pending > 0 ? ($pending === 1 ? '1 suggestion is waiting to be looked over.' : "{$pending} suggestions are waiting to be looked over.") : null,
                'action' => $ideas > 0 || $pending > 0
                    ? ['type' => 'link', 'label' => 'Open the content plan', 'url' => cp_route('ghostwriter.plan.show')]
                    : ['type' => 'post', 'label' => 'Suggest ideas', 'url' => cp_route('ghostwriter.plan.suggest'), 'data' => [], 'needs_key' => true],
            ],
            [
                'key' => 'write',
                'title' => 'Write something',
                'text' => 'Start a new entry with Ghostwriter beside it. Answer a short brief, talk the draft through, then put it into the entry and save it as usual.',
                'done' => $this->sessions->visibleTo(User::current())->isNotEmpty(),
                'working' => false,
                'optional' => false,
                'detail' => null,
                'action' => $collections->isNotEmpty() ? [
                    'type' => 'write',
                    'label' => 'Start writing',
                    'options' => $collections->map(fn ($collection) => ['label' => $collection->title(), 'url' => $collection->createEntryUrl().'?ghostwriter=new'])->values()->all(),
                ] : null,
            ],
        ];
    }

    /**
     * What each step of the wizard shows beyond its title and status: the
     * collections to choose from, the start of each guide, the kinds
     * suggested and learned, how the plan stands.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        $enabled = $this->types->collections()->map->handle()->all();
        $chosen = $this->settings->collections();
        $forVoice = $this->settings->voiceCollections();

        return [
            'can_change_settings' => (bool) User::current()?->can('edit '.Settings::ADDON.' settings'),
            'settings_url' => $this->settings->url(),
            'provider' => $this->providerName($this->studio->provider()),
            'key_name' => self::KEYS[$this->studio->provider()] ?? null,
            'collections' => Collections::all()->map(fn ($collection) => [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'entries' => Entry::query()->where('collection', $collection->handle())->where('published', true)->count(),
                'write_for' => in_array($collection->handle(), $enabled, true),
                'chosen' => in_array($collection->handle(), $chosen, true),
                'voice' => $forVoice === [] || in_array($collection->handle(), $forVoice, true),
            ])->values()->all(),
            'voice' => $this->guide($this->voice->get(), $this->voiceState->get()),
            'imagery' => $this->guide($this->imagery->get(), $this->imageryState->get()),
            'kinds' => $this->types->collections()->map(fn ($collection) => [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'state' => $this->kinds->get($collection->handle())['status'],
                'error' => $this->kinds->get($collection->handle())['error'],
                'suggestions' => array_map(fn (array $kind) => array_intersect_key($kind, array_flip(['id', 'title', 'description', 'why'])) + [
                    'learn_url' => cp_route('ghostwriter.kinds.learn', [$collection->handle(), $kind['id']]),
                    'dismiss_url' => cp_route('ghostwriter.kinds.dismiss', [$collection->handle(), $kind['id']]),
                ], $this->kinds->get($collection->handle())['suggestions']),
                'learning' => $this->typeState->get($collection->handle()),
                'types' => $this->types->forCollection($collection->handle())->map(fn (ContentType $type) => ['title' => $type->title, 'url' => cp_route('ghostwriter.types.edit', $type->handle)])->values()->all(),
                'suggest_url' => cp_route('ghostwriter.kinds.suggest', $collection->handle()),
                'learn_all_url' => cp_route('ghostwriter.kinds.learn_all', $collection->handle()),
            ])->values()->all(),
            'plan' => [
                'ideas' => $this->ideas->all()->where('status', IdeaRepository::OPEN)->count(),
                'pending' => count($this->planState->get()['pending']),
                'status' => $this->planState->get()['status'],
                'error' => $this->planState->get()['error'],
                'url' => cp_route('ghostwriter.plan.show'),
            ],
        ];
    }

    /**
     * A guide as the wizard shows it: its opening, rendered, and how the job
     * writing it stands.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function guide(string $markdown, array $state): array
    {
        $opening = mb_substr(trim($markdown), 0, 900);

        return [
            'exists' => trim($markdown) !== '',
            'status' => $state['status'],
            'error' => $state['error'],
            'scanned' => count($state['scanned']),
            'excerpt' => $opening === '' ? '' : (string) (new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]))->convert($opening.(mb_strlen(trim($markdown)) > 900 ? ' …' : '')),
        ];
    }

    /**
     * @return array{done: int, total: int, complete: bool, hidden: bool, next: ?array<string, mixed>}
     */
    public function progress(): array
    {
        $steps = $this->steps();
        $required = array_filter($steps, fn (array $step) => ! $step['optional']);
        $done = count(array_filter($steps, fn (array $step) => $step['done']));
        $next = null;

        foreach ($steps as $i => $step) {
            if (! $step['done']) {
                $next = ['number' => $i + 1, 'key' => $step['key'], 'title' => $step['title'], 'optional' => $step['optional']];
                break;
            }
        }

        return [
            'done' => $done,
            'total' => count($steps),
            'complete' => ! array_filter($required, fn (array $step) => ! $step['done']),
            'hidden' => $this->hidden(),
            'next' => $next,
        ];
    }

    public function hidden(): bool
    {
        return File::exists($this->path()) && (bool) (json_decode((string) File::get($this->path()), true)['hidden'] ?? false);
    }

    public function hide(bool $hidden = true): void
    {
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), (string) json_encode(['hidden' => $hidden]));
    }

    private function path(): string
    {
        return dirname((string) config('ghostwriter.sessions_path')).'/onboarding.json';
    }

    private function providerName(string $provider): string
    {
        return ['anthropic' => 'Claude (Anthropic)', 'openai' => 'ChatGPT (OpenAI)', 'gemini' => 'Gemini (Google)'][$provider] ?? $provider;
    }
}
