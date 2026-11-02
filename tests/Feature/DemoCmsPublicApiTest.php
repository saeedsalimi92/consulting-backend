<?php

namespace Tests\Feature;

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoCmsPublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_demo_content_uses_the_public_cms_types_and_is_repeatable(): void
    {
        $this->seed(DemoSeeder::class);
        $firstCount = DB::table('cms_entries')->where('slug', 'like', 'demo-%')->count();
        $this->seed(DemoSeeder::class);
        $this->assertSame($firstCount, DB::table('cms_entries')->where('slug', 'like', 'demo-%')->count());

        foreach ([
            'pages' => 'demo-home',
            'posts' => 'demo-stress-basics',
            'faqs' => 'demo-booking-guide',
            'gallery' => 'demo-sample-room',
        ] as $type => $slug) {
            $this->getJson('/api/content/'.$type)->assertOk()->assertJsonFragment(['slug' => $slug]);
        }

        $this->getJson('/api/content/pages/demo-home')->assertOk()->assertJsonPath('data.slug', 'demo-home');
        $this->getJson('/api/content/page')->assertNotFound();
    }
}
