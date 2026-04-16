<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * Generate and return a CSV download for the project's translated products.
     */
    public function download(Request $request, Project $project): StreamedResponse
    {
        $team = $request->attributes->get('team');

        if ($project->team_id !== $team->id) {
            abort(403, 'This project does not belong to your team.');
        }

        $mapping = $project->latestMapping;

        if (! $mapping) {
            abort(404, 'No mapping found for this project.');
        }

        $filename = sprintf(
            '%s-translated-%s.csv',
            str_replace(' ', '_', strtolower($project->name)),
            now()->format('Ymd-His'),
        );

        return response()->streamDownload(function () use ($project, $mapping) {
            $handle = fopen('php://output', 'w');

            // Build header row from mapping
            $sourceFields = array_keys($mapping->mapping ?? []);
            $translatableFields = $mapping->translatable_fields ?? [];
            $targetLanguages = $project->target_languages ?? [];

            // Create headers: original fields + translated fields per language
            $headers = $sourceFields;
            foreach ($translatableFields as $field) {
                foreach ($targetLanguages as $lang) {
                    $headers[] = "{$field}_{$lang}";
                }
            }

            fputcsv($handle, $headers);

            // Stream products in chunks
            $project->products()
                ->with('translations')
                ->orderBy('row_number')
                ->chunk(500, function ($products) use ($handle, $sourceFields, $translatableFields, $targetLanguages) {
                    foreach ($products as $product) {
                        $row = [];

                        // Source data fields
                        foreach ($sourceFields as $field) {
                            $row[] = $product->source_data[$field] ?? '';
                        }

                        // Translated fields per language
                        $translationsByFieldLang = $product->translations
                            ->groupBy(fn ($t) => $t->field_name . '|' . $t->target_language);

                        foreach ($translatableFields as $field) {
                            foreach ($targetLanguages as $lang) {
                                $key = "{$field}|{$lang}";
                                $translation = $translationsByFieldLang->get($key)?->first();
                                $row[] = $translation?->translated_text ?? '';
                            }
                        }

                        fputcsv($handle, $row);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
