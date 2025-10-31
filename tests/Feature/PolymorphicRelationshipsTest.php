<?php

use Tests\Models\Comment;
use Tests\Models\Image;
use Tests\Models\Post;
use Tests\Models\User;
use Tests\Models\Video;
use Tests\TestCase\GraphTestCase;

class PolymorphicRelationshipsTest extends GraphTestCase
{
    public function test_morph_one_can_create_and_retrieve_single_related_model()
    {
        $user = User::create(['name' => 'John']);
        $post = Post::create(['title' => 'First Post', 'user_id' => $user->id]);

        // Create avatars for both user and post
        $userAvatar = $user->avatar()->create(['url' => 'user-avatar.jpg']);
        $postImage = $post->featuredImage()->create(['url' => 'post-featured.jpg']);

        // Refresh models
        $user = User::find($user->id);
        $post = Post::find($post->id);

        // Assertions
        $this->assertNotNull($user->avatar);
        $this->assertEquals('user-avatar.jpg', $user->avatar->url);
        $this->assertEquals($user->id, $user->avatar->imageable_id);
        $this->assertEquals(User::class, $user->avatar->imageable_type);

        $this->assertNotNull($post->featuredImage);
        $this->assertEquals('post-featured.jpg', $post->featuredImage->url);
        $this->assertEquals($post->id, $post->featuredImage->imageable_id);
        $this->assertEquals(Post::class, $post->featuredImage->imageable_type);
    }

    public function test_morph_many_can_create_and_retrieve_multiple_related_models()
    {
        $user = User::create(['name' => 'John']);
        $post = Post::create(['title' => 'First Post', 'user_id' => $user->id]);

        // Create multiple images for both
        $userImage1 = $user->images()->create(['url' => 'user1.jpg']);
        $userImage2 = $user->images()->create(['url' => 'user2.jpg']);
        $postImage1 = $post->images()->create(['url' => 'post1.jpg']);
        $postImage2 = $post->images()->create(['url' => 'post2.jpg']);
        $postImage3 = $post->images()->create(['url' => 'post3.jpg']);

        // Refresh models
        $user = User::find($user->id);
        $post = Post::find($post->id);

        // Assertions
        $this->assertCount(2, $user->images);
        $userUrls = $user->images->pluck('url')->toArray();
        $this->assertContains('user1.jpg', $userUrls);
        $this->assertContains('user2.jpg', $userUrls);
        $this->assertTrue($user->images->every(fn ($img) => $img->imageable_id === $user->id && $img->imageable_type === User::class
        ));

        $this->assertCount(3, $post->images);
        $postUrls = $post->images->pluck('url')->toArray();
        $this->assertContains('post1.jpg', $postUrls);
        $this->assertContains('post2.jpg', $postUrls);
        $this->assertContains('post3.jpg', $postUrls);
    }

    public function test_morph_to_can_retrieve_parent_model()
    {
        $user = User::create(['name' => 'John']);
        $post = Post::create(['title' => 'First Post', 'user_id' => $user->id]);
        $video = Video::create(['title' => 'First Video', 'url' => 'video.mp4']);

        // Create comments for different models
        $postComment = Comment::create([
            'content' => 'Great post!',
            'commentable_id' => $post->id,
            'commentable_type' => Post::class,
        ]);

        $videoComment = Comment::create([
            'content' => 'Nice video!',
            'commentable_id' => $video->id,
            'commentable_type' => Video::class,
        ]);

        // Retrieve comments and check parent
        $postComment = Comment::find($postComment->id);
        $videoComment = Comment::find($videoComment->id);

        $this->assertInstanceOf(Post::class, $postComment->commentable);
        $this->assertEquals($post->id, $postComment->commentable->id);
        $this->assertEquals('First Post', $postComment->commentable->title);

        $this->assertInstanceOf(Video::class, $videoComment->commentable);
        $this->assertEquals($video->id, $videoComment->commentable->id);
        $this->assertEquals('First Video', $videoComment->commentable->title);
    }

    public function test_polymorphic_relationships_with_multiple_types()
    {
        $user = User::create(['name' => 'John']);
        $video = Video::create(['title' => 'Tutorial Video', 'url' => 'tutorial.mp4']);

        // Create images for both
        $userImage1 = $user->images()->create(['url' => 'user-img-1.jpg']);
        $userImage2 = $user->images()->create(['url' => 'user-img-2.jpg']);
        $videoImage1 = $video->images()->create(['url' => 'video-img-1.jpg']);
        $videoImage2 = $video->images()->create(['url' => 'video-img-2.jpg']);
        $videoImage3 = $video->images()->create(['url' => 'video-img-3.jpg']);

        // Refresh and check
        $user = User::find($user->id);
        $video = Video::find($video->id);

        $this->assertCount(2, $user->images);
        $this->assertCount(3, $video->images);

        // Verify all images point to correct parent
        foreach ($user->images as $image) {
            $this->assertEquals(User::class, $image->imageable_type);
            $this->assertEquals($user->id, $image->imageable_id);
        }

        foreach ($video->images as $image) {
            $this->assertEquals(Video::class, $image->imageable_type);
            $this->assertEquals($video->id, $image->imageable_id);
        }
    }

    public function test_eager_loading_morph_one_relationships()
    {
        $user1 = User::create(['name' => 'User 1']);
        $user2 = User::create(['name' => 'User 2']);
        $user1->avatar()->create(['url' => 'avatar1.jpg']);
        $user2->avatar()->create(['url' => 'avatar2.jpg']);

        $users = User::with('avatar')->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users[0]->relationLoaded('avatar'));
        $this->assertTrue($users[1]->relationLoaded('avatar'));
        $this->assertEquals('avatar1.jpg', $users->firstWhere('name', 'User 1')->avatar->url);
        $this->assertEquals('avatar2.jpg', $users->firstWhere('name', 'User 2')->avatar->url);
    }

    public function test_eager_loading_morph_many_relationships()
    {
        $video1 = Video::create(['title' => 'Video 1', 'url' => 'v1.mp4']);
        $video2 = Video::create(['title' => 'Video 2', 'url' => 'v2.mp4']);

        $video1->comments()->create(['content' => 'Video 1 Comment 1']);
        $video1->comments()->create(['content' => 'Video 1 Comment 2']);
        $video2->comments()->create(['content' => 'Video 2 Comment 1']);

        $videos = Video::with('comments')->get();

        $this->assertCount(2, $videos);
        $this->assertTrue($videos[0]->relationLoaded('comments'));
        $this->assertTrue($videos[1]->relationLoaded('comments'));

        $video1 = $videos->firstWhere('title', 'Video 1');
        $video2 = $videos->firstWhere('title', 'Video 2');

        $this->assertCount(2, $video1->comments);
        $this->assertCount(1, $video2->comments);
    }

    public function test_eager_loading_morph_to_relationships()
    {
        $post = Post::create(['title' => 'Post', 'user_id' => 1]);
        $video = Video::create(['title' => 'Video', 'url' => 'video.mp4']);

        Comment::create(['content' => 'Comment 1', 'commentable_id' => $post->id, 'commentable_type' => Post::class]);
        Comment::create(['content' => 'Comment 2', 'commentable_id' => $video->id, 'commentable_type' => Video::class]);
        Comment::create(['content' => 'Comment 3', 'commentable_id' => $post->id, 'commentable_type' => Post::class]);

        $comments = Comment::with('commentable')->get();

        $this->assertCount(3, $comments);
        foreach ($comments as $comment) {
            $this->assertTrue($comment->relationLoaded('commentable'));
            $this->assertNotNull($comment->commentable);
        }

        // Check that correct models are loaded
        $postComments = $comments->filter(fn ($c) => $c->commentable_type === Post::class);
        $videoComments = $comments->filter(fn ($c) => $c->commentable_type === Video::class);

        $this->assertCount(2, $postComments);
        $this->assertCount(1, $videoComments);

        foreach ($postComments as $comment) {
            $this->assertInstanceOf(Post::class, $comment->commentable);
            $this->assertEquals('Post', $comment->commentable->title);
        }

        foreach ($videoComments as $comment) {
            $this->assertInstanceOf(Video::class, $comment->commentable);
            $this->assertEquals('Video', $comment->commentable->title);
        }
    }

    public function test_polymorphic_where_conditions()
    {
        $user = User::create(['name' => 'John']);
        $video = Video::create(['title' => 'Video', 'url' => 'video.mp4']);

        // Create images with different URLs
        $userImage1 = Image::create(['url' => 'profile.jpg', 'imageable_id' => $user->id, 'imageable_type' => User::class]);
        $userImage2 = Image::create(['url' => 'banner.jpg', 'imageable_id' => $user->id, 'imageable_type' => User::class]);
        $videoImage1 = Image::create(['url' => 'thumbnail.jpg', 'imageable_id' => $video->id, 'imageable_type' => Video::class]);
        $videoImage2 = Image::create(['url' => 'banner.jpg', 'imageable_id' => $video->id, 'imageable_type' => Video::class]);

        // Find images with specific URL for users
        $userBannerImages = Image::where('imageable_type', User::class)
            ->where('url', 'banner.jpg')
            ->get();

        $this->assertCount(1, $userBannerImages);
        $this->assertEquals($user->id, $userBannerImages->first()->imageable_id);

        // Find all video images
        $videoImages = Image::where('imageable_type', Video::class)->get();
        $this->assertCount(2, $videoImages);
    }

    public function test_morph_one_returns_null_when_no_relation()
    {
        $user = User::create(['name' => 'John']);

        $this->assertNull($user->avatar);
    }

    public function test_morph_many_returns_empty_collection_when_no_relations()
    {
        $post = Post::create(['title' => 'Post', 'user_id' => 1]);

        $this->assertCount(0, $post->images);
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $post->images);
    }

    public function test_morph_to_returns_null_when_parent_not_found()
    {
        // Create comment with non-existent parent
        $comment = Comment::create([
            'content' => 'Orphaned',
            'commentable_id' => 99999,
            'commentable_type' => Post::class,
        ]);

        $this->assertNull($comment->commentable);
    }

    public function test_morph_many_with_ordering()
    {
        $video = Video::create(['title' => 'Video', 'url' => 'video.mp4']);

        $video->comments()->create(['content' => 'First']);
        sleep(1);
        $video->comments()->create(['content' => 'Second']);
        sleep(1);
        $video->comments()->create(['content' => 'Third']);

        $comments = $video->comments()->orderBy('content')->get();

        $this->assertEquals('First', $comments[0]->content);
        $this->assertEquals('Second', $comments[1]->content);
        $this->assertEquals('Third', $comments[2]->content);
    }

    public function test_counting_polymorphic_relations()
    {
        $user1 = User::create(['name' => 'User 1']);
        $user2 = User::create(['name' => 'User 2']);
        $video = Video::create(['title' => 'Video', 'url' => 'video.mp4']);

        $user1->images()->create(['url' => 'img1.jpg']);
        $user1->images()->create(['url' => 'img2.jpg']);
        $user2->images()->create(['url' => 'img3.jpg']);
        $video->images()->create(['url' => 'img4.jpg']);

        $this->assertEquals(2, $user1->images()->count());
        $this->assertEquals(1, $user2->images()->count());
        $this->assertEquals(1, $video->images()->count());
    }

    public function test_updating_polymorphic_relations()
    {
        $video = Video::create(['title' => 'Video', 'url' => 'video.mp4']);
        $comment = $video->comments()->create(['content' => 'Original']);

        $comment->update(['content' => 'Updated']);

        $comment = Comment::find($comment->id);
        $this->assertEquals('Updated', $comment->content);
        $this->assertEquals($video->id, $comment->commentable_id);
        $this->assertEquals(Video::class, $comment->commentable_type);
    }

    public function test_deleting_polymorphic_relations()
    {
        $video = Video::create(['title' => 'Video', 'url' => 'video.mp4']);
        $comment = $video->comments()->create(['content' => 'To be deleted']);
        $commentId = $comment->id;

        $comment->delete();

        $this->assertNull(Comment::find($commentId));
        $this->assertCount(0, $video->fresh()->comments);
    }
}
