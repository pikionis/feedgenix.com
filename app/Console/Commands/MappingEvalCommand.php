<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\AI\CallEstimate;
use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Exceptions\AiException;
use App\Domain\Credits\Actions\ReserveCredits;
use App\Domain\Credits\Actions\SettleReservation;
use App\Domain\Credits\Exceptions\InsufficientCredits;
use App\Domain\Mapping\Data\MappingInput;
use App\Domain\Mapping\Data\Proposal;
use App\Domain\Mapping\Evals\CaseScore;
use App\Domain\Mapping\Evals\MappingEval;
use App\Domain\Mapping\MappingPrompt;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Staff tool: score the mapper on the synthetic eval feeds. Without --ai it scores the free draft
 * (no cost). With --ai the configured model is asked too, paid from a workspace's credits through
 * the gateway like any call; --record keeps its answers so a model or prompt change can be compared
 * (ADR 0004: a model or prompt change must pass the recorded evals). Fails below the baseline.
 */
#[AsCommand(name: 'mapping:eval', description: 'Score the mapper on the synthetic eval feeds (optionally with the AI, paid from a workspace)')]
#[Signature('mapping:eval
        {--ai : Ask the configured AI too (spends credits)}
        {--workspace= : Workspace id or slug whose credits pay for --ai}
        {--record : Save the AI answers under resources/evals/mapping/recorded}')]
final class MappingEvalCommand extends Command
{
    public function handle(MappingEval $eval, TenantContext $context): int
    {
        $workspace = null;
        if ((bool) $this->option('ai')) {
            $key = (string) $this->option('workspace');
            $workspace = ctype_digit($key) ? Tenant::query()->find((int) $key) : Tenant::query()->where('slug', $key)->first();
            if (! $workspace instanceof Tenant) {
                $this->error('--ai needs --workspace: the workspace whose credits pay for the calls.');

                return self::INVALID;
            }
        }

        $scores = [];
        foreach (MappingEval::cases() as $case) {
            $suggest = $workspace instanceof Tenant ? $this->asker($context, $workspace, (string) $case['name']) : null;
            $scores[] = $score = $eval->score($case, $suggest);
            $this->line(sprintf('%-20s precision %5.1f%%  recall %5.1f%%  %d/%d', $score->name, $score->precision() * 100, $score->recall() * 100, $score->right, $score->expected));
            foreach ($score->mistakes as $mistake) {
                $this->line("    ✗ {$mistake}");
            }
        }

        $total = CaseScore::total($scores);
        $this->info(sprintf('Total: precision %.1f%%, recall %.1f%%, wrong golden %d, protected in text %d.', $total['precision'] * 100, $total['recall'] * 100, $total['wrong_golden'], $total['protected_in_text']));

        $baseline = (array) (json_decode((string) file_get_contents(resource_path('evals/mapping/baseline.json')), true)['free_draft'] ?? []);
        $failed = $total['wrong_golden'] > 0 || $total['protected_in_text'] > 0
            || $total['precision'] < (float) ($baseline['precision'] ?? 1) || $total['recall'] < (float) ($baseline['recall'] ?? 0);
        if ($failed) {
            $this->error('Below the baseline (resources/evals/mapping/baseline.json), or a golden-rule failure.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return \Closure(MappingInput, ?string): list<Proposal> */
    private function asker(TenantContext $context, Tenant $workspace, string $case): \Closure
    {
        return fn (MappingInput $input, ?string $locale): array => $context->run($workspace->id, function () use ($input, $locale, $workspace, $case): array {
            $prompt = resolve(MappingPrompt::class);
            try {
                $amount = resolve(CallEstimate::class)->total($prompt->requests($workspace->id, null, $input, $locale));
                $reservation = resolve(ReserveCredits::class)->handle("eval:{$case}:".now()->getTimestamp(), $amount);
            } catch (AiException|InsufficientCredits $unavailable) {
                $this->warn("    AI skipped for {$case}: {$unavailable->getMessage()}");

                return [];
            }

            $proposals = [];
            $recorded = [];
            try {
                foreach ($prompt->requests($workspace->id, $reservation->id, $input, $locale) as $request) {
                    $values = resolve(AiClient::class)->generate($request)->values;
                    $recorded[] = $values;
                    $proposals = [...$proposals, ...$prompt->proposals($input, $values)];
                }
            } catch (AiException $failure) {
                $this->warn("    AI failed for {$case}: {$failure->reason}");
            } finally {
                resolve(SettleReservation::class)->handle($reservation->id);
            }

            if ((bool) $this->option('record') && $recorded !== []) {
                $directory = resource_path('evals/mapping/recorded');
                if (! is_dir($directory)) {
                    mkdir($directory, 0775, true);
                }
                file_put_contents("{$directory}/{$case}.json", json_encode(['prompt' => MappingPrompt::NAME.'@'.MappingPrompt::VERSION, 'answers' => $recorded], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
            }

            return $proposals;
        });
    }
}
