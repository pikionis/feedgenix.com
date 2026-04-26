<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tier-driven validation on the project endpoints. The pricing model
 * (pass-through translation cost + subscription) gates target-language counts
 * by plan: free=1, starter=4, pro=7, enterprise=unlimited. Tests pin the
 * boundaries of that contract so a regression can't silently let a free user
 * spin up a 7-language project.
 */
class ProjectControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(string $plan = 'free'): User
    {
        $team = Team::create([
            'name' => 'Acme',
            'owner_id' => 1,
            'slug' => 'acme-' . uniqid(),
            'plan' => $plan,
            'products_used_this_month' => 0,
        ]);
        $user = User::create([
            'name' => 'Tester',
            'email' => 'tester-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'team_id' => $team->id,
            'role' => 'owner',
        ]);
        Sanctum::actingAs($user);
        return $user;
    }

    #[Test]
    public function free_tier_allows_one_target_language_on_create(): void
    {
        $this->actAs('free');

        $this->postJson('/api/projects', [
            'name' => 'P1',
            'source_language' => 'en',
            'target_languages' => ['de'],
        ])->assertCreated();
    }

    #[Test]
    public function free_tier_rejects_two_target_languages_on_create(): void
    {
        $this->actAs('free');

        $this->postJson('/api/projects', [
            'name' => 'P1',
            'source_language' => 'en',
            'target_languages' => ['de', 'fr'],
        ])->assertStatus(422)
          ->assertJsonValidationErrors('target_languages')
          ->assertJsonPath('errors.target_languages.0', 'Your free plan supports up to 1 target language. Upgrade to add more.');
    }

    #[Test]
    public function starter_allows_up_to_four_languages_and_rejects_a_fifth(): void
    {
        $this->actAs('starter');

        // 4 OK
        $this->postJson('/api/projects', [
            'name' => 'P1',
            'source_language' => 'en',
            'target_languages' => ['de', 'fr', 'it', 'es'],
        ])->assertCreated();

        // 5 → 422
        $this->postJson('/api/projects', [
            'name' => 'P2',
            'source_language' => 'en',
            'target_languages' => ['de', 'fr', 'it', 'es', 'nl'],
        ])->assertStatus(422)
          ->assertJsonValidationErrors('target_languages');
    }

    #[Test]
    public function pro_allows_up_to_seven_languages(): void
    {
        $this->actAs('pro');

        $this->postJson('/api/projects', [
            'name' => 'P',
            'source_language' => 'en',
            'target_languages' => ['de', 'fr', 'it', 'es', 'nl', 'pt', 'el'],
        ])->assertCreated();

        $this->postJson('/api/projects', [
            'name' => 'P2',
            'source_language' => 'en',
            'target_languages' => ['de', 'fr', 'it', 'es', 'nl', 'pt', 'el', 'pl'],
        ])->assertStatus(422)
          ->assertJsonValidationErrors('target_languages');
    }

    #[Test]
    public function enterprise_has_no_target_language_cap(): void
    {
        $this->actAs('enterprise');

        $this->postJson('/api/projects', [
            'name' => 'Polyglot',
            'source_language' => 'en',
            'target_languages' => ['de', 'fr', 'it', 'es', 'nl', 'pt', 'el', 'pl', 'sv', 'da', 'fi', 'cs', 'ro'],
        ])->assertCreated();
    }

    #[Test]
    public function update_also_enforces_the_target_language_cap(): void
    {
        $user = $this->actAs('free');
        $project = Project::create([
            'team_id' => $user->team_id,
            'name' => 'P',
            'status' => 'draft',
            'target_languages' => ['de'],
        ]);

        // Try to add a second language — should be rejected.
        $this->putJson("/api/projects/{$project->id}", [
            'target_languages' => ['de', 'fr'],
        ])->assertStatus(422)
          ->assertJsonValidationErrors('target_languages');

        // Single language update still works.
        $this->putJson("/api/projects/{$project->id}", [
            'target_languages' => ['fr'],
        ])->assertOk();
    }

    #[Test]
    public function update_without_target_languages_field_is_unaffected(): void
    {
        $user = $this->actAs('free');
        $project = Project::create([
            'team_id' => $user->team_id,
            'name' => 'P',
            'status' => 'draft',
            'target_languages' => ['de'],
        ]);

        // Change only the name — tier check shouldn't kick in.
        $this->putJson("/api/projects/{$project->id}", ['name' => 'Renamed'])->assertOk();
    }

    #[Test]
    public function show_rejects_a_project_belonging_to_another_team(): void
    {
        $otherTeam = Team::create(['name' => 'Other', 'owner_id' => 1, 'slug' => 'other-' . uniqid()]);
        $otherProject = Project::create([
            'team_id' => $otherTeam->id,
            'name' => 'Theirs',
            'status' => 'draft',
        ]);

        $this->actAs('free');

        $this->getJson("/api/projects/{$otherProject->id}")->assertForbidden();
    }
}
