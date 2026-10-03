<?php

use App\Enums\PlatformSettingKey;
use App\Jobs\GeneratePostingEmbeddings;
use App\Models\PlatformSetting;
use App\Models\Posting;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;

function similarityAdmin(): User
{
    app(PlatformSettings::class)->markOnboardingCompleted();

    return User::factory()->platformAdmin()->create();
}

test('similarity settings can be patched without an api key until enabling', function () {
    config()->set('ai.providers.openai.key', null);

    $admin = similarityAdmin();
    $settings = app(PlatformSettings::class);

    $this->actingAs($admin)
        ->patch(route('platform.settings.similarity.update'), [
            'since' => '5y',
            'auto_categorize' => '1',
        ])
        ->assertRedirect();

    expect($settings->similaritySince())->toBe('5y')
        ->and($settings->similarityAutoCategorize())->toBeTrue()
        ->and($settings->similarityEnabled())->toBeFalse();
});

test('enabling similarity requires an openai key', function () {
    config()->set('ai.providers.openai.key', null);

    $admin = similarityAdmin();

    $this->actingAs($admin)
        ->from(route('platform.settings.edit'))
        ->patch(route('platform.settings.similarity.update'), ['enabled' => '1'])
        ->assertSessionHasErrors('enabled');

    expect(app(PlatformSettings::class)->similarityEnabled())->toBeFalse();
});

test('enabling similarity kicks off the embeddings job', function () {
    Queue::fake();

    $admin = similarityAdmin();
    $settings = app(PlatformSettings::class);
    $settings->storeOpenAiApiKey('sk-stored-key');

    $this->actingAs($admin)
        ->patch(route('platform.settings.similarity.update'), [
            'enabled' => '1',
            'since' => '2y',
        ])
        ->assertRedirect();

    expect($settings->similarityEnabled())->toBeTrue()
        ->and($settings->similarityAvailable())->toBeTrue();

    Queue::assertPushed(GeneratePostingEmbeddings::class);
});

test('the openai key can be stored and removed', function () {
    $admin = similarityAdmin();
    $settings = app(PlatformSettings::class);

    $this->actingAs($admin)
        ->put(route('platform.settings.openai.update'), [
            'api_key' => 'sk-new-key-12345',
        ])
        ->assertRedirect();

    $row = PlatformSetting::query()->find('openai.api_key');
    expect(Crypt::decryptString($row->value))->toBe('sk-new-key-12345')
        ->and($settings->openAiConfigured())->toBeTrue();

    $settings->set(PlatformSettingKey::SimilarityEnabled, true);

    $this->actingAs($admin)
        ->delete(route('platform.settings.openai.destroy'))
        ->assertRedirect();

    expect($settings->isStored(PlatformSettingKey::OpenAiApiKey))->toBeFalse()
        ->and($settings->similarityEnabled())->toBeFalse();
});

test('storing an openai key while similarity is enabled kicks off the job', function () {
    Queue::fake();

    $admin = similarityAdmin();
    $settings = app(PlatformSettings::class);
    $settings->set(PlatformSettingKey::SimilarityEnabled, true);

    $this->actingAs($admin)
        ->put(route('platform.settings.openai.update'), [
            'api_key' => 'sk-new-key-12345',
        ])
        ->assertRedirect();

    Queue::assertPushed(GeneratePostingEmbeddings::class);
});

test('the similar endpoint returns the posting payload with similarity disabled on unsupported databases', function () {
    $admin = similarityAdmin();
    $posting = Posting::factory()->create([
        'user_id' => $admin->id,
        'description' => 'Netflix',
    ]);

    $this->actingAs($admin)
        ->getJson(route('postings.similar', $posting))
        ->assertOk()
        ->assertJsonPath('similarity_enabled', false)
        ->assertJsonPath('posting.id', $posting->id)
        ->assertJsonPath('posting.description', 'Netflix')
        ->assertJsonPath('similar', []);
});

test('the similar endpoint forbids other users postings', function () {
    $admin = similarityAdmin();
    $other = User::factory()->create();
    $posting = Posting::factory()->create(['user_id' => $other->id]);

    $this->actingAs($admin)
        ->getJson(route('postings.similar', $posting))
        ->assertNotFound();
});
