<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'help' => $this->help,
            'assignment_id' => $this->assignment_id,
            'assignment_title' => $this->whenLoaded('assignment', fn (): ?string => $this->assignment?->title),
            'sender' => $this->whenLoaded('sender', fn (): array => $this->sender->only(['id', 'name', 'role'])),
            'is_mine' => $this->sender_id === $request->user()->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
        ];
    }
}
