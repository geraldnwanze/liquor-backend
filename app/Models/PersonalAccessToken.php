<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/** Sanctum token with a UUID id, so every identifier in the app is a UUID. The bearer format is "{uuid}|{secret}". */
class PersonalAccessToken extends SanctumToken
{
    use HasUuids;
}
