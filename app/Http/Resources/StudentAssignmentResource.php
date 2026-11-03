<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'subject' => $this->subject,
            'title' => $this->title,
            'instructions' => $this->instructions,
            'reference' => $this->reference,
            'minutes' => $this->minutes,
            'position' => $this->position,
            'requires_photo' => $this->requires_photo,
            'status' => $this->status,
            'note' => $this->note,
            'feedback' => $this->feedback,
            'revision' => $this->revision,
            'started_at' => $this->started_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'activity_type' => $this->activity_type,
            'is_extra_practice' => $this->is_extra_practice,
            'game_id' => $this->game_id,
            'question_target' => $this->question_target,
            'fact_min' => $this->fact_min,
            'fact_max' => $this->fact_max,
            'spelling_words' => $this->spelling_words,
            'book' => $this->whenLoaded('book', fn (): ?array => $this->book?->only(['id', 'title', 'subject', 'publisher', 'level'])),
            'photos' => StudentPhotoResource::collection($this->whenLoaded('photos')),
        ];
    }
}
