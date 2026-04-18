<?php

declare(strict_types=1);

namespace App\Services\Mapping;

use App\Models\FieldMapping;
use App\Models\Project;
use App\Models\Upload;
use App\Services\AI\ClaudeApiClient;
use App\Services\Validation\GoldenRuleValidator;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Generate a FieldMapping for a project upload.
 *
 * Pipeline:
 *   1. Heuristic pass — GoldenRuleValidator splits protected vs. regular columns,
 *      TranslatableFieldDetector classifies the regulars (translatable / code / numeric / empty).
 *   2. AI pass (optional) — Claude verifies ambiguous columns and confirms the
 *      dominant source language. Skipped if no API key or caller opts out.
 *   3. Persist — writes a `field_mappings` row and updates the project status.
 *
 * Inputs come from an `Upload` (column_names + sample_rows). The service is
 * tenant-agnostic: the `Project` supplies team context only through the relation,
 * so callers don't need to thread team_id.
 */
class FieldMappingService
{
    public function __construct(
        private readonly ClaudeApiClient $claude,
        private readonly GoldenRuleValidator $goldenRule,
        private readonly TranslatableFieldDetector $detector,
    ) {}

    /**
     * Build a mapping for an upload and persist it as a FieldMapping row.
     */
    public function generate(Project $project, Upload $upload, bool $useAi = true): FieldMapping
    {
        $columns = $upload->column_names ?? [];
        $samples = $upload->sample_rows ?? [];

        if (empty($columns)) {
            throw new RuntimeException("Upload #{$upload->id} has no column_names — analyze it first.");
        }

        $classification = $this->classify($columns, $samples);

        $aiConfidence = $classification['confidence'];
        $aiModel = null;

        if ($useAi && $this->isAiConfigured()) {
            try {
                $refined = $this->refineWithClaude($columns, $samples, $classification);
                $classification = $this->mergeAiRefinement($classification, $refined);
                $aiConfidence = max($aiConfidence, $refined['confidence'] ?? 0.0);
                $aiModel = $refined['model'] ?? null;
            } catch (\Throwable $e) {
                Log::warning('Field mapping AI refinement failed, keeping heuristic result', [
                    'project_id' => $project->id,
                    'upload_id' => $upload->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $sourceLang = $classification['source_language']
            ?? $this->detector->detectDominantLanguage($samples, $classification['translatable_fields']);

        return FieldMapping::updateOrCreate(
            ['project_id' => $project->id, 'upload_id' => $upload->id],
            [
                'mapping' => $classification['mapping'],
                'translatable_fields' => $classification['translatable_fields'],
                'protected_fields' => $classification['protected_fields'],
                'skipped_fields' => $classification['skipped_fields'],
                'ambiguous_fields' => $classification['ambiguous_fields'],
                'detected_source_language' => $sourceLang,
                'ai_confidence' => round($aiConfidence, 2),
                'ai_model' => $aiModel,
                'user_approved' => false,
                'approved_at' => null,
            ]
        );
    }

    /**
     * Heuristic-only classification. No network calls.
     *
     * @return array{
     *   mapping: array<string, array{kind: string, confidence: float, source_column: string, notes?: string}>,
     *   translatable_fields: string[],
     *   protected_fields: string[],
     *   skipped_fields: string[],
     *   ambiguous_fields: string[],
     *   confidence: float,
     *   source_language: ?string,
     * }
     */
    public function classify(array $columns, array $sampleRows): array
    {
        $split = $this->goldenRule->classify($columns);
        $protected = $split['protected'];
        $regular = $split['regular'];

        $perColumn = $this->detector->classifyAll($sampleRows, $regular);

        $mapping = [];
        $translatable = [];
        $skipped = [];
        $ambiguous = [];

        foreach ($protected as $col) {
            $mapping[$col] = [
                'kind' => 'protected',
                'confidence' => 1.0,
                'source_column' => $col,
                'notes' => 'Golden Rule — pass-through, never translated.',
            ];
        }

        foreach ($regular as $col) {
            $info = $perColumn[$col];
            $kind = $info['kind'];

            $mapping[$col] = [
                'kind' => $kind,
                'confidence' => round($info['confidence'], 2),
                'source_column' => $col,
            ];

            if ($kind === TranslatableFieldDetector::KIND_TRANSLATABLE) {
                // Low-confidence translatables need AI review.
                if ($info['translatable_ratio'] < 0.85) {
                    $ambiguous[] = $col;
                }
                $translatable[] = $col;
            } else {
                $skipped[] = $col;
            }
        }

        $confidences = array_column($mapping, 'confidence');
        $overall = $confidences === [] ? 0.0 : (array_sum($confidences) / count($confidences));

        return [
            'mapping' => $mapping,
            'translatable_fields' => $translatable,
            'protected_fields' => $protected,
            'skipped_fields' => $skipped,
            'ambiguous_fields' => $ambiguous,
            'confidence' => $overall,
            'source_language' => null,
        ];
    }

    private function isAiConfigured(): bool
    {
        return !empty(config('services.anthropic.api_key'));
    }

    /**
     * Ask Claude to (a) reclassify ambiguous columns and (b) name the dominant source language.
     *
     * @return array{
     *   adjustments: array<string, string>,
     *   source_language: ?string,
     *   confidence: float,
     *   model: string,
     * }
     */
    private function refineWithClaude(array $columns, array $sampleRows, array $classification): array
    {
        $model = (string) config('services.anthropic.model_mapping', 'claude-opus-4-7');

        $ambiguous = $classification['ambiguous_fields'];

        $system = "You are a data classifier for a product-feed translation platform.\n" .
            "Classify each supplier CSV column as one of: translatable | code | numeric | empty | protected.\n" .
            "GOLDEN RULE: columns containing prices, stock, barcodes, SKUs, weights, or dimensions\n" .
            "must be marked 'protected' and never translated.\n" .
            "Also detect the dominant source language (ISO 639-1: en, fr, de, it, es, …).\n" .
            "Return ONLY valid JSON. No prose, no markdown.";

        $user = "COLUMNS: " . implode(', ', $columns) . "\n\n"
            . "SAMPLE ROWS (first 3):\n"
            . $this->formatSamples(array_slice($sampleRows, 0, 3)) . "\n\n"
            . "HEURISTIC CLASSIFICATION (needs verification):\n"
            . json_encode($this->slimMapping($classification['mapping']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n"
            . "AMBIGUOUS COLUMNS TO REVIEW CAREFULLY: " . ($ambiguous ? implode(', ', $ambiguous) : '(none)') . "\n\n"
            . "Respond with this exact JSON shape:\n"
            . "{\n"
            . "  \"columns\": { \"<column>\": { \"kind\": \"translatable|code|numeric|empty|protected\", \"reason\": \"…\" } },\n"
            . "  \"source_language\": \"en\",\n"
            . "  \"confidence\": 0.92\n"
            . "}";

        $res = $this->claude->message($model, $system, $user, 0.1);
        $parsed = $this->parseJson($res['content']);

        $adjustments = [];
        foreach (($parsed['columns'] ?? []) as $col => $info) {
            if (is_array($info) && isset($info['kind'])) {
                $adjustments[$col] = (string) $info['kind'];
            }
        }

        return [
            'adjustments' => $adjustments,
            'source_language' => isset($parsed['source_language']) ? (string) $parsed['source_language'] : null,
            'confidence' => (float) ($parsed['confidence'] ?? 0.0),
            'model' => $model,
        ];
    }

    /**
     * Apply Claude's reclassifications to the heuristic result.
     * Golden-Rule protected columns are immutable — AI cannot override.
     */
    private function mergeAiRefinement(array $classification, array $refinement): array
    {
        $translatable = array_flip($classification['translatable_fields']);
        $skipped = array_flip($classification['skipped_fields']);
        $protected = array_flip($classification['protected_fields']);
        $ambiguous = array_flip($classification['ambiguous_fields']);

        foreach ($refinement['adjustments'] as $col => $kind) {
            if (isset($protected[$col])) {
                continue; // Golden Rule wins — always.
            }

            unset($translatable[$col], $skipped[$col], $ambiguous[$col]);

            $classification['mapping'][$col]['kind'] = $kind;
            $classification['mapping'][$col]['ai_verified'] = true;

            if ($kind === 'translatable') {
                $translatable[$col] = true;
            } elseif ($kind === 'protected') {
                // AI can promote to protected if Golden-Rule patterns missed something.
                $protected[$col] = true;
                $classification['mapping'][$col]['notes'] = 'Promoted to protected by AI review.';
            } else {
                $skipped[$col] = true;
            }
        }

        $classification['translatable_fields'] = array_keys($translatable);
        $classification['skipped_fields'] = array_keys($skipped);
        $classification['protected_fields'] = array_keys($protected);
        $classification['ambiguous_fields'] = array_keys($ambiguous);
        $classification['source_language'] = $refinement['source_language'];

        return $classification;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function formatSamples(array $rows): string
    {
        $out = '';
        foreach ($rows as $i => $row) {
            $out .= 'Row ' . ($i + 1) . ': ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        }
        return $out === '' ? '(no sample rows)' : $out;
    }

    /** Compress the mapping for the AI prompt so we don't waste tokens on boilerplate. */
    private function slimMapping(array $mapping): array
    {
        $out = [];
        foreach ($mapping as $col => $info) {
            $out[$col] = [
                'kind' => $info['kind'],
                'confidence' => $info['confidence'],
            ];
        }
        return $out;
    }

    private function parseJson(string $content): array
    {
        // Strip ```json fences if present.
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $m)) {
            $content = $m[1];
        }
        $data = json_decode(trim($content), true);
        if (!is_array($data)) {
            throw new RuntimeException('Field mapping AI returned non-JSON response.');
        }
        return $data;
    }
}
