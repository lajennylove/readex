<?php

namespace App\NativeComponents\Concerns;

use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Dialog;

/**
 * The content-rating toggles (spec §8) for Setup and Settings: adult ratings only after an
 * "I'm 18 or older" confirmation, and at least one rating stays on.
 */
trait ChoosesContentRatings
{
    public const ADULT_RATINGS = ['erotica', 'pornographic'];

    public const CONFIRM_ADULT = "I'm 18 or older";

    /** @var list<string> in config('readex.content_ratings') order */
    public array $ratings = [];

    /**
     * Part of the toggles' native:key. The phone flips a toggle before PHP answers, so a
     * refused change needs a new node to flip it back (the value prop alone didn't change).
     */
    public int $ratingToggles = 0;

    /** Called after the set changes; Settings saves here, Setup waits for Continue. */
    protected function ratingsChanged(): void {}

    public function setRating(string $rating, bool $on): void
    {
        if (! in_array($rating, config('readex.content_ratings'), true)) {
            return;
        }

        $firstAdult = $on && in_array($rating, self::ADULT_RATINGS, true)
            && array_intersect($this->ratings, self::ADULT_RATINGS) === [];

        if (! $firstAdult) {
            $this->applyRating($rating, $on);

            return;
        }

        Dialog::alert('Adult content', 'Erotica and pornographic series are for adults only.', [
            ['label' => 'Cancel', 'style' => 'cancel'],
            self::CONFIRM_ADULT,
        ])->buttonPressed(fn (ButtonPressed $pressed) => $pressed->label === self::CONFIRM_ADULT
            ? $this->applyRating($rating, true)
            : $this->ratingToggles++);
    }

    private function applyRating(string $rating, bool $on): void
    {
        $chosen = $on ? [...$this->ratings, $rating] : array_diff($this->ratings, [$rating]);
        $chosen = array_values(array_intersect(config('readex.content_ratings'), $chosen));

        if ($chosen === []) {
            $this->ratingToggles++;

            return;
        }

        if ($chosen !== $this->ratings) {
            $this->ratings = $chosen;
            $this->ratingsChanged();
        }
    }
}
