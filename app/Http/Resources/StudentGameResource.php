<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentGameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'configuration' => $this->configuration, 'active' => $this->active, 'rounds_count' => $this->whenCounted('rounds'), 'latest_round_id' => $this->whenLoaded('rounds', fn (): ?int => $this->rounds->first()?->id)];
    }
}
