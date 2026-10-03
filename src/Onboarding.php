<?php

namespace NineteenNinetyFour\Ghostwriter;

use Illuminate\Support\Facades\File;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Facades\Addon;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry;

/**
 * Getting started: the steps that take a site from installed to writing,
 * in the order they are worth doing, each with whether it is done. Every
 * step is read from the state of the site, so nothing has to be ticked off
 * by hand and a step undone (a guide deleted) shows as undone again.
 */
class Onboarding
{
    public function __construct(
        private Studio $studio,
        private Settings $settings,
        private TypeRepository $types,
        private WorkStates $states,
        private GuideStore $guides,
        private PlanStore $ideas,
        private SessionGuard $sessions,
        private Providers $providers,
    ) {}

    /**
     * @return array<int, array{key: string, title: string, text: string, done: bool, working: bool, optional: bool, detail: ?string, action: ?array<string, mixed>}>
     */
    public function steps(): array
    {
        $collections = $this->types->collections();
        $configured = $this->studio->configured();
        // Only those who may change the settings are sent to them.
        $settingsUrl = $this->settings->urlForCurrentUser();

        $learned = $collections->sum(fn ($collection) => $this->types->forCollection($collection->handle())->count());
        $suggested = $collections->sum(fn ($collection) => count($this->states->suggestions($collection->handle())->suggestions));
        $kindsWorking = $collections->contains(fn ($collection) => $this->states->suggestions($collection->handle())->isWorking() || $this->states->analysis($collection->handle())->isWorking());
        $ideas = count($this->ideas->ideas());
        $plan = $this->states->plan();
        $pending = count($plan->pending);
        $voice = $this->guides->guide(Guide::VOICE)->exists();
        $voiceState = $this->states->guide(Guide::VOICE);
        $imagery = $this->guides->guide(Guide::IMAGERY)->exists();
        $imageryState = $this->states->guide(Guide::IMAGERY);
        $names = $collections->map->title()->implode(', ');

        $settingsLink = $settingsUrl ? ['type' => 'link', 'label' => 'Open the settings', 'url' => $settingsUrl] : null;

        return [
            [
                'key' => 'key',
                'title' => 'Connect a model',
                'text' => 'Ghostwriter writes with Claude, ChatGPT or Gemini, on your own account. Add the API key to your .env file; it is read from there and never stored. Or choose OpenRouter in the settings and Connect with OpenRouter, with no key to copy.',
                'done' => $configured,
                'working' => false,
                'optional' => false,
                'detail' => $configured
                    ? 'Writing with '.$this->providerName($this->studio->provider()).'.'
                    : $this->missingKey(),
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
                'done' => $voice,
                'working' => $voiceState->isWorking(),
                'optional' => false,
                'detail' => $voiceState->hasFailed() ? $voiceState->error : null,
                'action' => $voice
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
                'done' => $imagery,
                'working' => $imageryState->isWorking(),
                'optional' => true,
                'detail' => $imageryState->hasFailed() ? $imageryState->error : null,
                'action' => $imagery
                    ? ['type' => 'link', 'label' => 'Review the image style', 'url' => cp_route('ghostwriter.imagery.show')]
                    : ['type' => 'post', 'label' => 'Describe the images', 'url' => cp_route('ghostwriter.imagery.scan'), 'data' => ['collections' => $collections->map->handle()->values()->all()], 'needs_key' => true],
            ],
            [
                'key' => 'plan',
                'title' => 'Plan what to write',
                'text' => 'Ghostwriter reads the whole site and suggests entries it is missing. Keep the good ones on the content plan; each opens a new entry with its brief filled in.',
                'done' => $ideas > 0,
                'working' => $plan->isWorking(),
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
                'done' => $this->sessions->visible(Presenter::viewer()) !== [],
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
            'can_change_settings' => $this->settings->canChange(),
            'settings_url' => $this->settings->urlForCurrentUser(),
            // Set in config/ghostwriter.php, so not to be chosen here.
            'collections_locked' => $this->settings->isOverridden('collections'),
            'voice_locked' => $this->settings->isOverridden('voice_collections'),
            'provider' => $this->providerName($this->studio->provider()),
            'key_name' => $this->keyName($this->studio->provider()),
            'collections' => Collections::all()->map(fn ($collection) => [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'entries' => Entry::query()->where('collection', $collection->handle())->where('published', true)->count(),
                'write_for' => in_array($collection->handle(), $enabled, true),
                'chosen' => in_array($collection->handle(), $chosen, true),
                'voice' => $forVoice === [] || in_array($collection->handle(), $forVoice, true),
            ])->values()->all(),
            'voice' => $this->guide($this->guides->guide(Guide::VOICE)->body, $this->states->guide(Guide::VOICE)),
            'imagery' => $this->guide($this->guides->guide(Guide::IMAGERY)->body, $this->states->guide(Guide::IMAGERY)),
            'kinds' => $this->types->collections()->map(fn ($collection) => [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'state' => $this->states->suggestions($collection->handle())->status,
                'error' => $this->states->suggestions($collection->handle())->error,
                'suggestions' => array_map(fn (array $kind) => array_intersect_key($kind, array_flip(['id', 'title', 'description', 'why'])) + [
                    // The entries that show it, so the person can judge it.
                    'titles' => array_slice(array_values(array_filter(array_map(fn (string $id) => Entry::find($id)?->get('title'), (array) ($kind['examples'] ?? [])))), 0, 3),
                    'learn_url' => cp_route('ghostwriter.kinds.learn', [$collection->handle(), $kind['id']]),
                    'dismiss_url' => cp_route('ghostwriter.kinds.dismiss', [$collection->handle(), $kind['id']]),
                ], $this->states->suggestions($collection->handle())->suggestions),
                'learning' => $this->states->analysis($collection->handle())->toArray(),
                'types' => $this->types->forCollection($collection->handle())->map(fn (ContentType $type) => ['title' => $type->title, 'url' => cp_route('ghostwriter.types.edit', $type->handle)])->values()->all(),
                'suggest_url' => cp_route('ghostwriter.kinds.suggest', $collection->handle()),
                'learn_all_url' => cp_route('ghostwriter.kinds.learn_all', $collection->handle()),
            ])->values()->all(),
            'plan' => [
                'ideas' => count(array_filter($this->ideas->ideas(), fn (Idea $idea) => $idea->isOpen())),
                'pending' => count(($plan = $this->states->plan())->pending),
                'status' => $plan->status,
                'error' => $plan->error,
                'url' => cp_route('ghostwriter.plan.show'),
            ],
        ];
    }

    /**
     * A guide as the wizard shows it: its opening, rendered, and how the job
     * writing it stands.
     *
     * @return array<string, mixed>
     */
    private function guide(string $markdown, GuideState $state): array
    {
        $opening = mb_substr(trim($markdown), 0, 900);

        return [
            'exists' => trim($markdown) !== '',
            'status' => $state->status,
            'error' => $state->error,
            'scanned' => count($state->scanned),
            'excerpt' => $opening === '' ? '' : (string) (new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]))->convert($opening.(mb_strlen(trim($markdown)) > 900 ? ' …' : '')),
        ];
    }

    /**
     * How far setup has got. Only the required steps are counted, so the bar
     * reaches the end when setup is complete; the optional ones are offered
     * as the next step once the required ones are done.
     *
     * @return array{done: int, total: int, complete: bool, hidden: bool, can_toggle: bool, next: ?array<string, mixed>}
     */
    public function progress(): array
    {
        $steps = $this->steps();
        $required = array_filter($steps, fn (array $step) => ! $step['optional']);
        $next = null;

        foreach ([false, true] as $optional) {
            foreach ($steps as $i => $step) {
                if ($step['optional'] === $optional && ! $step['done']) {
                    $next ??= ['number' => $i + 1, 'key' => $step['key'], 'title' => $step['title'], 'optional' => $step['optional']];
                }
            }
        }

        return [
            'done' => count(array_filter($required, fn (array $step) => $step['done'])),
            'total' => count($required),
            'complete' => ! array_filter($required, fn (array $step) => ! $step['done']),
            'hidden' => $this->hidden(),
            // Hiding Get started hides it for the whole site, so it is for
            // those who look after Ghostwriter's settings.
            'can_toggle' => $this->settings->canChange(),
            'next' => $next,
        ];
    }

    /**
     * Choose, from Get started, which collections Ghostwriter writes for and
     * learns the voice from. Every collection ticked is saved as none, which
     * means all, so a collection added later is included too.
     *
     * @param  array<int, string>|null  $write
     * @param  array<int, string>|null  $voice
     */
    public function chooseCollections(?array $write, ?array $voice): void
    {
        $all = Collections::all()->map->handle()->values()->all();
        $tidy = fn (array $chosen) => array_values(array_intersect($all, $chosen));
        $settings = Addon::get(Settings::ADDON)->settings();

        foreach (['collections' => $write, 'voice_collections' => $voice] as $key => $chosen) {
            if ($chosen === null || $this->settings->isOverridden($key)) {
                continue;
            }

            $chosen = $tidy($chosen);
            $settings->set($key, count($chosen) === count($all) ? [] : $chosen);
        }

        $settings->save();
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

    /**
     * What to do when the chosen provider has no key: add it, or, when
     * another provider's key is already there, choose that one instead.
     */
    private function missingKey(): string
    {
        $provider = $this->studio->provider();
        $wanted = $this->keyName($provider);
        $keys = $this->providers->keyStatus();
        $others = array_values(array_filter(Providers::TEXT, fn (string $other) => $other !== $provider && ($keys[Credentials::ENV[$other]] ?? false)));

        $advice = match (true) {
            $provider === 'openrouter' => 'Connect with OpenRouter in the settings, or add OPENROUTER_API_KEY to .env, then reload this page.',
            $wanted !== null => "Add {$wanted} to .env, then reload this page.",
            default => "\"{$provider}\" is not a provider Ghostwriter can write with. Choose Claude, ChatGPT, Gemini or OpenRouter in the settings.",
        };

        return $others === []
            ? $advice
            : $advice.' '.Credentials::ENV[$others[0]].' is set already: choose '.$this->providerName($others[0]).' in the settings to write with it.';
    }

    /**
     * The .env variable a provider's key is read from.
     */
    private function keyName(string $provider): ?string
    {
        return in_array($provider, Providers::TEXT, true) ? Credentials::ENV[$provider] : null;
    }

    private function providerName(string $provider): string
    {
        return ['anthropic' => 'Claude (Anthropic)', 'openai' => 'ChatGPT (OpenAI)', 'gemini' => 'Gemini (Google)', 'openrouter' => 'OpenRouter'][$provider] ?? $provider;
    }
}
