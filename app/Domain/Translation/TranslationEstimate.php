<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use App\Domain\AI\AiPurpose;
use App\Domain\AI\CallEstimate;
use App\Domain\AI\Exceptions\AiException;
use App\Domain\Credits\Millicredits;

/**
 * What a translation plan will cost, in credits, before it runs: "about" from typical token counts
 * (about three characters a token, translations a little longer than their source), and "at most"
 * from the same bounds each call reserves (a byte a token, the answer room of UnitSizing). The
 * translation memory's repeats cost nothing. Never a provider cost.
 */
final readonly class TranslationEstimate
{
    /** Instructions, schema and framing per call, in tokens (typical). */
    private const int CALL_TOKENS = 700;

    public function __construct(private CallEstimate $estimate) {}

    /**
     * @param  list<array{field: string, target_locale: string, mode: string, texts: int, characters: int, bytes: int, memory: int}>  $work
     * @return array{available: bool, unavailable_reason: string|null, about_credits: int|null, at_most_credits: int|null}
     */
    public function of(array $work): array
    {
        $characters = 0;
        $bytes = 0;
        $calls = 0;
        $answerRoom = 0;
        foreach ($work as $line) {
            $texts = $line['texts'] - $line['memory'];
            if ($line['mode'] !== 'translate' || $texts <= 0) {
                continue;
            }
            $characters += (int) round($line['characters'] * $texts / max(1, $line['texts']));
            $bytes += $line['bytes'];
            $lineCalls = max((int) ceil($texts / UnitSizing::maxItems($line['field'])), (int) ceil($line['bytes'] / UnitSizing::MAX_BYTES));
            $calls += $lineCalls;
            $answerRoom += UnitSizing::maxOutputTokens($line['bytes'], $texts) + 512 * ($lineCalls - 1);
        }

        try {
            $about = $this->estimate->charge(AiPurpose::Translation, (int) ceil($characters / 3) + $calls * self::CALL_TOKENS, (int) ceil($characters / 3 * 1.25) + $calls * 40);
            $atMost = $this->estimate->charge(AiPurpose::Translation, $bytes + $calls * 2_600, $answerRoom);
        } catch (AiException $unavailable) {
            return ['available' => false, 'unavailable_reason' => $unavailable->reason, 'about_credits' => null, 'at_most_credits' => null];
        }

        return [
            'available' => true,
            'unavailable_reason' => null,
            'about_credits' => $calls === 0 ? 0 : Millicredits::toCredits($about),
            'at_most_credits' => $calls === 0 ? 0 : Millicredits::toCredits($atMost),
        ];
    }
}
