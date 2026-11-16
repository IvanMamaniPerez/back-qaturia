<?php

namespace VentureDrake\LaravelCrm\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use VentureDrake\LaravelCrm\Models\ChatMessage;

class ChatMessageSent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public ChatMessage $message;

    public function __construct(ChatMessage $message)
    {
        $this->message = $message;
    }

    /**
     * Public channels — both visitor (unauthenticated) and CRM agent need to receive.
     * Channel name uses conversation external_id (UUID, unguessable).
     */
    public function broadcastOn(): array
    {
        return [
            new Channel($this->message->conversation->channelName()),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    public function broadcastWith(): array
    {
        // A public event is only a refresh hint. Both agents and visitors
        // fetch message bodies through their authenticated/visitor-token API.
        return ['refresh' => true];
    }
}
