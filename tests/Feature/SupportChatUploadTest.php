<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\StoredFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithSupportChat;
use Tests\TestCase;

class SupportChatUploadTest extends TestCase
{
    use InteractsWithSupportChat;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_member_uploads_an_image_and_gets_the_stored_files_shape(): void
    {
        $member = $this->member();

        $response = $this->asMember($member)
            ->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonStructure(['url', 'signed_url']);

        $url = $response->json('url');
        $this->assertStringStartsWith('chat-uploads/', $url);
        Storage::disk('local')->assertExists($url);
        $this->assertDatabaseHas('stored_files', ['url' => $url, 'user_id' => $member->id, 'category' => 'chat']);

        // The temporary link actually serves the picture (it is not attached to a message yet).
        $this->flushHeaders();
        $this->get($response->json('signed_url'))->assertOk();
    }

    public function test_guest_uploads_a_png_without_a_stored_file_record(): void
    {
        [$session, $token] = $this->guestSession();

        $response = $this->asGuest($token)
            ->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonStructure(['url', 'signed_url']);

        $this->assertStringStartsWith("chat-uploads/guest-{$session->id}/", $response->json('url'));
        Storage::disk('local')->assertExists($response->json('url'));
        $this->assertDatabaseCount('stored_files', 0);

        $this->flushHeaders();
        $this->get($response->json('signed_url'))->assertOk();
    }

    public function test_only_jpg_and_png_are_accepted(): void
    {
        $member = $this->member();
        $as = fn () => $this->asMember($member);

        $as()->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $as()->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->create('a.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $as()->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->create('big.jpg', 10241, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $as()->post('/api/support/chat/uploads', [], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('stored_files', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_upload_requires_an_identity(): void
    {
        $this->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(401);
        $this->asGuest('not-a-token')
            ->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(401);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_guest_is_capped_at_five_uploads_per_session(): void
    {
        [$session, $token] = $this->guestSession();
        [, $otherToken] = $this->guestSession();
        $upload = fn (string $t) => $this->asGuest($t)
            ->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json']);

        foreach (range(1, 5) as $i) {
            $upload($token)->assertCreated();
        }
        $upload($token)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertCount(5, Storage::disk('local')->files("chat-uploads/guest-{$session->id}"));

        // The cap is per session, not global.
        $upload($otherToken)->assertCreated();
    }

    public function test_upload_endpoint_is_throttled(): void
    {
        $member = $this->member();

        foreach (range(1, 10) as $i) {
            $this->asMember($member)
                ->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
                ->assertCreated();
        }
        $this->asMember($member)
            ->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(429);
    }

    // --- Attaching to messages ---

    public function test_member_attaches_their_uploaded_image_to_a_message(): void
    {
        $member = $this->member();
        $session = $this->waitingSession($member);
        $url = $this->asMember($member)
            ->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->json('url');

        $response = $this->asMember($member)
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => 'عکس', 'fileUrl' => $url])
            ->assertCreated();

        $this->assertSame($url, ChatMessage::first()->file_url);
        $this->assertNotNull($response->json('fileUrl'));
        $this->assertStringContainsString('signature=', $response->json('fileUrl'));

        $this->flushHeaders();
        $this->get($response->json('fileUrl'))->assertOk();
    }

    public function test_member_can_start_a_chat_lazily_with_an_image(): void
    {
        $member = $this->member();
        $url = $this->asMember($member)
            ->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->json('url');

        $this->asMember($member)
            ->postJson('/api/support/chat/messages', ['text' => 'عکس', 'fileUrl' => $url])
            ->assertCreated();

        $this->assertDatabaseCount('chat_sessions', 1);
        $this->assertSame($url, ChatMessage::first()->file_url);
    }

    public function test_guest_attaches_their_uploaded_image_and_the_agent_can_open_it(): void
    {
        [$session, $token] = $this->guestSession();
        $headers = $this->guestHeaders($token);
        $url = $this->withHeaders($headers)
            ->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])
            ->json('url');

        $this->withHeaders($headers)
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => 'عکس', 'fileUrl' => $url])
            ->assertCreated();

        $history = $this->asMember($this->agent())
            ->getJson("/api/admin/chat/messages?sessionId={$session->id}")
            ->assertOk();

        $this->flushHeaders();
        $this->get($history->json('0.fileUrl'))->assertOk();
    }

    public function test_arbitrary_and_external_file_urls_are_rejected_for_members_and_guests(): void
    {
        $member = $this->member();
        $session = $this->waitingSession($member);
        [$guestSession, $token] = $this->guestSession();
        Storage::disk('local')->put('secret/other.jpg', 'x');
        // A genuine document the member owns, but not through the chat uploader.
        StoredFile::create(['url' => 'uploads/lab.pdf', 'user_id' => $member->id, 'category' => 'nutrition-docs']);

        $bad = [
            'https://evil.example/x.jpg',
            '//evil.example/x.jpg',
            'secret/other.jpg',
            'chat-uploads/../secret/other.jpg',
            'chat-uploads/does-not-exist.jpg',
            'uploads/lab.pdf',
            'C:\\windows\\system32\\cmd.exe',
        ];
        foreach ($bad as $fileUrl) {
            $this->asMember($member)
                ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => 'x', 'fileUrl' => $fileUrl])
                ->assertStatus(422)
                ->assertJsonValidationErrors('fileUrl');
            $this->asGuest($token)
                ->postJson('/api/support/chat/messages', ['sessionId' => $guestSession->id, 'text' => 'x', 'fileUrl' => $fileUrl])
                ->assertStatus(422)
                ->assertJsonValidationErrors('fileUrl');
        }

        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_rejected_attachment_on_a_lazy_message_creates_no_session(): void
    {
        $this->asMember($this->member())
            ->postJson('/api/support/chat/messages', ['text' => 'x', 'fileUrl' => 'chat-uploads/nope.jpg'])
            ->assertStatus(422);

        $this->assertDatabaseCount('chat_sessions', 0);
    }

    public function test_files_uploaded_by_someone_else_cannot_be_attached(): void
    {
        $owner = $this->member();
        $thief = $this->member();
        $ownerSession = $this->waitingSession($owner);
        $thiefSession = $this->waitingSession($thief);
        [$guestSession, $guestToken] = $this->guestSession();
        [, $otherGuestToken] = $this->guestSession();

        $memberFile = $this->asMember($owner)
            ->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->json('url');
        $guestFile = $this->asGuest($guestToken)
            ->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->json('url');
        $this->flushHeaders();

        // another member
        $this->asMember($thief)
            ->postJson('/api/support/chat/messages', ['sessionId' => $thiefSession->id, 'text' => 'x', 'fileUrl' => $memberFile])
            ->assertStatus(422);
        // a member using a guest's file / a guest using a member's file or another guest's file
        $this->asMember($owner)
            ->postJson('/api/support/chat/messages', ['sessionId' => $ownerSession->id, 'text' => 'x', 'fileUrl' => $guestFile])
            ->assertStatus(422);
        $this->asGuest($otherGuestToken)
            ->postJson('/api/support/chat/messages', ['sessionId' => $this->guestSessionIdFor($otherGuestToken), 'text' => 'x', 'fileUrl' => $guestFile])
            ->assertStatus(422);
        $this->asGuest($guestToken)
            ->postJson('/api/support/chat/messages', ['sessionId' => $guestSession->id, 'text' => 'x', 'fileUrl' => $memberFile])
            ->assertStatus(422);

        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_the_stable_path_may_also_be_sent_with_a_leading_storage_prefix(): void
    {
        $member = $this->member();
        $session = $this->waitingSession($member);
        $url = $this->asMember($member)
            ->post('/api/support/chat/uploads', ['file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->json('url');

        $this->asMember($member)
            ->postJson('/api/support/chat/messages', ['sessionId' => $session->id, 'text' => 'x', 'fileUrl' => "/storage/{$url}"])
            ->assertCreated();

        $this->assertSame($url, ChatMessage::first()->file_url);
    }

    public function test_the_signed_file_route_does_not_serve_unrelated_files_next_to_chat_uploads(): void
    {
        Storage::disk('local')->put('uploads/stray.jpg', 'x');

        $this->get(\App\Support\PrivateFiles::signedUrl('uploads/stray.jpg'))->assertNotFound();
    }

    private function guestSessionIdFor(string $token): int
    {
        return \App\Models\ChatSession::where('guest_token_hash', hash('sha256', $token))->value('id');
    }
}
