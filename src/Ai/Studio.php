<?php

namespace NineteenNinetyFour\Ghostwriter\Ai;

use Illuminate\Support\Collection;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Truncated;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\BriefThread;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Brief;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Result;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio as CoreStudio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\SuggestedIdea;
use NineteenNinetyFour\Ghostwriter\Core\Studio\SuggestedKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\UnreadableReply;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Text\TaggedResponse;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Collection as EntryCollection;
use Statamic\Fields\Blueprint;

/**
 * Every call the addon makes to a model for writing, planning and learning
 * the site. The prompts, the calls and reading the replies are Ghostwriter
 * Core's Studio; this turns Statamic's objects into its inputs (through
 * StudioInputs) and its results back into the addon's own.
 *
 * A reply that can't be read throws UnreadableReply (an
 * InvalidArgumentException); a draft or guide still cut off after a retry
 * throws Truncated; provider failures are ProviderExceptions.
 */
class Studio
{
    public function __construct(
        private CoreStudio $studio,
        private StudioInputs $inputs,
        private Settings $settings,
    ) {}

    /**
     * Whether the configured provider has an API key to call with.
     */
    public function configured(): bool
    {
        return $this->studio->configured();
    }

    public function provider(): string
    {
        return $this->settings->provider();
    }

    /**
     * @param  Collection<int, array{title: string, collection: string, url: ?string, text: string}>  $samples
     *
     * @throws ProviderException|Truncated
     */
    public function analyseVoice(Collection $samples): TaggedResponse
    {
        return $this->studio->analyseVoice($this->inputs->voiceSamples($samples));
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     *
     * @throws ProviderException|Truncated
     */
    public function refineVoice(string $guide, array $history, string $request): TaggedResponse
    {
        return $this->studio->refineVoice($guide, $history, $request);
    }

    /**
     * Work out what a collection holds and what to ask before writing for it.
     *
     * @param  array<int, string>  $examples  Entry IDs to model the type on; empty to use the collection's newest.
     *
     * @throws UnreadableReply|ProviderException|Truncated
     */
    public function analyseCollection(EntryCollection $collection, Blueprint $blueprint, ?string $title = null, array $examples = []): ContentType
    {
        $data = $this->studio->analyseType($this->inputs->typeSurvey($collection, $blueprint, $title, $examples))->value;

        $handle = app(TypeRepository::class)->handleFor($title ?: '', $collection->handle());

        return TypeRepository::make($handle, array_filter([
            'collection' => $collection->handle(),
            'blueprint' => $blueprint->handle(),
            'examples' => $examples,
            'title' => $title,
        ]) + $data);
    }

    /**
     * The kinds of content a collection seems to hold, each with the entries
     * that show it, for a person to look over and have taught.
     *
     * @param  array<int, string>  $dismissed  Titles turned down before, not to be suggested again.
     * @return array<int, array{title: string, description: string, why: string, examples: array<int, string>, blueprint: ?string}>
     *
     * @throws UnreadableReply|ProviderException
     */
    public function suggestKinds(EntryCollection $collection, TypeRepository $types, array $dismissed): array
    {
        $survey = $this->inputs->kindSurvey($collection, $types, $dismissed);

        return array_map(fn (SuggestedKind $kind) => $kind->toArray('blueprint'), $this->studio->suggestKinds($survey)->value);
    }

    /**
     * Ideas for entries the site is missing, from what it has and what is
     * already planned.
     *
     * @param  array<int, string>  $collections  Handles of the collections to plan for.
     * @param  array<int, Idea>  $plan  Ideas already on the plan, whatever their status.
     * @return array<int, array{title: string, collection: string, type: ?string, why: string, notes: string}>
     *
     * @throws UnreadableReply|ProviderException
     */
    public function suggestIdeas(array $collections, TypeRepository $types, array $plan, string $voice, string $steer = ''): array
    {
        $ideas = $this->studio->suggestIdeas($this->inputs->planContext($collections, $types, $plan, $voice, $steer))->value;

        return array_map(fn (SuggestedIdea $idea) => $idea->toArray('collection', 'type'), $ideas);
    }

    /**
     * Describe the style of one collection's images from a spread of them.
     *
     * @param  array<int, array{label: string, entry: string, image: Image}>  $samples
     *
     * @throws ProviderException
     */
    public function analyseImagery(string $collectionTitle, array $samples): string
    {
        return $this->studio->analyseImagery($collectionTitle, $this->inputs->imagerySamples($samples))->value;
    }

    /**
     * The piece's whole brief, filled in from the person's quick details or
     * a plan idea (and after "Try again", the card as they left it), for
     * them to check. Anything only they know stays in square brackets.
     *
     * @return Result<Brief>
     *
     * @throws UnreadableReply|ProviderException
     */
    public function fillBrief(Session $session, ContentType $type): Result
    {
        return $this->studio->fillBrief(BriefThread::request($session, $this->inputs->kind($type), $this->inputs->briefTitles($type)));
    }

    /**
     * Run the next turn of a writing session. The session's last message is
     * the colleague's latest input; on the first turn that is the brief.
     * The response carries the tokens used, retries included.
     *
     * @throws ProviderException|Truncated
     */
    public function write(Session $session, ContentType $type, string $voice): TaggedResponse
    {
        return $this->turn($session, $type, $voice)[0];
    }

    /**
     * A writer's turn with what it was given: the conversation and the
     * writer's context, which reading its extras and laying out the draft
     * (DraftLayouts) need as they were.
     *
     * @return array{0: TaggedResponse, 1: Conversation, 2: WriterContext}
     *
     * @throws ProviderException|Truncated
     */
    public function turn(Session $session, ContentType $type, string $voice): array
    {
        $conversation = $this->inputs->conversation($session);
        $context = $this->writerContext($type, $voice);

        return [$this->studio->write($conversation, $context), $conversation, $context];
    }

    /**
     * The brief as the message the writer starts from: the session's
     * answers, or a brief card's answers and working title.
     */
    public function brief(ContentType $type, Session|Brief $brief): string
    {
        return $brief instanceof Brief
            ? $this->studio->brief($this->inputs->kind($type), $brief->answers, $brief->title)
            : $this->studio->brief($this->inputs->kind($type), $brief->answers);
    }

    public function writerInstructions(ContentType $type, string $voice): string
    {
        return $this->studio->writerInstructions($this->writerContext($type, $voice));
    }

    private function writerContext(ContentType $type, string $voice): WriterContext
    {
        $layout = $this->inputs->layout($type);

        return new WriterContext($this->inputs->kind($type), $voice, $layout, app(ImageStudio::class)->describe($type));
    }
}
