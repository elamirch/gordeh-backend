<?php

namespace App\Services\Support;

use App\Models\ChatSession;
use App\Models\StoredFile;
use App\Support\ChatActor;
use App\Support\PrivateFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Image uploads for the chat widget, for members and guests alike.
 *
 * Members get a StoredFile row (category 'chat'). Guests have no user to attach one to, so
 * their files live in a per-session directory — which is also what enforces the per-session
 * cap and proves a guest can only attach files from their own session.
 */
class ChatUploadService
{
    public const DIRECTORY = 'chat-uploads';

    public const CATEGORY = 'chat';

    /** @return array{url: string, signed_url: string|null} same keys as POST /stored-files */
    public function store(ChatActor $actor, UploadedFile $file): array
    {
        if ($actor->isGuest()) {
            $directory = $this->guestDirectory($actor->guestSession);

            $limit = (int) config('support.chat.guest_upload_limit');
            if (count(Storage::disk(PrivateFiles::DISK)->files($directory)) >= $limit) {
                throw ValidationException::withMessages([
                    'file' => ["حداکثر {$limit} تصویر در هر گفتگو مجاز است"],
                ]);
            }

            $path = PrivateFiles::store($file, $directory);
        } else {
            $path = PrivateFiles::store($file, self::DIRECTORY);

            StoredFile::create([
                'url' => $path,
                'fileName' => $file->hashName(),
                'originalFileName' => $file->getClientOriginalName(),
                'user_id' => $actor->user->id,
                'category' => self::CATEGORY,
            ]);
        }

        return ['url' => $path, 'signed_url' => PrivateFiles::signedUrl($path)];
    }

    /**
     * Turns a client-supplied `fileUrl` into the stored path to save on the message.
     * Only a file this actor uploaded through store() is accepted — never an arbitrary path
     * or an external URL — otherwise a 422 on `fileUrl`.
     */
    public function attachmentFor(ChatActor $actor, ?string $fileUrl): ?string
    {
        if ($fileUrl === null || $fileUrl === '') {
            return null;
        }

        $path = PrivateFiles::path($fileUrl);

        if ($path === null || ! $this->ownedBy($actor, $path)) {
            throw ValidationException::withMessages([
                'fileUrl' => ['فایل پیوست معتبر نیست'],
            ]);
        }

        return $path;
    }

    private function ownedBy(ChatActor $actor, string $path): bool
    {
        if ($actor->isGuest()) {
            return Str::startsWith($path, $this->guestDirectory($actor->guestSession).'/')
                && Storage::disk(PrivateFiles::DISK)->exists($path);
        }

        return Str::startsWith($path, self::DIRECTORY.'/')
            && StoredFile::where('url', $path)
                ->where('user_id', $actor->user->id)
                ->where('category', self::CATEGORY)
                ->exists();
    }

    private function guestDirectory(ChatSession $session): string
    {
        return self::DIRECTORY.'/guest-'.$session->id;
    }
}
