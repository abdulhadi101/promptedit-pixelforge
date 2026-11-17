<?php

namespace Tests\Feature;

use App\Jobs\SubmitGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GenerationSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const PRICING = [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*/v1/pricing*' => Http::response(self::PRICING)]);
        Queue::fake();
        $user = User::factory()->create();
        $user->forceFill(['balance_nano' => 1_000_000_000])->save(); // fillable is locked to name/email/password
        $this->actingAs($user);
    }

    private function submit(array $extra = []): TestResponse
    {
        return $this->post('/generations', array_merge([
            'request_id' => fake()->uuid(),
            'prompt' => 'A lighthouse on a cliff at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ], $extra));
    }

    public function test_estimate_returns_ordered_min_expected_max(): void
    {
        $res = $this->postJson('/generations/estimate', [
            'prompt' => str_repeat('x', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ]);

        $res->assertOk();
        $data = $res->json();
        $this->assertIsInt($data['min']);
        $this->assertIsInt($data['expected']);
        $this->assertIsInt($data['max']);
        $this->assertTrue($data['min'] <= $data['expected'] && $data['expected'] <= $data['max']);

        // Docs worked example, maximum case (115 text tokens, 1530 image tokens,
        // 6450 output, 8000 reasoning), +25%: 467_343_750 nanodollars.
        $this->assertSame(467_343_750, $data['max']);
    }

    public function test_submit_creates_one_row_and_one_job(): void
    {
        $this->submit();

        $this->assertDatabaseCount('generations', 1);
        $this->assertDatabaseHas('generations', ['status' => 'queued', 'provider_cost_nano' => null]);
        Queue::assertPushed(SubmitGeneration::class, 1);
    }

    public function test_double_click_with_the_same_request_id_is_idempotent(): void
    {
        $id = fake()->uuid();
        $this->submit(['request_id' => $id]);
        $this->submit(['request_id' => $id]);

        $this->assertDatabaseCount('generations', 1);        // one row, not two
        Queue::assertPushed(SubmitGeneration::class, 1);    // submitted once, not twice
    }

    public function test_idempotent_retry_returns_existing_generation_even_when_balance_is_reserved(): void
    {
        $estimate = $this->postJson('/generations/estimate', [
            'prompt' => 'A lighthouse on a cliff at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ])->json();
        auth()->user()->forceFill(['balance_nano' => $estimate['max']])->save();
        $requestId = fake()->uuid();

        $this->submit(['request_id' => $requestId])->assertSessionDoesntHaveErrors('balance');
        $retry = $this->submit(['request_id' => $requestId]);

        $retry->assertSessionDoesntHaveErrors('balance');
        $this->assertDatabaseCount('generations', 1);
        Queue::assertPushed(SubmitGeneration::class, 1);
    }

    public function test_different_request_ids_are_separate_generations(): void
    {
        $this->submit();
        $this->submit();

        $this->assertDatabaseCount('generations', 2);
        Queue::assertPushed(SubmitGeneration::class, 2);
    }

    public function test_rejects_more_than_four_reference_images(): void
    {
        $refs = ['https://a.example/x.png', 'https://b.example/x.png', 'https://c.example/x.png', 'https://d.example/x.png', 'https://e.example/x.png'];

        $this->submit(['reference_images' => $refs]);

        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
    }
}
