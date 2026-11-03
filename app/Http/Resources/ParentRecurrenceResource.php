<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParentRecurrenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'starts_on' => $this->starts_on->toDateString(), 'ends_on' => $this->ends_on?->toDateString(), 'weekdays' => $this->weekdays, 'assignment_data' => $this->assignment_data, 'active' => $this->active];
    }
}
