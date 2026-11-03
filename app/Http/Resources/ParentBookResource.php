<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParentBookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'subject', 'title', 'publisher', 'level', 'reference', 'instructions', 'minutes', 'requires_photo', 'active']);
    }
}
