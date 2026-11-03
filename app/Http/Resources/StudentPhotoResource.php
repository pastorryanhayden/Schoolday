<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentPhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'assignment_id' => $this->assignment_id, 'name' => $this->name, 'revision' => $this->revision, 'created_at' => $this->created_at?->toIso8601String(), 'url' => route('api.'.$request->user()->role.'.photo', $this->resource, false)];
    }
}
