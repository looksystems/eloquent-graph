<?php

use Tests\Models\Post;
use Tests\Models\Tag;
use Tests\Models\Video;
use Tests\TestCase\GraphTestCase;

class MorphToManyTest extends GraphTestCase
{
    public function test_morph_to_many_can_attach_and_retrieve_tags_for_posts()
    {
        $post = Post::create(['title' => 'Laravel Tips', 'user_id' => 1]);
        $tag1 = Tag::create(['name' => 'php']);
        $tag2 = Tag::create(['name' => 'laravel']);
        $tag3 = Tag::create(['name' => 'tips']);

        // Attach tags to post
        $post->attachedTags()->attach([$tag1->id, $tag2->id, $tag3->id]);

        // Refresh and check
        $post = Post::find($post->id);
        $tags = $post->attachedTags;

        $this->assertCount(3, $tags);
        $this->assertContains('php', $tags->pluck('name')->toArray());
        $this->assertContains('laravel', $tags->pluck('name')->toArray());
        $this->assertContains('tips', $tags->pluck('name')->toArray());
    }

    public function test_morph_to_many_can_attach_and_retrieve_tags_for_videos()
    {
        $video = Video::create(['title' => 'Tutorial', 'url' => 'tutorial.mp4']);
        $tag1 = Tag::create(['name' => 'tutorial']);
        $tag2 = Tag::create(['name' => 'educational']);

        // Attach tags to video
        $video->attachedTags()->attach([$tag1->id, $tag2->id]);

        // Refresh and check
        $video = Video::find($video->id);
        $tags = $video->attachedTags;

        $this->assertCount(2, $tags);
        $this->assertContains('tutorial', $tags->pluck('name')->toArray());
        $this->assertContains('educational', $tags->pluck('name')->toArray());
    }

    public function test_morphed_by_many_can_retrieve_posts_for_tag()
    {
        $tag = Tag::create(['name' => 'news']);
        $post1 = Post::create(['title' => 'Breaking News', 'user_id' => 1]);
        $post2 = Post::create(['title' => 'Daily News', 'user_id' => 1]);
        $post3 = Post::create(['title' => 'Tech Article', 'user_id' => 1]);

        // Attach tag to posts
        $post1->attachedTags()->attach($tag->id);
        $post2->attachedTags()->attach($tag->id);
        // post3 not attached

        // Get posts for tag
        $posts = $tag->posts;

        $this->assertCount(2, $posts);
        $this->assertContains('Breaking News', $posts->pluck('title')->toArray());
        $this->assertContains('Daily News', $posts->pluck('title')->toArray());
        $this->assertNotContains('Tech Article', $posts->pluck('title')->toArray());
    }

    public function test_morphed_by_many_can_retrieve_videos_for_tag()
    {
        $tag = Tag::create(['name' => 'coding']);
        $video1 = Video::create(['title' => 'PHP Tutorial', 'url' => 'php.mp4']);
        $video2 = Video::create(['title' => 'Laravel Tutorial', 'url' => 'laravel.mp4']);
        $video3 = Video::create(['title' => 'Music Video', 'url' => 'music.mp4']);

        // Attach tag to videos
        $video1->attachedTags()->attach($tag->id);
        $video2->attachedTags()->attach($tag->id);
        // video3 not attached

        // Get videos for tag
        $videos = $tag->videos;

        $this->assertCount(2, $videos);
        $this->assertContains('PHP Tutorial', $videos->pluck('title')->toArray());
        $this->assertContains('Laravel Tutorial', $videos->pluck('title')->toArray());
        $this->assertNotContains('Music Video', $videos->pluck('title')->toArray());
    }

    public function test_morph_to_many_with_pivot_data()
    {
        $post = Post::create(['title' => 'Article', 'user_id' => 1]);
        $tag = Tag::create(['name' => 'featured']);

        // Attach with pivot data
        $post->attachedTags()->attach($tag->id, ['created_at' => now()]);

        $post = Post::find($post->id);
        $attachedTag = $post->attachedTags->first();

        $this->assertNotNull($attachedTag);
        $this->assertEquals('featured', $attachedTag->name);
        $this->assertNotNull($attachedTag->pivot);
        $this->assertNotNull($attachedTag->pivot->created_at);
    }

    public function test_detaching_morph_to_many_relationships()
    {
        $post = Post::create(['title' => 'Article', 'user_id' => 1]);
        $tag1 = Tag::create(['name' => 'tag1']);
        $tag2 = Tag::create(['name' => 'tag2']);
        $tag3 = Tag::create(['name' => 'tag3']);

        // Attach all tags
        $post->attachedTags()->attach([$tag1->id, $tag2->id, $tag3->id]);
        $this->assertCount(3, $post->fresh()->attachedTags);

        // Detach one tag
        $post->attachedTags()->detach($tag2->id);
        $this->assertCount(2, $post->fresh()->attachedTags);
        $remainingTags = $post->fresh()->attachedTags->pluck('name')->toArray();
        $this->assertContains('tag1', $remainingTags);
        $this->assertContains('tag3', $remainingTags);
        $this->assertNotContains('tag2', $remainingTags);

        // Detach all
        $post->attachedTags()->detach();
        $this->assertCount(0, $post->fresh()->attachedTags);
    }

    public function test_syncing_morph_to_many_relationships()
    {
        $post = Post::create(['title' => 'Article', 'user_id' => 1]);
        $tag1 = Tag::create(['name' => 'keep']);
        $tag2 = Tag::create(['name' => 'remove']);
        $tag3 = Tag::create(['name' => 'add']);

        // Initial attachment
        $post->attachedTags()->attach([$tag1->id, $tag2->id]);
        $this->assertCount(2, $post->fresh()->attachedTags);

        // Sync (keep tag1, remove tag2, add tag3)
        $post->attachedTags()->sync([$tag1->id, $tag3->id]);

        $tags = $post->fresh()->attachedTags->pluck('name')->toArray();
        $this->assertCount(2, $tags);
        $this->assertContains('keep', $tags);
        $this->assertContains('add', $tags);
        $this->assertNotContains('remove', $tags);
    }

    public function test_toggle_morph_to_many_relationships()
    {
        $post = Post::create(['title' => 'Article', 'user_id' => 1]);
        $tag1 = Tag::create(['name' => 'tag1']);
        $tag2 = Tag::create(['name' => 'tag2']);

        // Initially attach tag1
        $post->attachedTags()->attach($tag1->id);
        $this->assertCount(1, $post->fresh()->attachedTags);
        $this->assertContains('tag1', $post->fresh()->attachedTags->pluck('name')->toArray());

        // Toggle both tags (removes tag1, adds tag2)
        $post->attachedTags()->toggle([$tag1->id, $tag2->id]);

        $tags = $post->fresh()->attachedTags->pluck('name')->toArray();
        $this->assertCount(1, $tags);
        $this->assertNotContains('tag1', $tags);
        $this->assertContains('tag2', $tags);

        // Toggle again (adds tag1, removes tag2)
        $post->attachedTags()->toggle([$tag1->id, $tag2->id]);

        $tags = $post->fresh()->attachedTags->pluck('name')->toArray();
        $this->assertCount(1, $tags);
        $this->assertContains('tag1', $tags);
        $this->assertNotContains('tag2', $tags);
    }

    public function test_eager_loading_morph_to_many_relationships()
    {
        $tag1 = Tag::create(['name' => 'technology']);
        $tag2 = Tag::create(['name' => 'science']);

        $post1 = Post::create(['title' => 'Tech Post', 'user_id' => 1]);
        $post2 = Post::create(['title' => 'Science Post', 'user_id' => 1]);

        $post1->attachedTags()->attach([$tag1->id, $tag2->id]);
        $post2->attachedTags()->attach($tag2->id);

        // Eager load tags
        $posts = Post::with('attachedTags')->get();

        $this->assertTrue($posts[0]->relationLoaded('attachedTags'));
        $this->assertTrue($posts[1]->relationLoaded('attachedTags'));

        $tech = $posts->firstWhere('title', 'Tech Post');
        $science = $posts->firstWhere('title', 'Science Post');

        $this->assertCount(2, $tech->attachedTags);
        $this->assertCount(1, $science->attachedTags);
    }

    public function test_eager_loading_morphed_by_many_relationships()
    {
        $tag = Tag::create(['name' => 'popular']);

        $post1 = Post::create(['title' => 'Post 1', 'user_id' => 1]);
        $post2 = Post::create(['title' => 'Post 2', 'user_id' => 1]);
        $video1 = Video::create(['title' => 'Video 1', 'url' => 'v1.mp4']);
        $video2 = Video::create(['title' => 'Video 2', 'url' => 'v2.mp4']);

        $post1->attachedTags()->attach($tag->id);
        $post2->attachedTags()->attach($tag->id);
        $video1->attachedTags()->attach($tag->id);

        // Eager load both posts and videos
        $tags = Tag::with(['posts', 'videos'])->get();
        $popularTag = $tags->firstWhere('name', 'popular');

        $this->assertTrue($popularTag->relationLoaded('posts'));
        $this->assertTrue($popularTag->relationLoaded('videos'));
        $this->assertCount(2, $popularTag->posts);
        $this->assertCount(1, $popularTag->videos);
    }

    public function test_morph_to_many_with_where_conditions()
    {
        $post = Post::create(['title' => 'Article', 'user_id' => 1]);

        $tag1 = Tag::create(['name' => 'php']);
        $tag2 = Tag::create(['name' => 'python']);
        $tag3 = Tag::create(['name' => 'javascript']);

        $post->attachedTags()->attach([$tag1->id, $tag2->id, $tag3->id]);

        // Query with where condition
        $pTags = $post->tags()->where('name', 'like', 'p%')->get();

        $this->assertCount(2, $pTags);
        $names = $pTags->pluck('name')->toArray();
        $this->assertContains('php', $names);
        $this->assertContains('python', $names);
        $this->assertNotContains('javascript', $names);
    }

    public function test_counting_morph_to_many_relationships()
    {
        $post = Post::create(['title' => 'Article', 'user_id' => 1]);
        $video = Video::create(['title' => 'Tutorial', 'url' => 'tutorial.mp4']);

        $tag1 = Tag::create(['name' => 'tag1']);
        $tag2 = Tag::create(['name' => 'tag2']);
        $tag3 = Tag::create(['name' => 'tag3']);

        $post->attachedTags()->attach([$tag1->id, $tag2->id, $tag3->id]);
        $video->attachedTags()->attach([$tag1->id]);

        $this->assertEquals(3, $post->tags()->count());
        $this->assertEquals(1, $video->tags()->count());

        // Count from tag side
        $this->assertEquals(1, $tag1->posts()->count());
        $this->assertEquals(1, $tag1->videos()->count());
        $this->assertEquals(1, $tag2->posts()->count());
        $this->assertEquals(0, $tag2->videos()->count());
    }

    public function test_with_count_for_morph_to_many_relationships()
    {
        $post1 = Post::create(['title' => 'Post 1', 'user_id' => 1]);
        $post2 = Post::create(['title' => 'Post 2', 'user_id' => 1]);

        $tag1 = Tag::create(['name' => 'tag1']);
        $tag2 = Tag::create(['name' => 'tag2']);
        $tag3 = Tag::create(['name' => 'tag3']);

        $post1->attachedTags()->attach([$tag1->id, $tag2->id]);
        $post2->attachedTags()->attach([$tag1->id, $tag2->id, $tag3->id]);

        $posts = Post::withCount('attachedTags')->get();

        $p1 = $posts->firstWhere('title', 'Post 1');
        $p2 = $posts->firstWhere('title', 'Post 2');

        $this->assertEquals(2, $p1->attached_tags_count);
        $this->assertEquals(3, $p2->attached_tags_count);
    }

    public function test_updating_existing_pivot()
    {
        $post = Post::create(['title' => 'Article', 'user_id' => 1]);
        $tag = Tag::create(['name' => 'important']);

        // Attach with initial pivot data
        $post->attachedTags()->attach($tag->id, ['priority' => 1]);

        // Update pivot data
        $post->attachedTags()->updateExistingPivot($tag->id, ['priority' => 5]);

        $post = Post::find($post->id);
        $attachedTag = $post->tags()->withPivot('priority')->first();

        $this->assertEquals(5, $attachedTag->pivot->priority);
    }

    public function test_mixed_content_types_with_same_tag()
    {
        $tag = Tag::create(['name' => 'trending']);

        $post1 = Post::create(['title' => 'Trending Post 1', 'user_id' => 1]);
        $post2 = Post::create(['title' => 'Trending Post 2', 'user_id' => 1]);
        $video1 = Video::create(['title' => 'Trending Video 1', 'url' => 'tv1.mp4']);
        $video2 = Video::create(['title' => 'Trending Video 2', 'url' => 'tv2.mp4']);

        // Attach same tag to different content types
        $post1->attachedTags()->attach($tag->id);
        $post2->attachedTags()->attach($tag->id);
        $video1->attachedTags()->attach($tag->id);
        $video2->attachedTags()->attach($tag->id);

        // Verify from tag side
        $tag = Tag::find($tag->id);
        $this->assertCount(2, $tag->posts);
        $this->assertCount(2, $tag->videos);

        // Verify each content has the tag
        $this->assertCount(1, Post::find($post1->id)->attachedTags);
        $this->assertCount(1, Post::find($post2->id)->attachedTags);
        $this->assertCount(1, Video::find($video1->id)->attachedTags);
        $this->assertCount(1, Video::find($video2->id)->attachedTags);
    }

    public function test_morph_to_many_returns_empty_collection_when_no_relations()
    {
        $post = Post::create(['title' => 'Untagged Post', 'user_id' => 1]);

        $tags = $post->attachedTags;

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $tags);
        $this->assertCount(0, $tags);
    }

    public function test_morphed_by_many_returns_empty_collection_when_no_relations()
    {
        $tag = Tag::create(['name' => 'unused']);

        $posts = $tag->posts;
        $videos = $tag->videos;

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $posts);
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $videos);
        $this->assertCount(0, $posts);
        $this->assertCount(0, $videos);
    }
}
