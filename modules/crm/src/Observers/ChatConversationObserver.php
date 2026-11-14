<?php

namespace VentureDrake\LaravelCrm\Observers;

use Illuminate\Support\Facades\Cache;
use Ramsey\Uuid\Uuid;
use VentureDrake\LaravelCrm\Models\ChatConversation;

class ChatConversationObserver
{
    public function creating(ChatConversation $conversation)
    {
        $conversation->external_id = Uuid::uuid4()->toString();

        // Auto-generate human ID like C1001
        if (! $conversation->chat_id) {
            $conversation->chat_id = $this->nextChatReference();
        }

        if (! app()->runningInConsole()) {
            $conversation->user_created_id = auth()->user()->id ?? null;
        }
    }

    protected function nextChatReference(): string
    {
        $key = 'laravel-crm:chat-reference:'.(new ChatConversation)->getTable();

        return Cache::lock($key.':lock', 10)->block(5, function () use ($key): string {
            $last = Cache::get($key);

            if ($last === null) {
                $last = 1000;

                foreach (ChatConversation::withoutGlobalScopes()->withTrashed()->select('chat_id')->cursor() as $conversation) {
                    if (preg_match('/^C(\d+)$/', $conversation->chat_id ?? '', $matches)) {
                        $last = max($last, (int) $matches[1]);
                    }
                }
            }

            $next = $last + 1;
            Cache::put($key, $next, now()->addMinutes(5));

            return 'C'.$next;
        });
    }

    public function updating(ChatConversation $conversation)
    {
        if (! app()->runningInConsole()) {
            $conversation->user_updated_id = auth()->user()->id ?? null;
        }
    }

    public function deleting(ChatConversation $conversation)
    {
        if (! app()->runningInConsole()) {
            $conversation->user_deleted_id = auth()->user()->id ?? null;
            $conversation->saveQuietly();
        }
    }
}
