<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\WorkStates;
use Statamic\Facades\Collection;
use Throwable;

/**
 * Looks at the images each collection uses and writes the image style guide
 * from them, a section per collection, replacing any guide already there.
 */
class GenerateImageryGuide implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<int, string>  $collections
     */
    public function __construct(public array $collections) {}

    public function subject(): ?string
    {
        return 'guide:imagery';
    }

    public function handle(ImageStudio $images, Studio $studio, GuideStore $guides, WorkStates $states): void
    {
        $this->allowTimeToFinish();

        try {
            $sections = [];
            $seen = [];

            foreach ($this->collections as $handle) {
                $collection = Collection::findByHandle($handle);
                $samples = $collection ? $images->samples($handle, (int) config('ghostwriter.images.guide_samples', 10)) : [];

                // Two images are too few to call a style.
                if (count($samples) < 3) {
                    continue;
                }

                $sections[] = '## '.$collection->title()."\n\n".trim($studio->analyseImagery($collection->title(), $samples));

                foreach ($samples as $sample) {
                    $seen[] = ['title' => $sample['entry'].' ('.$sample['label'].')', 'collection' => $handle];
                }
            }

            if ($sections === []) {
                $states->changeGuide(Guide::IMAGERY, fn (GuideState $state) => $state->fail('None of those collections has enough images to describe a style from. It takes at least three.'));

                return;
            }

            $guides->saveGuide(new Guide(Guide::IMAGERY, "# Image style\n\n".implode("\n\n", $sections)));

            $states->changeGuide(Guide::IMAGERY, function (GuideState $state) use ($seen) {
                $state->succeed();
                $state->messages = [];
                $state->scanned = $seen;
            });
        } catch (Throwable $exception) {
            report($exception);

            $states->changeGuide(Guide::IMAGERY, fn (GuideState $state) => $state->fail($exception->getMessage()));
        }
    }
}
