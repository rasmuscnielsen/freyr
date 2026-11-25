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
            'auto_categorize' => '1',
        ])
        ->assertRedirect();

    expect($settings->similarityAutoCategorize())->toBeTrue()
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
        ])
        ->assertRedirect();

    expect($settings->similarityEnabled())->toBeTrue()
        ->and($settings->similarityAvailable())->toBeTrue();

    Queue::assertPushed(GeneratePostingEmbeddings::class);
});

test('the openai key can be stored and removed', function () {
    fakeOpenAiEmbeddings();
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
    fakeOpenAiEmbeddings();

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

test('an openai key that does not work is not saved', function (int $status, ?string $errorCode, string $message) {
    config()->set('ai.providers.openai.key', null);
    fakeOpenAiEmbeddings($status, $errorCode);

    $admin = similarityAdmin();

    $this->actingAs($admin)
        ->from(route('platform.settings.edit'))
        ->put(route('platform.settings.openai.update'), ['api_key' => 'sk-bad-key-12345'])
        ->assertSessionHasErrors(['api_key' => $message]);

    expect(app(PlatformSettings::class)->openAiConfigured())->toBeFalse();
})->with([
    'invalid key' => [401, 'invalid_api_key', "This key doesn't work. Check that you copied all of it."],
    'no credit' => [429, 'insufficient_quota', 'This OpenAI account has no credit. Add billing on platform.openai.com and try again.'],
    'missing permission' => [403, null, "This key can't create embeddings. Check its permissions on platform.openai.com."],
    'openai down' => [500, null, "Couldn't reach OpenAI to check the key. Try again in a moment."],
]);

test('a rate limited openai key is saved, since it works', function () {
    fakeOpenAiEmbeddings(429, 'rate_limit_exceeded');

    $this->actingAs(similarityAdmin())
        ->put(route('platform.settings.openai.update'), ['api_key' => 'sk-busy-key-12345'])
        ->assertSessionHasNoErrors();

    expect(app(PlatformSettings::class)->openAiApiKey())->toBe('sk-busy-key-12345');
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
