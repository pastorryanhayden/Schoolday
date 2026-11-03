<?php

namespace App\Http\Controllers\Api;

class ParentAuthController extends DeviceAuthController
{
    protected function role(): string
    {
        return 'parent';
    }
}
