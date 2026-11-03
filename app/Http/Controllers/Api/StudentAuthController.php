<?php

namespace App\Http\Controllers\Api;

class StudentAuthController extends DeviceAuthController
{
    protected function role(): string
    {
        return 'student';
    }
}
